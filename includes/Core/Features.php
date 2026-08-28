<?php
/**
 * Product feature flags (Lite / Pro style).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Core;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\Module\ModuleRegistry;

/**
 * Gates optional capabilities. Default plan is Lite (Free core only) — a clean
 * WordPress.org install with no Pro add-on must not unlock Pro-tier features.
 * The Pro add-on registers a module via ModuleRegistry on plugins_loaded, and
 * that registration is the ONLY thing that can make is_pro_active() true —
 * there is no production override (no constant, no filter) that can report
 * Pro active without the separate Pro plugin actually being installed and
 * active. This is deliberate: a plan-override mechanism independent of the
 * real Pro module would be indistinguishable from a license-key/trialware
 * unlock, which WordPress.org's guidelines prohibit for a "free" plugin.
 */
final class Features {

	public const PLAN_LITE = 'lite';
	public const PLAN_PRO  = 'pro';

	/**
	 * @return list<string>
	 */
	public static function pro_feature_keys(): array {
		return array(
			'multisite_network',
			'multiple_profiles',
			'storage_profile_migration',
			'orphan_scan',
			'advanced_health',
		);
	}

	/**
	 * Whether Pro tier is active. Sole source of truth: the Pro add-on
	 * registered a module via ModuleRegistry.
	 */
	public static function is_pro_active(): bool {
		return ModuleRegistry::instance()->has_pro_module();
	}

	/**
	 * Display-only plan label, derived from is_pro_active() — not an
	 * independent source of truth, and not itself something that gates
	 * anything.
	 */
	public static function plan(): string {
		return self::is_pro_active() ? self::PLAN_PRO : self::PLAN_LITE;
	}

	/**
	 * Whether a named feature is available.
	 *
	 * @param string $feature Feature key.
	 */
	public static function enabled( string $feature ): bool {
		$pro_only = self::pro_feature_keys();

		$allowed = true;
		if ( in_array( $feature, $pro_only, true ) && ! self::is_pro_active() ) {
			$allowed = false;
		}

		/**
		 * Filter whether a feature is enabled.
		 *
		 * @param bool   $allowed Whether enabled.
		 * @param string $feature Feature key.
		 * @param string $plan    Active plan.
		 */
		return (bool) apply_filters( 'kazus_feature_enabled', $allowed, $feature, self::plan() );
	}
}
