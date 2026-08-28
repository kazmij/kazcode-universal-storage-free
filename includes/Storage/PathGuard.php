<?php
/**
 * Confines relative upload paths to the WordPress uploads tree.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Rejects path traversal and absolute paths before filesystem / S3 use.
 */
final class PathGuard {

	/**
	 * Normalize and validate a relative uploads path.
	 *
	 * @param string $path Candidate relative path.
	 * @return string Normalized relative path (no leading slash).
	 * @throws \InvalidArgumentException When path escapes uploads.
	 */
	public static function normalize_relative(string $path): string {
		$path = str_replace('\\', '/', $path);

		if ($path === '' || str_contains($path, "\0")) {
			throw new \InvalidArgumentException('Empty or invalid media path.');
		}

		if (preg_match('#^(?:[a-zA-Z]:)?/#', $path) || str_starts_with($path, '//')) {
			throw new \InvalidArgumentException('Absolute media paths are not allowed.');
		}

		$path = ltrim($path, '/');

		if (str_starts_with($path, 'wp-content/uploads/')) {
			$path = substr($path, strlen('wp-content/uploads/'));
		}

		$segments = explode('/', $path);
		$stack    = array();
		foreach ($segments as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..') {
				throw new \InvalidArgumentException('Path traversal is not allowed in media paths.');
			}
			$stack[] = $segment;
		}

		$normalized = implode('/', $stack);
		if ($normalized === '') {
			throw new \InvalidArgumentException('Empty media path after normalization.');
		}

		return $normalized;
	}

	/**
	 * Absolute filesystem path under uploads basedir, or null if invalid/outside.
	 *
	 * @param string $relative Relative uploads path.
	 */
	public static function absolute_under_uploads(string $relative): ?string {
		try {
			$relative = self::normalize_relative($relative);
		} catch (\InvalidArgumentException $e) {
			return null;
		}

		$uploads = wp_upload_dir();
		$basedir = realpath((string) $uploads['basedir']);
		if ($basedir === false) {
			$basedir = rtrim(str_replace('\\', '/', (string) $uploads['basedir']), '/');
		} else {
			$basedir = rtrim(str_replace('\\', '/', $basedir), '/');
		}

		$absolute = $basedir . '/' . $relative;
		$real_parent = realpath(dirname($absolute));
		if ($real_parent !== false) {
			$real_parent = rtrim(str_replace('\\', '/', $real_parent), '/');
			if ($real_parent !== $basedir && !str_starts_with($real_parent . '/', $basedir . '/')) {
				return null;
			}
		} elseif (!str_starts_with(str_replace('\\', '/', dirname($absolute)) . '/', $basedir . '/')
			&& str_replace('\\', '/', dirname($absolute)) !== $basedir
		) {
			return null;
		}

		return $absolute;
	}
}
