<?php
/**
 * Keeps normal Media Library uploads from reusing Direct Upload or remote-only paths.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\ReservationRepository;

/**
 * wp_unique_filename() only examines local files. Once a file is offloaded,
 * its absent local copy would otherwise allow a second attachment to claim the
 * same relative path. Direct Upload reservations need the same protection.
 *
 * Ownership is not limited to verified PRESENT rows: a row recorded as missing,
 * failed or stale, or another attachment's `_wp_attached_file`, still owns the
 * path and its object key. Reusing it made the new upload's offload fail with
 * "object key is owned by another attachment".
 */
final class UploadPathCollisionGuard {

	public function __construct(
		private ?ReservationRepository $reservations = null,
		private ?ObjectRepository $objects = null,
	) {
		$this->reservations ??= new ReservationRepository();
		$this->objects      ??= new ObjectRepository();
	}

	public function register(): void {
		add_filter( 'wp_unique_filename', array( $this, 'avoid_reserved_filename' ), 20, 6 );
	}

	/**
	 * @param list<string>|array<string,string> $alt_filenames
	 */
	public function avoid_reserved_filename( string $filename, string $ext, string $dir, $unique_filename_callback = null, array $alt_filenames = array(), int|string $number = '' ): string {
		unset( $unique_filename_callback );
		$relative = $this->relative_path( $dir, $filename );
		if (
			$relative === ''
			|| (
				! $this->is_claimed( $relative )
				&& ! $this->alternate_filename_is_claimed( $dir, $alt_filenames, $number )
			)
		) {
			return $filename;
		}

		$extension    = $ext !== '' ? $ext : (string) strrchr( $filename, '.' );
		$stem         = $extension !== '' && str_ends_with( $filename, $extension ) ? substr( $filename, 0, -strlen( $extension ) ) : $filename;
		$start_number = is_int( $number ) ? $number : ( ctype_digit( $number ) ? (int) $number : 0 );

		for ( $suffix = max( 1, $start_number + 1 ); $suffix <= 10000; $suffix++ ) {
			$candidate = $this->renumber_filename( $stem, $extension, $number, $suffix );
			$relative  = $this->relative_path( $dir, $candidate );
			if (
				$relative !== ''
				&& ! is_file( rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $candidate )
				&& ! $this->alternate_filename_exists( $dir, $alt_filenames, $number, $suffix )
				&& ! $this->alternate_filename_is_claimed( $dir, $alt_filenames, $number, $suffix )
				&& ! $this->is_claimed( $relative )
			) {
				return $candidate;
			}
		}

		// WordPress already has its own collision error handling; retaining the
		// generated value is safer than claiming a path after an implausible loop.
		return $filename;
	}

	/**
	 * Keep WordPress' alternate-format collision protection when this filter
	 * has to advance the numeric suffix because a remote path is reserved.
	 *
	 * @param list<string>|array<string,string> $alt_filenames
	 */
	private function alternate_filename_exists( string $dir, array $alt_filenames, int|string $number, int $suffix ): bool {
		foreach ( $alt_filenames as $alt_ext => $alt_filename ) {
			if ( ! is_string( $alt_filename ) || $alt_filename === '' ) {
				continue;
			}

			$extension = is_string( $alt_ext ) && str_starts_with( $alt_ext, '.' )
				? $alt_ext
				: (string) strrchr( $alt_filename, '.' );
			$stem      = $extension !== '' && str_ends_with( $alt_filename, $extension )
				? substr( $alt_filename, 0, -strlen( $extension ) )
				: $alt_filename;
			$candidate = $this->renumber_filename( $stem, $extension, $number, $suffix );

			if ( is_file( rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $candidate ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<string>|array<string,string> $alt_filenames
	 */
	private function alternate_filename_is_claimed( string $dir, array $alt_filenames, int|string $number, ?int $suffix = null ): bool {
		foreach ( $alt_filenames as $alt_ext => $alt_filename ) {
			if ( ! is_string( $alt_filename ) || $alt_filename === '' ) {
				continue;
			}
			$extension = is_string( $alt_ext ) && str_starts_with( $alt_ext, '.' )
				? $alt_ext
				: (string) strrchr( $alt_filename, '.' );
			$stem = $extension !== '' && str_ends_with( $alt_filename, $extension )
				? substr( $alt_filename, 0, -strlen( $extension ) )
				: $alt_filename;
			$candidate = $suffix === null ? $alt_filename : $this->renumber_filename( $stem, $extension, $number, $suffix );
			$relative = $this->relative_path( $dir, $candidate );
			if ( $relative !== '' && $this->is_claimed( $relative ) ) {
				return true;
			}
		}
		return false;
	}

	private function renumber_filename( string $stem, string $extension, int|string $number, int $suffix ): string {
		$current = (string) $number;
		if ( $current !== '' && preg_match( '/-' . preg_quote( $current, '/' ) . '$/', $stem ) ) {
			$stem = preg_replace( '/-' . preg_quote( $current, '/' ) . '$/', '-' . $suffix, $stem ) ?? $stem;
			return $stem . $extension;
		}

		return $stem . '-' . $suffix . $extension;
	}

	private function is_claimed( string $relative ): bool {
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		return $this->reservations->has_pinned_relative_path( max( 1, $blog_id ), $relative )
			|| $this->objects->has_owned_relative_path( $relative );
	}

	private function relative_path( string $dir, string $filename ): string {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}
		$uploads = wp_upload_dir();
		$basedir = str_replace( '\\', '/', (string) ( $uploads['basedir'] ?? '' ) );
		$dir     = rtrim( str_replace( '\\', '/', $dir ), '/' );
		if ( $basedir === '' || ! str_starts_with( $dir . '/', rtrim( $basedir, '/' ) . '/' ) ) {
			return '';
		}
		return ltrim( substr( $dir, strlen( rtrim( $basedir, '/' ) ) ) . '/' . $filename, '/' );
	}
}
