<?php
/**
 * Ensures local files exist for Image Editor / regenerate by downloading from S3.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Attachment;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Storage\PathGuard;
use Kazcode\WpStorage\Storage\S3Storage;

/**
 * On-demand local materialization — admin / editor / CLI only (never frontend traffic).
 */
final class LocalFileProvider {

	private Settings $settings;
	private S3Storage $storage;
	private AttachmentFileResolver $files;

	public function __construct(Settings $settings, S3Storage $storage) {
		$this->settings = $settings;
		$this->storage  = $storage;
		$this->files    = new AttachmentFileResolver($storage->keys());
	}

	/**
	 * Register hooks that need a local original.
	 */
	public function register(): void {
		add_filter('wp_get_original_image_path', array($this, 'filter_original_image_path'), 10, 2);
		add_filter('get_attached_file', array($this, 'filter_get_attached_file'), 10, 2);
		add_action('wp_ajax_image-editor', array($this, 'ensure_before_image_editor'), 1);
	}

	/**
	 * Ensure attached file path exists on disk when offloaded (privileged contexts only).
	 *
	 * @param string|false $file File path.
	 * @param int          $attachment_id Attachment ID.
	 * @return string|false
	 */
	public function filter_get_attached_file($file, int $attachment_id) {
		if (!$this->should_materialize($attachment_id) || !is_string($file) || $file === '') {
			return $file;
		}
		if (is_file($file)) {
			return $file;
		}
		try {
			$this->ensure_local($attachment_id, true);
		} catch (\Throwable $e) {
			return $file;
		}
		return is_file($file) ? $file : $file;
	}

	/**
	 * Ensure original image path for big image / editor.
	 *
	 * @param string|false $path Path.
	 * @param int          $attachment_id Attachment ID.
	 * @return string|false
	 */
	public function filter_original_image_path($path, int $attachment_id) {
		if (!$this->should_materialize($attachment_id)) {
			return $path;
		}
		try {
			$this->ensure_local($attachment_id, true);
		} catch (\Throwable $e) {
			return $path;
		}
		if (is_string($path) && $path !== '' && !is_file($path)) {
			$attached = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
			$absolute = $this->files->absolute_path($attached);
			if ($absolute !== '' && is_file($absolute)) {
				return $absolute;
			}
		}
		return $path;
	}

	/**
	 * Before AJAX image editor runs, pull source from S3 if needed.
	 */
	public function ensure_before_image_editor(): void {
		$post_id = isset($_POST['postid']) ? (int) $_POST['postid'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ($post_id <= 0 || !$this->is_offloaded($post_id)) {
			return;
		}
		if (!current_user_can('edit_post', $post_id)) {
			return;
		}
		// Nonce is validated later by core image editor; we only gate capability here.
		try {
			$this->ensure_local($post_id, true);
		} catch (\Throwable $e) {
			// Image editor will surface its own error.
		}
	}

	/**
	 * Download missing files from S3 into uploads (path-jailed).
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $original_only Only ensure original / original_image.
	 * @return list<string> Relatives downloaded.
	 */
	public function ensure_local(int $attachment_id, bool $original_only = false): array {
		if (!$this->settings->is_enabled() || !$this->settings->is_aws_configured()) {
			throw new \RuntimeException('Plugin is not configured for S3 downloads.');
		}

		// wp media regenerate (WP_CLI only) re-materializes the original and then
		// genuinely calls wp_generate_attachment_metadata() again — guard against that
		// function's own pre-resize checkpoint call(s) re-triggering a destructive
		// offload before real sub-sizes exist. See AttachmentOffloader::guard_next_generate().
		// The Image Editor's crop/rotate/scale save reaches this same method (also via
		// ensure_before_image_editor()) but — unlike regenerate — never calls
		// wp_generate_attachment_metadata() at all (wp_save_image() saves metadata
		// directly), so nothing would ever clear the guard for that flow: it must not be
		// set here in the first place, or Image Editor saves stop being processed
		// forever. WP_CLI is the reliable signal, since regenerate is CLI-only and the
		// Image Editor is exclusively a browser admin-ajax action.
		if (defined('WP_CLI') && WP_CLI) {
			AttachmentOffloader::guard_next_generate($attachment_id);
		}

		$downloaded = array();
		$attached   = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
		if ($attached === '') {
			throw new \RuntimeException('Attachment has no _wp_attached_file.');
		}

		try {
			$targets = array($this->storage->keys()->normalize_relative($attached));
		} catch (\InvalidArgumentException $e) {
			throw new \RuntimeException('Invalid attachment path.');
		}

		if (!$original_only) {
			$targets = $this->files->relative_paths($attachment_id);
		} else {
			$meta = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
			if (is_array($meta) && !empty($meta['original_image'])) {
				$extra = $this->storage->keys()->relative_for_size($attached, (string) $meta['original_image']);
				if ($extra !== '') {
					$targets[] = $extra;
				}
			}
		}

		foreach (array_unique(array_filter($targets)) as $relative) {
			$absolute = PathGuard::absolute_under_uploads($relative);
			if ($absolute === null) {
				continue;
			}
			if (is_file($absolute)) {
				continue;
			}
			$head = $this->storage->head_relative($relative);
			if (empty($head['exists'])) {
				continue;
			}
			$this->storage->download_relative($relative, $absolute);
			$downloaded[] = $relative;
		}

		return $downloaded;
	}

	/**
	 * Materialize only in privileged contexts (admin, cron, CLI) — never public HTML requests.
	 */
	private function should_materialize(int $attachment_id): bool {
		if (!$this->is_offloaded($attachment_id)) {
			return false;
		}
		if (defined('WP_CLI') && WP_CLI) {
			return true;
		}
		if (wp_doing_cron()) {
			return true;
		}
		if (!is_admin()) {
			return false;
		}
		return current_user_can('upload_files');
	}

	/**
	 * Whether attachment is marked offloaded.
	 */
	private function is_offloaded(int $attachment_id): bool {
		return (string) get_post_meta($attachment_id, '_s3ms_status', true) === AttachmentOffloader::STATUS_OFFLOADED;
	}
}
