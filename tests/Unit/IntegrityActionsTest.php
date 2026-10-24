<?php
/**
 * Integrity Verify / Repair actions (#28): apply only after a stored dry run.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Admin\IntegrityActions;
use Kazcode\WpStorage\Services\AuditLog;
use Kazcode\WpStorage\Services\DeliveryDriftService;
use Kazcode\WpStorage\Services\PolicyReconciliationService;
use Kazcode\WpStorage\Services\HealthScanService;
use Kazcode\WpStorage\Services\IntegrityVerifyService;
use Kazcode\WpStorage\Services\RepairObjectService;
use Kazcode\WpStorage\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class IntegrityActionsTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_verify_apply_without_dry_run_does_nothing(): void {
		$verify = $this->createMock( IntegrityVerifyService::class );
		$verify->expects( $this->never() )->method( 'apply' );

		$actions = $this->actions( $verify );
		$actions->apply_verify( 1 );

		$this->assertSame( 'Run a dry run before applying.', $actions->load( 'verify', 1 )['error'] );
	}

	public function test_verify_apply_uses_only_rows_with_a_change_from_this_users_dry_run(): void {
		$verify = $this->createMock( IntegrityVerifyService::class );
		$verify->method( 'observe_page' )->willReturn(
			array(
				'scanned' => 3, 'next_after_id' => 3, 'has_more' => false, 'counts' => array(),
				'items'   => array(
					array( 'object_id' => 1, 'change' => IntegrityVerifyService::CHANGE_MARK_MISSING ),
					array( 'object_id' => 2, 'change' => null ),
					array( 'object_id' => 3, 'change' => IntegrityVerifyService::CHANGE_MARK_PRESENT ),
				),
			)
		);
		$verify->expects( $this->once() )->method( 'apply' )->with( array( 1, 3 ) )
			->willReturn( array( 'applied' => 2, 'unchanged' => 0, 'skipped' => 0, 'results' => array() ) );

		$actions = $this->actions( $verify );
		$actions->dry_run_verify( 1, 0 );
		$actions->apply_verify( 2 ); // Another user's apply never sees user 1's dry run.
		$this->assertArrayHasKey( 'error', $actions->load( 'verify', 2 ) );

		$actions->apply_verify( 1 );
		$this->assertSame( 2, $actions->load( 'verify', 1 )['applied']['applied'] );

		// The dry run is consumed: a second apply needs a fresh one.
		$actions->apply_verify( 1 );
		$this->assertArrayHasKey( 'error', $actions->load( 'verify', 1 ) );
	}

	public function test_repair_apply_repairs_dry_run_rows_and_audits_without_paths(): void {
		$health = $this->createMock( HealthScanService::class );
		$health->method( 'repairable_page' )->willReturn(
			array(
				'scanned' => 2, 'next_after_id' => 9, 'has_more' => false,
				'items'   => array(
					array( 'object_id' => 4, 'relative' => '2026/08/a.jpg' ),
					array( 'object_id' => 9, 'relative' => '2026/08/b.jpg' ),
				),
			)
		);
		$repair = $this->createMock( RepairObjectService::class );
		$repair->expects( $this->exactly( 2 ) )->method( 'repair' )->willReturnCallback(
			static fn( int $id, bool $dry ): array => $dry ? array() : ( $id === 4 ? array( 'success' => true, 'message' => 'ok' ) : array( 'success' => false, 'message' => 'Size verification failed after repair upload.' ) )
		);
		$audit = $this->createMock( AuditLog::class );
		$audit->expects( $this->once() )->method( 'record' )->with(
			'integrity_repair_apply',
			array( 'requested' => 2, 'repaired' => 1, 'failed' => 1 )
		);

		$actions = new IntegrityActions( null, static fn() => $health, static fn() => $repair, $audit );
		$actions->dry_run_repair( 1, 0 );
		$actions->apply_repair( 1 );

		$applied = $actions->load( 'repair', 1 )['applied'];
		$this->assertSame( 1, $applied['repaired'] );
		$this->assertSame( 1, $applied['failed'] );
		$this->assertArrayNotHasKey( 'report', $actions->load( 'repair', 1 ) );
	}

	public function test_drift_scan_stores_a_report_for_this_user_only(): void {
		$drift = $this->createMock( DeliveryDriftService::class );
		$drift->expects( $this->once() )->method( 'scan_page' )->with( IntegrityActions::DRIFT_PAGE, 40 )
			->willReturn( array( 'scanned' => 1, 'next_after_id' => 41, 'has_more' => false, 'counts' => array(), 'findings' => array() ) );

		$actions = new IntegrityActions( null, null, null, $this->createMock( AuditLog::class ), static fn() => $drift );
		$actions->scan_drift( 1, 40 );

		$this->assertSame( 41, $actions->load( 'drift', 1 )['report']['next_after_id'] );
		$this->assertSame( array(), $actions->load( 'drift', 2 ) );
	}

	public function test_policy_apply_needs_this_users_dry_run_and_consumes_it(): void {
		$findings = array( array( 'attachment_id' => 5, 'relative' => 'a.jpg', 'finding' => 'local_missing', 'repairable' => true ) );
		$policy   = $this->createMock( PolicyReconciliationService::class );
		$policy->method( 'scan_page' )->willReturn( array( 'policy' => 'keep_all', 'scanned' => 1, 'next_after_id' => 5, 'has_more' => false, 'site' => array(), 'findings' => $findings ) );
		$policy->expects( $this->once() )->method( 'apply' )->with( $findings )->willReturn( array( 'applied' => 1, 'skipped' => 0, 'failed' => 0, 'results' => array() ) );

		$actions = new IntegrityActions( null, null, null, $this->createMock( AuditLog::class ), null, static fn() => $policy );
		$actions->apply_policy( 1 );
		$this->assertArrayHasKey( 'error', $actions->load( 'policy', 1 ) );

		$actions->dry_run_policy( 1, 0 );
		$actions->apply_policy( 1 );
		$this->assertSame( 1, $actions->load( 'policy', 1 )['applied']['applied'] );
		$actions->apply_policy( 1 );
		$this->assertArrayHasKey( 'error', $actions->load( 'policy', 1 ) );
	}

	private function actions( IntegrityVerifyService $verify ): IntegrityActions {
		return new IntegrityActions( static fn() => $verify, null, null, $this->createMock( AuditLog::class ) );
	}
}
