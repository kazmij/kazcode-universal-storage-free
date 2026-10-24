<?php
/**
 * RepairAttachmentService lease re-read coverage.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Infrastructure\AttachmentLeaseHandle;
use Kazcode\WpStorage\Infrastructure\AttachmentLock;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Services\RepairAttachmentService;
use Kazcode\WpStorage\Services\RepairObjectService;
use Kazcode\WpStorage\Storage\S3Storage;
use Kazcode\WpStorage\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class RepairAttachmentServiceTest extends TestCase {

	private string $uploads;

	protected function setUp(): void {
		WpStubs::reset();
		$this->uploads            = sys_get_temp_dir() . '/s3ms-repair-att-' . uniqid( '', true );
		WpStubs::$uploads_basedir = $this->uploads;
		wp_mkdir_p( $this->uploads . '/2026/08' );
	}

	protected function tearDown(): void {
		$this->rmTree( $this->uploads );
		WpStubs::reset();
	}

	public function test_reloads_inventory_after_acquiring_lease(): void {
		$relative = '2026/08/photo.jpg';
		file_put_contents( $this->uploads . '/' . $relative, 'bytes' );

		$healthy = array(
			'id'                  => 1,
			'attachment_id'       => 55,
			'storage_profile_id'  => 1,
			'local_relative_path' => $relative,
			'object_key'          => 'uploads/' . $relative,
			'variant_type'        => 'original',
			'remote_status'       => ObjectRemoteStatus::PRESENT,
			'verified_at'         => gmdate( 'Y-m-d H:i:s' ),
		);
		$missing = array_merge( $healthy, array( 'remote_status' => ObjectRemoteStatus::MISSING, 'verified_at' => null ) );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->willReturnOnConsecutiveCalls(
			array( $healthy ), // pre-lock stale snapshot
			array( $missing )  // post-lock truth
		);

		$lease = new AttachmentLeaseHandle( 55, 'repair-tok', 1, 'repair', time() + 300 );
		$lock  = $this->createMock( AttachmentLock::class );
		$lock->method( 'acquire_lease' )->willReturn( $lease );
		$lock->method( 'renew' )->willReturn( true );
		$lock->expects( $this->once() )->method( 'release_lease' )->with( $lease );

		$inner = $this->createMock( RepairObjectService::class );
		$inner->expects( $this->once() )
			->method( 'repair' )
			->with( 1, false, $lease )
			->willReturn(
				array(
					'success' => true,
					'message' => 'repaired',
					'health'  => 'healthy',
				)
			);

		$storage = $this->createMock( S3Storage::class );
		$result  = ( new RepairAttachmentService( $storage, $objects, $inner, $lock ) )->repair( 55, false );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['repaired'] );
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
