<?php
/**
 * Contract coverage for cursor batch-selector query plans.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MigrationServiceCursorQueryContractTest extends TestCase {

	public function test_verify_and_restore_cursor_selectors_skip_total_found_rows_work(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/Services/MigrationService.php' );
		$this->assertIsString( $source );

		$verify_selector = substr( $source, (int) strpos( $source, 'public function verify_batch' ), (int) strpos( $source, 'public function restore_batch' ) - (int) strpos( $source, 'public function verify_batch' ) );
		$restore_selector = substr( $source, (int) strpos( $source, 'public function restore_batch' ) );

		$this->assertStringContainsString( "'no_found_rows'  => true", $verify_selector );
		$this->assertStringContainsString( "'no_found_rows'  => true", $restore_selector );
	}
}
