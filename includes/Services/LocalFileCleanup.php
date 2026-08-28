<?php
/**
 * Deletes local attachment files under uploads jail.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Shared local cleanup after verified remote durability.
 */
final class LocalFileCleanup {

	/**
	 * @param list<string> $absolute_paths Absolute filesystem paths.
	 */
	public static function delete_files( array $absolute_paths ): void {
		foreach ( $absolute_paths as $path ) {
			$uploads = wp_upload_dir();
			$basedir = realpath( (string) $uploads['basedir'] );
			$real    = realpath( $path );
			if ( $basedir === false || $real === false ) {
				continue;
			}
			$basedir = rtrim( str_replace( '\\', '/', $basedir ), '/' );
			$real    = str_replace( '\\', '/', $real );
			if ( $real !== $basedir && ! str_starts_with( $real, $basedir . '/' ) ) {
				continue;
			}
			if ( is_file( $real ) ) {
				wp_delete_file( $real );
			}
		}
	}
}
