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
use Kazcode\WpStorage\Services\ProfileAwareObjectOperations;
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

		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->method( 'head_attachment_relative' )->willReturn( array( 'exists' => true ) );
		$profile_ops->method( 'download_attachment_relative_to_local' )->willReturnCallback(
			static function ( int $attachment_id, string $relative, string $absolute ): array {
				unset( $attachment_id, $relative );
				file_put_contents( $absolute, 'bytes' );
				return array( 'success' => true, 'location_status' => 'test', 'legacy_fallback' => false );
			}
		);

		$restorer = new AttachmentRestorer( $settings, $storage, null, null, $profile_ops );
		$ref      = new \ReflectionClass( AttachmentRestorer::class );
		$prop     = $ref->getProperty( 'objects' );
		$prop->setAccessible( true );
		$prop->setValue( $restorer, $objects );

		$result = $restorer->restore( $id );

		$this->assertTrue( $result['success'] );
		$this->assertSame( '', (string) get_post_meta( $id, '_s3ms_status', true ) );
		$this->assertSame( '', (string) get_post_meta( $id, '_s3ms_original_key', true ) );
	}

	public function test_partial_restore_with_confirmed_missing_does_not_mark_attachment_local(): void {
		$id = 43;
		$this->seed_attachment_with_thumbnail( $id );

		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_aws_configured' )->willReturn( true );

		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->method( 'head_attachment_relative' )->willReturnCallback(
			static fn( int $attachment_id, string $relative ): array => $relative === '2026/08/photo.jpg'
				? array( 'exists' => true )
				: array( 'exists' => false, 'confirmed_missing' => true )
		);
		$profile_ops->method( 'download_attachment_relative_to_local' )->willReturnCallback(
			static function ( int $attachment_id, string $relative, string $absolute ): array {
				unset( $attachment_id );
				if ( $relative === '2026/08/photo.jpg' ) {
					file_put_contents( $absolute, 'bytes' );
					return array( 'success' => true, 'location_status' => 'test', 'legacy_fallback' => false );
				}
				return array( 'success' => false, 'location_status' => 'test', 'legacy_fallback' => false );
			}
		);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->never() )->method( 'delete_by_attachment' );

		$restorer = new AttachmentRestorer( $settings, $storage, null, null, $profile_ops );
		$this->inject_objects( $restorer, $objects );

		$result = $restorer->restore( $id );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'partial', $result['status'] ?? '' );
		$this->assertSame( 'offloaded', (string) get_post_meta( $id, '_s3ms_status', true ) );
		$this->assertSame( 'uploads/2026/08/photo.jpg', (string) get_post_meta( $id, '_s3ms_original_key', true ) );
	}

	public function test_partial_restore_with_unknown_head_does_not_mark_attachment_local(): void {
		$id = 44;
		$this->seed_attachment_with_thumbnail( $id );

		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_aws_configured' )->willReturn( true );

		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->method( 'head_attachment_relative' )->willReturnCallback(
			static fn( int $attachment_id, string $relative ): array => $relative === '2026/08/photo.jpg'
				? array( 'exists' => true )
				: array( 'exists' => false, 'confirmed_missing' => false, 'error' => 'timeout' )
		);
		$profile_ops->method( 'download_attachment_relative_to_local' )->willReturnCallback(
			static function ( int $attachment_id, string $relative, string $absolute ): array {
				unset( $attachment_id );
				if ( $relative === '2026/08/photo.jpg' ) {
					file_put_contents( $absolute, 'bytes' );
					return array( 'success' => true, 'location_status' => 'test', 'legacy_fallback' => false );
				}
				return array( 'success' => false, 'location_status' => 'test', 'legacy_fallback' => false );
			}
		);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->never() )->method( 'delete_by_attachment' );

		$restorer = new AttachmentRestorer( $settings, $storage, null, null, $profile_ops );
		$this->inject_objects( $restorer, $objects );

		$result = $restorer->restore( $id );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'partial', $result['status'] ?? '' );
		$this->assertSame( 'offloaded', (string) get_post_meta( $id, '_s3ms_status', true ) );
		$this->assertSame( 'uploads/2026/08/photo.jpg', (string) get_post_meta( $id, '_s3ms_original_key', true ) );
	}

	private function seed_attachment_with_thumbnail( int $id ): void {
		WpStubs::$post_meta[ $id ]['_wp_attached_file']       = '2026/08/photo.jpg';
		WpStubs::$post_meta[ $id ]['_s3ms_status']            = 'offloaded';
		WpStubs::$post_meta[ $id ]['_s3ms_original_key']      = 'uploads/2026/08/photo.jpg';
		WpStubs::$post_meta[ $id ]['_wp_attachment_metadata'] = array(
			'file'  => '2026/08/photo.jpg',
			'sizes' => array(
				'thumbnail' => array( 'file' => 'photo-150x150.jpg' ),
			),
		);
	}

	private function inject_objects( AttachmentRestorer $restorer, ObjectRepository $objects ): void {
		$ref  = new \ReflectionClass( AttachmentRestorer::class );
		$prop = $ref->getProperty( 'objects' );
		$prop->setValue( $restorer, $objects );
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
