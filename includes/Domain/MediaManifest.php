<?php
/**
 * Expected physical files for one attachment.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Deterministic manifest of local-relative paths + variant types.
 */
final class MediaManifest {

	/**
	 * @param list<array{relative:string,variant_type:string}> $items Manifest items.
	 */
	public function __construct(
		public readonly int $attachment_id,
		private array $items,
	) {
	}

	/**
	 * @return list<array{relative:string,variant_type:string}>
	 */
	public function items(): array {
		return $this->items;
	}

	/**
	 * @return list<string>
	 */
	public function relative_paths(): array {
		$paths = array();
		foreach ( $this->items as $item ) {
			$paths[] = $item['relative'];
		}
		return $paths;
	}

	public function count(): int {
		return count( $this->items );
	}
}
