<?php
/**
 * Restores local files from S3 and syncs deletes.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Attachment;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\RemoteObservation;
use Kazcode\WpStorage\Infrastructure\AttachmentLeaseHandle;
use Kazcode\WpStorage\Infrastructure\AttachmentLock;
use Kazcode\WpStorage\Infrastructure\LeaseLostException;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Services\AuditLog;
use Kazcode\WpStorage\Services\ProfileAwareObjectOperations;
use Kazcode\WpStorage\Services\RemoteDeleteSafetyGuard;
use Kazcode\WpStorage\Storage\PathGuard;
use Kazcode\WpStorage\Storage\S3Storage;

/**
 * Restore local tree from S3; delete remote objects on attachment delete.
 */
final class AttachmentRestorer {

	private Settings $settings;
	private S3Storage $storage;
	private AttachmentFileResolver $files;
	private AttachmentLock $lock;
	private ObjectRepository $objects;
	private RemoteDeleteSafetyGuard $delete_guard;
	private AuditLog $audit;
	private ProfileAwareObjectOperations $profile_ops;

	public function __construct(
		Settings $settings,
		S3Storage $storage,
		?RemoteDeleteSafetyGuard $delete_guard = null,
		?AuditLog $audit = null,
		?ProfileAwareObjectOperations $profile_ops = null
	) {
		$this->settings     = $settings;
		$this->storage      = $storage;
		$this->files        = new AttachmentFileResolver($storage->keys());
		$this->lock         = new AttachmentLock();
		$this->objects      = new ObjectRepository();
		$this->delete_guard = $delete_guard ?? new RemoteDeleteSafetyGuard(null, null, $this->files);
		$this->audit        = $audit ?? new AuditLog();
		$this->profile_ops  = $profile_ops ?? new ProfileAwareObjectOperations( legacy: $storage, settings: $settings );
	}

	/**
	 * Hook attachment deletion.
	 */
	public function register_delete_hooks(): void {
		add_action('delete_attachment', array($this, 'on_delete_attachment'), 5, 1);
	}

