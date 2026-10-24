<?php
/**
 * RepairObjectService — remote_missing repair when local exists (P6-T06).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Domain\ObjectHealthState;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Infrastructure\AttachmentLeaseHandle;
use Kazcode\WpStorage\Infrastructure\AttachmentLock;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Services\ProfileAwareObjectOperations;
use Kazcode\WpStorage\Services\RepairObjectService;
use Kazcode\WpStorage\Storage\S3Storage;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class RepairObjectServiceTest extends TestCase {

	private string $uploads;

	protected function setUp(): void {
		WpStubs::reset();
		$this->uploads = sys_get_temp_dir() . '/s3ms-repair-' . uniqid( '', true );
		wp_mkdir_p( $this->uploads . '/2026/08' );
		WpStubs::$uploads_basedir = $this->uploads;
	}

	protected function tearDown(): void {
		$this->rmTree( $this->uploads );
		WpStubs::reset();
	}

	public function test_reuploads_when_remote_missing_and_local_exists(): void {
		$relative = '2026/08/photo.jpg';
		$absolute = $this->uploads . '/' . $relative;
		file_put_contents( $absolute, 'photo-bytes' );

		$row = array(
			'id'                  => 7,
			'attachment_id'       => 42,
			'storage_profile_id'  => 1,
			'local_relative_path' => $relative,
			'object_key'          => 'uploads/' . $relative,
			'variant_type'        => 'original',
			'remote_status'       => ObjectRemoteStatus::MISSING,
		);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_id' )->willReturn( $row );
		$objects->expects( $this->once() )
			->method( 'upsert' )
			->with(
				$this->callback(
					static function ( array $data ): bool {
						return ( $data['remote_status'] ?? '' ) === ObjectRemoteStatus::PRESENT
							&& ! empty( $data['verified_at'] );
					}
				)
			);
		$objects->method( 'find_by_attachment' )->willReturn(
			array(
				array_merge(
					$row,
					array(
						'remote_status' => ObjectRemoteStatus::PRESENT,
						'verified_at'   => gmdate( 'Y-m-d H:i:s' ),
					)
				),
			)
		);

		$locations = $this->createMock( ProfileAwareObjectOperations::class );
		$locations->expects( $this->once() )
			->method( 'upload_file_for_object_row' )
			->with( $this->callback( static fn( array $r ): bool => (int) $r['id'] === 7 ), $absolute )
			->willReturn( array( 'success' => true, 'head' => array( 'exists' => true, 'content_length' => 11 ), 'storage_profile_id' => 1 ) );

		$result = ( new RepairObjectService( $this->createMock( S3Storage::class ), $objects, $this->open_lock(), $locations ) )->repair( 7, false );

		$this->assertTrue( $result['success'] );
		$this->assertSame( ObjectHealthState::HEALTHY, $result['health'] );
	}

	public function test_dry_run_does_not_upload(): void {
		$relative = '2026/08/photo.jpg';
		file_put_contents( $this->uploads . '/' . $relative, 'x' );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_id' )->willReturn(
			array(
				'id'                  => 8,
				'attachment_id'       => 1,
				'storage_profile_id'  => 1,
				'local_relative_path' => $relative,
				'object_key'          => 'uploads/' . $relative,
				'remote_status'       => ObjectRemoteStatus::FAILED,
			)
		);

		$result = ( new RepairObjectService( $this->createMock( S3Storage::class ), $objects, $this->open_lock(), $this->no_upload() ) )->repair( 8, true );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['dry_run'] );
	}

	public function test_skips_when_local_missing(): void {
		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_id' )->willReturn(
			array(
				'id'                  => 9,
				'attachment_id'       => 1,
				'local_relative_path' => '2026/08/gone.jpg',
				'object_key'          => 'uploads/2026/08/gone.jpg',
				'remote_status'       => ObjectRemoteStatus::MISSING,
			)
		);

		$storage = $this->createMock( S3Storage::class );
		$result  = ( new RepairObjectService( $storage, $objects, $this->open_lock() ) )->repair( 9, false );

		$this->assertFalse( $result['success'] );
		$this->assertSame( ObjectHealthState::LOCAL_MISSING, $result['health'] );
	}

	public function test_refuses_when_attachment_lease_held(): void {
		$relative = '2026/08/photo.jpg';
		file_put_contents( $this->uploads . '/' . $relative, 'photo-bytes' );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_id' )->willReturn(
			array(
				'id'                  => 10,
				'attachment_id'       => 77,
				'storage_profile_id'  => 1,
				'local_relative_path' => $relative,
				'object_key'          => 'uploads/' . $relative,
				'variant_type'        => 'original',
				'remote_status'       => ObjectRemoteStatus::MISSING,
			)
		);
		$objects->expects( $this->never() )->method( 'upsert' );

		$lease = new AttachmentLeaseHandle( 77, 'rebuild-token', 1, 'rebuild', time() + 300 );
		$lock  = $this->createMock( AttachmentLock::class );
		$lock->method( 'acquire_lease' )->willReturn( null );
		$lock->expects( $this->never() )->method( 'renew' );

		$result = ( new RepairObjectService( $this->createMock( S3Storage::class ), $objects, $lock, $this->no_upload() ) )->repair( 10, false );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'locked', $result['message'] );
		unset( $lease );
	}

	public function test_does_not_write_when_lease_lost_before_upsert(): void {
		$relative = '2026/08/photo.jpg';
		$absolute = $this->uploads . '/' . $relative;
		file_put_contents( $absolute, 'photo-bytes' );

		$row = array(
			'id'                  => 11,
			'attachment_id'       => 88,
			'storage_profile_id'  => 1,
			'local_relative_path' => $relative,
			'object_key'          => 'uploads/' . $relative,
			'variant_type'        => 'original',
			'remote_status'       => ObjectRemoteStatus::MISSING,
		);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_id' )->willReturn( $row );
		$objects->expects( $this->never() )->method( 'upsert' );

		$lease = new AttachmentLeaseHandle( 88, 'repair-token', 2, 'repair', time() + 300 );
		$lock  = $this->createMock( AttachmentLock::class );
		$lock->method( 'acquire_lease' )->willReturn( $lease );
		$lock->method( 'renew' )->willReturn( false );
		$lock->expects( $this->once() )->method( 'release_lease' )->with( $lease );

		$result = ( new RepairObjectService( $this->createMock( S3Storage::class ), $objects, $lock, $this->no_upload() ) )->repair( 11, false );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'lease lost', $result['message'] );
	}

	public function test_aborts_when_row_deleted_after_lease_acquired(): void {
		$relative = '2026/08/photo.jpg';
		file_put_contents( $this->uploads . '/' . $relative, 'photo-bytes' );

		$pre_lock = array(
			'id'                  => 12,
			'attachment_id'       => 99,
			'storage_profile_id'  => 1,
			'local_relative_path' => $relative,
			'object_key'          => 'uploads/' . $relative,
			'variant_type'        => 'original',
			'remote_status'       => ObjectRemoteStatus::MISSING,
		);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_id' )->willReturnOnConsecutiveCalls( $pre_lock, null );
		$objects->expects( $this->never() )->method( 'upsert' );

		$lease = new AttachmentLeaseHandle( 99, 'repair-token', 3, 'repair', time() + 300 );
		$lock  = $this->createMock( AttachmentLock::class );
		$lock->method( 'acquire_lease' )->willReturn( $lease );
		$lock->method( 'renew' )->willReturn( true );
		$lock->expects( $this->once() )->method( 'release_lease' )->with( $lease );

		$result = ( new RepairObjectService( $this->createMock( S3Storage::class ), $objects, $lock, $this->no_upload() ) )->repair( 12, false );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not found after acquiring', $result['message'] );
	}

	public function test_size_mismatch_after_upload_fails_without_marking_present(): void {
		$relative = '2026/08/photo.jpg';
		$absolute = $this->uploads . '/' . $relative;
		file_put_contents( $absolute, 'photo-bytes' );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_id' )->willReturn( $this->missing_row( 13, $relative ) );
		$written = array();
		$objects->method( 'upsert' )->willReturnCallback(
			static function ( array $d ) use ( &$written ): int {
				$written[] = $d['remote_status'] ?? '';
				return 1;
			}
		);

		$locations = $this->createMock( ProfileAwareObjectOperations::class );
		$locations->method( 'upload_file_for_object_row' )->willReturn(
			array( 'success' => false, 'head' => array( 'exists' => true, 'content_length' => 3 ) )
		);

		$result = ( new RepairObjectService( $this->createMock( S3Storage::class ), $objects, $this->open_lock(), $locations ) )->repair( 13, false );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Size verification failed', $result['message'] );
		$this->assertNotContains( ObjectRemoteStatus::PRESENT, $written );
	}

	public function test_unresolved_profile_does_not_fall_back_to_default_storage(): void {
		$relative = '2026/08/photo.jpg';
		file_put_contents( $this->uploads . '/' . $relative, 'photo-bytes' );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_id' )->willReturn( $this->missing_row( 14, $relative ) );

		$storage = $this->createMock( S3Storage::class );
		$storage->expects( $this->never() )->method( 'upload_file_to_key' );
		$locations = $this->createMock( ProfileAwareObjectOperations::class );
		$locations->method( 'upload_file_for_object_row' )->willReturn( array( 'success' => false, 'location_status' => 'profile_not_found' ) );

		$result = ( new RepairObjectService( $storage, $objects, $this->open_lock(), $locations ) )->repair( 14, false );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'profile_not_found', $result['message'] );
	}

	public function test_deleted_row_is_not_repairable(): void {
		$relative = '2026/08/photo.jpg';
		file_put_contents( $this->uploads . '/' . $relative, 'photo-bytes' );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_id' )->willReturn( array_merge( $this->missing_row( 15, $relative ), array( 'remote_status' => ObjectRemoteStatus::DELETED ) ) );
		$objects->expects( $this->never() )->method( 'upsert' );

		$result = ( new RepairObjectService( $this->createMock( S3Storage::class ), $objects, $this->open_lock(), $this->no_upload() ) )->repair( 15, false );

		$this->assertFalse( $result['success'] );
		$this->assertSame( ObjectHealthState::STALE, $result['health'] );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function missing_row( int $id, string $relative ): array {
		return array(
			'id'                  => $id,
			'attachment_id'       => 42,
			'storage_profile_id'  => 1,
			'local_relative_path' => $relative,
			'object_key'          => 'uploads/' . $relative,
			'variant_type'        => 'original',
			'remote_status'       => ObjectRemoteStatus::MISSING,
			'size_bytes'          => 11,
		);
	}

	private function no_upload(): ProfileAwareObjectOperations {
		$locations = $this->createMock( ProfileAwareObjectOperations::class );
		$locations->expects( $this->never() )->method( 'upload_file_for_object_row' );
		return $locations;
	}

	private function open_lock(): AttachmentLock {
		$lease = new AttachmentLeaseHandle( 42, 'repair-token', 1, 'repair', time() + 300 );
		$lock  = $this->createMock( AttachmentLock::class );
		$lock->method( 'acquire_lease' )->willReturnCallback(
			static function ( int $attachment_id, string $operation ) use ( $lease ): AttachmentLeaseHandle {
				unset( $operation );
				return new AttachmentLeaseHandle( $attachment_id, $lease->owner_token, $lease->generation, 'repair', time() + 300 );
			}
		);
		$lock->method( 'renew' )->willReturn( true );
		$lock->method( 'release_lease' )->willReturn( true );
		return $lock;
	}

	private function rmTree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}
}
