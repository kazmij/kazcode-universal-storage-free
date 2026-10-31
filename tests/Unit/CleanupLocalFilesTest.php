<?php
/**
 * CleanupLocalFiles policy, gate and revalidation tests (#53).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\LocalStoragePolicy;
use Kazcode\WpStorage\Services\CleanupEligibility;
use Kazcode\WpStorage\Services\CleanupLocalFiles;
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

	public function test_keep_all_skips_delete_without_evaluating(): void {
		$settings = $this->settings_with_policy( LocalStoragePolicy::KEEP_ALL );
		$files    = $this->sample_files();
		$eligibility = $this->createMock( CleanupEligibility::class );
		$eligibility->expects( $this->never() )->method( 'evaluate' );

		$result = $this->cleanup( $settings, $eligibility )->maybe_cleanup( 1, $files, $this->manifest_items(), null );

		$this->assertTrue( $result['skipped'] );
		$this->assertSame( 0, $result['deleted'] );
		$this->assertFileExists( $files['2026/08/photo.jpg'] );
	}

	public function test_remote_only_deletes_after_eligibility_and_unchanged_manifest(): void {
		$settings = $this->settings_with_policy( LocalStoragePolicy::REMOTE_ONLY );
		$files    = $this->sample_files();

		$result = $this->cleanup( $settings, $this->eligibility( true ) )->maybe_cleanup( 2, $files, $this->manifest_items(), null, 1 );

		$this->assertFalse( $result['skipped'] );
		$this->assertSame( 2, $result['deleted'] );
		$this->assertSame( array_values( $files ), WpStubs::$deleted_files );
		foreach ( $files as $absolute ) {
			$this->assertFileDoesNotExist( $absolute );
		}
	}

	public function test_profile_and_full_manifest_are_passed_to_eligibility(): void {
		$settings    = $this->settings_with_policy( LocalStoragePolicy::KEEP_ORIGINALS );
		$files       = $this->sample_files();
		$eligibility = $this->createMock( CleanupEligibility::class );
		$eligibility->expects( $this->once() )
			->method( 'evaluate' )
			->with( 3, $this->manifest_items(), $files, array( '2026/08/photo-150.jpg' => $files['2026/08/photo-150.jpg'] ), 9 )
			->willReturn( array( 'eligible' => true, 'reason' => '', 'relative' => '' ) );

		$result = $this->cleanup( $settings, $eligibility )->maybe_cleanup( 3, $files, $this->manifest_items(), null, 9 );

		$this->assertSame( 1, $result['deleted'] );
		$this->assertFileExists( $files['2026/08/photo.jpg'] );
		$this->assertFileDoesNotExist( $files['2026/08/photo-150.jpg'] );
	}

	public function test_blocked_eligibility_keeps_files_and_records_sanitized_reason(): void {
		$settings = $this->settings_with_policy( LocalStoragePolicy::REMOTE_ONLY );
		$files    = $this->sample_files();

		$result = $this->cleanup( $settings, $this->eligibility( false, CleanupEligibility::DELIVERY_NOT_REMOTE, '2026/08/photo.jpg' ) )
			->maybe_cleanup( 4, $files, $this->manifest_items(), null );

		$this->assertTrue( $result['skipped'] );
		$this->assertSame( CleanupEligibility::DELIVERY_NOT_REMOTE, $result['blocked_reason'] );
		$this->assertSame( array(), WpStubs::$deleted_files );
		$this->assertFileExists( $files['2026/08/photo.jpg'] );
		$this->assertSame(
			'Offloaded but local delete skipped (delivery_not_remote: 2026/08/photo.jpg)',
			WpStubs::$post_meta[4]['_s3ms_last_error'] ?? null
		);
	}

	public function test_unverified_hook_contributed_sidecar_blocks_remote_only_cleanup(): void {
		$settings = $this->settings_with_policy( LocalStoragePolicy::REMOTE_ONLY );
		$files    = $this->sample_files();
		$sidecar  = $this->touch( '2026/08/photo.webp' );
		$files['2026/08/photo.webp'] = $sidecar;
		$manifest = array_merge( $this->manifest_items(), array( array( 'relative' => '2026/08/photo.webp', 'variant_type' => 'webp' ) ) );

		$result = $this->cleanup( $settings, $this->eligibility( false, 'remote_not_verified', '2026/08/photo.webp' ) )
			->maybe_cleanup( 41, $files, $manifest, null );

		$this->assertTrue( $result['skipped'] );
		$this->assertSame( 'remote_not_verified', $result['blocked_reason'] );
		$this->assertFileExists( $files['2026/08/photo.jpg'] );
		$this->assertFileExists( $sidecar );
	}

	public function test_metadata_changed_after_verification_blocks_stale_deletion(): void {
		$settings = $this->settings_with_policy( LocalStoragePolicy::REMOTE_ONLY );
		$files    = $this->sample_files();
		$changed  = array_merge(
			$this->manifest_items(),
			array( array( 'relative' => '2026/08/photo-300.jpg', 'variant_type' => 'size' ) )
		);

		$result = $this->cleanup( $settings, $this->eligibility( true ), static fn(): array => $changed )
			->maybe_cleanup( 5, $files, $this->manifest_items(), null );

		$this->assertTrue( $result['skipped'] );
		$this->assertSame( CleanupLocalFiles::MANIFEST_CHANGED, $result['blocked_reason'] );
		$this->assertSame( array(), WpStubs::$deleted_files );
	}

	public function test_manifest_reread_failure_fails_closed(): void {
		$settings = $this->settings_with_policy( LocalStoragePolicy::REMOTE_ONLY );
		$files    = $this->sample_files();

		$result = $this->cleanup(
			$settings,
			$this->eligibility( true ),
			static function (): array {
				throw new \RuntimeException( 'metadata unreadable' );
			}
		)->maybe_cleanup( 6, $files, $this->manifest_items(), null );

		$this->assertSame( CleanupLocalFiles::MANIFEST_CHANGED, $result['blocked_reason'] );
		$this->assertSame( array(), WpStubs::$deleted_files );
	}

	public function test_retry_after_interrupted_cleanup_deletes_remaining_files(): void {
		$settings = $this->settings_with_policy( LocalStoragePolicy::REMOTE_ONLY );
		$files    = $this->sample_files();
		unlink( $files['2026/08/photo-150.jpg'] );
		$remaining = array( '2026/08/photo.jpg' => $files['2026/08/photo.jpg'] );

		$result = $this->cleanup( $settings, $this->eligibility( true ) )->maybe_cleanup( 7, $remaining, $this->manifest_items(), null );

		$this->assertSame( 1, $result['deleted'] );
		$this->assertFileDoesNotExist( $files['2026/08/photo.jpg'] );
	}

	private function cleanup( Settings $settings, CleanupEligibility $eligibility, ?callable $manifest = null ): CleanupLocalFiles {
		$items = $this->manifest_items();
		return new CleanupLocalFiles(
			$settings,
			$this->createMock( S3Storage::class ),
			$eligibility,
			$manifest ?? static fn(): array => $items
		);
	}

	private function eligibility( bool $eligible, string $reason = '', string $relative = '' ): CleanupEligibility {
		$eligibility = $this->createMock( CleanupEligibility::class );
		$eligibility->method( 'evaluate' )->willReturn(
			array(
				'eligible' => $eligible,
				'reason'   => $reason,
				'relative' => $relative,
			)
		);
		return $eligibility;
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
