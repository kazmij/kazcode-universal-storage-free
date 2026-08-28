<?php
/**
 * Builds public URLs for offloaded media.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Storage;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\Settings;

/**
 * Priority: CDN URL → custom Public/Base URL → default S3 URL.
 */
final class PublicUrlResolver {

	private Settings $settings;
	private S3KeyResolver $keys;

	public function __construct(Settings $settings, ?S3KeyResolver $keys = null) {
		$this->settings = $settings;
		$this->keys     = $keys ?? new S3KeyResolver($settings);
	}

	/**
	 * Public URL for a relative uploads path.
	 *
	 * @param string $relative_path Path relative to uploads (e.g. 2026/08/photo.jpg).
	 */
	public function url_for_relative(string $relative_path): string {
		$relative = $this->keys->try_normalize_relative($relative_path);
		if ($relative === '') {
			return '';
		}
		$key = $this->keys->resolve($relative);
		return $this->url_for_key($key, $relative);
	}

	/**
	 * Public URL for a known S3 object key.
	 *
	 * @param string $key      Full S3 key.
	 * @param string $relative Optional relative path for bases that omit object prefix.
	 */
	public function url_for_key(string $key, string $relative = ''): string {
		$include_prefix = (bool) $this->settings->get('cdn_includes_prefix', false);

		$cdn = untrailingslashit((string) $this->settings->get('cdn_url', ''));
		if ($cdn !== '') {
			$path = $include_prefix ? $key : ($relative !== '' ? $relative : $key);
			return $cdn . '/' . ltrim($this->encode_path($path), '/');
		}

		$base = untrailingslashit((string) $this->settings->get('public_base_url', ''));
		if ($base !== '') {
			$path = $include_prefix ? $key : ($relative !== '' ? $relative : $key);
			return $base . '/' . ltrim($this->encode_path($path), '/');
		}

		return $this->default_s3_url($key);
	}

	/**
	 * Default virtual-hosted or path-style S3 URL.
	 */
	private function default_s3_url(string $key): string {
		$bucket   = (string) $this->settings->get('bucket', '');
		$region   = (string) $this->settings->get('region', 'us-east-1');
		$endpoint = untrailingslashit((string) $this->settings->get('endpoint', ''));
		$path     = $this->encode_path($key);

		if ($bucket === '') {
			return '';
		}

		if ($endpoint !== '') {
			return $endpoint . '/' . rawurlencode($bucket) . '/' . $path;
		}

		if ($region === 'us-east-1') {
			return 'https://' . $bucket . '.s3.amazonaws.com/' . $path;
		}

		return 'https://' . $bucket . '.s3.' . $region . '.amazonaws.com/' . $path;
	}

	/**
	 * Encode each path segment but keep slashes.
	 */
	private function encode_path(string $path): string {
		$path     = ltrim(str_replace('\\', '/', $path), '/');
		$segments = explode('/', $path);
		$encoded  = array_map(
			static function (string $segment): string {
				return rawurlencode($segment);
			},
			$segments
		);
		return implode('/', $encoded);
	}
}
