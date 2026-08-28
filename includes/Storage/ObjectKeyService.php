<?php
/**
 * Canonical object-key normalization and prefix join.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Single authority for prefix + relative path → object key.
 */
final class ObjectKeyService {

	/**
	 * Normalize object prefix: no leading slash; trailing slash if non-empty.
	 */
	public static function normalize_prefix( string $prefix ): string {
		$prefix = str_replace( '\\', '/', trim( $prefix ) );
		$prefix = ltrim( $prefix, '/' );
		if ( $prefix === '' ) {
			return '';
		}
		if ( str_contains( $prefix, '..' ) ) {
			throw new \InvalidArgumentException( 'Object prefix must not contain path traversal.' );
		}
		return rtrim( $prefix, '/' ) . '/';
	}

	/**
	 * Join prefix + relative uploads path into an S3 object key.
	 */
	public static function key_for( string $prefix, string $relative_path ): string {
		$relative = PathGuard::normalize_relative( $relative_path );
		$prefix   = self::normalize_prefix( $prefix );
		return $prefix . $relative;
	}

	/**
	 * Soft join; empty string if relative path invalid.
	 */
	public static function try_key_for( string $prefix, string $relative_path ): string {
		try {
			return self::key_for( $prefix, $relative_path );
		} catch ( \InvalidArgumentException $e ) {
			return '';
		}
	}
}
