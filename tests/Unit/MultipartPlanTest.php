<?php
/**
 * Multipart transfer sizing plan.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Storage\MultipartPlan;
use PHPUnit\Framework\TestCase;

final class MultipartPlanTest extends TestCase {

	public function test_uses_single_put_below_threshold(): void {
		$plan = MultipartPlan::for_size( 99 * 1024 * 1024, 100 * 1024 * 1024, 64 * 1024 * 1024 );

		$this->assertFalse( $plan->uses_multipart() );
		$this->assertSame( 0, $plan->part_size_bytes() );
		$this->assertSame( 1, $plan->part_count() );
	}

	public function test_forced_multipart_below_threshold_uses_a_valid_single_part_plan(): void {
		$plan = MultipartPlan::for_forced_multipart_size( 11, 100 * 1024 * 1024, 64 * 1024 * 1024 );

		$this->assertTrue( $plan->uses_multipart() );
		$this->assertSame( 64 * 1024 * 1024, $plan->part_size_bytes() );
		$this->assertSame( 1, $plan->part_count() );
	}

	public function test_starts_multipart_at_exact_threshold(): void {
		$plan = MultipartPlan::for_size( 100 * 1024 * 1024, 100 * 1024 * 1024, 64 * 1024 * 1024 );

		$this->assertTrue( $plan->uses_multipart() );
		$this->assertSame( 64 * 1024 * 1024, $plan->part_size_bytes() );
		$this->assertSame( 2, $plan->part_count() );
	}

	public function test_normal_planning_keeps_its_threshold_boundary(): void {
		$threshold = 100 * 1024 * 1024;

		$this->assertFalse( MultipartPlan::for_size( $threshold - 1, $threshold, 5 * 1024 * 1024 )->uses_multipart() );
		$this->assertTrue( MultipartPlan::for_size( $threshold, $threshold, 5 * 1024 * 1024 )->uses_multipart() );
		$this->assertTrue( MultipartPlan::for_size( $threshold + 1, $threshold, 5 * 1024 * 1024 )->uses_multipart() );
	}

	public function test_uses_requested_default_part_size_when_safe(): void {
		$plan = MultipartPlan::for_size( 256 * 1024 * 1024, 100 * 1024 * 1024, 64 * 1024 * 1024 );

		$this->assertTrue( $plan->uses_multipart() );
		$this->assertSame( 64 * 1024 * 1024, $plan->part_size_bytes() );
		$this->assertSame( 4, $plan->part_count() );
	}

	public function test_grows_part_size_to_stay_within_max_parts(): void {
		$size = 10001 * 5 * 1024 * 1024;
		$plan = MultipartPlan::for_size( $size, 100 * 1024 * 1024, 5 * 1024 * 1024 );

		$this->assertTrue( $plan->uses_multipart() );
		$this->assertSame( 6 * 1024 * 1024, $plan->part_size_bytes() );
		$this->assertSame( 8335, $plan->part_count() );
	}
}
