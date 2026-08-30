<?php
/**
 * Profile-bound object location tests.
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
use Kazcode\WpStorage\Services\ProfileObjectLocation;
use Kazcode\WpStorage\Services\ProfileObjectLocator;

final class ProfileObjectLocatorTest extends TestCase {

	public function test_present_row_wins_over_stale_row_after_migration(): void {
		$locator = $this->locator(
			array(
				$this->row( 1, ObjectRemoteStatus::STALE, 'old/2026/08/photo.jpg' ),
				$this->row( 2, ObjectRemoteStatus::PRESENT, 'new/2026/08/photo.jpg' ),
			)
		);

		$location = $locator->locate( 7, '2026/08/photo.jpg' );

		$this->assertSame( ProfileObjectLocation::FOUND, $location->status );
		$this->assertSame( 2, $location->storage_profile->id );
		$this->assertSame( 'new/2026/08/photo.jpg', $location->object_key );
	}

	public function test_competing_present_rows_are_ambiguous(): void {
		$locator = $this->locator(
			array(
				$this->row( 1, ObjectRemoteStatus::PRESENT, 'a/2026/08/photo.jpg' ),
				$this->row( 2, ObjectRemoteStatus::PRESENT, 'b/2026/08/photo.jpg' ),
			)
		);

		$location = $locator->locate( 7, '2026/08/photo.jpg' );

		$this->assertSame( ProfileObjectLocation::AMBIGUOUS_OBJECT_LOCATION, $location->status );
	}

	public function test_same_object_key_on_two_profiles_is_ambiguous(): void {
		$locator = $this->locator(
			array(
				$this->row( 1, ObjectRemoteStatus::PRESENT, 'shared/key.jpg' ),
				$this->row( 2, ObjectRemoteStatus::PRESENT, 'shared/key.jpg' ),
			)
		);

		$location = $locator->locate( 7, '2026/08/photo.jpg' );

		$this->assertSame( ProfileObjectLocation::AMBIGUOUS_OBJECT_LOCATION, $location->status );
	}

	public function test_missing_profile_does_not_fall_back_to_default_profile(): void {
		$locator = $this->locator(
			array(
				$this->row( 99, ObjectRemoteStatus::PRESENT, 'lost/2026/08/photo.jpg' ),
			),
			array()
		);

		$location = $locator->locate( 7, '2026/08/photo.jpg' );

		$this->assertSame( ProfileObjectLocation::PROFILE_MISSING, $location->status );
		$this->assertSame( 99, $location->storage_profile_id );
	}

	public function test_no_inventory_is_explicitly_distinct_from_profile_failure(): void {
		$locator = $this->locator( array() );

		$location = $locator->locate( 7, '2026/08/photo.jpg' );

		$this->assertSame( ProfileObjectLocation::NOT_IN_INVENTORY, $location->status );
	}

	public function test_repeated_variant_resolution_reuses_attachment_rows_and_profile(): void {
		$rows = array(
			$this->row( 1, ObjectRemoteStatus::PRESENT, 'remote/2026/08/photo.jpg', '2026/08/photo.jpg' ),
			$this->row( 1, ObjectRemoteStatus::PRESENT, 'remote/2026/08/photo-300x225.jpg', '2026/08/photo-300x225.jpg' ),
			$this->row( 1, ObjectRemoteStatus::PRESENT, 'remote/2026/08/photo-1024x768.jpg', '2026/08/photo-1024x768.jpg' ),
		);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )->method( 'find_by_attachment' )->with( 7 )->willReturn( $rows );

		$profile_repo = $this->createMock( WpdbStorageProfileRepository::class );
		$profile_repo->expects( $this->once() )->method( 'find' )->with( 1 )->willReturn( $this->profile( 1 ) );

		$locator = new ProfileObjectLocator( $objects, $profile_repo );

		for ( $i = 0; $i < 20; ++$i ) {
			$this->assertSame( ProfileObjectLocation::FOUND, $locator->locate( 7, '2026/08/photo.jpg' )->status );
			$this->assertSame( ProfileObjectLocation::FOUND, $locator->locate( 7, '2026/08/photo-300x225.jpg' )->status );
			$this->assertSame( ProfileObjectLocation::FOUND, $locator->locate( 7, '2026/08/photo-1024x768.jpg' )->status );
		}
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @param array<int, StorageProfile>|null $profiles
	 */
	private function locator( array $rows, ?array $profiles = null ): ProfileObjectLocator {
		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->with( 7 )->willReturn( $rows );

		if ( $profiles === null ) {
			$profiles = array(
				1 => $this->profile( 1 ),
				2 => $this->profile( 2 ),
			);
		}
		$profile_repo = $this->createMock( WpdbStorageProfileRepository::class );
		$profile_repo->method( 'find' )->willReturnCallback(
			static fn( int $id ): ?StorageProfile => $profiles[ $id ] ?? null
		);

		return new ProfileObjectLocator( $objects, $profile_repo );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row( int $profile_id, string $status, string $key, string $relative = '2026/08/photo.jpg' ): array {
		return array(
			'id'                  => $profile_id,
			'attachment_id'       => 7,
			'storage_profile_id'  => $profile_id,
			'object_key'          => $key,
			'local_relative_path' => $relative,
			'remote_status'       => $status,
		);
	}

	private function profile( int $id ): StorageProfile {
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
			'ref-' . $id,
			false,
			false,
			false,
			'',
			''
		);
	}
}
