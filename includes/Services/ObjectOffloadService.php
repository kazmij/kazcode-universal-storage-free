<?php
/**
 * Object-level offload orchestration (v2).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\SecretRedactor;
use Kazcode\WpStorage\Attachment\AttachmentFileResolver;
use Kazcode\WpStorage\Attachment\AttachmentOffloader;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\AttachmentSyncDeriver;
use Kazcode\WpStorage\Domain\ManifestBuilder;
use Kazcode\WpStorage\Domain\LocalStoragePolicy;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Infrastructure\AttachmentLeaseHandle;
use Kazcode\WpStorage\Infrastructure\AttachmentLock;
use Kazcode\WpStorage\Infrastructure\LeaseLostException;
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

	private ?AttachmentLeaseHandle $active_lease = null;
	private ?AttachmentLock $active_lock = null;

	public function __construct(
		private Settings $settings,
		private S3Storage $storage,
		private ?AttachmentFileResolver $files = null,
		private ?ManifestBuilder $manifest_builder = null,
		private ?ObjectRepository $objects = null,
		private ?WpdbStorageProfileRepository $profiles = null,
		private ?object $transfer_service = null,
	) {
		$this->files            = $files ?? new AttachmentFileResolver( $storage->keys() );
		$this->manifest_builder = $manifest_builder ?? new ManifestBuilder( $this->files );
		$this->objects          = $objects ?? new ObjectRepository();
		$this->profiles         = $profiles ?? new WpdbStorageProfileRepository();
		$this->transfer_service = $transfer_service ?? $this->build_transfer_service();
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
	public function offload(
		int $attachment_id,
		?bool $delete_local = null,
		?array $metadata_override = null,
		?AttachmentLeaseHandle $lease = null,
		?AttachmentLock $lock = null
	): array {
		$this->active_lease = $lease;
		$this->active_lock  = $lock;
		try {
			return $this->offload_fenced( $attachment_id, $delete_local, $metadata_override );
		} finally {
			$this->active_lease = null;
			$this->active_lock  = null;
		}
	}

	/**
	 * @param array<string, mixed>|null $metadata_override Metadata from WP filter.
	 * @return array{success:bool,message:string,files?:int,keys?:list<string>,status?:string}
	 */
	private function offload_fenced( int $attachment_id, ?bool $delete_local, ?array $metadata_override ): array {
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

		$manifest      = $this->manifest_builder->build( $attachment_id, $metadata_override );
		$uploaded_keys = array();
		$failures      = array();
		/** @var array<string, string> $intended_bindings relative => object_key for this profile run */
		$intended_bindings = array();

		$existing_rows = $this->objects->find_by_attachment( $attachment_id );

		foreach ( $manifest->items() as $item ) {
			$relative = $item['relative'];
			$object_key = ObjectKeyService::key_for( $profile->prefix, $relative );
			// Every manifest path must be covered by this profile+key — including variants
			// whose local file is already gone (remote-only). Skipping them from bindings
			// would let a partial local upload look fully covered and delete remaining locals.
			$intended_bindings[ $relative ] = $object_key;
			if ( ! isset( $local_files[ $relative ] ) ) {
				$this->revive_required_stale_row( $attachment_id, (int) $profile->id, $relative, $object_key, $item['variant_type'], $existing_rows );
				continue;
			}

			$absolute = $local_files[ $relative ];

			try {
				$size = (int) filesize( $absolute );
				$head = $this->storage->head_key( $object_key );
				if ( ! $this->head_matches_expected_size( $head, $size ) ) {
					if ( ! $this->write_owned_row(
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
					) ) {
						$failures[] = $relative . ': object key is owned by another attachment.';
						continue;
					}

					$result = $this->transfer_service->upload_local(
						$attachment_id,
						$profile->id,
						$relative,
						$object_key,
						$this->storage->content_type_for_local_file( $absolute, $relative ),
						(string) $this->settings->get( 'cache_control', 'public, max-age=31536000' )
					);
					if ( empty( $result['verified_complete'] ) ) {
						$msg = (string) ( $result['message'] ?? 'Transfer remains incomplete.' );
						$this->write_owned_row(
							array(
								'attachment_id'       => $attachment_id,
								'storage_profile_id'  => $profile->id,
								'local_relative_path' => $relative,
								'object_key'          => $object_key,
								'variant_type'        => $item['variant_type'],
								'remote_status'       => ObjectRemoteStatus::UPLOADING,
								'last_error_message'  => $msg,
							)
						);
						$failures[] = $relative . ': ' . $msg;
						continue;
					}
				}

				$now = gmdate( 'Y-m-d H:i:s' );
				if ( ! $this->write_owned_row(
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
				) ) {
					$failures[] = $relative . ': object key is owned by another attachment.';
					continue;
				}
				$uploaded_keys[] = $object_key;
			} catch ( LeaseLostException $e ) {
				throw $e;
			} catch ( \Throwable $e ) {
				$msg = $this->safe_error_message( $e );
				$this->write_owned_row(
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
		// Owner-preserving refusal / partial writes must not look fully offloaded via older
		// profile rows. Require PRESENT coverage for this run's selected profile+key bindings,
		// and never promote/cleanup when any variant failed.
		$coverage_ok = $this->inventory_covers_intended_bindings( $rows, (int) $profile->id, $intended_bindings );
		if ( $failures !== array() || ! $coverage_ok ) {
			if ( $status === AttachmentOffloader::STATUS_OFFLOADED || $status === '' ) {
				$status = $uploaded_keys !== array()
					? AttachmentOffloader::STATUS_PARTIAL
					: AttachmentOffloader::STATUS_FAILED;
			}
		}
		$this->sync_attachment_meta( $attachment_id, $rows, $status );

		if ( $status === AttachmentOffloader::STATUS_OFFLOADED && $failures === array() && $coverage_ok ) {
			// Metadata passed by a generate/update filter is not persisted yet, so the
			// current-manifest revalidation must run later against saved metadata.
			$defer = $metadata_override !== null
				|| (bool) apply_filters( 's3ms_defer_local_cleanup', false );
			if ( $defer ) {
				if ( $delete_local === true || ( $delete_local === null && $this->settings->local_storage_policy() !== LocalStoragePolicy::KEEP_ALL ) ) {
					Plugin::instance()->queue()->enqueue(
						QueueJobType::CLEANUP_LOCAL_FILES,
						array(
							'attachment_id' => $attachment_id,
							'delete_local'  => $delete_local,
							'profile_id'    => (int) $profile->id,
						)
					);
				}
			} else {
				$cleanup = ( new CleanupLocalFiles( $this->settings, $this->storage ) )->maybe_cleanup(
					$attachment_id,
					$local_files,
					$manifest->items(),
					$delete_local,
					(int) $profile->id
				);
				if ( ! empty( $cleanup['blocked_reason'] ) ) {
					return array(
						'success' => true,
						'message' => 'Offloaded; local delete skipped (' . $cleanup['blocked_reason'] . ').',
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

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by AttachmentOffloader, redacted/truncated (safe_error_message()), and the one place it reaches HTML (AttachmentDetailsPanel.php) already wraps it in esc_html().
		throw new \RuntimeException( $message );
	}

	private function resolve_profile(): ?StorageProfile {
		$profile = $this->profiles->find_default_upload_target();
		if ( $profile !== null ) {
			return $profile;
		}
		return ( new LegacyProfileMigrator( $this->settings, $this->profiles ) )->ensure_legacy_profile();
	}

	private function build_transfer_service(): ResumableTransferService {
		return Plugin::instance()->resumable_transfers();
	}

	/**
	 * Persist an inventory row without stealing another attachment's profile+key.
	 *
	 * @param array<string, mixed> $data Row.
	 */
	private function write_owned_row( array $data ): bool {
		$this->fence_write( 'object inventory write' );
		$result = $this->objects->insert_owned_or_conflict( $data );
		return ! empty( $result['ok'] );
	}

	/**
	 * A current manifest path can have only a STALE row when WordPress persisted
	 * metadata in two steps: wp_save_image() saves the edited sizes first (the
	 * reconciler then marks the pre-edit files stale) and only afterwards records
	 * them as `_wp_attachment_backup_sizes`, which makes them required again.
	 * With no local copy to re-upload, revive the row only when an exact-key HEAD
	 * still proves the recorded object at its recorded size; otherwise coverage
	 * stays incomplete and nothing is cleaned up.
	 *
	 * @param list<array<string, mixed>> $rows Inventory rows read before this run.
	 */
	private function revive_required_stale_row( int $attachment_id, int $profile_id, string $relative, string $object_key, string $variant_type, array $rows ): void {
		$stale = null;
		foreach ( $rows as $row ) {
			if ( (int) ( $row['storage_profile_id'] ?? 0 ) !== $profile_id
				|| (string) ( $row['local_relative_path'] ?? '' ) !== $relative
				|| (string) ( $row['object_key'] ?? '' ) !== $object_key ) {
				continue;
			}
			$status = (string) ( $row['remote_status'] ?? '' );
			if ( $status === ObjectRemoteStatus::PRESENT ) {
				return;
			}
			if ( $status === ObjectRemoteStatus::STALE ) {
				$stale = $row;
			}
		}
		$size = $stale !== null ? (int) ( $stale['size_bytes'] ?? 0 ) : 0;
		if ( $stale === null || $size <= 0 ) {
			return;
		}
		if ( ! $this->head_matches_expected_size( $this->storage->head_key( $object_key ), $size ) ) {
			return;
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$this->write_owned_row(
			array(
				'attachment_id'       => $attachment_id,
				'storage_profile_id'  => $profile_id,
				'local_relative_path' => $relative,
				'object_key'          => $object_key,
				'variant_type'        => $variant_type,
				'size_bytes'          => $size,
				'remote_status'       => ObjectRemoteStatus::PRESENT,
				'verified_at'         => $now,
				'last_error_code'     => null,
				'last_error_message'  => null,
			)
		);
	}

	/**
	 * True when every intended relative has a PRESENT row on the selected profile+key.
	 *
	 * @param list<array<string, mixed>> $rows               Inventory rows.
	 * @param array<string, string>      $intended_bindings  relative => object_key.
	 */
	private function inventory_covers_intended_bindings( array $rows, int $profile_id, array $intended_bindings ): bool {
		if ( $intended_bindings === array() ) {
			return false;
		}
		$present = array();
		foreach ( $rows as $row ) {
			if ( (int) ( $row['storage_profile_id'] ?? 0 ) !== $profile_id ) {
				continue;
			}
			if ( (string) ( $row['remote_status'] ?? '' ) !== ObjectRemoteStatus::PRESENT ) {
				continue;
			}
			$relative = (string) ( $row['local_relative_path'] ?? '' );
			$key      = (string) ( $row['object_key'] ?? '' );
			if ( $relative === '' || $key === '' ) {
				continue;
			}
			if ( isset( $intended_bindings[ $relative ] ) && $intended_bindings[ $relative ] === $key ) {
				$present[ $relative ] = true;
			}
		}
		foreach ( $intended_bindings as $relative => $_key ) {
			if ( ! isset( $present[ $relative ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Renew the caller-held attachment lease before any same-owner inventory/meta write.
	 */
	private function fence_write( string $stage ): void {
		if ( ! $this->active_lease instanceof AttachmentLeaseHandle || ! $this->active_lock instanceof AttachmentLock ) {
			return;
		}
		if ( ! $this->active_lock->renew( $this->active_lease ) ) {
			throw new LeaseLostException( 'Attachment operation ownership lost before ' . $stage . '.' );
		}
	}

	/**
	 * @param array<string, mixed> $head HEAD result from storage.
	 */
	private function head_matches_expected_size( array $head, int $expected_size ): bool {
		return ! empty( $head['exists'] )
			&& isset( $head['content_length'] )
			&& (int) $head['content_length'] === $expected_size;
	}

	/**
	 * @param list<array<string, mixed>> $rows Object rows.
	 */
	private function sync_attachment_meta( int $attachment_id, array $rows, string $status ): void {
		$this->fence_write( 'offload metadata sync' );
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
		return SecretRedactor::redact( $e, 300 );
	}
}
