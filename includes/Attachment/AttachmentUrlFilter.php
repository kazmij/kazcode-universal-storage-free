<?php
/**
 * Filters attachment URLs to S3/CDN for offloaded media.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Attachment;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\SecretRedactor;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Storage\ProfileDeliveryUrlResolver;
use Kazcode\WpStorage\Storage\PublicUrlResolver;
use Kazcode\WpStorage\Storage\S3KeyResolver;
use Kazcode\WpStorage\Storage\S3Storage;

/**
 * URL resolving layer — never remaps basedir to HTTP.
 */
final class AttachmentUrlFilter {

	private Settings $settings;
	private PublicUrlResolver $urls;
	private ProfileDeliveryUrlResolver $profile_urls;
	private S3KeyResolver $keys;
	private ?S3Storage $storage;

	public function __construct(Settings $settings, PublicUrlResolver $urls, S3KeyResolver $keys, ?S3Storage $storage = null, ?ProfileDeliveryUrlResolver $profile_urls = null) {
		$this->settings     = $settings;
		$this->urls         = $urls;
		$this->profile_urls = $profile_urls ?? new ProfileDeliveryUrlResolver( fallback: $urls );
		$this->keys         = $keys;
		$this->storage      = $storage;
	}

	/**
	 * Register WordPress filters.
	 */
	public function register(): void {
		add_filter('wp_get_attachment_url', array($this, 'filter_attachment_url'), 10, 2);
		add_filter('wp_get_attachment_image_src', array($this, 'filter_image_src'), 10, 4);
		add_filter('image_downsize', array($this, 'filter_image_downsize'), 10, 3);
		add_filter('wp_calculate_image_srcset', array($this, 'filter_srcset'), 10, 5);
		add_filter('wp_prepare_attachment_for_js', array($this, 'filter_prepare_for_js'), 10, 3);
		add_filter('rest_prepare_attachment', array($this, 'filter_rest_prepare'), 10, 3);
		add_filter('wp_content_img_tag', array($this, 'filter_content_img_tag'), 10, 3);
	}

	/**
	 * Whether this attachment should be served from S3.
	 */
	public function should_serve(int $attachment_id): bool {
		if (!$this->settings->is_serve_enabled()) {
			return false;
		}
		$status = (string) get_post_meta($attachment_id, '_s3ms_status', true);
		return $status === AttachmentOffloader::STATUS_OFFLOADED;
	}

	/**
	 * Filter full attachment URL.
	 *
	 * @param string $url URL.
	 * @param int    $attachment_id Attachment ID.
	 */
	public function filter_attachment_url(string $url, int $attachment_id): string {
		if (!$this->should_serve($attachment_id)) {
			return $url;
		}
		if (!$this->settings->has_public_url_config()) {
			return $url;
		}
		$attached = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
		if ($attached === '') {
			return $url;
		}
		$resolved = $this->serve_url_for_relative( $attached, $attachment_id );
		return $resolved !== '' ? $resolved : $url;
	}

	/**
	 * Filter image src array.
	 *
	 * @param array{0:string,1:int,2:int,3:bool}|false $image Image data.
	 * @param int                                       $attachment_id Attachment ID.
	 * @param string|int[]                              $size Size.
	 * @param bool                                      $icon Icon.
	 * @return array{0:string,1:int,2:int,3:bool}|false
	 */
	public function filter_image_src($image, int $attachment_id, $size, bool $icon) {
		if ($image === false || !$this->should_serve($attachment_id)) {
			return $image;
		}

		$resolved = $this->resolve_size_url($attachment_id, $size);
		if ($resolved === null || $this->is_full_object_fallback_for_non_image($attachment_id, $resolved)) {
			return $image;
		}

		$image[0] = $resolved['url'];
		if ($resolved['width'] > 0) {
			$image[1] = $resolved['width'];
		}
		if ($resolved['height'] > 0) {
			$image[2] = $resolved['height'];
		}
		return $image;
	}

