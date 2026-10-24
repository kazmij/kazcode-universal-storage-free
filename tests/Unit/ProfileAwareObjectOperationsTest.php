<?php
/**
 * Profile-aware object operation tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
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

		$r2  = $this->profile( 2 );
		$row = array(
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

	public function test_download_uses_bound_profile_gateway_download_key_to_local(): void {
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
			->method( 'download_key_to_local' )
			->with( 'profile-b/2026/08/photo.jpg', '/tmp/photo.jpg' );

		$legacy = $this->createMock( S3Storage::class );
		$legacy->expects( $this->never() )->method( 'download_relative' );

		$ops = new ProfileAwareObjectOperations(
			$locator,
			static fn( StorageProfile $profile ): ProfileStorageGateway => $gateway,
			$legacy
		);

		$result = $ops->download_attachment_relative_to_local( 7, '2026/08/photo.jpg', '/tmp/photo.jpg' );

		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['legacy_fallback'] );
	}

	public function test_multipart_cleanup_retries_an_already_absent_upload_after_a_later_failure(): void {
		$profile = $this->profile( 2 );
		$GLOBALS['wpdb'] = new ProfileLookupWpdbFake( $profile->to_row() + array( 'id' => 2 ) );
		$fingerprint = hash( 'sha256', json_encode( array( 'profile_id' => $profile->id, 'provider_type' => $profile->provider_type, 'bucket' => $profile->bucket, 'region' => $profile->region, 'endpoint' => $profile->endpoint, 'path_style' => 0, 'prefix' => '', 'credentials_ref' => $profile->credentials_ref ), JSON_THROW_ON_ERROR ) );
		$gateway = $this->createMock( ProfileStorageGateway::class );
		$abort_calls = 0;
		$delete_calls = 0;
		$gateway->method( 'abort_multipart_upload' )->willReturnCallback( static function () use ( &$abort_calls ): void {
			++$abort_calls;
			if ( $abort_calls === 2 ) throw new class( 'already absent' ) extends \RuntimeException { public function getAwsErrorCode(): string { return 'NoSuchUpload'; } };
		} );
		$gateway->method( 'list_parts' )->willReturnCallback( static function () use ( &$abort_calls ): array {
			if ( $abort_calls === 2 ) throw new class( 'already absent' ) extends \RuntimeException { public function getAwsErrorCode(): string { return 'NoSuchUpload'; } };
			return array();
		} );
		$gateway->method( 'delete_key' )->willReturnCallback( static function () use ( &$delete_calls ): void {
			++$delete_calls;
			if ( $delete_calls === 1 ) throw new \RuntimeException( 'delete timed out after successful abort' );
		} );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => false, 'confirmed_missing' => true ) );
		$ops = new ProfileAwareObjectOperations( $this->createMock( ProfileObjectLocator::class ), static fn( StorageProfile $resolved ): ProfileStorageGateway => $gateway, null );

		$this->assertFalse( $ops->settle_exact_multipart_cleanup( 2, 'isolated/object', $fingerprint, 'upload-id' ) );
		$this->assertTrue( $ops->settle_exact_multipart_cleanup( 2, 'isolated/object', $fingerprint, 'upload-id' ) );
		$this->assertSame( 2, $abort_calls );
		$this->assertSame( 2, $delete_calls );
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

final class ProfileLookupWpdbFake {
	public string $prefix = 'wp_';
	/** @param array<string,mixed> $profile */
	public function __construct( private array $profile ) {}
	public function prepare( string $query, mixed ...$args ): string { return $query; }
	public function get_row( string $query, mixed $output = null ): ?array { unset( $query, $output ); return $this->profile; }
}
