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

		$storage = $this->createMock( S3Storage::class );
		$storage->expects( $this->never() )->method( 'upload_file_to_key' );
		$storage->expects( $this->never() )->method( 'head_key' );

		$ops = $this->createMock( ProfileAwareObjectOperations::class );
		$ops->expects( $this->once() )
			->method( 'upload_file_for_object_row' )
			->with( $row, $absolute )
			->willReturn( array( 'success' => true, 'head' => array( 'exists' => true ), 'storage_profile_id' => 1 ) );

		$result = ( new RepairObjectService( $storage, $objects, $ops ) )->repair( 7, false );

		$this->assertTrue( $result['success'] );
		$this->assertSame( ObjectHealthState::HEALTHY, $result['health'] );
	}

	public function test_repair_uses_inventory_profile_not_current_default_storage(): void {
		$relative = '2026/08/profile-bound.jpg';
		$absolute = $this->uploads . '/' . $relative;
		file_put_contents( $absolute, 'profile-bound-bytes' );

		$row = array(
			'id'                  => 17,
			'attachment_id'       => 42,
			'storage_profile_id'  => 2,
			'local_relative_path' => $relative,
			'object_key'          => 'r2/' . $relative,
			'variant_type'        => 'original',
			'remote_status'       => ObjectRemoteStatus::MISSING,
		);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_id' )->willReturn( $row );
		$objects->method( 'find_by_attachment' )->willReturn(
			array(
				array_merge( $row, array( 'remote_status' => ObjectRemoteStatus::PRESENT ) ),
			)
		);
		$objects->method( 'upsert' );

		$storage = $this->createMock( S3Storage::class );
		$storage->expects( $this->never() )->method( 'upload_file_to_key' );
		$storage->expects( $this->never() )->method( 'head_key' );

		$ops = $this->createMock( ProfileAwareObjectOperations::class );
		$ops->expects( $this->once() )
			->method( 'upload_file_for_object_row' )
			->with( $row, $absolute )
			->willReturn( array( 'success' => true, 'head' => array( 'exists' => true ), 'storage_profile_id' => 2 ) );

		$result = ( new RepairObjectService( $storage, $objects, $ops ) )->repair( 17, false );

		$this->assertTrue( $result['success'] );
		$this->assertSame( ObjectHealthState::HEALTHY, $result['health'] );
	}

	public function test_repair_does_not_commit_inventory_or_meta_when_lease_is_lost(): void {
		$relative = '2026/08/photo.jpg';
		$absolute = $this->uploads . '/' . $relative;
		file_put_contents( $absolute, 'photo-bytes' );

		$row = array(
			'id'                  => 27,
			'attachment_id'       => 78,
			'storage_profile_id'  => 1,
			'local_relative_path' => $relative,
			'object_key'          => 'uploads/' . $relative,
			'variant_type'        => 'original',
			'remote_status'       => ObjectRemoteStatus::MISSING,
		);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_id' )->willReturn( $row );
		$objects->expects( $this->never() )->method( 'upsert' );
		$objects->expects( $this->never() )->method( 'find_by_attachment' );

		$storage = $this->createMock( S3Storage::class );
		$ops = $this->createMock( ProfileAwareObjectOperations::class );
		$ops->method( 'upload_file_for_object_row' )->willReturn( array( 'success' => true ) );

		$lease = ( new AttachmentLock() )->acquire_lease( 78, 'repair' );
		$this->assertNotNull( $lease );
		$lock = $this->getMockBuilder( AttachmentLock::class )
			->onlyMethods( array( 'acquire_lease', 'renew', 'release_lease' ) )
			->getMock();
		$lock->method( 'acquire_lease' )->willReturn( $lease );
		$lock->method( 'renew' )->willReturn( false );
		$lock->method( 'release_lease' )->willReturn( false );

		$result = ( new RepairObjectService( $storage, $objects, $ops, $lock ) )->repair( 27, false );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'ownership lost', strtolower( (string) ( $result['message'] ?? '' ) ) );
		$this->assertSame( '', (string) get_post_meta( 78, '_s3ms_status', true ) );
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

		$storage = $this->createMock( S3Storage::class );
		$storage->expects( $this->never() )->method( 'upload_file_to_key' );

		$result = ( new RepairObjectService( $storage, $objects ) )->repair( 8, true );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['dry_run'] );
	}

	public function test_skips_when_local_missing(): void {
		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_id' )->willReturn(
			array(
				'id'                  => 9,
				'local_relative_path' => '2026/08/gone.jpg',
				'object_key'          => 'uploads/2026/08/gone.jpg',
				'remote_status'       => ObjectRemoteStatus::MISSING,
			)
		);

		$storage = $this->createMock( S3Storage::class );
		$result  = ( new RepairObjectService( $storage, $objects ) )->repair( 9, false );

		$this->assertFalse( $result['success'] );
		$this->assertSame( ObjectHealthState::LOCAL_MISSING, $result['health'] );
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
