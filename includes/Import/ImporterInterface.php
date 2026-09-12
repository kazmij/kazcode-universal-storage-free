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
}
