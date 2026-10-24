<?php
/**
 * Temp-file download install with optional size validation.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Download → temp → optional size check → atomic rename.
 *
 * expected_size null = unknown (skip size check). expected_size 0 is valid and must match.
 */
final class AtomicLocalDownload {

	/**
	 * @param callable(string):void $writer Writes the full object to the given temp path.
	 */
	public static function install( string $local_path, callable $writer, ?int $expected_size = null ): void {
		$dir = dirname( $local_path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new \RuntimeException( 'Unable to create local directory for download.' );
		}

		// Short independent temp name — do not append to the media basename
		// (near filesystem NAME_MAX that would break SaveAs).
		$temp_path = $dir . '/.kazus-dl-' . uniqid( '', true );

		try {
			$writer( $temp_path );

			if ( $expected_size !== null ) {
				$actual = is_file( $temp_path ) ? filesize( $temp_path ) : false;
				if ( $actual === false || (int) $actual !== $expected_size ) {
					throw new \RuntimeException(
						'Downloaded size does not match expected size (expected '
						. $expected_size . ', got ' . ( $actual === false ? 'missing' : (string) (int) $actual ) . ').'
					);
				}
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rename -- PathGuard-jailed local install.
			if ( ! @rename( $temp_path, $local_path ) ) {
				throw new \RuntimeException( 'Unable to install downloaded file into destination.' );
			}
		} catch ( \Throwable $e ) {
			if ( is_file( $temp_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- owned temp cleanup.
				@unlink( $temp_path );
			}
			if ( $e instanceof \RuntimeException ) {
				throw $e;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- callers catch Throwable; never echoed as HTML.
			throw new \RuntimeException( $e->getMessage(), 0, $e );
		}
	}
}
