<?php
/**
 * Plugin stale cleanup scheduler tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Plugin;
use Kazcode\WpStorage\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class PluginStaleCleanupScheduleTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_stale_transfer_cleanup_daily_hook_registration_is_idempotent(): void {
		$plugin = ( new \ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod( Plugin::class, 'register_stale_transfer_cleanup' );

		$method->invoke( $plugin );
		$first_scheduled_at = WpStubs::$scheduled_hooks['kazus_stale_transfer_cleanup'] ?? 0;
		$method->invoke( $plugin );

		$this->assertGreaterThan( time(), $first_scheduled_at );
		$this->assertSame( $first_scheduled_at, WpStubs::$scheduled_hooks['kazus_stale_transfer_cleanup'] );
		$this->assertArrayHasKey( 'kazus_stale_transfer_cleanup', $GLOBALS['s3ms_test_filters'] );
	}
}
