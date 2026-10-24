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
		private array $descriptors,
		private int $rejected_attachment_count = 0
	) {
		if ( $limit <= 0 ) {
			throw new \InvalidArgumentException( 'Import scan limit must be positive.' );
		}
		if ( $cursor < 0 ) {
			throw new \InvalidArgumentException( 'Import scan cursor must not be negative.' );
		}
		if ( $rejected_attachment_count < 0 ) {
			throw new \InvalidArgumentException( 'Import scan rejected attachment count must not be negative.' );
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

	public function rejected_attachment_count(): int {
		return $this->rejected_attachment_count;
	}
}
