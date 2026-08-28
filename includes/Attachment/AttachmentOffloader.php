<?php
/**
 * Uploads attachment files to S3 and updates offload meta.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Attachment;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Infrastructure\AttachmentLock;
use Kazcode\WpStorage\Services\AttachmentReconciler;
use Kazcode\WpStorage\Services\CleanupLocalFiles;
use Kazcode\WpStorage\Services\ObjectOffloadService;
use Kazcode\WpStorage\Storage\S3Storage;

/**
 * Offload pipeline: upload → verify → mark → optional delete local.
 */
final class AttachmentOffloader {

	public const STATUS_PENDING   = 'pending';
	public const STATUS_UPLOADING = 'uploading';
	public const STATUS_OFFLOADED = 'offloaded';
	public const STATUS_PARTIAL   = 'partial';
	public const STATUS_FAILED    = 'failed';

	private Settings $settings;
	private S3Storage $storage;
	private AttachmentFileResolver $files;
	private AttachmentLock $lock;

	/**
	 * Attachment IDs between creation and generate_metadata completing — see
	 * on_add_attachment() for why the window has to start this early.
	 *
	 * @var array<int, true>
	 */
	private static array $in_generate = array();

	/**
	 * Attachment IDs whose on_generate_metadata() just finished in this request — the
	 * very next on_update_metadata() call for the same ID is WP core's own paired
	 * call for that same upload (same metadata, no intervening event), not a new one.
	 *
	 * @var array<int, true>
	 */
	private static array $just_generated = array();

	public function __construct(Settings $settings, S3Storage $storage) {
		$this->settings = $settings;
		$this->storage  = $storage;
		$this->files    = new AttachmentFileResolver($storage->keys());
		$this->lock     = new AttachmentLock();
	}

	/**
	 * Register upload hooks.
	 */
	public function register(): void {
		add_action('add_attachment', array($this, 'on_add_attachment'));
		add_filter('wp_generate_attachment_metadata', array($this, 'on_generate_metadata'), 20, 2);
		add_filter('wp_update_attachment_metadata', array($this, 'on_update_metadata'), 20, 2);
	}

	/**
	 * WP core's own wp_create_image_subsizes() (called from inside
	 * wp_generate_attachment_metadata(), before any sub-sizes are generated) saves a
	 * checkpoint via wp_update_attachment_metadata() with 'sizes' still empty, so that
	 * a crash mid-resize doesn't lose width/height/file. That checkpoint fires our own
	 * on_update_metadata() with force=true — before on_generate_metadata() has even
	 * started, so its own $in_generate guard is set too late to catch it. Left
	 * unguarded, on_update_metadata() would offload+delete the original from this
	 * checkpoint alone (under a delete-local-after-verify policy), leaving
	 * wp_create_image_subsizes() with no local original to resize from: every
	 * upload ends up with zero sub-sizes and a permanent `failed` status. Setting the
	 * guard here, at attachment creation — strictly before WordPress ever calls
	 * wp_generate_attachment_metadata() for it — closes that window; on_generate_metadata()
	 * still clears it in its `finally` once the real generation pass completes.
	 *
	 * @param mixed $attachment_id Attachment ID.
	 */
	public function on_add_attachment($attachment_id): void {
		self::guard_next_generate((int) $attachment_id);
	}

	/**
	 * Same guard as on_add_attachment(), for callers other than a fresh upload that
	 * are about to feed an attachment back into wp_generate_attachment_metadata() —
	 * currently LocalFileProvider, right after it re-materializes a local file for an
	 * S3-only attachment (regenerate, Image Editor). Without this, regenerating an
	 * S3-only attachment under a delete-local-after-verify policy hits the exact same
	 * wp_create_image_subsizes() checkpoint corruption as a fresh upload — no
	 * `add_attachment` action fires for an *existing* attachment, so on_add_attachment()
	 * alone doesn't cover it.
	 */
	public static function guard_next_generate(int $attachment_id): void {
		if ($attachment_id > 0) {
			self::$in_generate[ $attachment_id ] = true;
		}
	}

	/**
	 * After WordPress generates sizes on upload.
	 *
	 * @param mixed $metadata Metadata.
	 * @param mixed $attachment_id Attachment ID.
	 * @return mixed
	 */
	public function on_generate_metadata($metadata, $attachment_id) {
		$attachment_id = (int) $attachment_id;
		if (!is_array($metadata) || $attachment_id <= 0) {
			return $metadata;
		}
		// maybe_offload(force: false) no-ops when already offloaded (e.g. regenerating
		// an S3-only attachment's thumbnails) — captured before the call so we can tell
		// afterward whether THIS pass actually did the real offload+cleanup work, or
		// whether that's still the paired on_update_metadata() call's job below.
		$was_already_offloaded = (string) get_post_meta($attachment_id, '_s3ms_status', true) === self::STATUS_OFFLOADED;
		self::$in_generate[ $attachment_id ] = true;
		try {
			$this->maybe_offload($attachment_id, false, $metadata);
		} finally {
			unset(self::$in_generate[ $attachment_id ]);
		}
		if (!$was_already_offloaded) {
			// WP core always immediately follows wp_generate_attachment_metadata() with
			// wp_update_attachment_metadata() for the very same upload, same $metadata,
			// same request — not a new event. Without this, on_update_metadata()'s
			// unconditional force=true reoffload re-runs right after this one just
			// succeeded, and — since a delete-local-after-verify policy already removed
			// the local files this pass uploaded — fails with "no local files found",
			// clobbering the correct `offloaded` status with `failed` even though every
			// object genuinely made it to storage. Skip exactly that one paired call.
			//
			// Skipping is conditional on real work having happened here: for a
			// regenerate/Image Editor save on an already-offloaded attachment, this
			// pass no-ops (force: false, nothing to do) and the paired
			// on_update_metadata() call (force: true) is the one that must actually
			// process the newly (re)generated local files — blocking it too would
			// leave them sitting on disk forever, never uploaded.
			self::$just_generated[ $attachment_id ] = true;
		}
		return $metadata;
	}

