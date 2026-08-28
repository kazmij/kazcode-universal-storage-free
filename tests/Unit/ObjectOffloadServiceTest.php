<?php
/**
 * ObjectOffloadService unit tests (mocked storage + in-memory object rows).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Attachment\AttachmentFileResolver;
use Kazcode\WpStorage\Attachment\AttachmentOffloader;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
use Kazcode\WpStorage\Services\ObjectOffloadService;
use Kazcode\WpStorage\Storage\S3KeyResolver;
use Kazcode\WpStorage\Storage\S3Storage;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class ObjectOffloadServiceTest extends TestCase {

	private string $uploads;

	protected function setUp(): void {
		WpStubs::reset();
		$this->uploads = sys_get_temp_dir() . '/s3ms-obj-' . uniqid( '', true );
		wp_mkdir_p( $this->uploads . '/2026/08' );
		WpStubs::$uploads_basedir = $this->uploads;
	}

	protected function tearDown(): void {
		$this->rmTree( $this->uploads );
		WpStubs::reset();
	}

	public function test_is_enabled_defaults_true(): void {
		$this->assertTrue( ObjectOffloadService::is_enabled() );
	}

	public function test_full_offload_writes_object_rows_and_offloaded_meta(): void {
		$files = array(
			'2026/08/photo.jpg'         => $this->touch_file( '2026/08/photo.jpg' ),
			'2026/08/photo-150x150.jpg' => $this->touch_file( '2026/08/photo-150x150.jpg' ),
		);

		WpStubs::set_meta( 10, '_wp_attached_file', '2026/08/photo.jpg' );
		WpStubs::set_meta(
			10,
			'_wp_attachment_metadata',
			array(
				'file'  => '2026/08/photo.jpg',
				'sizes' => array(
					'thumbnail' => array( 'file' => 'photo-150x150.jpg' ),
				),
			)
		);

		$service = $this->build_service( $files, static function (): void {
			// uploads succeed
		} );

		$result = $service->offload( 10, false );

		$this->assertTrue( $result['success'] );
		$this->assertSame( AttachmentOffloader::STATUS_OFFLOADED, WpStubs::$post_meta[10]['_s3ms_status'] ?? null );
		$this->assertSame( 'uploads/2026/08/photo.jpg', WpStubs::$post_meta[10]['_s3ms_original_key'] ?? null );
		$this->assertCount( 2, $result['keys'] ?? array() );
		foreach ( $files as $absolute ) {
			$this->assertFileExists( $absolute );
		}
	}

	public function test_retry_skips_objects_already_present_on_remote(): void {
		$files = array(
			'2026/08/photo.jpg'         => $this->touch_file( '2026/08/photo.jpg', 100 ),
			'2026/08/photo-150x150.jpg' => $this->touch_file( '2026/08/photo-150x150.jpg', 50 ),
		);

		WpStubs::set_meta( 12, '_wp_attached_file', '2026/08/photo.jpg' );
		WpStubs::set_meta(
			12,
			'_wp_attachment_metadata',
			array(
				'file'  => '2026/08/photo.jpg',
				'sizes' => array(
					'thumbnail' => array( 'file' => 'photo-150x150.jpg' ),
				),
			)
		);

		$uploaded_keys = array();
		$service       = $this->build_service_with_head(
			$files,
			static function ( string $key ) use ( &$uploaded_keys ): array {
				if ( isset( $uploaded_keys[ $key ] ) ) {
					return array( 'exists' => true, 'content_length' => $uploaded_keys[ $key ] );
				}
				if ( $key === 'uploads/2026/08/photo.jpg' ) {
					return array( 'exists' => true, 'content_length' => 100 );
				}
				return array( 'exists' => false );
			},
			static function ( string $absolute, string $key ) use ( &$uploaded_keys, $files ): void {
				$relative = array_search( $absolute, $files, true );
				$uploaded_keys[ $key ] = $relative === '2026/08/photo.jpg' ? 100 : 50;
			}
		);

		$result = $service->offload( 12, false );

		$this->assertTrue( $result['success'] );
		$this->assertSame( AttachmentOffloader::STATUS_OFFLOADED, $result['status'] ?? null );
		$this->assertCount( 2, $result['keys'] ?? array() );
		$this->assertArrayNotHasKey( 'uploads/2026/08/photo.jpg', $uploaded_keys );
		$this->assertArrayHasKey( 'uploads/2026/08/photo-150x150.jpg', $uploaded_keys );
	}

	public function test_partial_failure_keeps_local_files_and_sets_partial_status(): void {
		$files = array(
			'2026/08/photo.jpg'         => $this->touch_file( '2026/08/photo.jpg' ),
			'2026/08/photo-150x150.jpg' => $this->touch_file( '2026/08/photo-150x150.jpg' ),
		);

		WpStubs::set_meta( 11, '_wp_attached_file', '2026/08/photo.jpg' );
		WpStubs::set_meta(
			11,
			'_wp_attachment_metadata',
			array(
				'file'  => '2026/08/photo.jpg',
				'sizes' => array(
					'thumbnail' => array( 'file' => 'photo-150x150.jpg' ),
				),
			)
		);

		$uploads = 0;
		$service = $this->build_service(
			$files,
			static function () use ( &$uploads ): void {
				++$uploads;
				if ( $uploads >= 2 ) {
					throw new \RuntimeException( 'Simulated upload failure' );
				}
			}
		);

		$result = $service->offload( 11, true );

		$this->assertFalse( $result['success'] );
		$this->assertSame( AttachmentOffloader::STATUS_PARTIAL, $result['status'] ?? null );
		$this->assertSame( AttachmentOffloader::STATUS_PARTIAL, WpStubs::$post_meta[11]['_s3ms_status'] ?? null );
		$this->assertSame( array(), WpStubs::$deleted_files );
		foreach ( $files as $absolute ) {
			$this->assertFileExists( $absolute );
		}
	}

	/**
	 * @param array<string, string> $files relative => absolute
	 */
	private function build_service_with_head( array $files, callable $head_callback, callable $on_upload ): ObjectOffloadService {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'should_delete_local' )->willReturn( false );
		$settings->method( 'should_verify_before_delete' )->willReturn( true );

		$keys    = new S3KeyResolver( $this->settings_map( array( 'object_prefix' => 'uploads/' ) ) );
		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( $keys );
		$storage->method( 'head_key' )->willReturnCallback( $head_callback );
		$storage->method( 'upload_file_to_key' )->willReturnCallback( $on_upload );

		$file_resolver = $this->getMockBuilder( AttachmentFileResolver::class )
			->setConstructorArgs( array( $keys ) )
			->onlyMethods( array( 'existing_local_files', 'relative_paths' ) )
			->getMock();
		$file_resolver->method( 'existing_local_files' )->willReturn( $files );
		$file_resolver->method( 'relative_paths' )->willReturn( array_keys( $files ) );

		return $this->build_service_from_parts( $settings, $storage, $file_resolver );
	}

	/**
	 * @param array<string, string> $files relative => absolute
	 */
	private function build_service( array $files, callable $on_upload ): ObjectOffloadService {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'should_delete_local' )->willReturn( true );
		$settings->method( 'should_verify_before_delete' )->willReturn( true );

		$keys     = new S3KeyResolver( $this->settings_map( array( 'object_prefix' => 'uploads/' ) ) );
		$uploaded = array();
		$storage  = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( $keys );
		$storage->method( 'head_key' )->willReturnCallback(
			static function ( string $key ) use ( &$uploaded ): array {
				if ( isset( $uploaded[ $key ] ) ) {
					return array( 'exists' => true, 'content_length' => 1 );
				}
				return array( 'exists' => false );
			}
		);
		$storage->method( 'upload_file_to_key' )->willReturnCallback(
			static function ( string $absolute, string $key, string $relative ) use ( &$uploaded, $on_upload ): void {
				$on_upload( $absolute, $key, $relative );
				$uploaded[ $key ] = true;
			}
		);

		$file_resolver = $this->getMockBuilder( AttachmentFileResolver::class )
			->setConstructorArgs( array( $keys ) )
			->onlyMethods( array( 'existing_local_files', 'relative_paths' ) )
			->getMock();
		$file_resolver->method( 'existing_local_files' )->willReturn( $files );
		$file_resolver->method( 'relative_paths' )->willReturn( array_keys( $files ) );

		return $this->build_service_from_parts(
			$settings,
			$storage,
			$file_resolver
		);
	}

	/**
	 * Shared mocked dependencies for ObjectOffloadService tests.
	 */
	private function build_service_from_parts(
		Settings $settings,
		S3Storage $storage,
		AttachmentFileResolver $file_resolver,
	): ObjectOffloadService {
		$profile = new StorageProfile(
			1,
			'uuid-test',
			'Test Profile',
			'aws',
			'test-bucket',
			'us-east-1',
			'',
			false,
			'uploads/',
			'storage',
			'',
			false,
			'keys',
			'legacy',
			true,
			false,
			false,
			gmdate( 'Y-m-d H:i:s' ),
			gmdate( 'Y-m-d H:i:s' ),
		);

		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find_default_upload_target' )->willReturn( $profile );

		$rows   = array();
		$next_id = 1;
		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'upsert' )->willReturnCallback(
			static function ( array $data ) use ( &$rows, &$next_id ): int {
				$key = (int) ( $data['storage_profile_id'] ?? 0 ) . ':' . (string) ( $data['object_key'] ?? '' );
				if ( ! isset( $rows[ $key ] ) ) {
					$rows[ $key ] = array_merge( $data, array( 'id' => $next_id++ ) );
				} else {
					$rows[ $key ] = array_merge( $rows[ $key ], $data );
				}
				return (int) $rows[ $key ]['id'];
			}
		);
		$objects->method( 'find_by_attachment' )->willReturnCallback(
			static function ( int $attachment_id ) use ( &$rows ): array {
				$out = array();
				foreach ( $rows as $row ) {
					if ( (int) ( $row['attachment_id'] ?? 0 ) === $attachment_id ) {
						$out[] = $row;
					}
				}
				return $out;
			}
		);

		return new ObjectOffloadService(
			$settings,
			$storage,
			$file_resolver,
			null,
			$objects,
			$profiles
		);
	}

	private function touch_file( string $relative, int $size = 1 ): string {
		$absolute = $this->uploads . '/' . $relative;
		$dir      = dirname( $absolute );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		file_put_contents( $absolute, str_repeat( 'x', max( 1, $size ) ) );
		return $absolute;
	}

	/**
	 * @param array<string, mixed> $map
	 */
	private function settings_map( array $map ): Settings {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'get' )->willReturnCallback(
			static function ( string $key, mixed $default = null ) use ( $map ): mixed {
				return $map[ $key ] ?? $default;
			}
		);
		return $settings;
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
