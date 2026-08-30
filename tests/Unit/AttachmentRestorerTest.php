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
use Kazcode\WpStorage\Infrastructure\AttachmentLock;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Services\ProfileAwareObjectOperations;
use Kazcode\WpStorage\Services\ProfileObjectLocation;
use Kazcode\WpStorage\Services\ProfileObjectLocator;
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

		$restorer = new AttachmentRestorer( $settings, $storage, null, null, $this->legacy_ops( $storage ) );
		$ref      = new \ReflectionClass( AttachmentRestorer::class );
		$prop     = $ref->getProperty( 'objects' );
		$prop->setValue( $restorer, $objects );

		$result = $restorer->restore( $id );

		$this->assertTrue( $result['success'] );
		$this->assertSame( '', (string) get_post_meta( $id, '_s3ms_status', true ) );
		$this->assertSame( '', (string) get_post_meta( $id, '_s3ms_original_key', true ) );
	}

	public function test_partial_restore_keeps_offload_meta_and_inventory_rows(): void {
		$id = 43;
		WpStubs::$post_meta[ $id ]['_wp_attached_file']       = '2026/08/photo.jpg';
		WpStubs::$post_meta[ $id ]['_wp_attachment_metadata'] = array(
			'file'  => '2026/08/photo.jpg',
			'sizes' => array(
				'thumbnail' => array( 'file' => 'photo-150x150.jpg' ),
			),
		);
		WpStubs::$post_meta[ $id ]['_s3ms_status']       = 'offloaded';
		WpStubs::$post_meta[ $id ]['_s3ms_original_key'] = 'uploads/2026/08/photo.jpg';

		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_aws_configured' )->willReturn( true );

		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		$storage->method( 'head_relative' )->willReturnCallback(
			static fn( string $relative ): array => $relative === '2026/08/photo.jpg'
				? array( 'exists' => true )
				: array( 'exists' => false, 'confirmed_missing' => true )
		);
		$storage->method( 'download_relative' )->willReturnCallback(
			static function ( string $relative, string $absolute ): void {
				file_put_contents( $absolute, 'bytes' );
			}
		);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->never() )->method( 'delete_by_attachment' );

		$restorer = new AttachmentRestorer( $settings, $storage, null, null, $this->legacy_ops( $storage ) );
		$ref      = new \ReflectionClass( AttachmentRestorer::class );
		$prop     = $ref->getProperty( 'objects' );
		$prop->setValue( $restorer, $objects );

		$result = $restorer->restore( $id );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'partial', $result['status'] ?? null );
		$this->assertSame( 'offloaded', get_post_meta( $id, '_s3ms_status', true ) );
		$this->assertSame( 'uploads/2026/08/photo.jpg', get_post_meta( $id, '_s3ms_original_key', true ) );
		$this->assertFileExists( $this->uploads . '/2026/08/photo.jpg' );
		$this->assertFileDoesNotExist( $this->uploads . '/2026/08/photo-150x150.jpg' );
	}

	public function test_restore_reports_unknown_when_head_is_transient_not_missing(): void {
		$id = 44;
		WpStubs::$post_meta[ $id ]['_wp_attached_file']       = '2026/08/photo.jpg';
		WpStubs::$post_meta[ $id ]['_wp_attachment_metadata'] = array(
			'file'  => '2026/08/photo.jpg',
			'sizes' => array(),
		);
		WpStubs::$post_meta[ $id ]['_s3ms_status']            = 'offloaded';
		WpStubs::$post_meta[ $id ]['_s3ms_original_key']      = 'uploads/2026/08/photo.jpg';

		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_aws_configured' )->willReturn( true );

		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		$storage->method( 'head_relative' )->willReturn(
			array(
				'exists'            => false,
				'confirmed_missing' => false,
				'error_class'       => 'timeout',
				'error'             => 'timeout',
			)
		);
		$storage->expects( $this->never() )->method( 'download_relative' );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->never() )->method( 'delete_by_attachment' );

		$restorer = new AttachmentRestorer( $settings, $storage, null, null, $this->legacy_ops( $storage ) );
		$ref      = new \ReflectionClass( AttachmentRestorer::class );
		$prop     = $ref->getProperty( 'objects' );
		$prop->setValue( $restorer, $objects );

		$result = $restorer->restore( $id );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'unknown', $result['status'] ?? null );
		$this->assertStringContainsString( 'could not be verified', $result['message'] );
		$this->assertStringNotContainsString( 'missing', strtolower( $result['message'] ) );
		$this->assertSame( 'offloaded', get_post_meta( $id, '_s3ms_status', true ) );
		$this->assertFileDoesNotExist( $this->uploads . '/2026/08/photo.jpg' );
	}

	public function test_restore_does_not_clear_meta_or_inventory_when_lease_is_lost_before_finalization(): void {
		$id = 45;
		WpStubs::$post_meta[ $id ]['_wp_attached_file']       = '2026/08/photo.jpg';
		WpStubs::$post_meta[ $id ]['_wp_attachment_metadata'] = array(
			'file'  => '2026/08/photo.jpg',
			'sizes' => array(),
		);
		WpStubs::$post_meta[ $id ]['_s3ms_status']            = 'offloaded';
		WpStubs::$post_meta[ $id ]['_s3ms_original_key']      = 'uploads/2026/08/photo.jpg';

		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_aws_configured' )->willReturn( true );

		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		$storage->method( 'head_relative' )->willReturn( array( 'exists' => true ) );
		$storage->method( 'download_relative' )->willReturnCallback(
			function ( string $relative, string $absolute ) use ( $id ): void {
				file_put_contents( $absolute, 'bytes' );
				$this->expire_lock( $id );
				$this->assertNotNull( ( new AttachmentLock() )->acquire_lease( $id, 'delete' ) );
			}
		);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->never() )->method( 'delete_by_attachment' );

		$restorer = new AttachmentRestorer( $settings, $storage, null, null, $this->legacy_ops( $storage ) );
		$ref      = new \ReflectionClass( AttachmentRestorer::class );
		$prop     = $ref->getProperty( 'objects' );
		$prop->setValue( $restorer, $objects );

		$result = $restorer->restore( $id );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'unknown', $result['status'] ?? null );
		$this->assertSame( 'offloaded', get_post_meta( $id, '_s3ms_status', true ) );
		$this->assertSame( 'uploads/2026/08/photo.jpg', get_post_meta( $id, '_s3ms_original_key', true ) );
		$this->assertFileExists( $this->uploads . '/2026/08/photo.jpg' );
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

	private function legacy_ops( S3Storage $storage ): ProfileAwareObjectOperations {
		$locator = $this->createMock( ProfileObjectLocator::class );
		$locator->method( 'locate' )->willReturnCallback(
			static fn( int $attachment_id, string $relative ): ProfileObjectLocation => ProfileObjectLocation::not_in_inventory( $relative )
		);
		return new ProfileAwareObjectOperations( $locator, null, $storage );
	}

	private function expire_lock( int $attachment_id ): void {
		$key      = 's3ms_lock_' . $attachment_id;
		$existing = WpStubs::$options[ $key ] ?? array();
		if ( is_string( $existing ) ) {
			$existing = json_decode( $existing, true );
		}
		$this->assertIsArray( $existing );
		$existing['expires']        = time() - 1;
		WpStubs::$options[ $key ] = json_encode( $existing );
	}
}
