<?php
/**
 * Page of imported object descriptors.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Import;

defined( 'ABSPATH' ) || exit;

final class ImportScanPage {

	/** @param list<ImportedObjectDescriptor> $descriptors */
	public function __construct(
		private int $limit,
		private int $cursor,
		private array $descriptors
	) {
		if ( $limit <= 0 ) {
			throw new \InvalidArgumentException( 'Import scan limit must be positive.' );
		}
		if ( $cursor < 0 ) {
			throw new \InvalidArgumentException( 'Import scan cursor must not be negative.' );
		}
		foreach ( $descriptors as $descriptor ) {
			if ( ! $descriptor instanceof ImportedObjectDescriptor ) {
				throw new \InvalidArgumentException( 'Import scan descriptors must be ImportedObjectDescriptor instances.' );
			}
		}
	}

	public function limit(): int {
		return $this->limit;
	}

	public function cursor(): int {
		return $this->cursor;
	}

	/** @return list<ImportedObjectDescriptor> */
	public function descriptors(): array {
		return $this->descriptors;
	}
}
