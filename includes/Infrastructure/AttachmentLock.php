<?php
/**
 * Per-attachment processing lock with TTL (atomic acquire).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure;

defined( 'ABSPATH' ) || exit;

/**
 * Uses add_option for atomic create; falls back to transient TTL cleanup.
 */
final class AttachmentLock {

	private const TTL_SECONDS = 300;

	/**
	 * Attempt to acquire a lock.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $operation     Operation name (migrate, regenerate, delete, retry, restore).
	 */
	public function acquire(int $attachment_id, string $operation): bool {
		$key  = $this->option_key($attachment_id);
		$now  = time();
		$data = array(
			'operation' => $operation,
			'at'        => $now,
			'expires'   => $now + self::TTL_SECONDS,
		);

		$existing = get_option($key, null);
		if (is_array($existing)) {
			$expires = (int) ($existing['expires'] ?? 0);
			if ($expires > $now) {
				return false;
			}
			delete_option($key);
		}

		// add_option is atomic for a new key.
		$added = add_option($key, $data, '', false);
		if ($added) {
			return true;
		}

		// Lost race — confirm still held.
		$existing = get_option($key, null);
		if (is_array($existing) && (int) ($existing['expires'] ?? 0) > $now) {
			return false;
		}

		delete_option($key);
		return (bool) add_option($key, $data, '', false);
	}

	/**
	 * Release lock.
	 */
	public function release(int $attachment_id): void {
		delete_option($this->option_key($attachment_id));
	}

	/**
	 * Whether locked.
	 */
	public function is_locked(int $attachment_id): bool {
		$existing = get_option($this->option_key($attachment_id), null);
		if (!is_array($existing)) {
			return false;
		}
		return (int) ($existing['expires'] ?? 0) > time();
	}

	/**
	 * Option key (not autoloaded).
	 */
	private function option_key(int $attachment_id): string {
		return 's3ms_lock_' . $attachment_id;
	}
}
