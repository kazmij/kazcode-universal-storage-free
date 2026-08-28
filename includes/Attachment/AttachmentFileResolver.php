<?php
/**
 * Discovers all physical files belonging to an attachment.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Attachment;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Storage\S3KeyResolver;

/**
 * Parses _wp_attachment_metadata and related meta into relative paths.
 */
final class AttachmentFileResolver {

	private S3KeyResolver $keys;

	public function __construct(?S3KeyResolver $keys = null) {
		$this->keys = $keys ?? new S3KeyResolver(new \Kazcode\WpStorage\Core\Settings());
	}

	/**
	 * List of relative upload paths for an attachment.
	 *
	 * @param int                       $attachment_id Attachment post ID.
	 * @param array<string, mixed>|null $metadata_override Optional metadata (e.g. during wp_generate_attachment_metadata).
	 * @return list<string>
	 */
	public function relative_paths(int $attachment_id, ?array $metadata_override = null): array {
		$attached_raw = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
		$attached     = $this->keys->try_normalize_relative($attached_raw);
		if ($attached === '') {
			return array();
		}

		$paths   = array();
		$paths[] = $attached;

		$meta = $metadata_override;
		if ($meta === null) {
			$meta = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
		}
		if (!is_array($meta)) {
			return array_values(array_unique($paths));
		}

		if (!empty($meta['file']) && is_string($meta['file'])) {
			$normalized = $this->keys->try_normalize_relative($meta['file']);
			if ($normalized !== '') {
				$paths[] = $normalized;
			}
		}

		if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
			foreach ($meta['sizes'] as $size) {
				if (!is_array($size) || empty($size['file'])) {
					continue;
				}
				$rel = $this->keys->relative_for_size($attached, (string) $size['file']);
				if ($rel !== '') {
					$paths[] = $rel;
				}
			}
		}

		if (!empty($meta['original_image']) && is_string($meta['original_image'])) {
			$rel = $this->keys->relative_for_size($attached, $meta['original_image']);
			if ($rel !== '') {
				$paths[] = $rel;
			}
		}

		$backups = get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true);
		if (is_array($backups)) {
			foreach ($backups as $backup) {
					if (!is_array($backup) || empty($backup['file'])) {
						continue;
					}
					$rel = $this->keys->relative_for_size($attached, (string) $backup['file']);
				if ($rel !== '') {
					$paths[] = $rel;
				}
			}
		}

		$paths = array_filter(
			$paths,
			static function (string $p): bool {
				return $p !== '';
			}
		);

		return array_values(array_unique($paths));
	}

	/**
	 * Absolute local paths that currently exist on disk.
	 *
	 * @param int                       $attachment_id Attachment ID.
	 * @param array<string, mixed>|null $metadata_override Optional metadata override.
	 * @return array<string, string> Map relative => absolute for existing files.
	 */
	public function existing_local_files(int $attachment_id, ?array $metadata_override = null): array {
		$found = array();

		foreach ($this->relative_paths($attachment_id, $metadata_override) as $relative) {
			$absolute = \Kazcode\WpStorage\Storage\PathGuard::absolute_under_uploads($relative);
			if ($absolute !== null && is_file($absolute)) {
				$found[ $relative ] = $absolute;
			}
		}

		return $found;
	}

	/**
	 * Absolute path for a relative uploads path (empty if invalid).
	 */
	public function absolute_path(string $relative): string {
		$absolute = \Kazcode\WpStorage\Storage\PathGuard::absolute_under_uploads($relative);
		return $absolute ?? '';
	}

	/**
	 * Total bytes of existing local files.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function local_bytes(int $attachment_id): int {
		$total = 0;
		foreach ($this->existing_local_files($attachment_id) as $absolute) {
			$size = filesize($absolute);
			if ($size !== false) {
				$total += (int) $size;
			}
		}
		return $total;
	}

	/**
	 * Relative paths missing on the local filesystem.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return list<string>
	 */
	public function missing_local(int $attachment_id): array {
		$missing = array();
		foreach ($this->relative_paths($attachment_id) as $relative) {
			$absolute = \Kazcode\WpStorage\Storage\PathGuard::absolute_under_uploads($relative);
			if ($absolute === null || !is_file($absolute)) {
				$missing[] = $relative;
			}
		}
		return $missing;
	}
}
