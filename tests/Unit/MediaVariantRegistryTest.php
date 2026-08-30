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

	public function test_provider_registered_by_hook_contributes_to_first_manifest_build(): void {
		WpStubs::set_meta( 4, '_wp_attached_file', '2026/08/photo.jpg' );
		WpStubs::set_meta(
			4,
			'_wp_attachment_metadata',
			array(
				'file'  => '2026/08/photo.jpg',
				'sizes' => array(),
			)
		);

		$provider = new class() implements MediaVariantProviderInterface {
			public int $calls = 0;

			public function contribute( int $attachment_id, ?array $metadata_override = null ): array {
				++$this->calls;
				return array(
					array(
						'relative'     => '2026/08/photo.avif',
						'variant_type' => 'avif',
					),
				);
			}
		};

		add_action(
			'kazus_register_variant_providers',
			static function ( MediaVariantRegistry $registry ) use ( $provider ): void {
				$registry->register( $provider );
			}
		);

		$registry = new MediaVariantRegistry();
		$manifest = ( new ManifestBuilder( null, $registry ) )->build( 4 );

		$this->assertContains( '2026/08/photo.avif', $manifest->relative_paths() );
		$this->assertSame( 1, $provider->calls );

		$second = ( new ManifestBuilder( null, $registry ) )->build( 4 );
		$this->assertContains( '2026/08/photo.avif', $second->relative_paths() );
		$this->assertSame( 2, $provider->calls, 'Provider registration must not accumulate duplicates across builds.' );
	}
}