	/**
	 * Critical for Media Library Grid when local files are gone.
	 *
	 * @param bool|array{0:string,1:int,2:int,3:bool} $downsize Existing.
	 * @param int                                     $attachment_id Attachment ID.
	 * @param string|int[]                            $size Size.
	 * @return bool|array{0:string,1:int,2:int,3:bool}
	 */
	public function filter_image_downsize($downsize, int $attachment_id, $size) {
		if ($downsize !== false || !$this->should_serve($attachment_id)) {
			return $downsize;
		}
		if (!$this->settings->has_public_url_config()) {
			return $downsize;
		}

		// WordPress already returned false (typically missing local file); supply S3 URL from metadata.
		$attached = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
		if ($attached === '') {
			return $downsize;
		}

		$resolved = $this->resolve_size_url($attachment_id, $size);
		if ($resolved === null || $resolved['url'] === '' || $this->is_full_object_fallback_for_non_image($attachment_id, $resolved)) {
			return $downsize;
		}

		return array($resolved['url'], $resolved['width'], $resolved['height'], $resolved['is_intermediate']);
	}

	/**
	 * Replace srcset URLs with S3/CDN.
	 *
	 * @param array<string, array{url:string,descriptor:string,value:int}> $sources Sources.
	 * @param array{0:int,1:int}                                           $size_array Size.
	 * @param string                                                       $image_src Image src.
	 * @param array<string, mixed>                                         $image_meta Meta.
	 * @param int                                                          $attachment_id Attachment ID.
	 * @return array<string, array{url:string,descriptor:string,value:int}>
	 */
	public function filter_srcset(array $sources, array $size_array, string $image_src, array $image_meta, int $attachment_id): array {
		if (!$this->should_serve($attachment_id) || $sources === array()) {
			return $sources;
		}

		$attached = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
		$dir      = $this->keys->directory_of($attached);

		foreach ($sources as $width => $source) {
			if (empty($source['url'])) {
				continue;
			}
			// Extract basename from existing URL and rebuild.
			$path = (string) wp_parse_url($source['url'], PHP_URL_PATH);
			$base = $path !== '' ? basename($path) : '';
			if ($base === '') {
				continue;
			}
			$relative           = $dir . $base;
			$sources[ $width ]['url'] = $this->serve_url_for_relative( $relative, $attachment_id );
		}

		return $sources;
	}

	/**
	 * Media modal / Grid JS payload.
	 *
	 * @param array<string, mixed> $response Response.
	 * @param \WP_Post             $attachment Attachment.
	 * @param array<string, mixed> $meta Meta.
	 * @return array<string, mixed>
	 */
	public function filter_prepare_for_js(array $response, \WP_Post $attachment, $meta): array {
		$id = (int) $attachment->ID;
		if (!$this->should_serve($id)) {
			return $response;
		}

		$attached = (string) get_post_meta($id, '_wp_attached_file', true);
		if ($attached !== '') {
			$response['url'] = $this->serve_url_for_relative( $attached, $id );
		}

		if (!empty($response['sizes']) && is_array($response['sizes'])) {
			foreach ($response['sizes'] as $name => $size_data) {
				if (!is_array($size_data)) {
					continue;
				}
				$resolved = $this->resolve_size_url($id, (string) $name);
				if ($resolved !== null) {
					$response['sizes'][ $name ]['url'] = $resolved['url'];
				}
			}
		}

		if (!empty($response['icon']) && is_string($response['icon']) && $attached !== '') {
			// Prefer thumbnail for icon when available. A fallback to the full
			// object is not a thumbnail: replacing a native icon (e.g. the ZIP
			// icon) with the remote object URL would break the Media Library.
			$thumb = $this->resolve_size_url($id, 'thumbnail');
			if ($thumb !== null && $thumb['is_intermediate']) {
				$response['icon'] = $thumb['url'];
			}
		}

		return $response;
	}

