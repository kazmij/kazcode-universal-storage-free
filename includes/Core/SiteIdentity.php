<?php
/**
 * Persisted site identity used to detect clones, restores and staging copies (#61).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Records which site this database belongs to: a random id plus the home URL
 * and environment type it was bound on. A copied database keeps the record but
 * runs at another URL or in a non-production environment, which is how a
 * clone is recognised. Nothing secret is stored.
 */
final class SiteIdentity {

	public const OPTION = 's3ms_site_identity';

	/**
	 * Create the record on first boot; never overwrite an existing one.
	 */
	public static function bootstrap(): void {
		if ( self::stored() === null ) {
			self::write( self::new_id() );
		}
	}

	/**
	 * @return array{id:string,home:string,environment:string,bound_at:string}|null
	 */
	public static function stored(): ?array {
		$value = get_option( self::OPTION, null );
		if ( ! is_array( $value ) || empty( $value['id'] ) || ! is_string( $value['id'] ) ) {
			return null;
		}
		return array(
			'id'          => (string) $value['id'],
			'home'        => (string) ( $value['home'] ?? '' ),
			'environment' => (string) ( $value['environment'] ?? '' ),
			'bound_at'    => (string) ( $value['bound_at'] ?? '' ),
		);
	}

	public static function id(): string {
		return (string) ( self::stored()['id'] ?? '' );
	}

	public static function current_home(): string {
		$home = function_exists( 'home_url' ) ? (string) home_url() : '';
		$home = strtolower( (string) preg_replace( '#^https?://#i', '', rtrim( $home, '/' ) ) );
		return $home;
	}

	public static function current_environment(): string {
		return function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : 'production';
	}

	/**
	 * Why this database no longer looks like the site it was bound to, or null.
	 *
	 * @return array{reason:string,detail:string}|null
	 */
	public static function mismatch(): ?array {
		$stored = self::stored();
		if ( $stored === null ) {
			return null;
		}
		$home = self::current_home();
		if ( $stored['home'] !== '' && $home !== '' && $stored['home'] !== $home ) {
			return array(
				'reason' => 'site_url_changed',
				'detail' => sprintf( 'This site runs at %1$s, but its storage settings were bound at %2$s.', $home, $stored['home'] ),
			);
		}
		$environment = self::current_environment();
		if ( $stored['environment'] === 'production' && $environment !== 'production' ) {
			return array(
				'reason' => 'environment_changed',
				'detail' => sprintf( 'This site reports the "%s" environment, but its storage settings were bound on production.', $environment ),
			);
		}
		return null;
	}

	/**
	 * Same site, moved or reconfigured: keep the id, bind to the current URL and environment.
	 */
	public static function rebind(): void {
		self::write( self::id() !== '' ? self::id() : self::new_id() );
	}

	/**
	 * A separate copy: take a new id, so it never claims the original's storage namespace.
	 */
	public static function become_independent(): void {
		self::write( self::new_id() );
	}

	private static function write( string $id ): void {
		update_option(
			self::OPTION,
			array(
				'id'          => $id,
				'home'        => self::current_home(),
				'environment' => self::current_environment(),
				'bound_at'    => gmdate( 'c' ),
			),
			true
		);
	}

	private static function new_id(): string {
		return function_exists( 'wp_generate_uuid4' ) ? (string) wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
	}
}
