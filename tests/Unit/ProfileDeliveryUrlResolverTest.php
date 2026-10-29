<?php
/**
 * ProfileDeliveryUrlResolver unit tests (v2 acceptance criterion 6).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
use Kazcode\WpStorage\Storage\ProfileDeliveryUrlResolver;
use Kazcode\WpStorage\Storage\PublicUrlResolver;

final class ProfileDeliveryUrlResolverTest extends TestCase {

	public function test_exposes_a_reverse_lookup_for_delivery_urls(): void {
		$this->assertTrue(
			method_exists( ProfileDeliveryUrlResolver::class, 'attachment_id_for_delivery_url' ),
			'A profile-bound delivery URL needs an explicit, fail-closed reverse lookup.'
		);
	}

	public function test_resolves_an_exact_current_cdn_delivery_url_to_its_present_attachment(): void {
		$objects = new class extends ObjectRepository {
			/** @return list<array<string, mixed>> */
			public function find_present_by_profile_and_local_relative( int $profile_id, string $relative ): array {
				return 9 === $profile_id && '2026/10/photo.jpg' === $relative
					? array(
						array(
							'attachment_id'      => 42,
							'storage_profile_id' => 9,
							'local_relative_path' => $relative,
							'object_key'         => 'uploads/' . $relative,
							'remote_status'      => ObjectRemoteStatus::PRESENT,
						)
					)
					: array();
			}
		};
		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'all' )->willReturn( array( $this->cdn_profile( 9, 'https://media.example.test' ) ) );

		$resolver = new ProfileDeliveryUrlResolver( $objects, $profiles );

		$this->assertSame( 42, $resolver->attachment_id_for_delivery_url( 'http://media.example.test/2026/10/photo.jpg?cache=1#preview' ) );
	}

	public function test_resolves_a_cdn_delivery_url_that_includes_the_profile_prefix(): void {
		$objects = new class extends ObjectRepository {
			/** @return list<array<string, mixed>> */
			public function find_present_by_profile_and_local_relative( int $profile_id, string $relative ): array {
				return 10 === $profile_id && '2026/10/photo.jpg' === $relative
					? array(
						array(
							'attachment_id'       => 43,
							'storage_profile_id'  => 10,
							'local_relative_path' => $relative,
							'object_key'          => 'uploads/' . $relative,
							'remote_status'       => ObjectRemoteStatus::PRESENT,
						)
					)
					: array();
			}
		};
		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'all' )->willReturn( array( $this->cdn_profile( 10, 'https://media.example.test', true ) ) );

		$this->assertSame( 43, ( new ProfileDeliveryUrlResolver( $objects, $profiles ) )->attachment_id_for_delivery_url( 'https://media.example.test/uploads/2026/10/photo.jpg' ) );
	}

	public function test_reverse_lookup_rejects_a_foreign_url_and_ambiguous_owners(): void {
		$objects = new class extends ObjectRepository {
			/** @return list<array<string, mixed>> */
			public function find_present_by_profile_and_local_relative( int $profile_id, string $relative ): array {
				if ( 9 !== $profile_id || '2026/10/photo.jpg' !== $relative ) {
					return array();
				}
				return array(
					array( 'attachment_id' => 44, 'storage_profile_id' => 9, 'local_relative_path' => $relative, 'object_key' => 'uploads/' . $relative, 'remote_status' => ObjectRemoteStatus::PRESENT ),
					array( 'attachment_id' => 45, 'storage_profile_id' => 9, 'local_relative_path' => $relative, 'object_key' => 'uploads/' . $relative, 'remote_status' => ObjectRemoteStatus::PRESENT ),
				);
			}
		};
		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'all' )->willReturn( array( $this->cdn_profile( 9, 'https://media.example.test' ) ) );
		$resolver = new ProfileDeliveryUrlResolver( $objects, $profiles );

		$this->assertSame( 0, $resolver->attachment_id_for_delivery_url( 'https://foreign.example.test/2026/10/photo.jpg' ) );
		$this->assertSame( 0, $resolver->attachment_id_for_delivery_url( 'https://media.example.test/2026/10/photo.jpg' ) );
	}

	public function test_reverse_lookup_rejects_malformed_percent_encoding_without_querying_inventory(): void {
		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->never() )->method( 'find_present_by_profile_and_local_relative' );
		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'all' )->willReturn( array( $this->cdn_profile( 9, 'https://media.example.test' ) ) );

		$this->assertSame(
			0,
			( new ProfileDeliveryUrlResolver( $objects, $profiles ) )
				->attachment_id_for_delivery_url( 'https://media.example.test/2026/10/%ZZ.jpg' )
		);
	}

	public function test_uses_profile_cdn_not_live_settings(): void {
		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->willReturn(
			array(
				array(
					'storage_profile_id'  => 2,
					'local_relative_path' => '2026/08/photo.jpg',
					'object_key'          => 'uploads/2026/08/photo.jpg',
					'remote_status'       => ObjectRemoteStatus::PRESENT,
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

	public function test_prefers_present_source_row_over_uploading_destination_during_migration(): void {
		// Catches: routing frontend URLs to an unverified destination row while a
		// provider migration is still uploading or failed before ownership switch.
		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->willReturn(
			array(
				array(
					'storage_profile_id'  => 2,
					'local_relative_path' => '2026/08/photo.jpg',
					'object_key'          => 'dest-prefix/2026/08/photo.jpg',
					'remote_status'       => ObjectRemoteStatus::UPLOADING,
				),
				array(
					'storage_profile_id'  => 1,
					'local_relative_path' => '2026/08/photo.jpg',
					'object_key'          => 'source-prefix/2026/08/photo.jpg',
					'remote_status'       => ObjectRemoteStatus::PRESENT,
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
					'cdn',
					'https://profile-' . $id . '.example',
					true,
					'keys',
					'ref',
					false,
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

		$this->assertSame( 'https://profile-1.example/source-prefix/2026/08/photo.jpg', $url );
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

	/**
	 * A partial attachment may use only a row Verify has confirmed present; a
	 * missing sibling must not qualify the path for delivery (#152).
	 */
	public function test_present_row_eligibility_is_exact_to_the_attachment_relative_path(): void {
		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->with( 31 )->willReturn(
			array(
				array(
					'local_relative_path' => '2026/10/original.jpg',
					'remote_status'       => ObjectRemoteStatus::MISSING,
				),
				array(
					'local_relative_path' => '2026/10/original-300x200.jpg',
					'remote_status'       => ObjectRemoteStatus::PRESENT,
				),
			)
		);

		$resolver = new ProfileDeliveryUrlResolver( $objects );

		$this->assertFalse( $resolver->has_present_attachment_relative( 31, '2026/10/original.jpg' ) );
		$this->assertTrue( $resolver->has_present_attachment_relative( 31, '2026/10/original-300x200.jpg' ) );
	}

	/** Failed and stale duplicates never qualify unless another exact row is present. */
	public function test_present_row_eligibility_rejects_failed_and_stale_rows(): void {
		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->with( 32 )->willReturn(
			array(
				array( 'local_relative_path' => '2026/10/photo.jpg', 'remote_status' => ObjectRemoteStatus::FAILED ),
				array( 'local_relative_path' => '2026/10/photo.jpg', 'remote_status' => ObjectRemoteStatus::STALE ),
			)
		);

		$resolver = new ProfileDeliveryUrlResolver( $objects );
		$this->assertFalse( $resolver->has_present_attachment_relative( 32, '2026/10/photo.jpg' ) );
	}

	private function cdn_profile( int $id, string $base, bool $includes_prefix = false ): StorageProfile {
		return new StorageProfile(
			$id,
			'uuid-' . $id,
			'Profile ' . $id,
			'aws',
			'bucket-' . $id,
			'us-east-1',
			'',
			false,
			'uploads/',
			'cdn',
			$base,
			$includes_prefix,
			'keys',
			'ref',
			true,
			false,
			false,
			'',
			''
		);
	}
}
