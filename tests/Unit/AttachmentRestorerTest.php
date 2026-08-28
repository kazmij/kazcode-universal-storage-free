<?php
/**
 * AttachmentRestorer unit tests (v2 acceptance criterion 4).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Attachment\AttachmentRestorer;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Storage\S3KeyResolver;
use Kazcode\WpStorage\Storage\S3Storage;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class AttachmentRestorerTest extends TestCase {

	private string $uploads;

	protected function setUp(): void {
		WpStubs::reset();
		$this->uploads = sys_get_temp_dir() . '/s3ms-restore-' . uniqid( '', true );
		wp_mkdir_p( $this->uploads . '/2026/08' );
		WpStubs::$uploads_basedir = $this->uploads;
	}

	protected function tearDown(): void {
		$this->rmTree( $this->uploads );
		WpStubs::reset();
	}

	public function test_successful_restore_clears_offload_meta_and_object_rows(): void {
		$id = 42;
		WpStubs::$post_meta[ $id ]['_wp_attached_file']  = '2026/08/photo.jpg';
		WpStubs::$post_meta[ $id ]['_s3ms_status']       = 'offloaded';
		WpStubs::$post_meta[ $id ]['_s3ms_original_key'] = 'uploads/2026/08/photo.jpg';

		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_aws_configured' )->willReturn( true );

		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		$storage->method( 'head_relative' )->willReturn( array( 'exists' => true ) );
		$storage->method( 'download_relative' )->willReturnCallback(
			static function ( string $relative, string $absolute ): void {
				file_put_contents( $absolute, 'bytes' );
			}
		);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )->method( 'delete_by_attachment' )->with( 42 )->willReturn( 1 );

		$restorer = new AttachmentRestorer( $settings, $storage );
		$ref      = new \ReflectionClass( AttachmentRestorer::class );
		$prop     = $ref->getProperty( 'objects' );
		$prop->setAccessible( true );
		$prop->setValue( $restorer, $objects );

		$result = $restorer->restore( $id );

		$this->assertTrue( $result['success'] );
		$this->assertSame( '', (string) get_post_meta( $id, '_s3ms_status', true ) );
		$this->assertSame( '', (string) get_post_meta( $id, '_s3ms_original_key', true ) );
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
