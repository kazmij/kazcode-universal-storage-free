<?php
/**
 * Extension point Pro uses to hand its implementations back to Core.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Core never references a Pro class directly (that would fatal a Free-only
 * install). Instead Pro registers factory closures on plugins_loaded via the
 * `kazus_pro_service_factory` filter, keyed by a stable string id, and Core
 * asks for an instance by id (passing whatever constructor args that service
 * needs — typically Settings and/or a storage gateway). No registered
 * factory (Pro absent, or Pro didn't provide that id) is a normal outcome,
 * not an error — callers decide how to degrade (typically the same
 * "requires Pro" message ProFeatureGate used to throw).
 */
final class ProServices {

	/**
	 * @param string $id   Stable service id, e.g. "storage_migration".
	 * @param mixed  ...$args Constructor args forwarded to the Pro factory.
	 */
	public static function get( string $id, ...$args ): ?object {
		/**
		 * Filter to supply a factory closure for the given service id.
		 *
		 * @param callable|null $factory Default (none).
		 * @param string        $id      Service id being requested.
		 */
		$factory = apply_filters( 'kazus_pro_service_factory', null, $id );
		if ( ! is_callable( $factory ) ) {
			return null;
		}
		$service = $factory( ...$args );
		return is_object( $service ) ? $service : null;
	}

	/**
	 * @param mixed $args Constructor args forwarded to the Pro factory.
	 * @throws \RuntimeException When no Pro implementation is registered for $id.
	 */
	public static function require( string $id, ...$args ): object {
		$service = self::get( $id, ...$args );
		if ( $service === null ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by callers and returned only in a WP_CLI::error()/WP_REST_Response JSON payload, never echoed as HTML.
			throw new \RuntimeException( sprintf( 'Feature "%s" requires KAZCODE Universal Storage Pro.', $id ) );
		}
		return $service;
	}
}
