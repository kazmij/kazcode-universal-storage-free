<?php
/**
 * Resolves WordPress relative upload paths to S3 object keys.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Storage;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\Settings;

/**
 * Central S3 key builder — do not duplicate elsewhere.
 */
final class S3KeyResolver {

	private Settings $settings;

	public function __construct(Settings $settings) {
		$this->settings = $settings;
	}

	/**
	 * Convert a relative uploads path to an S3 object key.
	 *
	 * @param string $relative_path Path relative to uploads basedir (e.g. 2026/08/photo.jpg).
	 */
	public function resolve(string $relative_path): string {
		$prefix = (string) $this->settings->get('object_prefix', '');
		return ObjectKeyService::key_for($prefix, $relative_path);
	}

	/**
	 * Normalize a relative path (no leading slash, no traversal).
	 */
	public function normalize_relative(string $path): string {
		return PathGuard::normalize_relative($path);
	}

	/**
	 * Soft normalize for display / best-effort (returns empty string if invalid).
	 */
	public function try_normalize_relative(string $path): string {
		try {
			return PathGuard::normalize_relative($path);
		} catch (\InvalidArgumentException $e) {
			return '';
		}
	}

	/**
	 * Directory portion of a relative path (for size files that store basename only).
	 *
	 * @param string $attached_file Value of _wp_attached_file.
	 */
	public function directory_of(string $attached_file): string {
		$relative = $this->try_normalize_relative($attached_file);
		if ($relative === '') {
			return '';
		}
		$dir = dirname($relative);
		if ($dir === '.' || $dir === '') {
			return '';
		}
		return rtrim($dir, '/') . '/';
	}

	/**
	 * Build relative path for a size file that may be basename-only.
	 *
	 * @param string $attached_file Main _wp_attached_file.
	 * @param string $size_file     sizes[*]['file'] value.
	 */
	public function relative_for_size(string $attached_file, string $size_file): string {
		try {
			$size_file = PathGuard::normalize_relative($size_file);
		} catch (\InvalidArgumentException $e) {
			return '';
		}
		if (str_contains($size_file, '/')) {
			return $size_file;
		}
		return $this->directory_of($attached_file) . $size_file;
	}
}
