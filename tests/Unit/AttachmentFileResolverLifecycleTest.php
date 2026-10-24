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

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
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
}
