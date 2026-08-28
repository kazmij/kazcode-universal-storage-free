<?php
/**
 * CleanupLocalFiles policy tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\LocalStoragePolicy;
use Kazcode\WpStorage\Services\CleanupLocalFiles;
use Kazcode\WpStorage\Storage\S3KeyResolver;
use Kazcode\WpStorage\Storage\S3Storage;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class CleanupLocalFilesTest extends TestCase {

	private string $uploads;

	protected function setUp(): void {
		WpStubs::reset();
		$this->uploads = sys_get_temp_dir() . '/s3ms-cleanup-' . uniqid( '', true );
		wp_mkdir_p( $this->uploads . '/2026/08' );
		WpStubs::$uploads_basedir = $this->uploads;
	}

	protected function tearDown(): void {
		$this->rmTree( $this->uploads );
		WpStubs::reset();
	}

	public function test_keep_all_skips_delete(): void {
		$settings = $this->settings_with_policy( LocalStoragePolicy::KEEP_ALL );
		$files    = $this->sample_files();

		$result = ( new CleanupLocalFiles( $settings, $this->storage_mock( true ) ) )->maybe_cleanup(
			1,
			$files,
			$this->manifest_items(),
			null
		);

		$this->assertTrue( $result['skipped'] );
		$this->assertSame( 0, $result['deleted'] );
		$this->assertFileExists( $files['2026/08/photo.jpg'] );
	}

	public function test_remote_only_deletes_after_verify(): void {
		$settings = $this->settings_with_policy( LocalStoragePolicy::REMOTE_ONLY );
		$files    = $this->sample_files();

		$result = ( new CleanupLocalFiles( $settings, $this->storage_mock( true ) ) )->maybe_cleanup(
			2,
			$files,
			$this->manifest_items(),
			null
		);

		$this->assertFalse( $result['skipped'] );
		$this->assertSame( 2, $result['deleted'] );
		$this->assertSame( array_values( $files ), WpStubs::$deleted_files );
		foreach ( $files as $absolute ) {
			$this->assertFileDoesNotExist( $absolute );
		}
	}

	public function test_keep_originals_deletes_only_sizes(): void {
		$settings = $this->settings_with_policy( LocalStoragePolicy::KEEP_ORIGINALS );
		$files    = $this->sample_files();

		$result = ( new CleanupLocalFiles( $settings, $this->storage_mock( true ) ) )->maybe_cleanup(
			3,
			$files,
			$this->manifest_items(),
			null
		);

		$this->assertFalse( $result['skipped'] );
		$this->assertSame( 1, $result['deleted'] );
		$this->assertFileExists( $files['2026/08/photo.jpg'] );
		$this->assertFileDoesNotExist( $files['2026/08/photo-150.jpg'] );
	}

	public function test_verify_failure_skips_delete(): void {
		$settings = $this->settings_with_policy( LocalStoragePolicy::REMOTE_ONLY );
		$files    = $this->sample_files();

		$result = ( new CleanupLocalFiles( $settings, $this->storage_mock( false ) ) )->maybe_cleanup(
			4,
			$files,
			$this->manifest_items(),
			null
		);

		$this->assertTrue( $result['skipped'] );
		$this->assertStringContainsString( 'verify failure', $result['message'] );
		$this->assertFileExists( $files['2026/08/photo.jpg'] );
	}

	/**
	 * @return array<string, string>
	 */
	private function sample_files(): array {
		$orig = $this->touch( '2026/08/photo.jpg' );
		$size = $this->touch( '2026/08/photo-150.jpg' );
		return array(
			'2026/08/photo.jpg'      => $orig,
			'2026/08/photo-150.jpg'  => $size,
		);
	}

	/**
	 * @return list<array{relative:string,variant_type:string}>
	 */
	private function manifest_items(): array {
		return array(
			array(
				'relative'     => '2026/08/photo.jpg',
				'variant_type' => 'original',
			),
			array(
				'relative'     => '2026/08/photo-150.jpg',
				'variant_type' => 'size',
			),
		);
	}

	private function settings_with_policy( string $policy ): Settings {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'local_storage_policy' )->willReturn( $policy );
		return $settings;
	}

	private function storage_mock( bool $remote_exists ): S3Storage {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'get' )->willReturn( '' );
		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		$storage->method( 'head_relative' )->willReturn( array( 'exists' => $remote_exists ) );
		$storage->method( 'head_key' )->willReturn( array( 'exists' => $remote_exists ) );
		return $storage;
	}

	private function touch( string $relative ): string {
		$absolute = $this->uploads . '/' . $relative;
		file_put_contents( $absolute, 'x' );
		return $absolute;
	}

	private function rmTree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}
}
