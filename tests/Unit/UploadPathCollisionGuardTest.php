<?php
/** Direct Upload and remote-only path collision regressions. */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\ReservationRepository;
use Kazcode\WpStorage\Services\UploadPathCollisionGuard;
use PHPUnit\Framework\TestCase;

final class UploadPathCollisionGuardTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb'] = new UploadPathCollisionWpdbFake();
	}

	public function test_normal_upload_avoids_a_pinned_direct_upload_relative_path(): void {
		$GLOBALS['wpdb']->pinned = true;
		$guard = new UploadPathCollisionGuard( new ReservationRepository(), new ObjectRepository() );
		$this->assertSame( 'archive-1.zip', $guard->avoid_reserved_filename( 'archive.zip', '.zip', sys_get_temp_dir() . '/s3ms-uploads/2026/09' ) );
	}

	public function test_normal_upload_avoids_remote_only_inventory_path(): void {
		$GLOBALS['wpdb']->present = true;
		$guard = new UploadPathCollisionGuard( new ReservationRepository(), new ObjectRepository() );
		$this->assertSame( 'archive-1.zip', $guard->avoid_reserved_filename( 'archive.zip', '.zip', sys_get_temp_dir() . '/s3ms-uploads/2026/09' ) );
	}

	public function test_path_owned_by_a_missing_or_failed_inventory_row_is_not_reused(): void {
		// Ownership query (any status) matches; a PRESENT-only query would not.
		$GLOBALS['wpdb']->owned_any_status = array( '2026/09/archive.zip' );
		$guard = new UploadPathCollisionGuard( new ReservationRepository(), new ObjectRepository() );
		$this->assertSame( 'archive-1.zip', $guard->avoid_reserved_filename( 'archive.zip', '.zip', sys_get_temp_dir() . '/s3ms-uploads/2026/09' ) );
	}

	public function test_path_of_another_attachments_original_is_not_reused(): void {
		$GLOBALS['wpdb']->attached_files = array( '2026/09/archive.zip' );
		$guard = new UploadPathCollisionGuard( new ReservationRepository(), new ObjectRepository() );
		$this->assertSame( 'archive-1.zip', $guard->avoid_reserved_filename( 'archive.zip', '.zip', sys_get_temp_dir() . '/s3ms-uploads/2026/09' ) );
	}

	public function test_unowned_path_is_kept(): void {
		$guard = new UploadPathCollisionGuard( new ReservationRepository(), new ObjectRepository() );
		$this->assertSame( 'fresh.zip', $guard->avoid_reserved_filename( 'fresh.zip', '.zip', sys_get_temp_dir() . '/s3ms-uploads/2026/09' ) );
	}

	public function test_wordpress_empty_number_is_accepted(): void {
		$GLOBALS['wpdb']->pinned = true;
		$guard = new UploadPathCollisionGuard( new ReservationRepository(), new ObjectRepository() );
		$this->assertSame( 'archive-1.zip', $guard->avoid_reserved_filename( 'archive.zip', '.zip', sys_get_temp_dir() . '/s3ms-uploads/2026/09', null, array(), '' ) );
	}

	public function test_remote_only_alternate_filename_blocks_the_initial_basename(): void {
		$GLOBALS['wpdb']->claimed_paths = array( '2026/09/photo.webp' );
		$guard = new UploadPathCollisionGuard( new ReservationRepository(), new ObjectRepository() );

		$this->assertSame(
			'photo-1.jpg',
			$guard->avoid_reserved_filename(
				'photo.jpg',
				'.jpg',
				sys_get_temp_dir() . '/s3ms-uploads/2026/09',
				null,
				array( '.webp' => 'photo.webp', '.jpg' => 'photo.jpg' ),
				''
			)
		);
	}

	public function test_reserved_image_path_preserves_alternate_filename_collision_checks(): void {
		$GLOBALS['wpdb']->pinned = true;
		$dir = sys_get_temp_dir() . '/s3ms-uploads/2026/09';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		$alternate = $dir . '/photo-1.webp';
		file_put_contents( $alternate, 'collision' );

		try {
			$guard = new UploadPathCollisionGuard( new ReservationRepository(), new ObjectRepository() );
			$this->assertSame(
				'photo-2.jpg',
				$guard->avoid_reserved_filename(
					'photo.jpg',
					'.jpg',
					$dir,
					null,
					array( '.webp' => 'photo.webp', '.jpg' => 'photo.jpg' ),
					''
				)
			);
		} finally {
			@unlink( $alternate );
		}
	}
}

final class UploadPathCollisionWpdbFake {
	public string $prefix = 'wp_';
	public bool $pinned = false;
	public bool $present = false;
	/** @var list<string> */
	public array $claimed_paths = array();
	/** @var list<string> */
	public array $queries = array();
	/** @var list<string> Paths owned by an inventory row in a non-PRESENT state. */
	public array $owned_any_status = array();
	/** @var list<string> Paths that are another attachment's _wp_attached_file. */
	public array $attached_files = array();
	public string $postmeta = 'wp_postmeta';
	public string $posts = 'wp_posts';
	public string $last_error = '';
	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[ds]/', is_int( $arg ) ? (string) $arg : "'" . (string) $arg . "'", $query, 1 ) ?? $query;
		}
		return $query;
	}
	public function get_var( string $query ) {
		$this->queries[] = $query;
		$is_original = str_contains( $query, "'2026/09/archive.zip'" ) || str_contains( $query, "'2026/09/photo.jpg'" );
		$is_claimed_path = false;
		foreach ( $this->claimed_paths as $path ) {
			if ( str_contains( $query, "'" . $path . "'" ) ) {
				$is_claimed_path = true;
				break;
			}
		}
		foreach ( $this->attached_files as $path ) {
			if ( str_contains( $query, '_wp_attached_file' ) && str_contains( $query, "'" . $path . "'" ) ) {
				return 1;
			}
		}
		foreach ( $this->owned_any_status as $path ) {
			if ( str_contains( $query, 's3ms_objects' ) && ! str_contains( $query, 'remote_status' ) && str_contains( $query, "'" . $path . "'" ) ) {
				return 1;
			}
		}
		return str_contains( $query, 's3ms_storage_reservations' )
			? ( ( $this->pinned && $is_original ) || $is_claimed_path ? 1 : null )
			: ( ( $this->present && $is_original ) || $is_claimed_path ? 1 : null );
	}
}
