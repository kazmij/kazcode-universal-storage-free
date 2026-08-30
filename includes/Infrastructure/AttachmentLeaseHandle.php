<?php
/**
 * Opaque attachment lease ownership handle.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class AttachmentLeaseHandle {

	public function __construct(
		public readonly int $attachment_id,
		public readonly string $owner_token,
		public readonly int $generation,
		public readonly string $operation,
		public int $expires_at,
	) {
	}
}
