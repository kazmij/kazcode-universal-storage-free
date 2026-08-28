<?php
/**
 * ProFeatureGate and service-level gate tests (Phase 9).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\Features;
use Kazcode\WpStorage\Core\ProFeatureGate;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class ProFeatureGateTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
		add_filter(
			'kazus_plan',
			static function (): string {
				return Features::PLAN_LITE;
			}
		);
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_require_throws_on_lite_plan(): void {
		$this->expectException( \RuntimeException::class );
		ProFeatureGate::require( 'orphan_scan' );
	}

	// Service-level "returns error when gated" coverage for orphan scan /
	// provider migration now lives with those services in
	// kazcode-universal-storage-pro/tests/Unit/ (they physically moved there — see
	// docs/FREE-PRO-CODE-AUDIT.md). ProServicesTest covers the Core-side
	// "no Pro service registered" degradation path.
}