	/**
	 * REST attachment response.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_Post          $post Post.
	 * @param \WP_REST_Request  $request Request.
	 */
	public function filter_rest_prepare(\WP_REST_Response $response, \WP_Post $post, $request): \WP_REST_Response {
		$id = (int) $post->ID;
		if (!$this->should_serve($id)) {
			return $response;
		}

		$data = $response->get_data();
		if (!is_array($data)) {
			return $response;
		}

		$attached = (string) get_post_meta($id, '_wp_attached_file', true);
		if ($attached !== '') {
			$data['source_url'] = $this->serve_url_for_relative( $attached, $id );
		}

		// WP_REST_Attachments_Controller supplies media_details as a stdClass on
		// current WordPress releases. Normalize only this nested payload before
		// reading its sizes so remote-only attachments cannot make Media REST
		// fatal after a successful finalization.
		$media_details = $data['media_details'] ?? null;
		if (is_object($media_details)) {
			$media_details = (array) $media_details;
		}
		if (is_array($media_details) && !empty($media_details['sizes']) && is_array($media_details['sizes'])) {
			foreach ($media_details['sizes'] as $name => $size_data) {
				$resolved = $this->resolve_size_url($id, (string) $name);
				if ($resolved !== null) {
					$media_details['sizes'][ $name ]['source_url'] = $resolved['url'];
				}
			}
			$data['media_details'] = $media_details;
		}

		$response->set_data($data);
		return $response;
	}

	/**
	 * Stored post content keeps the canonical uploads URL; only the rendered
	 * `src` is pointed at the active delivery URL (#65). Content is never
	 * mutated, so a CDN/domain change or a restore needs no search/replace.
	 *
	 * Rewrites only when the `src` path is provably one of this attachment's own
	 * files (current sizes, original, or Image Editor backups). A `wp-image-{ID}`
	 * class whose `src` names another file is left untouched and reported — a
	 * mismatch is diagnostic evidence, never permission to rewrite.
	 *
	 * @param string $filtered_image Full <img> tag.
	 * @param string $context        Filter context (e.g. the_content).
	 * @param int    $attachment_id  ID from the wp-image-{ID} class, 0 if none.
	 */
	public function filter_content_img_tag($filtered_image, $context, $attachment_id): string {
		$tag = (string) $filtered_image;
		$id  = (int) $attachment_id;
		if ($id <= 0 || !$this->should_serve($id) || !$this->settings->has_public_url_config()) {
			return $tag;
		}
		if (!preg_match('/\ssrc=(["\'])([^"\']+)\1/i', $tag, $match)) {
			return $tag;
		}

		$relative = $this->uploads_relative_from_url(html_entity_decode($match[2], ENT_QUOTES));
		if ($relative === '') {
			return $tag;
		}

		$owned = (new AttachmentFileResolver($this->keys))->relative_paths($id);
		if (!in_array($relative, $owned, true)) {
			/**
			 * A content image's class names one attachment while its src names a
			 * file that attachment does not own. Report-only (Integrity Center).
			 *
			 * @param int    $attachment_id Attachment ID from the class.
			 * @param string $relative      Uploads-relative path from src.
			 * @param string $context       Filter context.
			 */
			do_action('kazus_content_image_identity_mismatch', $id, $relative, (string) $context);
			return $tag;
		}

		$url = $this->serve_url_for_relative($relative, $id);
		if ($url === '') {
			return $tag;
		}
		$escaped = function_exists('esc_url') ? esc_url($url) : htmlspecialchars($url, ENT_QUOTES);
		return str_replace($match[0], ' src=' . $match[1] . $escaped . $match[1], $tag);
	}

