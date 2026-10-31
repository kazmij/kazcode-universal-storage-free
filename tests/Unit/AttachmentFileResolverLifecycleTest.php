<?php
/**
 * AttachmentFileResolver lifecycle characterization (Issue #7).
 *
 * Locks the Core expected-file enumeration used by offload/verify/rebuild —
 * characterize first; do not change product behavior to make these green.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Attachment\AttachmentFileResolver;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class AttachmentFileResolverLifecycleTest extends TestCase {

	private string $uploads;

	protected function setUp(): void {
		WpStubs::reset();
		$this->uploads = sys_get_temp_dir() . '/kazus-sidecar-resolver-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->uploads . '/2026/10', 0777, true );
		WpStubs::$uploads_basedir = $this->uploads;
	}

	protected function tearDown(): void {
		if ( is_dir( $this->uploads ) ) {
			$files = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $this->uploads, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $files as $file ) {
				$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
			}
			rmdir( $this->uploads );
		}
		WpStubs::reset();
	}

	public function test_empty_attached_file_yields_empty_list(): void {
		WpStubs::set_meta( 1, '_wp_attached_file', '' );
		$this->assertSame( array(), ( new AttachmentFileResolver() )->relative_paths( 1 ) );
	}

	public function test_metadata_override_is_used_during_generate_attachment_metadata(): void {
		WpStubs::set_meta( 2, '_wp_attached_file', '2026/09/live.jpg' );
		// Stale postmeta on purpose — override must win (regenerate / mid-upload path).
		WpStubs::set_meta(
			2,
			'_wp_attachment_metadata',
			array(
				'file'  => '2026/09/live.jpg',
				'sizes' => array(
					'thumbnail' => array( 'file' => 'live-OLD-150x150.jpg' ),
				),
			)
		);

		$override = array(
			'file'  => '2026/09/live.jpg',
			'sizes' => array(
				'thumbnail' => array( 'file' => 'live-NEW-150x150.jpg' ),
			),
		);

		$paths = ( new AttachmentFileResolver() )->relative_paths( 2, $override );
		$this->assertContains( '2026/09/live-NEW-150x150.jpg', $paths );
		$this->assertNotContains( '2026/09/live-OLD-150x150.jpg', $paths );
	}

	public function test_deduplicates_when_meta_file_matches_attached_file(): void {
		WpStubs::set_meta( 3, '_wp_attached_file', '2026/09/same.jpg' );
		WpStubs::set_meta(
			3,
			'_wp_attachment_metadata',
			array(
				'file'  => '2026/09/same.jpg',
				'sizes' => array(),
			)
		);

		$this->assertSame(
			array( '2026/09/same.jpg' ),
			( new AttachmentFileResolver() )->relative_paths( 3 )
		);
	}

	public function test_existing_local_files_for_manifest_paths_returns_only_safe_existing_sidecars(): void {
		$webp = $this->uploads . '/2026/10/photo.webp';
		file_put_contents( $webp, 'webp-sidecar' );

		$files = ( new AttachmentFileResolver() )->existing_local_files_for_manifest_paths(
			array(
				'2026/10/photo.webp',
				'../outside.avif',
				'2026/10/missing.avif',
			)
		);

		$this->assertSame( array( '2026/10/photo.webp' => $webp ), $files );
	}
}
