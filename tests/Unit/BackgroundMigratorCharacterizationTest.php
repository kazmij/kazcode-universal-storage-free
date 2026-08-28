<?php
/**
 * Characterization: BackgroundMigrator job shape / resume cursor.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Services\AuditLog;
use Kazcode\WpStorage\Services\BackgroundMigrator;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class BackgroundMigratorCharacterizationTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_status_defaults_include_resume_fields(): void {
		$migrator = new BackgroundMigrator(
			$this->createMock(Settings::class),
			$this->createMock(AuditLog::class)
		);
		$status = $migrator->status();

		foreach (array( 'running', 'action', 'after_id', 'processed', 'success', 'failed', 'started_at', 'updated_at', 'last_error', 'finished_at' ) as $field) {
			$this->assertArrayHasKey($field, $status);
		}
		$this->assertFalse($status['running']);
		$this->assertSame(0, $status['after_id']);
		$this->assertSame(BackgroundMigrator::OPTION_KEY, 's3ms_background_job');
		$this->assertSame(BackgroundMigrator::CRON_HOOK, 'kazus_background_tick');
	}

	public function test_persisted_after_id_is_returned_by_status(): void {
		WpStubs::$options[ BackgroundMigrator::OPTION_KEY ] = array(
			'running'   => true,
			'action'    => 'migrate',
			'after_id'  => 1500,
			'processed' => 40,
			'success'   => 38,
			'failed'    => 2,
		);

		$migrator = new BackgroundMigrator(
			$this->createMock(Settings::class),
			$this->createMock(AuditLog::class)
		);
		$status = $migrator->status();

		$this->assertTrue($status['running']);
		$this->assertSame(1500, $status['after_id']);
		$this->assertSame('migrate', $status['action']);
	}

	public function test_stop_clears_running_and_sets_finished_at(): void {
		WpStubs::$options[ BackgroundMigrator::OPTION_KEY ] = array(
			'running'  => true,
			'action'   => 'verify',
			'after_id' => 10,
		);
		$audit = $this->createMock(AuditLog::class);
		$audit->expects($this->once())->method('record')->with('background_stop', $this->anything());

		$migrator = new BackgroundMigrator($this->createMock(Settings::class), $audit);
		$job      = $migrator->stop();

		$this->assertFalse($job['running']);
		$this->assertNotSame('', $job['finished_at']);
	}

	public function test_process_batch_skips_when_tick_lease_held(): void {
		WpStubs::$options[ BackgroundMigrator::OPTION_KEY ] = array(
			'running'  => true,
			'action'   => 'migrate',
			'after_id' => 0,
		);
		WpStubs::$options[ BackgroundMigrator::TICK_LEASE_KEY ] = array(
			'at'      => time(),
			'expires' => time() + 120,
		);

		$migrator = new BackgroundMigrator(
			$this->createMock( Settings::class ),
			$this->createMock( AuditLog::class )
		);

		$this->assertFalse( $migrator->process_batch() );
	}
}
