<?php
/**
 * ProfileDeliveryUrlResolver unit tests (v2 acceptance criterion 6).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
use Kazcode\WpStorage\Storage\ProfileDeliveryUrlResolver;
use Kazcode\WpStorage\Storage\PublicUrlResolver;

final class ProfileDeliveryUrlResolverTest extends TestCase {

	public function test_uses_profile_cdn_not_live_settings(): void {
		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->willReturn(
			array(
				array(
					'storage_profile_id'  => 2,
					'local_relative_path' => '2026/08/photo.jpg',
					'object_key'          => 'uploads/2026/08/photo.jpg',
				),
			)
		);

		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->willReturn(
			new StorageProfile(
				2,
				'uuid-old',
				'Old CDN Profile',
				'aws',
				'bucket-old',
				'us-east-1',
				'',
				false,
				'uploads/',
				'cdn',
				'https://old.cdn.example',
				false,
				'keys',
				'ref',
				false,
				false,
				false,
				'',
				''
			)
		);

		$fallback = $this->createMock( PublicUrlResolver::class );
		$fallback->expects( $this->never() )->method( 'url_for_relative' );

		$url = ( new ProfileDeliveryUrlResolver( $objects, $profiles, $fallback ) )
			->url_for_attachment_relative( 5, '2026/08/photo.jpg' );

		$this->assertSame( 'https://old.cdn.example/2026/08/photo.jpg', $url );
	}

	public function test_prefers_present_row_over_stale_row_after_migration(): void {
		// Regression: a Pro storage-profile migration marks the old profile's row
		// `stale` rather than deleting it, so the same relative path can match two
		// rows — the old (stale) one on profile A and the new (present) one on
		// profile B. Rows are returned oldest-id-first, so a naive first-match
		// kept resolving to the pre-migration profile's URL right after a
		// successful migration.
		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->willReturn(
			array(
				array(
					'storage_profile_id'  => 1,
					'local_relative_path' => '2026/08/photo.jpg',
					'object_key'          => 'old-prefix/2026/08/photo.jpg',
					'remote_status'       => 'stale',
				),
				array(
					'storage_profile_id'  => 2,
					'local_relative_path' => '2026/08/photo.jpg',
					'object_key'          => 'new-prefix/2026/08/photo.jpg',
					'remote_status'       => 'present',
				),
			)
		);

		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->willReturnCallback(
			static function ( int $id ): StorageProfile {
				return new StorageProfile(
					$id,
					'uuid-' . $id,
					'Profile ' . $id,
					'aws',
					'bucket-' . $id,
					'us-east-1',
					'',
					false,
					'',
					'storage',
					'',
					false,
					'keys',
					'ref',
					$id === 2,
					false,
					false,
					'',
					''
				);
			}
		);

		$fallback = $this->createMock( PublicUrlResolver::class );
		$fallback->expects( $this->never() )->method( 'url_for_relative' );

		$url = ( new ProfileDeliveryUrlResolver( $objects, $profiles, $fallback ) )
			->url_for_attachment_relative( 7, '2026/08/photo.jpg' );

		$this->assertStringContainsString( 'new-prefix/2026/08/photo.jpg', $url );
		$this->assertStringNotContainsString( 'old-prefix', $url );
	}

	public function test_falls_back_when_no_object_rows(): void {
		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->willReturn( array() );

		$fallback = $this->createMock( PublicUrlResolver::class );
		$fallback->expects( $this->once() )
			->method( 'url_for_relative' )
			->with( '2026/08/photo.jpg' )
			->willReturn( 'https://live.example/2026/08/photo.jpg' );

		$url = ( new ProfileDeliveryUrlResolver( $objects, null, $fallback ) )
			->url_for_attachment_relative( 1, '2026/08/photo.jpg' );

		$this->assertSame( 'https://live.example/2026/08/photo.jpg', $url );
	}
}
