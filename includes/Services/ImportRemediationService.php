<?php
/**
 * Explicit, non-destructive upload remediation for import evidence.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Attachment\AttachmentFileResolver;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\ManifestBuilder;
use Kazcode\WpStorage\Domain\AttachmentSyncDeriver;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Infrastructure\AttachmentLock;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Storage\ObjectKeyService;
use Kazcode\WpStorage\Storage\ProfileStorageGateway;

/** Remediates one explicitly selected ledger row; it never schedules cleanup. */
final class ImportRemediationService {

	/** @param callable(StorageProfile):ProfileStorageGateway|null $gateway_factory */
	public function __construct(
		private Settings $settings,
		private ?AttachmentFileResolver $files = null,
		private ?ManifestBuilder $manifests = null,
		private ?ObjectRepository $objects = null,
		private ?AttachmentLock $locks = null,
		private $gateway_factory = null
	) {
		$this->files     = $files ?? new AttachmentFileResolver();
		$this->manifests = $manifests ?? new ManifestBuilder( $this->files );
		$this->objects   = $objects ?? new ObjectRepository();
		$this->locks     = $locks ?? new AttachmentLock();
	}

	/** @param array<string,mixed> $item @return array{classification:string,detail?:string} */
	public function remediate_item( array $item, StorageProfile $profile ): array {
		$classification = (string) ( $item['classification'] ?? '' );
		$attachment_id  = (int) ( $item['attachment_id'] ?? 0 );
		$relative       = (string) ( $item['local_relative_path'] ?? '' );
		$canonical      = (string) ( $item['canonical_object_key'] ?? '' );
		if ( ! in_array( $classification, array( 'confirmed_missing', 'size_conflict' ), true ) || $attachment_id <= 0 || $relative === '' || $profile->id === null || $canonical !== ObjectKeyService::key_for( $profile->prefix, $relative ) ) {
			return array( 'classification' => 'conflict', 'detail' => 'Ledger item is not an eligible canonical remediation target.' );
		}

		$lease = $this->locks->acquire_lease( $attachment_id, 'import_remediation' );
		if ( $lease === null ) {
			return array( 'classification' => 'transient_error', 'detail' => 'Attachment is locked by another operation.' );
		}
		try {
			$manifest_item = null;
			foreach ( $this->manifests->build( $attachment_id )->items() as $candidate ) {
				if ( (string) ( $candidate['relative'] ?? '' ) === $relative ) {
					$manifest_item = $candidate;
					break;
				}
			}
			if ( $manifest_item === null ) {
				return array( 'classification' => 'conflict', 'detail' => 'Current attachment manifest no longer contains the ledger path.' );
			}
			$local = $this->files->existing_local_files_for_manifest_paths( array( $relative ) )[ $relative ] ?? '';
			if ( $local === '' ) {
				return array( 'classification' => 'conflict', 'detail' => 'Eligible local file is no longer present.' );
			}
			$reservation = $this->objects->insert_owned_or_conflict( array( 'attachment_id' => $attachment_id, 'storage_profile_id' => (int) $profile->id, 'local_relative_path' => $relative, 'object_key' => $canonical, 'variant_type' => (string) ( $manifest_item['variant_type'] ?? 'size' ), 'local_status' => 'present', 'remote_status' => ObjectRemoteStatus::UPLOADING, 'attempt_count' => 1 ) );
			if ( empty( $reservation['ok'] ) ) {
				return array( 'classification' => 'conflict', 'detail' => 'Canonical object key is owned by another attachment.' );
			}
			$this->gateway( $profile )->put_object_from_file( $canonical, $local, $this->content_type( $local ), (string) $this->settings->get( 'cache_control', 'public, max-age=31536000' ) );
			$head = $this->gateway( $profile )->head_key( $canonical );
			if ( empty( $head['exists'] ) || (int) ( $head['content_length'] ?? -1 ) !== (int) filesize( $local ) ) {
				return array( 'classification' => 'transient_error', 'detail' => 'Uploaded object could not be verified by size.' );
			}
			$present = $this->objects->insert_owned_or_conflict( array( 'attachment_id' => $attachment_id, 'storage_profile_id' => (int) $profile->id, 'local_relative_path' => $relative, 'object_key' => $canonical, 'variant_type' => (string) ( $manifest_item['variant_type'] ?? 'size' ), 'size_bytes' => (int) filesize( $local ), 'local_status' => 'present', 'remote_status' => ObjectRemoteStatus::PRESENT, 'offloaded_at' => gmdate( 'Y-m-d H:i:s' ), 'verified_at' => gmdate( 'Y-m-d H:i:s' ) ) );
			if ( empty( $present['ok'] ) ) {
				return array( 'classification' => 'conflict', 'detail' => 'Canonical inventory ownership changed during remediation.' );
			}
			$this->sync_attachment_meta( $attachment_id );
			return array( 'classification' => 'uploaded' );
		} catch ( \Throwable $error ) {
			return array( 'classification' => 'transient_error', 'detail' => 'Remediation upload failed.' );
		} finally {
			$this->locks->release_lease( $lease );
		}
	}

	private function gateway( StorageProfile $profile ): ProfileStorageGateway {
		if ( is_callable( $this->gateway_factory ) ) {
			$gateway = ( $this->gateway_factory )( $profile );
			if ( $gateway instanceof ProfileStorageGateway ) return $gateway;
		}
		return new ProfileStorageGateway( $profile, $this->settings );
	}

	private function content_type( string $path ): string {
		$type = function_exists( 'wp_check_filetype' ) ? (string) ( wp_check_filetype( basename( $path ) )['type'] ?? '' ) : '';
		return $type !== '' ? $type : 'application/octet-stream';
	}

	private function sync_attachment_meta( int $attachment_id ): void {
		$rows   = $this->objects->find_by_attachment( $attachment_id );
		$status = AttachmentSyncDeriver::derive_status( $rows );
		update_post_meta( $attachment_id, '_s3ms_status', $status );
		$original = AttachmentSyncDeriver::original_key( $rows );
		if ( $original !== '' ) {
			update_post_meta( $attachment_id, '_s3ms_original_key', $original );
		}
	}
}
