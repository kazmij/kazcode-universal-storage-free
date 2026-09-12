<?php
/**
 * MediaVariantRegistry unit tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Domain\ManifestBuilder;
use Kazcode\WpStorage\Domain\MediaVariantProviderInterface;
use Kazcode\WpStorage\Domain\MediaVariantRegistry;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class MediaVariantRegistryTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_provider_contributions_merge_into_manifest(): void {
		WpStubs::set_meta( 3, '_wp_attached_file', '2026/08/photo.jpg' );
		WpStubs::set_meta(
			3,
			'_wp_attachment_metadata',
			array(
				'file'  => '2026/08/photo.jpg',
				'sizes' => array(),
			)
		);

		$registry = new MediaVariantRegistry();
		$registry->register(
			new class() implements MediaVariantProviderInterface {
				public function contribute( int $attachment_id, ?array $metadata_override = null ): array {
					return array(
						array(
							'relative'     => '2026/08/photo.webp',
							'variant_type' => 'webp',
						),
					);
				}
			}
		);

		$manifest = ( new ManifestBuilder( null, $registry ) )->build( 3 );
		$this->assertContains( '2026/08/photo.webp', $manifest->relative_paths() );
	}
}
