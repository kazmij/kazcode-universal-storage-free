<?php
/**
 * ManifestBuilder characterization / unit tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Domain\ManifestBuilder;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class ManifestBuilderTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_builds_original_and_sizes_from_metadata(): void {
		WpStubs::set_meta( 5, '_wp_attached_file', '2026/08/photo.jpg' );
		WpStubs::set_meta(
			5,
			'_wp_attachment_metadata',
			array(
				'file'  => '2026/08/photo.jpg',
				'sizes' => array(
					'thumbnail' => array( 'file' => 'photo-150x150.jpg' ),
					'medium'    => array( 'file' => 'photo-300x200.jpg' ),
				),
			)
		);

		$manifest = ( new ManifestBuilder() )->build( 5 );
		$paths    = $manifest->relative_paths();

		$this->assertSame(
			array(
				'2026/08/photo.jpg',
				'2026/08/photo-150x150.jpg',
				'2026/08/photo-300x200.jpg',
			),
			$paths
		);

		$original = array_values(
			array_filter(
				$manifest->items(),
				static fn( array $i ): bool => $i['variant_type'] === 'original'
			)
		);
		$this->assertCount( 1, $original );
		$this->assertSame( '2026/08/photo.jpg', $original[0]['relative'] );
	}

	/**
	 * HEIC / big-image retention: WP keeps source as metadata.original_image while
	 * `_wp_attached_file` points at the generated JPEG used for delivery.
	 */
	public function test_includes_original_image_sibling_for_heic_style_retention(): void {
		WpStubs::set_meta( 11, '_wp_attached_file', '2026/09/vacation.jpg' );
		WpStubs::set_meta(
			11,
			'_wp_attachment_metadata',
			array(
				'file'           => '2026/09/vacation.jpg',
				'original_image' => 'vacation.heic',
				'sizes'          => array(
					'thumbnail' => array( 'file' => 'vacation-150x150.jpg' ),
				),
			)
		);

		$paths = ( new ManifestBuilder() )->build( 11 )->relative_paths();

		$this->assertSame(
			array(
				'2026/09/vacation.jpg',
				'2026/09/vacation-150x150.jpg',
				'2026/09/vacation.heic',
			),
			$paths
		);
	}

	/**
	 * Image Editor crop/rotate leaves superseded files in `_wp_attachment_backup_sizes`.
	 * Expected-file model must still enumerate them so offload/verify/cleanup can see them.
	 */
	public function test_includes_image_editor_backup_sizes(): void {
		WpStubs::set_meta( 12, '_wp_attached_file', '2026/09/edit-me.jpg' );
		WpStubs::set_meta(
			12,
			'_wp_attachment_metadata',
			array(
				'file'  => '2026/09/edit-me.jpg',
				'sizes' => array(
					'thumbnail' => array( 'file' => 'edit-me-150x150.jpg' ),
				),
			)
		);
		WpStubs::set_meta(
			12,
			'_wp_attachment_backup_sizes',
			array(
				'full-orig'           => array( 'file' => 'edit-me-backup.jpg' ),
				'thumbnail-orig'      => array( 'file' => 'edit-me-150x150-backup.jpg' ),
			)
		);

		$paths = ( new ManifestBuilder() )->build( 12 )->relative_paths();

		$this->assertContains( '2026/09/edit-me.jpg', $paths );
		$this->assertContains( '2026/09/edit-me-150x150.jpg', $paths );
		$this->assertContains( '2026/09/edit-me-backup.jpg', $paths );
		$this->assertContains( '2026/09/edit-me-150x150-backup.jpg', $paths );
	}

	/**
	 * Optimizer sidecars are not hard-coded; integrations use `kazus_manifest_files`.
	 */
	public function test_kazus_manifest_files_filter_adds_webp_avif_sidecars(): void {
		WpStubs::set_meta( 13, '_wp_attached_file', '2026/09/photo.jpg' );
		WpStubs::set_meta(
			13,
			'_wp_attachment_metadata',
			array(
				'file'  => '2026/09/photo.jpg',
				'sizes' => array(),
			)
		);

		add_filter(
			'kazus_manifest_files',
			static function ( array $extra ): array {
				$extra[] = array(
					'relative'     => '2026/09/photo.webp',
					'variant_type' => 'webp',
				);
				$extra[] = array(
					'relative'     => '2026/09/photo.avif',
					'variant_type' => 'avif',
				);
				return $extra;
			},
			10,
			1
		);

		$paths = ( new ManifestBuilder() )->build( 13 )->relative_paths();
		$this->assertContains( '2026/09/photo.webp', $paths );
		$this->assertContains( '2026/09/photo.avif', $paths );
	}

	/**
	 * Core expected-file model does not invent animated-video / poster metadata keys.
	 * Those remain importer/integration concerns unless contributed via filter/provider.
	 */
	public function test_does_not_auto_include_animated_video_or_poster_metadata_keys(): void {
		WpStubs::set_meta( 14, '_wp_attached_file', '2026/09/clip.gif' );
		WpStubs::set_meta(
			14,
			'_wp_attachment_metadata',
			array(
				'file'                  => '2026/09/clip.gif',
				'animated_video'        => 'clip.mp4',
				'animated_video_poster' => 'clip-poster.jpg',
				'sizes'                 => array(),
			)
		);

		$paths = ( new ManifestBuilder() )->build( 14 )->relative_paths();

		$this->assertSame( array( '2026/09/clip.gif' ), $paths );
		$this->assertNotContains( '2026/09/clip.mp4', $paths );
		$this->assertNotContains( '2026/09/clip-poster.jpg', $paths );
	}
}
