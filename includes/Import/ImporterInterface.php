<?php
/**
 * Read-only competitor importer contract.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Import;

defined( 'ABSPATH' ) || exit;

interface ImporterInterface {

	public function id(): string;

	public function label(): string;

	public function detect(): ImportDetection;

	public function scan_page( int $limit, int $after_attachment_id = 0 ): ImportScanPage;

	/**
	 * Exact retained source keys for one attachment (empty when source has none).
	 *
	 * @return list<ImportedObjectDescriptor>
	 */
	public function descriptors_for_attachment( int $attachment_id ): array;
}
