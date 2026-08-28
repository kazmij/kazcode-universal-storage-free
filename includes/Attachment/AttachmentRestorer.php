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
use Kazcode\WpStorage\Infrastructure\AttachmentLock;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
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

	public function __construct(Settings $settings, S3Storage $storage) {
		$this->settings = $settings;
		$this->storage  = $storage;
		$this->files    = new AttachmentFileResolver($storage->keys());
		$this->lock     = new AttachmentLock();
		$this->objects  = new ObjectRepository();
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

		// Prefer lock; if busy, still attempt delete to avoid permanent orphans.
		$locked = $this->lock->acquire($attachment_id, 'delete');
		try {
			$this->storage->delete_relatives($relatives);
			// Only once the remote objects are actually gone: drop this attachment's
			// s3ms_objects rows too. wp_posts has no FK/cascade into that table, so
			// skipping this leaves permanently orphaned "present" rows behind — which
			// both poison Health/stats with objects that no longer exist and, for the
			// default profile, keep count_by_profile() > 0 forever, permanently
			// blocking LegacyProfileMigrator::sync_default_profile_from_settings()
			// from ever syncing a later bucket/region/endpoint change again.
			$this->objects->delete_by_attachment($attachment_id);
		} catch (\Throwable $e) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log('KAZCODE Universal Storage: failed deleting remote objects for attachment ' . $attachment_id);
			}
		} finally {
			if ($locked) {
				$this->lock->release($attachment_id);
			}
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

		if (!$this->lock->acquire($attachment_id, 'restore')) {
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
			foreach ($relatives as $relative) {
				$absolute = PathGuard::absolute_under_uploads($relative);
				if ($absolute === null) {
					++$missing;
					continue;
				}
				if (is_file($absolute)) {
					continue;
				}
				$head = $this->storage->head_relative($relative);
				if (empty($head['exists'])) {
					++$missing;
					continue;
				}
				$this->storage->download_relative($relative, $absolute);
				++$count;
			}

			if ($count === 0 && $missing > 0) {
				return array(
					'success' => false,
					'message' => 'Nothing restored; remote objects missing or paths invalid.',
					'files'   => 0,
				);
			}

			$this->mark_attachment_local( $attachment_id );

			return array(
				'success' => true,
				'message' => 'Restore completed.',
				'files'   => $count,
			);
		} catch (\Throwable $e) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		} finally {
			$this->lock->release($attachment_id);
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
