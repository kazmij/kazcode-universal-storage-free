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
			'direct_upload',
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
	 * Extension filters on `kazus_feature_enabled` are monotonic for Pro-only
	 * keys: they may narrow/disable a capability, but they must never unlock a
	 * Pro-only capability when no Pro module is registered. That keeps the
	 * ModuleRegistry registration as the sole production unlock path.
	 *
	 * @param string $feature Feature key.
	 */
	public static function enabled( string $feature ): bool {
		$pro_only       = self::pro_feature_keys();
		$is_pro_feature = in_array( $feature, $pro_only, true );

		$allowed = true;
		if ( $is_pro_feature && ! self::is_pro_active() ) {
			$allowed = false;
		}

		/**
		 * Filter whether a feature is enabled.
		 *
		 * For Pro-only feature keys, returning true cannot override a false
		 * base value when the Pro add-on is not registered. Use this filter
		 * only to narrow availability (true → false), never to unlock Pro.
		 *
		 * @param bool   $allowed Whether enabled.
		 * @param string $feature Feature key.
		 * @param string $plan    Active plan.
		 */
		$filtered = (bool) apply_filters( 'kazus_feature_enabled', $allowed, $feature, self::plan() );

		if ( $is_pro_feature ) {
			return $allowed && $filtered;
		}

		return $filtered;
	}
}
