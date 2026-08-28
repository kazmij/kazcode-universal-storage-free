<?php
/**
 * CronQueueAdapter unit tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Infrastructure\Queue\CronQueueAdapter;
use Kazcode\WpStorage\Infrastructure\Queue\JobHandlerRegistry;
use Kazcode\WpStorage\Infrastructure\Queue\Jobs\OffloadAttachmentJobHandler;
use Kazcode\WpStorage\Infrastructure\Queue\QueueJobType;
use Kazcode\WpStorage\Services\AuditLog;
use Kazcode\WpStorage\Services\BackgroundMigrator;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class CronQueueAdapterTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_enqueue_offload_attachment_persists_pending_job(): void {
		$adapter = $this->adapter();
		$job_id  = $adapter->enqueue(
			QueueJobType::OFFLOAD_ATTACHMENT,
			array(
				'attachment_id' => 42,
			)
		);

		$this->assertIsString( $job_id );
		$status = $adapter->status();
		$this->assertSame( 1, $status['pending_count'] );
		$this->assertSame( 'cron', $status['driver'] );
	}

	public function test_run_next_drains_pending_job(): void {
		WpStubs::set_meta( 42, '_s3ms_status', 'offloaded' );

		$adapter = $this->adapter();
		$job_id  = $adapter->enqueue(
			QueueJobType::OFFLOAD_ATTACHMENT,
			array(
				'attachment_id' => 42,
			)
		);
		$this->assertTrue( $adapter->run_next( $job_id ) );
		$this->assertSame( 0, $adapter->status()['pending_count'] );
	}

	public function test_enqueue_bulk_migrate_delegates_to_background_migrator(): void {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'get' )->willReturn( 20 );
		$migrator = new BackgroundMigrator( $settings, $this->createMock( AuditLog::class ) );

		$adapter = new CronQueueAdapter( $migrator, $this->registry() );
		$result  = $adapter->enqueue( QueueJobType::BULK_MIGRATE );

		$this->assertSame( 'bulk:migrate', $result );
		$this->assertTrue( $migrator->status()['running'] );
	}

	public function test_enqueue_bulk_migrate_succeeds_on_free(): void {
		// Background/resumable migration is a Free capability — it belongs to
		// the core Free offload/migrate workflow, not a Pro-gated operation.
		$settings = $this->createMock( Settings::class );
		$settings->method( 'get' )->willReturn( 20 );
		$migrator = new BackgroundMigrator( $settings, $this->createMock( AuditLog::class ) );

		$adapter = new CronQueueAdapter( $migrator, $this->registry() );
		$result  = $adapter->enqueue( QueueJobType::BULK_MIGRATE );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( $migrator->status()['running'] );
	}

	private function adapter(): CronQueueAdapter {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'get' )->willReturn( 20 );
		$migrator = new BackgroundMigrator( $settings, $this->createMock( AuditLog::class ) );
		return new CronQueueAdapter( $migrator, $this->registry() );
	}

	private function registry(): JobHandlerRegistry {
		$registry = new JobHandlerRegistry();
		$registry->register( new OffloadAttachmentJobHandler() );
		return $registry;
	}
}