	/**
	 * After metadata update (e.g. image editor).
	 *
	 * @param mixed $metadata Metadata.
	 * @param mixed $attachment_id Attachment ID.
	 * @return mixed
	 */
	public function on_update_metadata($metadata, $attachment_id) {
		$attachment_id = (int) $attachment_id;
		if (!is_array($metadata) || $attachment_id <= 0) {
			return $metadata;
		}
		if (isset(self::$in_generate[ $attachment_id ])) {
			// wp_create_image_subsizes() doesn't just save one empty-'sizes' checkpoint —
			// on this WP version it saves progressively as each sub-size finishes (sizes
			// with 0, then 1, then 2, ... entries), all still strictly before the real,
			// complete wp_generate_attachment_metadata() filter call. An earlier version
			// of this guard only blocked the empty-sizes shape specifically (to let a
			// genuinely complete Image Editor save through despite a stale guard) — that
			// let these non-empty-but-still-mid-generation saves through too, each one
			// triggering a real offload+cleanup with only a partial file set, deleting
			// the original before later sizes could even be generated from it. The guard
			// is now only ever set when a real wp_generate_attachment_metadata() call is
			// guaranteed to follow (see on_add_attachment() and
			// LocalFileProvider::ensure_local()'s WP_CLI-only guard call) — the Image
			// Editor flow never sets it at all, since it never calls generate_metadata()
			// — so it's safe to block unconditionally here again.
			return $metadata;
		}
		if (isset(self::$just_generated[ $attachment_id ])) {
			// WP core's own paired call right after on_generate_metadata() finished
			// processing this exact upload — see on_generate_metadata() for why
			// reprocessing it here would be both redundant and destructive.
			unset(self::$just_generated[ $attachment_id ]);
			return $metadata;
		}
		$status = (string) get_post_meta($attachment_id, '_s3ms_status', true);
		if ($status === self::STATUS_UPLOADING) {
			// Recover stale uploading left by a crashed request (lock TTL expired).
			if ($this->lock->is_locked($attachment_id)) {
				return $metadata;
			}
		}

		if (ObjectOffloadService::is_enabled()) {
			( new AttachmentReconciler() )->reconcile( $attachment_id, $metadata );
		}

		$this->maybe_offload($attachment_id, true, $metadata);
		return $metadata;
	}

	/**
	 * Offload if plugin is enabled and AWS is configured.
	 *
	 * @param int                       $attachment_id Attachment ID.
	 * @param bool                      $force Force even if already offloaded.
	 * @param array<string, mixed>|null $metadata_override Metadata from filter.
	 * @return array{success:bool,message:string,files?:int}
	 */
	public function maybe_offload(int $attachment_id, bool $force = false, ?array $metadata_override = null): array {
		if (!$this->settings->is_enabled() || !$this->settings->is_aws_configured()) {
			return array(
				'success' => false,
				'message' => 'Plugin disabled or AWS not configured.',
			);
		}

		$status = (string) get_post_meta($attachment_id, '_s3ms_status', true);
		if (!$force && $status === self::STATUS_OFFLOADED) {
			return array(
				'success' => true,
				'message' => 'Already offloaded.',
				'files'   => 0,
			);
		}

		return $this->offload($attachment_id, null, $metadata_override);
	}

