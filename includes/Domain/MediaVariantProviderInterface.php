<?php
/**
 * Optional manifest contributors (WebP sidecars, plugin variants).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Register via MediaVariantRegistry — no filesystem scans.
 */
interface MediaVariantProviderInterface {

	/**
	 * @param int                       $attachment_id Attachment ID.
	 * @param array<string, mixed>|null $metadata_override Metadata from WP hook when available.
	 * @return list<array{relative:string,variant_type?:string,mime?:string}>
	 */
	public function contribute( int $attachment_id, ?array $metadata_override = null ): array;
}