	/**
	 * Uploads-relative path for a URL under this site's uploads base, else ''.
	 */
	private function uploads_relative_from_url(string $url): string {
		$uploads = wp_upload_dir();
		$base    = (string) ($uploads['baseurl'] ?? '');
		if ($base === '') {
			return '';
		}
		$strip  = static fn(string $u): string => (string) preg_replace('#^https?:#i', '', $u);
		$base   = rtrim($strip($base), '/') . '/';
		$target = $strip(strtok($url, '?#') ?: '');
		if (!str_starts_with($target, $base)) {
			return '';
		}
		return $this->keys->try_normalize_relative(rawurldecode(substr($target, strlen($base))));
	}

	/**
	 * Public or signed URL for a relative uploads path.
	 */
	private function serve_url_for_relative( string $relative, int $attachment_id ): string {
		if ( $this->settings->is_private_media() && $this->storage !== null ) {
			try {
				return $this->storage->presigned_url_for_relative( $relative, $this->settings->signed_url_ttl() );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'KAZCODE Universal Storage presigned URL failed for ' . $relative . ': ' . SecretRedactor::redact( $e ) );
				}
				return '';
			}
		}
		return $this->profile_urls->url_for_attachment_relative( $attachment_id, $relative );
	}

	/**
	 * A ZIP/PDF/audio file has no image of its own: when no real intermediate
	 * size exists, the "size" is the full object, which must not stand in for
	 * an image (WordPress then shows its native file-type icon instead).
	 *
	 * @param array{url:string,width:int,height:int,is_intermediate:bool} $resolved
	 */
	private function is_full_object_fallback_for_non_image(int $attachment_id, array $resolved): bool {
		return !$resolved['is_intermediate'] && !$this->is_image_attachment($attachment_id);
	}

	private function is_image_attachment(int $attachment_id): bool {
		if (function_exists('wp_attachment_is_image')) {
			return (bool) wp_attachment_is_image($attachment_id);
		}
		$attached = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
		return (bool) preg_match('/\.(jpe?g|png|gif|webp|avif|heic|heif|bmp|tiff?|ico)$/i', $attached);
	}

	/**
	 * Resolve URL + dimensions for a size name or array.
	 *
	 * @param int             $attachment_id Attachment ID.
	 * @param string|int[]    $size Size.
	 * @return array{url:string,width:int,height:int,is_intermediate:bool}|null
	 */
	private function resolve_size_url(int $attachment_id, $size): ?array {
		$attached = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
		if ($attached === '') {
			return null;
		}

		$meta = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
		if (!is_array($meta)) {
			$meta = array();
		}

		$size_name = is_string($size) ? $size : 'full';

		if ($size_name === 'full' || $size === 'full') {
			return array(
				'url'             => $this->serve_url_for_relative( $attached, $attachment_id ),
				'width'           => isset($meta['width']) ? (int) $meta['width'] : 0,
				'height'          => isset($meta['height']) ? (int) $meta['height'] : 0,
				'is_intermediate' => false,
			);
		}

		if (is_array($size)) {
			// Best-effort: use full.
			return array(
				'url'             => $this->serve_url_for_relative( $attached, $attachment_id ),
				'width'           => (int) ($size[0] ?? ($meta['width'] ?? 0)),
				'height'          => (int) ($size[1] ?? ($meta['height'] ?? 0)),
				'is_intermediate' => false,
			);
		}

		if (!empty($meta['sizes'][ $size_name ]['file'])) {
			$file = (string) $meta['sizes'][ $size_name ]['file'];
			$rel  = $this->keys->relative_for_size($attached, $file);
			return array(
				'url'             => $this->serve_url_for_relative( $rel, $attachment_id ),
				'width'           => (int) ($meta['sizes'][ $size_name ]['width'] ?? 0),
				'height'          => (int) ($meta['sizes'][ $size_name ]['height'] ?? 0),
				'is_intermediate' => true,
			);
		}

		// Fallback to full.
		return array(
			'url'             => $this->serve_url_for_relative( $attached, $attachment_id ),
			'width'           => isset($meta['width']) ? (int) $meta['width'] : 0,
			'height'          => isset($meta['height']) ? (int) $meta['height'] : 0,
			'is_intermediate' => false,
		);
	}
}