	/**
	 * Full offload for one attachment.
	 *
	 * @param int                       $attachment_id Attachment ID.
	 * @param bool|null                 $delete_local Override delete-local setting (null uses setting).
	 * @param array<string, mixed>|null $metadata_override Metadata from generate/update filter.
	 * @return array{success:bool,message:string,files?:int,keys?:list<string>}
	 */
	public function offload(int $attachment_id, ?bool $delete_local = null, ?array $metadata_override = null): array {
		if (!$this->lock->acquire($attachment_id, 'migrate')) {
			return array(
				'success' => false,
				'message' => 'Attachment is locked by another operation.',
			);
		}

		try {
			if (ObjectOffloadService::is_enabled()) {
				update_post_meta($attachment_id, '_s3ms_status', self::STATUS_UPLOADING);
				delete_post_meta($attachment_id, '_s3ms_last_error');

				$service = new ObjectOffloadService($this->settings, $this->storage, $this->files);
				return $service->offload($attachment_id, $delete_local, $metadata_override);
			}

			$uploaded_keys = array();

			update_post_meta($attachment_id, '_s3ms_status', self::STATUS_UPLOADING);
			delete_post_meta($attachment_id, '_s3ms_last_error');

			$local_files = $this->files->existing_local_files($attachment_id, $metadata_override);
			if ($local_files === array()) {
				$paths = $this->files->relative_paths($attachment_id, $metadata_override);
				if ($paths === array()) {
					throw new \RuntimeException('Attachment has no file metadata.');
				}
				throw new \RuntimeException('No local files found to upload. Restore from S3 first if files were deleted.');
			}

			$original_key = '';

			foreach ($local_files as $relative => $absolute) {
				$key             = $this->storage->upload_file($absolute, $relative);
				$uploaded_keys[] = $key;
				$attached        = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
				try {
					if ($this->storage->keys()->normalize_relative($attached) === $relative) {
						$original_key = $key;
					}
				} catch (\InvalidArgumentException $e) {
					// Ignore invalid attached file for original-key detection.
				}
			}

			if ($original_key === '' && $uploaded_keys !== array()) {
				$original_key = $uploaded_keys[0];
			}

			$missing = array();
			foreach (array_keys($local_files) as $relative) {
				$head = $this->storage->head_relative($relative);
				if (empty($head['exists'])) {
					$missing[] = $relative;
				}
			}

			if ($missing !== array()) {
				throw new \RuntimeException('Verification failed for: ' . implode(', ', array_slice($missing, 0, 5)));
			}

			update_post_meta($attachment_id, '_s3ms_status', self::STATUS_OFFLOADED);
			update_post_meta($attachment_id, '_s3ms_original_key', $original_key);
			update_post_meta($attachment_id, '_s3ms_offloaded_at', gmdate('c'));
			update_post_meta($attachment_id, '_s3ms_verified_at', gmdate('c'));
			delete_post_meta($attachment_id, '_s3ms_last_error');

			$manifest_items = array();
			foreach ( array_keys( $local_files ) as $relative ) {
				$attached        = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
				$variant         = 'size';
				try {
					if ( $this->storage->keys()->normalize_relative( $attached ) === $relative ) {
						$variant = 'original';
					}
				} catch ( \InvalidArgumentException $e ) {
					// Ignore invalid attached file.
				}
				$manifest_items[] = array(
					'relative'     => $relative,
					'variant_type' => $variant,
				);
			}

			$cleanup = ( new CleanupLocalFiles( $this->settings, $this->storage ) )->maybe_cleanup(
				$attachment_id,
				$local_files,
				$manifest_items,
				$delete_local
			);
			if ( $cleanup['skipped'] && str_contains( $cleanup['message'], 'verify failure' ) ) {
				return array(
					'success' => true,
					'message' => 'Offloaded; local delete skipped after verify failure.',
					'files'   => count( $uploaded_keys ),
					'keys'    => $uploaded_keys,
				);
			}

			return array(
				'success' => true,
				'message' => 'Offloaded successfully.',
				'files'   => count($uploaded_keys),
				'keys'    => $uploaded_keys,
			);
		} catch (\Throwable $e) {
			update_post_meta($attachment_id, '_s3ms_status', self::STATUS_FAILED);
			update_post_meta($attachment_id, '_s3ms_last_error', $this->safe_error_message($e));
			return array(
				'success' => false,
				'message' => $this->safe_error_message($e),
			);
		} finally {
			$this->lock->release($attachment_id);
		}
	}

	/**
	 * Dry-run summary for one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>
	 */
	public function dry_run(int $attachment_id): array {
		$paths   = $this->files->relative_paths($attachment_id);
		$local   = $this->files->existing_local_files($attachment_id);
		$missing = $this->files->missing_local($attachment_id);
		$bytes   = $this->files->local_bytes($attachment_id);
		$keys    = array();
		foreach ($paths as $relative) {
			$keys[] = $this->storage->keys()->resolve($relative);
		}

		return array(
			'attachment_id'       => $attachment_id,
			'physical_files'      => count($paths),
			'local_files'         => count($local),
			'missing_local'       => $missing,
			'total_bytes'         => $bytes,
			'potential_s3_keys'   => $keys,
			'status'              => (string) get_post_meta($attachment_id, '_s3ms_status', true),
			'incomplete_metadata' => $paths === array(),
		);
	}

	/**
	 * User-safe error string (no credentials / long SDK dumps).
	 */
	private function safe_error_message(\Throwable $e): string {
		$msg = $e->getMessage();
		$msg = preg_replace('/AKIA[0-9A-Z]{16}/', '[ACCESS_KEY]', $msg) ?? $msg;
		$msg = preg_replace('/(?i)(secret|password|token)=\\S+/', '$1=[REDACTED]', $msg) ?? $msg;
		if (strlen($msg) > 300) {
			$msg = substr($msg, 0, 297) . '...';
		}
		return $msg;
	}
}
