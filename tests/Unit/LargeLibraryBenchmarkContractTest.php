<?php
/**
 * Contract tests for the large-library benchmark runner.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Services\AuditLog;
use Kazcode\WpStorage\Services\BackgroundMigrator;
use Kazcode\WpStorage\Tests\Support\WpStubs;

require_once dirname( __DIR__ ) . '/benchmarks/large-library.php';

final class LargeLibraryBenchmarkContractTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_fixture_descriptor_accepts_only_declared_production_scales(): void {
		$this->assertSame( 10000, kazus_large_library_fixture_descriptor( 10000 )['scale'] );
		$this->assertSame( 50000, kazus_large_library_fixture_descriptor( 50000 )['scale'] );
		$this->assertSame( 100000, kazus_large_library_fixture_descriptor( 100000 )['scale'] );

		$this->expectException( \InvalidArgumentException::class );
		kazus_large_library_fixture_descriptor( 9999 );
	}

	public function test_fixture_descriptor_uses_a_deterministic_monotonic_id_range(): void {
		$fixture = kazus_large_library_fixture_descriptor( 10000 );

		$this->assertSame( 1000000, $fixture['first_attachment_id'] );
		$this->assertSame( 1009999, $fixture['last_attachment_id'] );
		$this->assertSame( 10000, $fixture['expected_attachment_count'] );
		$this->assertSame( 5000, $fixture['expected_inventory_attachment_count'] );
		$this->assertSame( 'kazus-benchmark-attachment-', $fixture['post_name_prefix'] );
	}

	public function test_fixture_descriptor_reserves_half_of_the_attachments_for_inventory_pagination(): void {
		$fixture = kazus_large_library_fixture_descriptor( 50000 );

		$this->assertSame( 25000, $fixture['expected_inventory_attachment_count'] );
		$this->assertSame( 25000, $fixture['expected_legacy_attachment_count'] );
	}

	public function test_cursor_result_accepts_a_final_partial_page_without_gaps_or_duplicates(): void {
		$result = kazus_large_library_cursor_result(
			array( array( 100, 101 ), array( 102, 103 ), array( 104 ) ),
			5
		);

		$this->assertSame( 'PASS', $result['status'] );
		$this->assertSame( 5, $result['attachment_count'] );
		$this->assertSame( 104, $result['last_attachment_id'] );
		$this->assertSame( array(), $result['findings'] );
	}

	public function test_cursor_result_reports_a_duplicate_as_an_explicit_failure(): void {
		$result = kazus_large_library_cursor_result( array( array( 100, 101 ), array( 101, 102 ) ), 4 );

		$this->assertSame( 'FAIL', $result['status'] );
		$this->assertContains( 'non_monotonic_or_duplicate_cursor', $result['findings'] );
	}

	public function test_measurement_failure_is_an_explicit_result_not_an_omitted_operation(): void {
		$result = kazus_large_library_measure_operation(
			'exact_attachment_lookup',
			static function (): array {
				throw new \RuntimeException( 'simulated database failure' );
			}
		);

		$this->assertSame( 'FAIL', $result['status'] );
		$this->assertSame( 'exact_attachment_lookup', $result['name'] );
		$this->assertSame( 'RuntimeException', $result['error_class'] );
		$this->assertSame( 'simulated database failure', $result['error_message'] );
		$this->assertArrayHasKey( 'wall_time_ms', $result );
		$this->assertArrayHasKey( 'peak_memory_bytes', $result );
	}

	public function test_report_schema_carries_environment_fixture_operations_status_and_findings(): void {
		$report = kazus_large_library_report(
			kazus_large_library_fixture_descriptor( 10000 ),
			array(
				array(
					'name'              => 'cursor_scan',
					'status'            => 'PASS',
					'wall_time_ms'      => 1.25,
					'peak_memory_bytes' => 1024,
					'sql_statement_count' => 2,
				),
			)
		);

		$this->assertSame( 'LOCAL_SYNTHETIC', $report['environment']['evidence_scope'] );
		$this->assertArrayHasKey( 'core_plugin_version', $report['environment'] );
		$this->assertSame( 10000, $report['fixture']['scale'] );
		$this->assertSame( 'PASS', $report['status'] );
		$this->assertSame( array(), $report['findings'] );
		$this->assertCount( 1, $report['operations'] );
	}

	public function test_cursor_explain_summary_retains_estimated_rows_access_type_and_key(): void {
		$summary = kazus_large_library_cursor_explain_summary(
			array(
				array( 'type' => 'range', 'key' => 'type_status_date', 'rows' => '250' ),
				array( 'type' => 'ref', 'key' => 'post_parent', 'rows' => '1' ),
			)
		);

		$this->assertSame( 251, $summary['estimated_rows'] );
		$this->assertSame( array( 'range', 'ref' ), $summary['access_types'] );
		$this->assertSame( array( 'type_status_date', 'post_parent' ), $summary['keys'] );
	}

	public function test_background_characterization_records_local_start_busy_stop_and_restart_without_provider_claims(): void {
		$migrator = new BackgroundMigrator(
			$this->createMock( Settings::class ),
			$this->createMock( AuditLog::class )
		);

		$result = kazus_large_library_background_characterization( $migrator );

		$this->assertSame( 'LOCAL_SYNTHETIC', $result['evidence_scope'] );
		$this->assertFalse( $result['initial_running'] );
		$this->assertTrue( $result['started_running'] );
		$this->assertSame( 's3ms_busy', $result['busy_error_code'] );
		$this->assertFalse( $result['stopped_running'] );
		$this->assertTrue( $result['restarted_running'] );
	}

	public function test_benchmark_covers_the_real_bounded_selection_paths_for_verify_restore_adopt_and_reconciliation(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/benchmarks/large-library.php' );

		foreach ( array( 'verify_selection_cursor_scan', 'restore_selection_cursor_scan', 'adoption_cursor_scan', 'reconciliation_inventory_cursor_scan' ) as $operation ) {
			$this->assertStringContainsString( "'{$operation}'", $source );
		}
		$this->assertStringContainsString( 'new \\Kazcode\\WpStorage\\Services\\AdoptService', $source );
		$this->assertStringContainsString( 'new \\Kazcode\\WpStorage\\Infrastructure\\ObjectRepository', $source );
		$this->assertStringContainsString( "'cache_state' => 'cold'", $source );
		$this->assertStringContainsString( 'wp_cache_flush', $source );
		$verify_selector = substr( $source, (int) strpos( $source, 'function kazus_large_library_verify_cursor_pages' ), (int) strpos( $source, 'function kazus_large_library_restore_cursor_pages' ) - (int) strpos( $source, 'function kazus_large_library_verify_cursor_pages' ) );
		$restore_selector = substr( $source, (int) strpos( $source, 'function kazus_large_library_restore_cursor_pages' ), (int) strpos( $source, 'function kazus_large_library_service_cursor_pages' ) - (int) strpos( $source, 'function kazus_large_library_restore_cursor_pages' ) );
		$this->assertStringContainsString( "'meta_query'", $verify_selector, 'Verify selection must retain its production meta_query shape.' );
		$this->assertStringContainsString( "'meta_key'", $restore_selector, 'Restore selection must retain its production meta_key shape.' );
		$this->assertStringContainsString( 'if ( $after > 0 )', $verify_selector, 'Verify only applies the cursor WHERE clause after its first page.' );
		$this->assertStringContainsString( 'if ( $after > 0 )', $restore_selector, 'Restore only applies the cursor WHERE clause after its first page.' );
		$this->assertStringContainsString( "'no_found_rows'  => true", $verify_selector, 'Verify selection must skip total-row work because its cursor never reads found_posts.' );
		$this->assertStringContainsString( "'no_found_rows'  => true", $restore_selector, 'Restore selection must skip total-row work because its cursor never reads found_posts.' );
	}
}
