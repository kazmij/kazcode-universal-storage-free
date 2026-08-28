<?php
/**
 * Which capabilities are gated must match which capabilities are physically
 * implemented in core vs. Pro — the whole point of docs/FREE-PRO-CODE-AUDIT.md.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\Features;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class FeatureOwnershipTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	/**
	 * Capabilities whose implementation physically moved to
	 * kazcode-universal-storage-pro/includes/ this session — Core has zero classes
	 * left to run these even if the gate were bypassed.
	 */
	public function test_pro_gate_keys_with_no_core_implementation(): void {
		$moved = array( 'storage_profile_migration', 'orphan_scan', 'multisite_network' );
		foreach ( $moved as $key ) {
			$this->assertContains( $key, Features::pro_feature_keys() );
		}
	}

	/**
	 * Capabilities that are Free by definition (core UX, or already ungated
	 * at the REST/CLI layer) must never reappear in pro_feature_keys() — this
	 * is exactly the class of bug the Phase 3 default-plan fix surfaced
	 * (diagnostics/media_library_actions/setup_wizard/failed_dashboard).
	 */
	public function test_free_capabilities_never_gated(): void {
		$free = array(
			'diagnostics',
			'media_library_actions',
			'setup_wizard',
			'failed_dashboard',
			'migrate_existing',
		);
		foreach ( $free as $key ) {
			$this->assertNotContains( $key, Features::pro_feature_keys(), "{$key} must stay Free" );
		}
	}

	/**
	 * multiple_profiles stays a Core-enforced gate on a Core-owned shared
	 * primitive (WpdbStorageProfileRepository) — this is the deliberate
	 * exception the brief calls out: Core may gate a shared abstraction
	 * without that being a "complete premium implementation in Free".
	 */
	public function test_multiple_profiles_is_a_core_owned_gate(): void {
		$this->assertContains( 'multiple_profiles', Features::pro_feature_keys() );
	}
}
