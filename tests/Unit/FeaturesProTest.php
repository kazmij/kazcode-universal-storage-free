<?php
/**
 * Features / Pro tier tests (Phase 9).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\Features;
use Kazcode\WpStorage\Core\Module\ModuleInterface;
use Kazcode\WpStorage\Core\Module\ModuleRegistry;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class FeaturesProTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_default_plan_is_lite_and_blocks_pro_features(): void {
		// A clean core-only install (no KAZUS_PLAN override, no Pro module
		// registered) must behave as Free/Lite, not Pro — a WordPress.org
		// install must never ship every capability unlocked by default.
		$this->assertSame( 'lite', Features::plan() );
		$this->assertFalse( Features::is_pro_active() );
		$this->assertFalse( Features::enabled( 'storage_profile_migration' ) );
		$this->assertFalse( Features::enabled( 'orphan_scan' ) );
		$this->assertFalse( Features::enabled( 'multiple_profiles' ) );
	}

	public function test_core_alone_is_not_pro_active(): void {
		$this->assertFalse( Features::is_pro_active() );
	}

	public function test_free_capabilities_stay_on_without_pro(): void {
		// These moved out of pro_feature_keys() during the Free/Pro packaging
		// review: they are core UX (Health page, Media Library integration,
		// first-run wizard) or already-ungated at the REST/CLI layer (failed
		// items). Gating them broke the two most visible parts of the Free
		// product the moment the default plan changed from Pro to Lite.
		foreach ( array( 'diagnostics', 'media_library_actions', 'setup_wizard', 'failed_dashboard' ) as $feature ) {
			$this->assertTrue( Features::enabled( $feature ), "expected {$feature} to stay enabled without Pro" );
		}
	}

	public function test_core_with_active_pro_module_is_pro_active(): void {
		// No KAZUS_PLAN, no kazus_plan filter override — only registering a Pro
		// module (as the real Pro add-on does on plugins_loaded) should unlock
		// Pro-tier capabilities.
		ModuleRegistry::instance()->register(
			new class() implements ModuleInterface {
				public function id(): string {
					return 'pro-stub';
				}

				public function name(): string {
					return 'Pro stub';
				}

				public function is_pro(): bool {
					return true;
				}

				public function boot(): void {
				}
			}
		);

		$this->assertTrue( Features::is_pro_active() );
		$this->assertTrue( Features::enabled( 'orphan_scan' ) );
		$this->assertTrue( Features::enabled( 'multiple_profiles' ) );
	}

	/**
	 * The old KAZUS_PLAN constant / kazus_plan filter override was removed —
	 * neither can make a Free install report Pro active any more. A stray
	 * `kazus_plan` filter left over from an old integration (or deliberately
	 * added by a self-hosted site) must be inert now: Features no longer
	 * reads that filter at all.
	 */
	public function test_kazus_plan_filter_no_longer_has_any_effect(): void {
		add_filter(
			'kazus_plan',
			static function (): string {
				return Features::PLAN_PRO;
			}
		);

		$this->assertFalse( Features::is_pro_active() );
		$this->assertSame( 'lite', Features::plan() );
		$this->assertFalse( Features::enabled( 'multiple_profiles' ) );
		$this->assertFalse( Features::enabled( 'storage_profile_migration' ) );
		$this->assertFalse( Features::enabled( 'orphan_scan' ) );
	}

	/**
	 * kazus_feature_enabled may narrow availability, but must never unlock a
	 * Pro-only capability when the Pro add-on has not registered a module.
	 * A forced true from a filter (or a misbehaving integration) is treated
	 * as a no-op for Pro keys on Free.
	 */
	public function test_feature_enabled_filter_cannot_unlock_pro_without_module(): void {
		add_filter(
			'kazus_feature_enabled',
			static function (): bool {
				return true;
			}
		);

		$this->assertFalse( Features::is_pro_active() );
		foreach ( Features::pro_feature_keys() as $feature ) {
			$this->assertFalse(
				Features::enabled( $feature ),
				"filter must not unlock Pro feature {$feature} without Pro module"
			);
		}
	}

	/**
	 * With a real Pro module registered, the same filter remains useful for
	 * narrowing: integrations may disable a Pro capability site-wide, but
	 * intended Pro features stay available when the filter does not intervene.
	 */
	public function test_feature_enabled_filter_can_narrow_when_pro_active(): void {
		ModuleRegistry::instance()->register(
			new class() implements ModuleInterface {
				public function id(): string {
					return 'pro-stub-narrow';
				}

				public function name(): string {
					return 'Pro stub narrow';
				}

				public function is_pro(): bool {
					return true;
				}

				public function boot(): void {
				}
			}
		);

		$this->assertTrue( Features::is_pro_active() );
		$this->assertTrue( Features::enabled( 'orphan_scan' ) );

		add_filter(
			'kazus_feature_enabled',
			static function ( bool $allowed, string $feature ): bool {
				if ( $feature === 'orphan_scan' ) {
					return false;
				}
				return $allowed;
			},
			10,
			2
		);

		$this->assertFalse( Features::enabled( 'orphan_scan' ) );
		$this->assertTrue( Features::enabled( 'multiple_profiles' ) );
	}
}
