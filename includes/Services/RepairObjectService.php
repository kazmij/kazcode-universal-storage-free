<?php
/**
 * Re-upload a single missing/failed object when local file exists (v2 Phase 6).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\SecretRedactor;
use Kazcode\WpStorage\Domain\AttachmentSyncDeriver;
use Kazcode\WpStorage\Domain\ObjectHealthState;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Infrastructure\AttachmentLeaseHandle;
use Kazcode\WpStorage\Infrastructure\AttachmentLock;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Storage\PathGuard;
use Kazcode\WpStorage\Storage\S3Storage;

/**
 * Safe repair: Put only when local binary is present.
 */
final class RepairObjectService {

	public function __construct(
		private S3Storage $storage,
		private ?ObjectRepository $objects = null,
		private ?AttachmentLock $lock = null,
		private ?ProfileAwareObjectOperations $locations = null,
	) {
		$this->objects   = $objects ?? new ObjectRepository();
		$this->lock      = $lock ?? new AttachmentLock();
		$this->locations = $locations ?? new ProfileAwareObjectOperations( null, null, $storage );
	}

	/**
	 * @return array{success:bool,message:string,dry_run?:bool,health?:string}
	 */
	public function repair( int $object_id, bool $dry_run = false, ?AttachmentLeaseHandle $lease = null ): array {
		$row = $this->objects->find_by_id( $object_id );
		if ( $row === null ) {
			return array(
				'success' => false,
				'message' => 'Object row not found.',
			);
		}

		$relative = (string) ( $row['local_relative_path'] ?? '' );
		$absolute = $this->absolute_local( $relative );
		if ( $absolute === null ) {
			return array(
				'success' => false,
				'message' => 'Local file missing; cannot re-upload.',
				'health'  => ObjectHealthState::LOCAL_MISSING,
			);
		}

		$health = ObjectHealthState::classify_row( $row, true );
		if ( ! ObjectHealthState::is_repairable( $health, true ) ) {
			return array(
				'success' => false,
				'message' => 'Object is not repairable in current state: ' . $health,
				'health'  => $health,
			);
		}

		if ( $dry_run ) {
			return array(
				'success' => true,
				'message' => 'Dry run: would re-upload ' . $relative,
				'dry_run' => true,
				'health'  => $health,
			);
		}

		$key = (string) ( $row['object_key'] ?? '' );
		if ( $key === '' ) {
			return array(
				'success' => false,
				'message' => 'Object key missing on row.',
			);
		}

		$attachment_id = (int) ( $row['attachment_id'] ?? 0 );
		if ( $attachment_id <= 0 ) {
			return array(
				'success' => false,
				'message' => 'Object row is missing attachment_id.',
			);
		}

		$owns_lease = false;
		if ( $lease instanceof AttachmentLeaseHandle ) {
			if ( $lease->attachment_id !== $attachment_id ) {
				return array(
					'success' => false,
					'message' => 'Attachment lease does not match object owner.',
				);
			}
		} else {
			$lease = $this->lock->acquire_lease( $attachment_id, 'repair' );
			if ( ! $lease instanceof AttachmentLeaseHandle ) {
				return array(
					'success' => false,
					'message' => 'Attachment is locked by another operation.',
				);
			}
			$owns_lease = true;
		}

		try {
			if ( ! $this->lock->renew( $lease ) ) {
				return array(
					'success' => false,
					'message' => 'Attachment lease lost before repair write.',
				);
			}

			// Re-read under the lease: a concurrent restore may have deleted inventory
			// between the pre-lock snapshot and acquisition.
			$row = $this->objects->find_by_id( $object_id );
			if ( $row === null ) {
				return array(
					'success' => false,
					'message' => 'Object row not found after acquiring attachment lease.',
				);
			}
			if ( (int) ( $row['attachment_id'] ?? 0 ) !== $attachment_id ) {
				return array(
					'success' => false,
					'message' => 'Object attachment ownership changed under lease.',
				);
			}
			$relative = (string) ( $row['local_relative_path'] ?? '' );
			$absolute = $this->absolute_local( $relative );
			if ( $absolute === null ) {
				return array(
					'success' => false,
					'message' => 'Local file missing; cannot re-upload.',
					'health'  => ObjectHealthState::LOCAL_MISSING,
				);
			}
			$health = ObjectHealthState::classify_row( $row, true );
			if ( ! ObjectHealthState::is_repairable( $health, true ) ) {
				return array(
					'success' => false,
					'message' => 'Object is not repairable in current state: ' . $health,
					'health'  => $health,
				);
			}
			$key = (string) ( $row['object_key'] ?? '' );
			if ( $key === '' ) {
				return array(
					'success' => false,
					'message' => 'Object key missing on row.',
				);
			}

			try {
				// Upload and verify through the row's own storage profile (not the
				// site default), and require a size-verified HEAD — existence alone
				// would report a truncated/replaced object as repaired (#28, §6.2).
				$uploaded = $this->locations->upload_file_for_object_row( $row, $absolute );
				if ( empty( $uploaded['success'] ) ) {
					throw new \RuntimeException( empty( $uploaded['head'] ) ? 'Repair target location is unresolved (' . (string) ( $uploaded['location_status'] ?? 'unknown' ) . ').' : 'Size verification failed after repair upload.' );
				}

				if ( ! $this->lock->renew( $lease ) ) {
					return array(
						'success' => false,
						'message' => 'Attachment lease lost after repair upload.',
					);
				}

				$now = gmdate( 'Y-m-d H:i:s' );
				$this->objects->upsert(
					array(
						'attachment_id'       => $attachment_id,
						'storage_profile_id'  => (int) ( $row['storage_profile_id'] ?? 0 ),
						'local_relative_path' => $relative,
						'object_key'          => $key,
						'variant_type'        => (string) ( $row['variant_type'] ?? 'size' ),
						'size_bytes'          => (int) filesize( $absolute ),
						'remote_status'       => ObjectRemoteStatus::PRESENT,
						'verified_at'         => $now,
						'offloaded_at'        => $row['offloaded_at'] ?? $now,
						'last_error_code'     => null,
						'last_error_message'  => null,
					)
				);

				$rows   = $this->objects->find_by_attachment( $attachment_id );
				$status = AttachmentSyncDeriver::derive_status( $rows );
				update_post_meta( $attachment_id, '_s3ms_status', $status );
				if ( $status === \Kazcode\WpStorage\Attachment\AttachmentOffloader::STATUS_OFFLOADED ) {
					delete_post_meta( $attachment_id, '_s3ms_last_error' );
				}

				( new ObjectStatsAggregator( $this->objects ) )->invalidate();

				return array(
					'success' => true,
					'message' => 'Re-uploaded ' . $relative,
					'health'  => ObjectHealthState::HEALTHY,
				);
			} catch ( \Throwable $e ) {
				if ( $this->lock->renew( $lease ) ) {
					$this->objects->upsert(
						array(
							'attachment_id'       => $attachment_id,
							'storage_profile_id'  => (int) ( $row['storage_profile_id'] ?? 0 ),
							'local_relative_path' => $relative,
							'object_key'          => $key,
							'variant_type'        => (string) ( $row['variant_type'] ?? 'size' ),
							'remote_status'       => ObjectRemoteStatus::FAILED,
							'last_error_message'  => SecretRedactor::redact( $e ),
						)
					);
				}
				return array(
					'success' => false,
					'message' => SecretRedactor::redact( $e ),
					'health'  => ObjectHealthState::FAILED_UPLOAD,
				);
			}
		} finally {
			if ( $owns_lease ) {
				$this->lock->release_lease( $lease );
			}
		}
	}

	private function absolute_local( string $relative ): ?string {
		if ( $relative === '' ) {
			return null;
		}
		try {
			$absolute = PathGuard::absolute_under_uploads( $relative );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
		if ( $absolute === null || ! is_file( $absolute ) ) {
			return null;
		}
		return $absolute;
	}
}
