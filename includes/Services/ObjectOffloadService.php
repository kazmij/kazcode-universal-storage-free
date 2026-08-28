<?php
/**
 * Object-level offload orchestration (v2).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Attachment\AttachmentFileResolver;
use Kazcode\WpStorage\Attachment\AttachmentOffloader;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\AttachmentSyncDeriver;
use Kazcode\WpStorage\Domain\ManifestBuilder;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\Queue\QueueJobType;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
use Kazcode\WpStorage\Plugin;
use Kazcode\WpStorage\Services\CleanupLocalFiles;
use Kazcode\WpStorage\Services\LegacyProfileMigrator;
use Kazcode\WpStorage\Storage\ObjectKeyService;
use Kazcode\WpStorage\Storage\S3Storage;

/**
 * Upload → verify → persist object rows → derive attachment cache meta.
 */
final class ObjectOffloadService {

	public const OPTION_ENABLED = 's3ms_object_offload_enabled';

	public function __construct(
		private Settings $settings,
		private S3Storage $storage,
		private ?AttachmentFileResolver $files = null,
		private ?ManifestBuilder $manifest_builder = null,
		private ?ObjectRepository $objects = null,
		private ?WpdbStorageProfileRepository $profiles = null,
	) {
		$this->files            = $files ?? new AttachmentFileResolver( $storage->keys() );
		$this->manifest_builder = $manifest_builder ?? new ManifestBuilder( $this->files );
		$this->objects          = $objects ?? new ObjectRepository();
		$this->profiles         = $profiles ?? new WpdbStorageProfileRepository();
	}

	/**
	 * Whether object-level offload path is active.
	 */
	public static function is_enabled(): bool {
		$default = (bool) get_option( self::OPTION_ENABLED, true );
		/**
		 * Filter object-level offload (v2).
		 *
		 * @param bool $enabled Whether enabled.
		 */
		return (bool) apply_filters( 's3ms_object_offload_enabled', $default );
	}

