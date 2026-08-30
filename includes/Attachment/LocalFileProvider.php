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
use Kazcode\WpStorage\Domain\LocalStoragePolicy;
use Kazcode\WpStorage\Services\ProfileAwareObjectOperations;
use Kazcode\WpStorage\Storage\PathGuard;
use Kazcode\WpStorage\Storage\S3Storage;

/**
 * On-demand local materialization — admin / editor / CLI only (never frontend traffic).
 */
final class LocalFileProvider {

	private Settings $settings;
	private S3Storage $storage;
	private AttachmentFileResolver $files;
	private ProfileAwareObjectOperations $profile_ops;
	/** @var array<int, true> */
	private array $rest_media_edit_attachment_ids = array();
	private bool $rest_media_edit_all = false;
	/** @var array<string, true> */
	private array $rest_materialized_paths = array();

	public function __construct(Settings $settings, S3Storage $storage, ?ProfileAwareObjectOperations $profile_ops = null) {
		$this->settings    = $settings;
		$this->storage     = $storage;
		$this->files       = new AttachmentFileResolver($storage->keys());
		$this->profile_ops = $profile_ops ?? new ProfileAwareObjectOperations( legacy: $storage, settings: $settings );
	}

	/**
	 * Register hooks that need a local original.
	 */
	public function register(): void {
		add_filter('wp_get_original_image_path', array($this, 'filter_original_image_path'), 10, 2);
		add_filter('get_attached_file', array($this, 'filter_get_attached_file'), 10, 2);
		add_action('wp_ajax_image-editor', array($this, 'ensure_before_image_editor'), 1);
		add_filter('rest_request_before_callbacks', array($this, 'filter_rest_request_before_callbacks'), 10, 3);
		add_filter('rest_request_after_callbacks', array($this, 'filter_rest_request_after_callbacks'), 10, 3);
		add_action('shutdown', array($this, 'cleanup_rest_materialized_files'), 0);
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
			$this->record_rest_materialization($attachment_id, $this->ensure_local($attachment_id, true));
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
			$this->record_rest_materialization($attachment_id, $this->ensure_local($attachment_id, true));
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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- $post_id itself is only used to look up a post; the actual nonce (matching WP core's own wp_ajax_image_editor(), which this hook runs before at priority 1) is verified below prior to any file I/O.
		$post_id = isset($_POST['postid']) ? (int) $_POST['postid'] : 0;
		if ($post_id <= 0 || !$this->is_offloaded($post_id)) {
			return;
		}
		if (!current_user_can('edit_post', $post_id)) {
			return;
		}
		// This hook runs at priority 1 on the same wp_ajax_image-editor action
		// core's own wp_ajax_image_editor() handles at the default priority —
		// core verifies "image_editor-$post_id" there, but only after this
		// hook has already run. Verify the identical nonce here too, since
		// this method does real file I/O (downloading from S3) before core
		// gets a chance to reject an invalid request.
		check_ajax_referer( "image_editor-{$post_id}" );
		try {
			$this->ensure_local($post_id, true);
		} catch (\Throwable $e) {
			// Image editor will surface its own error.
		}
	}

	/**
	 * Permit on-demand files only for authorized REST media editor reads.
	 *
	 * @param mixed $response REST response/error.
	 * @param mixed $handler  Matched handler.
	 * @param mixed $request  REST request.
	 * @return mixed
	 */
	public function filter_rest_request_before_callbacks($response, $handler, $request) {
		$this->reset_rest_materialization_context();

		if (!$this->is_rest_media_edit_request($request)) {
			return $response;
		}

		$route = method_exists($request, 'get_route') ? (string) $request->get_route() : '';
		$route = '/' . trim($route, '/');

		if (preg_match('#^/wp/v2/media/(\d+)$#', $route, $matches)) {
			$attachment_id = (int) $matches[1];
			if ($attachment_id > 0 && current_user_can('edit_post', $attachment_id)) {
				$this->rest_media_edit_attachment_ids[$attachment_id] = true;
			}
			return $response;
		}

		if ($route === '/wp/v2/media' && current_user_can('upload_files')) {
			$this->rest_media_edit_all = true;
		}

		return $response;
	}

	/**
	 * Clean up temporary REST editor materialization after WordPress has built the response.
	 *
	 * @param mixed $response REST response/error.
	 * @param mixed $handler  Matched handler.
	 * @param mixed $request  REST request.
	 * @return mixed
	 */
	public function filter_rest_request_after_callbacks($response, $handler, $request) {
		$this->cleanup_rest_materialized_files();
		$this->reset_rest_materialization_context();
		return $response;
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
			$head = $this->profile_ops->head_attachment_relative($attachment_id, $relative);
			if (empty($head['exists'])) {
				continue;
			}
			$download = $this->profile_ops->download_attachment_relative_to_local($attachment_id, $relative, $absolute);
			if (empty($download['success'])) {
				continue;
			}
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
		if ($this->is_rest_media_edit_materialization_allowed($attachment_id)) {
			return true;
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
	 * @param list<string> $relatives
	 */
	private function record_rest_materialization(int $attachment_id, array $relatives): void {
		if (!$this->is_rest_media_edit_materialization_allowed($attachment_id)) {
			return;
		}
		foreach ($relatives as $relative) {
			$absolute = PathGuard::absolute_under_uploads($relative);
			if ($absolute !== null) {
				$this->rest_materialized_paths[$absolute] = true;
			}
		}
	}

	public function cleanup_rest_materialized_files(): void {
		if ($this->rest_materialized_paths === array()) {
			return;
		}
		if ($this->settings->local_storage_policy() !== LocalStoragePolicy::REMOTE_ONLY) {
			$this->rest_materialized_paths = array();
			return;
		}
		foreach (array_keys($this->rest_materialized_paths) as $path) {
			if (is_file($path)) {
				wp_delete_file($path);
			}
		}
		$this->rest_materialized_paths = array();
	}

	private function is_rest_media_edit_materialization_allowed(int $attachment_id): bool {
		if ($this->rest_media_edit_all) {
			return true;
		}
		return $attachment_id > 0 && isset($this->rest_media_edit_attachment_ids[$attachment_id]);
	}

	private function reset_rest_materialization_context(): void {
		$this->rest_media_edit_attachment_ids = array();
		$this->rest_media_edit_all            = false;
	}

	private function is_rest_media_edit_request($request): bool {
		if (!is_object($request) || !method_exists($request, 'get_route') || !method_exists($request, 'get_param')) {
			return false;
		}

		if (method_exists($request, 'get_method')) {
			$method = strtoupper((string) $request->get_method());
			if ($method !== 'GET' && $method !== 'HEAD') {
				return false;
			}
		}

		if ((string) $request->get_param('context') !== 'edit') {
			return false;
		}

		$route = '/' . trim((string) $request->get_route(), '/');
		return $route === '/wp/v2/media' || (bool) preg_match('#^/wp/v2/media/\d+$#', $route);
	}

	/**
	 * Whether attachment is marked offloaded.
	 */
	private function is_offloaded(int $attachment_id): bool {
		return (string) get_post_meta($attachment_id, '_s3ms_status', true) === AttachmentOffloader::STATUS_OFFLOADED;
	}
}
