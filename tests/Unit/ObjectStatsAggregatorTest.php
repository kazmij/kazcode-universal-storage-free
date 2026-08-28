<?php
/**
 * ObjectStatsAggregator cache tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Services\ObjectStatsAggregator;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class ObjectStatsAggregatorTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_builds_aggregate_counts(): void {
		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'aggregate_remote_status' )->willReturn(
			array(
				ObjectRemoteStatus::PRESENT => 3,
				ObjectRemoteStatus::FAILED  => 1,
			)
		);
		$objects->method( 'total_count' )->willReturn( 4 );

		$stats = ( new ObjectStatsAggregator( $objects ) )->get( true );

		$this->assertSame( 4, $stats['total_objects'] );
		$this->assertSame( 3, $stats['present'] );
		$this->assertSame( 1, $stats['failed'] );
	}
}
