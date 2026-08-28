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
 * Pro add-on registers a module via ModuleRegistry on plugins_loaded and that
 * is what unlocks Pro-tier features (Features::is_pro_active()).
 * KAZUS_PLAN remains supported as a development/backward-compatibility override
 * (e.g. self-hosted installs that want Pro behavior without the Pro plugin) —
 * it is not, and must never become, the mechanism for commercial entitlement.
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
	 * Whether Pro tier is active (Pro module or pro plan).
	 */
	public static function is_pro_active(): bool {
		if ( ModuleRegistry::instance()->has_pro_module() ) {
			return true;
		}
		return self::plan() === self::PLAN_PRO;
	}

	/**
	 * Active plan slug. Defaults to Lite unless KAZUS_PLAN overrides it — Pro-tier
	 * capabilities come from an active Pro module (see is_pro_active()), not from
	 * this default.
	 */
	public static function plan(): string {
		$plan = defined('KAZUS_PLAN') ? (string) KAZUS_PLAN : self::PLAN_LITE;
		$plan = strtolower($plan);
		if ($plan !== self::PLAN_LITE && $plan !== self::PLAN_PRO) {
			$plan = self::PLAN_LITE;
		}
		/**
		 * Filter the active product plan.
		 *
		 * @param string $plan lite|pro
		 */
		return (string) apply_filters('kazus_plan', $plan);
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
