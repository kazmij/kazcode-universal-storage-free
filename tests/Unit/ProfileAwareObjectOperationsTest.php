<?php
/**
 * Profile-aware object operation tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\RemoteObservation;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Services\ProfileAwareObjectOperations;
use Kazcode\WpStorage\Services\ProfileObjectLocation;
use Kazcode\WpStorage\Services\ProfileObjectLocator;
use Kazcode\WpStorage\Storage\ProfileStorageGateway;
use Kazcode\WpStorage\Storage\S3Storage;

final class ProfileAwareObjectOperationsTest extends TestCase {

	public function test_head_uses_bound_profile_when_default_profile_changed(): void {
		$r2      = $this->profile( 2 );
		$locator = $this->createMock( ProfileObjectLocator::class );
		$locator->method( 'locate' )->with( 7, '2026/08/photo.jpg' )->willReturn(
			ProfileObjectLocation::found(
				array( 'id' => 9 ),
				$r2,
				'profile-b/2026/08/photo.jpg',
				'2026/08/photo.jpg'
			)
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->once() )
			->method( 'head_key' )
			->with( 'profile-b/2026/08/photo.jpg' )
			->willReturn( array( 'exists' => true, 'content_length' => 123 ) );

		$legacy = $this->createMock( S3Storage::class );
		$legacy->expects( $this->never() )->method( 'head_relative' );

		$ops = new ProfileAwareObjectOperations(
			$locator,
			static fn( StorageProfile $profile ): ProfileStorageGateway => $gateway,
			$legacy
		);

		$result = $ops->head_attachment_relative( 7, '2026/08/photo.jpg' );

		$this->assertTrue( $result['exists'] );
		$this->assertSame( 2, $result['storage_profile_id'] );
		$this->assertFalse( $result['legacy_fallback'] );
	}

	public function test_no_inventory_can_explicitly_fallback_to_legacy_read_path(): void {
		$locator = $this->createMock( ProfileObjectLocator::class );
		$locator->method( 'locate' )->willReturn(
			ProfileObjectLocation::not_in_inventory( '2026/08/photo.jpg' )
		);

		$legacy = $this->createMock( S3Storage::class );
		$legacy->expects( $this->once() )
			->method( 'head_relative' )
			->with( '2026/08/photo.jpg' )
			->willReturn( array( 'exists' => true ) );

		$ops = new ProfileAwareObjectOperations( $locator, null, $legacy );

		$result = $ops->head_attachment_relative( 7, '2026/08/photo.jpg', true );

		$this->assertTrue( $result['exists'] );
		$this->assertTrue( $result['legacy_fallback'] );
	}

	public function test_profile_missing_does_not_fallback_to_legacy_read_path(): void {
		$locator = $this->createMock( ProfileObjectLocator::class );
		$locator->method( 'locate' )->willReturn(
			ProfileObjectLocation::profile_missing( 44, '2026/08/photo.jpg' )
		);

		$legacy = $this->createMock( S3Storage::class );
		$legacy->expects( $this->never() )->method( 'head_relative' );

		$ops = new ProfileAwareObjectOperations( $locator, null, $legacy );

		$result = $ops->head_attachment_relative( 7, '2026/08/photo.jpg', true );

		$this->assertFalse( $result['exists'] );
		$this->assertSame( ProfileObjectLocation::PROFILE_MISSING, $result['location_status'] );
		$this->assertFalse( $result['legacy_fallback'] );
	}

	public function test_presigned_url_uses_bound_profile_gateway(): void {
		$r2      = $this->profile( 2 );
		$locator = $this->createMock( ProfileObjectLocator::class );
		$locator->method( 'locate' )->willReturn(
			ProfileObjectLocation::found(
				array( 'id' => 9 ),
				$r2,
				'r2-prefix/2026/08/private.jpg',
				'2026/08/private.jpg'
			)
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->once() )
			->method( 'presigned_url_for_key' )
			->with( 'r2-prefix/2026/08/private.jpg', 300 )
			->willReturn( 'https://r2.example/private.jpg?test-token=redacted' );

		$legacy = $this->createMock( S3Storage::class );
		$legacy->expects( $this->never() )->method( 'presigned_url_for_relative' );

		$ops = new ProfileAwareObjectOperations(
			$locator,
			static fn( StorageProfile $profile ): ProfileStorageGateway => $gateway,
			$legacy
		);

		$this->assertSame(
			'https://r2.example/private.jpg?test-token=redacted',
			$ops->presigned_url_for_attachment_relative( 7, '2026/08/private.jpg', 300 )
		);
	}

	public function test_upload_file_for_object_row_requires_size_verified_head(): void {
		$local = tempnam( sys_get_temp_dir(), 'kazus-upload-size-' );
		$this->assertIsString( $local );
		file_put_contents( $local, str_repeat( 'x', 1000 ) );

		$r2      = $this->profile( 2 );
		$row     = array(
			'id'                  => 9,
			'attachment_id'       => 7,
			'storage_profile_id'  => 2,
			'local_relative_path' => '2026/08/photo.jpg',
			'object_key'          => 'profile-b/2026/08/photo.jpg',
		);
		$locator = $this->createMock( ProfileObjectLocator::class );
		$locator->method( 'locate_inventory_row' )->with( $row )->willReturn(
			ProfileObjectLocation::found(
				$row,
				$r2,
				'profile-b/2026/08/photo.jpg',
				'2026/08/photo.jpg'
			)
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->once() )->method( 'upload_file_to_key' );
		$gateway->expects( $this->once() )
			->method( 'head_key' )
			->willReturn( array( 'exists' => true, 'content_length' => 900 ) );

		$ops = new ProfileAwareObjectOperations(
			$locator,
			static fn( StorageProfile $profile ): ProfileStorageGateway => $gateway,
			null
		);

		$result = $ops->upload_file_for_object_row( $row, $local );
		unlink( $local );

		$this->assertFalse( $result['success'] );
		$this->assertSame( RemoteObservation::SIZE_MISMATCH, $result['verification_level'] );
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