	/**
	 * When an attachment is permanently deleted, remove S3 objects if configured.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function on_delete_attachment(int $attachment_id): void {
		if (!$this->settings->is_enabled() || !$this->settings->should_delete_remote()) {
			return;
		}
		if (!$this->settings->is_aws_configured()) {
			return;
		}

		$status = (string) get_post_meta($attachment_id, '_s3ms_status', true);
		$key    = (string) get_post_meta($attachment_id, '_s3ms_original_key', true);
		if ($status === '' && $key === '') {
			return;
		}

		$relatives = $this->files->relative_paths($attachment_id);
		if ($relatives === array()) {
			return;
		}

		$lease = $this->lock->acquire_lease($attachment_id, 'delete');
		if (!$lease instanceof AttachmentLeaseHandle) {
			$this->audit->record(
				'remote_delete_skipped',
				array(
					'attachment_id' => $attachment_id,
					'status'        => RemoteDeleteSafetyGuard::UNKNOWN,
					'reason'        => 'attachment_lock_busy',
				)
			);
			if (defined('WP_DEBUG') && WP_DEBUG) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log('KAZCODE Universal Storage: skipped remote delete for locked attachment ' . $attachment_id);
			}
			return;
		}

		try {
			$decision = $this->delete_guard->evaluate($attachment_id);
			if ((string) ($decision['status'] ?? '') !== RemoteDeleteSafetyGuard::SAFE_TO_DELETE) {
				$this->audit->record(
					'remote_delete_skipped',
					array(
						'attachment_id' => $attachment_id,
						'status'        => (string) ($decision['status'] ?? RemoteDeleteSafetyGuard::UNKNOWN),
						'reason'        => (string) ($decision['reason'] ?? 'unknown'),
					)
				);
				return;
			}

			$locations = $decision['locations'] ?? array();
			if (!is_array($locations) || $locations === array()) {
				$this->audit->record(
					'remote_delete_skipped',
					array(
						'attachment_id' => $attachment_id,
						'status'        => RemoteDeleteSafetyGuard::UNKNOWN,
						'reason'        => 'no_guard_locations',
					)
				);
				return;
			}

			$this->renew_or_abort($lease, 'remote delete');
			$this->profile_ops->delete_locations($locations);
			// Only once the remote objects are actually gone: drop this attachment's
			// s3ms_objects rows too. wp_posts has no FK/cascade into that table, so
			// skipping this leaves permanently orphaned "present" rows behind — which
			// both poison Health/stats with objects that no longer exist and, for the
			// default profile, keep count_by_profile() > 0 forever, permanently
			// blocking LegacyProfileMigrator::sync_default_profile_from_settings()
			// from ever syncing a later bucket/region/endpoint change again.
			$this->renew_or_abort($lease, 'remote delete inventory cleanup');
			$this->objects->delete_by_attachment($attachment_id);
		} catch (LeaseLostException $e) {
			$this->audit->record(
				'remote_delete_skipped',
				array(
					'attachment_id' => $attachment_id,
					'status'        => RemoteDeleteSafetyGuard::UNKNOWN,
					'reason'        => 'lease_lost',
				)
			);
		} catch (\Throwable $e) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log('KAZCODE Universal Storage: failed deleting remote objects for attachment ' . $attachment_id);
			}
		} finally {
			$this->lock->release_lease($lease);
		}
	}

	/**
	 * Restore all known files for an attachment from S3 to local uploads.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{success:bool,message:string,files?:int}
	 */
	public function restore(int $attachment_id): array {
		if (!$this->settings->is_aws_configured()) {
			return array(
				'success' => false,
				'message' => 'AWS is not configured.',
			);
		}

		$lease = $this->lock->acquire_lease($attachment_id, 'restore');
		if (!$lease instanceof AttachmentLeaseHandle) {
			return array(
				'success' => false,
				'message' => 'Attachment is locked by another operation.',
			);
		}

		try {
			$relatives = $this->files->relative_paths($attachment_id);
			if ($relatives === array()) {
				throw new \RuntimeException('No file metadata to restore.');
			}

			$count   = 0;
			$missing = 0;
			$unknown = 0;
			foreach ($relatives as $relative) {
				$absolute = PathGuard::absolute_under_uploads($relative);
				if ($absolute === null) {
					++$missing;
					continue;
				}
				if (is_file($absolute)) {
					continue;
				}
				$head = $this->profile_ops->head_attachment_relative($attachment_id, $relative);
				if (empty($head['exists'])) {
					$observation = RemoteObservation::from_head_result( $head );
					if ( $observation->is_confirmed_missing() ) {
						++$missing;
					} else {
						++$unknown;
					}
					continue;
				}
				$download = $this->profile_ops->download_attachment_relative_to_local($attachment_id, $relative, $absolute);
				if (empty($download['success'])) {
					++$unknown;
					continue;
				}
				++$count;
			}

			if ($unknown > 0 && $count === 0 && $missing === 0) {
				return array(
					'success' => false,
					'message' => 'Nothing restored; remote objects could not be verified.',
					'files'   => 0,
					'status'  => 'unknown',
				);
			}

			if ($unknown > 0) {
				return array(
					'success' => false,
					'message' => 'Restore incomplete; some remote objects could not be verified.',
					'files'   => $count,
					'status'  => 'partial',
				);
			}

			if ($count === 0 && $missing > 0) {
				return array(
					'success' => false,
					'message' => 'Nothing restored; remote objects missing or paths invalid.',
					'files'   => 0,
				);
			}

			if ($missing > 0) {
				return array(
					'success' => false,
					'message' => 'Restore incomplete; some remote objects are missing or paths invalid.',
					'files'   => $count,
					'status'  => 'partial',
				);
			}

			$this->renew_or_abort($lease, 'restore finalization');
			$this->mark_attachment_local( $attachment_id );

			return array(
				'success' => true,
				'message' => 'Restore completed.',
				'files'   => $count,
			);
		} catch (LeaseLostException $e) {
			return array(
				'success' => false,
				'message' => 'Operation ownership lost; retry required.',
				'files'   => 0,
				'status'  => 'unknown',
			);
		} catch (\Throwable $e) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		} finally {
			$this->lock->release_lease($lease);
		}
	}

	private function renew_or_abort( AttachmentLeaseHandle $lease, string $stage ): void {
		unset( $stage );
		if ( ! $this->lock->renew( $lease ) ) {
			throw new LeaseLostException( 'Attachment operation ownership lost before a guarded commit.' );
		}
	}

	/**
	 * Clear offload meta and object inventory so URLs serve locally again.
	 */
	private function mark_attachment_local( int $attachment_id ): void {
		foreach ( array(
			'_s3ms_status',
			'_s3ms_original_key',
			'_s3ms_offloaded_at',
			'_s3ms_verified_at',
			'_s3ms_last_error',
		) as $key ) {
			delete_post_meta( $attachment_id, $key );
		}
		$this->objects->delete_by_attachment( $attachment_id );
	}
}