	/**
	 * @param array<string, mixed>|null $metadata_override Metadata from WP filter.
	 * @return array{success:bool,message:string,files?:int,keys?:list<string>,status?:string}
	 */
	public function offload( int $attachment_id, ?bool $delete_local = null, ?array $metadata_override = null ): array {
		$profile = $this->resolve_profile();
		if ( $profile === null || $profile->id === null ) {
			throw new \RuntimeException( 'No default storage profile configured.' );
		}

		$local_files = $this->files->existing_local_files( $attachment_id, $metadata_override );
		if ( $local_files === array() ) {
			$paths = $this->files->relative_paths( $attachment_id, $metadata_override );
			if ( $paths === array() ) {
				throw new \RuntimeException( 'Attachment has no file metadata.' );
			}
			throw new \RuntimeException( 'No local files found to upload. Restore from S3 first if files were deleted.' );
		}

		$manifest     = $this->manifest_builder->build( $attachment_id, $metadata_override );
		$uploaded_keys = array();
		$failures      = array();

		foreach ( $manifest->items() as $item ) {
			$relative = $item['relative'];
			if ( ! isset( $local_files[ $relative ] ) ) {
				continue;
			}

			$absolute   = $local_files[ $relative ];
			$object_key = ObjectKeyService::key_for( $profile->prefix, $relative );
			$row_id     = $this->objects->upsert(
				array(
					'attachment_id'       => $attachment_id,
					'storage_profile_id'  => $profile->id,
					'local_relative_path' => $relative,
					'object_key'          => $object_key,
					'variant_type'        => $item['variant_type'],
					'local_status'        => 'present',
					'remote_status'       => ObjectRemoteStatus::UPLOADING,
					'attempt_count'       => 1,
				)
			);

			try {
				$size = (int) filesize( $absolute );
				$head = $this->storage->head_key( $object_key );
				if ( empty( $head['exists'] ) || ( isset( $head['content_length'] ) && (int) $head['content_length'] !== $size ) ) {
					$this->storage->upload_file_to_key( $absolute, $object_key, $relative );
				}

				$head = $this->storage->head_key( $object_key );
				if ( empty( $head['exists'] ) ) {
					throw new \RuntimeException( 'Verification failed after upload for ' . $relative );
				}

				$now = gmdate( 'Y-m-d H:i:s' );
				$this->objects->upsert(
					array(
						'attachment_id'       => $attachment_id,
						'storage_profile_id'  => $profile->id,
						'local_relative_path' => $relative,
						'object_key'          => $object_key,
						'variant_type'        => $item['variant_type'],
						'size_bytes'          => $size,
						'remote_status'       => ObjectRemoteStatus::PRESENT,
						'verified_at'         => $now,
						'offloaded_at'        => $now,
						'last_error_code'     => null,
						'last_error_message'  => null,
					)
				);
				$uploaded_keys[] = $object_key;
			} catch ( \Throwable $e ) {
				$msg = $this->safe_error_message( $e );
				$this->objects->upsert(
					array(
						'attachment_id'       => $attachment_id,
						'storage_profile_id'  => $profile->id,
						'local_relative_path' => $relative,
						'object_key'          => $object_key,
						'variant_type'        => $item['variant_type'],
						'remote_status'       => ObjectRemoteStatus::FAILED,
						'last_error_message'  => $msg,
					)
				);
				$failures[] = $relative . ': ' . $msg;
			}
		}

		$rows   = $this->objects->find_by_attachment( $attachment_id );
		$status = AttachmentSyncDeriver::derive_status( $rows );
		$this->sync_attachment_meta( $attachment_id, $rows, $status );

		if ( $status === AttachmentOffloader::STATUS_OFFLOADED ) {
			$defer = (bool) apply_filters( 's3ms_defer_local_cleanup', false );
			if ( $defer ) {
				Plugin::instance()->queue()->enqueue(
					QueueJobType::CLEANUP_LOCAL_FILES,
					array(
						'attachment_id'  => $attachment_id,
						'delete_local'   => $delete_local,
						'profile_prefix' => $profile->prefix,
					)
				);
			} else {
				$cleanup = ( new CleanupLocalFiles( $this->settings, $this->storage ) )->maybe_cleanup(
					$attachment_id,
					$local_files,
					$manifest->items(),
					$delete_local,
					$profile->prefix
				);
				if ( $cleanup['skipped'] && str_contains( $cleanup['message'], 'verify failure' ) ) {
					return array(
						'success' => true,
						'message' => 'Offloaded; local delete skipped after verify failure.',
						'files'   => count( $uploaded_keys ),
						'keys'    => $uploaded_keys,
						'status'  => $status,
					);
				}
			}

			return array(
				'success' => true,
				'message' => 'Offloaded successfully.',
				'files'   => count( $uploaded_keys ),
				'keys'    => $uploaded_keys,
				'status'  => $status,
			);
		}

		$message = $failures !== array()
			? implode( '; ', array_slice( $failures, 0, 3 ) )
			: 'Offload incomplete.';

		if ( $status === AttachmentOffloader::STATUS_PARTIAL ) {
			return array(
				'success' => false,
				'message' => $message,
				'files'   => count( $uploaded_keys ),
				'keys'    => $uploaded_keys,
				'status'  => $status,
			);
		}

		throw new \RuntimeException( $message );
	}

	private function resolve_profile(): ?StorageProfile {
		$profile = $this->profiles->find_default_upload_target();
		if ( $profile !== null ) {
			return $profile;
		}
		return ( new LegacyProfileMigrator( $this->settings, $this->profiles ) )->ensure_legacy_profile();
	}

	/**
	 * @param list<array<string, mixed>> $rows Object rows.
	 */
	private function sync_attachment_meta( int $attachment_id, array $rows, string $status ): void {
		update_post_meta( $attachment_id, '_s3ms_status', $status );

		$original = AttachmentSyncDeriver::original_key( $rows );
		if ( $original !== '' ) {
			update_post_meta( $attachment_id, '_s3ms_original_key', $original );
		}

		if ( $status === AttachmentOffloader::STATUS_OFFLOADED ) {
			$now = gmdate( 'c' );
			update_post_meta( $attachment_id, '_s3ms_offloaded_at', $now );
			update_post_meta( $attachment_id, '_s3ms_verified_at', $now );
			delete_post_meta( $attachment_id, '_s3ms_last_error' );
		} else {
			$error = AttachmentSyncDeriver::last_error( $rows );
			if ( $error !== '' ) {
				update_post_meta( $attachment_id, '_s3ms_last_error', $error );
			}
		}
	}

	private function safe_error_message( \Throwable $e ): string {
		$msg = $e->getMessage();
		$msg = preg_replace( '/AKIA[0-9A-Z]{16}/', '[ACCESS_KEY]', $msg ) ?? $msg;
		$msg = preg_replace( '/(?i)(secret|password|token)=\\S+/', '$1=[REDACTED]', $msg ) ?? $msg;
		if ( strlen( $msg ) > 300 ) {
			$msg = substr( $msg, 0, 297 ) . '...';
		}
		return $msg;
	}
}
