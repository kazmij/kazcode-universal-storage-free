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
}
