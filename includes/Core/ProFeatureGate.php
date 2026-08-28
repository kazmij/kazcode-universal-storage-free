<?php
/**
 * Pro capability gates shared by core services (v2 Phase 9).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Core;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\Module\ModuleRegistry;

/**
 * Throws when a Pro-only operation is attempted on Free core.
 */
final class ProFeatureGate {

	/**
	 * @throws \RuntimeException When feature is unavailable.
	 */
	public static function require( string $feature ): void {
		if ( Features::enabled( $feature ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by callers and returned only in a WP_CLI::error()/WP_REST_Response JSON payload, never echoed as HTML.
		throw new \RuntimeException(
			sprintf(
				'Feature "%s" requires KAZCODE Universal Storage Pro.',
				$feature
			)
		);
	}

	public static function is_pro_active(): bool {
		return Features::is_pro_active();
	}
}
