<?php
/** Direct Upload must not reuse local or WordPress-owned attachment paths. */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class ObjectRepositoryUploadPathOwnershipTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb'] = new ObjectRepositoryUploadPathOwnershipWpdbFake();
	}

	public function test_owned_path_in_any_inventory_state_is_unavailable(): void {
		$GLOBALS['wpdb']->inventory_owned = true;
		$this->assertTrue( ( new ObjectRepository() )->has_owned_relative_path( '2026/10/archive.zip' ) );
	}

	public function test_wordpress_attachment_meta_ownership_is_unavailable_without_inventory(): void {
		$GLOBALS['wpdb']->attachment_owned = true;
		$this->assertTrue( ( new ObjectRepository() )->has_owned_relative_path( '2026/10/archive.zip' ) );
	}

	public function test_absent_path_is_available(): void {
		$this->assertFalse( ( new ObjectRepository() )->has_owned_relative_path( '2026/10/available.zip' ) );
	}

	public function test_inventory_lookup_failure_fails_closed(): void {
		$GLOBALS['wpdb']->inventory_read_failure = true;
		$this->assertTrue( ( new ObjectRepository() )->has_owned_relative_path( '2026/10/archive.zip' ) );
	}

	public function test_attachment_lookup_failure_fails_closed(): void {
		$GLOBALS['wpdb']->attachment_read_failure = true;
		$this->assertTrue( ( new ObjectRepository() )->has_owned_relative_path( '2026/10/archive.zip' ) );
	}

	public function test_existing_local_upload_is_unavailable_without_inventory_or_attachment_meta(): void {
		$directory = sys_get_temp_dir() . '/kazus-owned-upload-' . uniqid( '', true );
		mkdir( $directory . '/2026/10', 0777, true );
		file_put_contents( $directory . '/2026/10/archive.zip', 'owned' );
		WpStubs::$uploads_basedir = $directory;

		try {
			$this->assertTrue( ( new ObjectRepository() )->has_owned_relative_path( '2026/10/archive.zip' ) );
		} finally {
			@unlink( $directory . '/2026/10/archive.zip' );
			@rmdir( $directory . '/2026/10' );
			@rmdir( $directory );
			WpStubs::$uploads_basedir = '';
		}
	}
}

final class ObjectRepositoryUploadPathOwnershipWpdbFake {
	public string $prefix = 'wp_';
	public string $postmeta = 'wp_postmeta';
	public string $posts = 'wp_posts';
	public bool $inventory_owned = false;
	public bool $attachment_owned = false;
	public bool $inventory_read_failure = false;
	public bool $attachment_read_failure = false;
	public string $last_error = '';
	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[ds]/', is_int( $arg ) ? (string) $arg : "'" . (string) $arg . "'", $query, 1 ) ?? $query;
		}
		return $query;
	}
	public function get_var( string $query ) {
		if ( str_contains( $query, 's3ms_objects' ) ) {
			if ( $this->inventory_read_failure ) { $this->last_error = 'Injected inventory ownership lookup failure'; return null; }
			return $this->inventory_owned ? 1 : null;
		}
		if ( $this->attachment_read_failure ) { $this->last_error = 'Injected attachment ownership lookup failure'; return null; }
		return $this->attachment_owned ? 1 : null;
	}
}
