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

	public function test_offload_refuses_to_steal_existing_owner_on_same_key(): void {
		$files = array(
			'2026/08/photo.jpg' => $this->touch_file( '2026/08/photo.jpg', 10 ),
		);
		WpStubs::set_meta( 20, '_wp_attached_file', '2026/08/photo.jpg' );
		WpStubs::set_meta( 20, '_wp_attachment_metadata', array( 'file' => '2026/08/photo.jpg' ) );

		$settings = $this->settings_map(
			array(
				'cache_control' => 'public, max-age=31536000',
			)
		);
		$keys    = new S3KeyResolver( $this->settings_map( array( 'object_prefix' => 'uploads/' ) ) );
		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( $keys );
		$storage->method( 'head_key' )->willReturn( array( 'exists' => false ) );
		$storage->method( 'content_type_for_local_file' )->willReturn( 'image/jpeg' );

		$file_resolver = $this->getMockBuilder( AttachmentFileResolver::class )
			->setConstructorArgs( array( $keys ) )
			->onlyMethods( array( 'existing_local_files', 'relative_paths' ) )
			->getMock();
		$file_resolver->method( 'existing_local_files' )->willReturn( $files );
		$file_resolver->method( 'relative_paths' )->willReturn( array_keys( $files ) );

		$transfer = new ObjectOffloadTransferCallbackFake(
			static function (): array {
				return array(
					'success'           => true,
					'verified_complete' => true,
					'session_uuid'      => 'unit-transfer',
					'resumed'           => false,
					'message'           => 'Destination verified.',
				);
			}
		);

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

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'insert_owned_or_conflict' )->willReturn(
			array(
				'ok'       => false,
				'reason'   => 'key_owned_by_other_attachment',
				'existing' => array( 'attachment_id' => 99 ),
			)
		);
		$objects->method( 'find_by_attachment' )->willReturn( array() );
		$objects->expects( $this->never() )->method( 'upsert' );

		$service = new ObjectOffloadService( $settings, $storage, $file_resolver, null, $objects, $profiles, $transfer );

		try {
			$service->offload( 20, false );
			$this->fail( 'Expected ownership conflict to fail offload.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'owned by another attachment', $e->getMessage() );
		}
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

	public function test_offload_uploads_existing_hook_contributed_sidecar(): void {
		$this->touch_file( '2026/08/photo.jpg', 100 );
		$this->touch_file( '2026/08/photo.webp', 60 );
		WpStubs::set_meta( 11, '_wp_attached_file', '2026/08/photo.jpg' );
		WpStubs::set_meta( 11, '_wp_attachment_metadata', array( 'file' => '2026/08/photo.jpg', 'sizes' => array() ) );
		add_filter(
			'kazus_manifest_files',
			static fn( array $items ): array => array_merge(
				$items,
				array( array( 'relative' => '2026/08/photo.webp', 'variant_type' => 'webp' ) )
			)
		);

		$settings = $this->createMock( Settings::class );
		$settings->method( 'should_delete_local' )->willReturn( false );
		$settings->method( 'should_verify_before_delete' )->willReturn( true );
		$keys    = new S3KeyResolver( $this->settings_map( array( 'object_prefix' => 'uploads/' ) ) );
		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( $keys );
		$storage->method( 'content_type_for_local_file' )->willReturn( 'image/webp' );
		$uploaded = array();
		$storage->method( 'head_key' )->willReturnCallback(
			static function ( string $key ) use ( &$uploaded ): array {
				return isset( $uploaded[ $key ] )
					? array( 'exists' => true, 'content_length' => $uploaded[ $key ] )
					: array( 'exists' => false );
			}
		);
		$transfer = new ObjectOffloadTransferCallbackFake(
			function ( int $attachment_id, int $profile_id, string $relative, string $key ) use ( &$uploaded ): array {
				unset( $attachment_id, $profile_id );
				$uploaded[ $key ] = (int) filesize( $this->uploads . '/' . $relative );
				return array( 'success' => true, 'verified_complete' => true, 'session_uuid' => 'sidecar', 'resumed' => false, 'message' => 'Destination verified.' );
			}
		);

		$result = $this->build_service_from_parts( $settings, $storage, new AttachmentFileResolver( $keys ), $transfer )->offload( 11, false );

		$this->assertTrue( $result['success'] );
		$this->assertSame( AttachmentOffloader::STATUS_OFFLOADED, $result['status'] );
		$this->assertSame( array( 'uploads/2026/08/photo.jpg', 'uploads/2026/08/photo.webp' ), $result['keys'] );
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
		$storage->method( 'content_type_for_local_file' )->willReturn( 'image/jpeg' );

		$file_resolver = $this->getMockBuilder( AttachmentFileResolver::class )
			->setConstructorArgs( array( $keys ) )
			->onlyMethods( array( 'existing_local_files', 'relative_paths' ) )
			->getMock();
		$file_resolver->method( 'existing_local_files' )->willReturn( $files );
		$file_resolver->method( 'relative_paths' )->willReturn( array_keys( $files ) );

		$transfer = new ObjectOffloadTransferCallbackFake(
			static function ( int $attachment_id, int $destination_profile_id, string $relative, string $key ) use ( $files, $on_upload ): array {
				$on_upload( $files[ $relative ], $key );
				return array(
					'success'           => true,
					'verified_complete' => true,
					'session_uuid'      => 'unit-transfer',
					'resumed'           => false,
					'message'           => 'Destination verified.',
				);
			}
		);

		return $this->build_service_from_parts( $settings, $storage, $file_resolver, $transfer );
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
					return array( 'exists' => true, 'content_length' => $uploaded[ $key ] );
				}
				return array( 'exists' => false );
			}
		);
		$storage->method( 'content_type_for_local_file' )->willReturn( 'image/jpeg' );

		$file_resolver = $this->getMockBuilder( AttachmentFileResolver::class )
			->setConstructorArgs( array( $keys ) )
			->onlyMethods( array( 'existing_local_files', 'relative_paths' ) )
			->getMock();
		$file_resolver->method( 'existing_local_files' )->willReturn( $files );
		$file_resolver->method( 'relative_paths' )->willReturn( array_keys( $files ) );

		$transfer = new ObjectOffloadTransferCallbackFake(
			static function ( int $attachment_id, int $destination_profile_id, string $relative, string $key ) use ( &$uploaded, $files, $on_upload ): array {
				$absolute = $files[ $relative ];
				$on_upload( $absolute, $key, $relative );
				$uploaded[ $key ] = (int) filesize( $absolute );
				return array(
					'success'           => true,
					'verified_complete' => true,
					'session_uuid'      => 'unit-transfer',
					'resumed'           => false,
					'message'           => 'Destination verified.',
				);
			}
		);

		return $this->build_service_from_parts(
			$settings,
			$storage,
			$file_resolver,
			$transfer
		);
	}

	/**
	 * Shared mocked dependencies for ObjectOffloadService tests.
	 */
	private function build_service_from_parts(
		Settings $settings,
		S3Storage $storage,
		AttachmentFileResolver $file_resolver,
		object $transfer,
		array $seed_rows = array(),
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

		$rows    = $seed_rows;
		$next_id = count( $seed_rows ) + 1;
		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'insert_owned_or_conflict' )->willReturnCallback(
			static function ( array $data ) use ( &$rows, &$next_id ): array {
				$key        = (int) ( $data['storage_profile_id'] ?? 0 ) . ':' . (string) ( $data['object_key'] ?? '' );
				$attachment = (int) ( $data['attachment_id'] ?? 0 );
				$relative   = (string) ( $data['local_relative_path'] ?? '' );
				if ( isset( $rows[ $key ] ) ) {
					$existing = $rows[ $key ];
					if (
						(int) ( $existing['attachment_id'] ?? 0 ) !== $attachment
						|| (string) ( $existing['local_relative_path'] ?? '' ) !== $relative
					) {
						$status = (string) ( $existing['remote_status'] ?? '' );
						if ( ! in_array( $status, array( ObjectRemoteStatus::STALE, ObjectRemoteStatus::DELETED ), true ) ) {
							return array( 'ok' => false, 'reason' => 'key_owned_by_other_attachment', 'existing' => $existing );
						}
					}
					$rows[ $key ] = array_merge( $existing, $data );
					return array( 'ok' => true, 'id' => (int) $rows[ $key ]['id'] );
				}
				$rows[ $key ] = array_merge( $data, array( 'id' => $next_id++ ) );
				return array( 'ok' => true, 'id' => (int) $rows[ $key ]['id'] );
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
			$profiles,
			$transfer
		);
	}

	public function test_fenced_offload_stops_inventory_writes_after_lease_loss(): void {
		$files = array(
			'2026/08/photo.jpg' => $this->touch_file( '2026/08/photo.jpg', 8 ),
		);
		WpStubs::set_meta( 30, '_wp_attached_file', '2026/08/photo.jpg' );
		WpStubs::set_meta(
			30,
			'_wp_attachment_metadata',
			array(
				'file'  => '2026/08/photo.jpg',
				'sizes' => array(),
			)
		);

		$service = $this->build_service(
			$files,
			static function (): array {
				return array(
					'success'           => true,
					'verified_complete' => true,
					'session_uuid'      => 'sess',
					'resumed'           => false,
					'message'           => 'ok',
				);
			}
		);

		$lease = new \Kazcode\WpStorage\Infrastructure\AttachmentLeaseHandle( 30, 'tok', 1, 'migrate', time() + 300 );
		$lock  = $this->createMock( \Kazcode\WpStorage\Infrastructure\AttachmentLock::class );
		$lock->method( 'renew' )->willReturn( false );

		$this->expectException( \Kazcode\WpStorage\Infrastructure\LeaseLostException::class );
		$service->offload( 30, false, null, $lease, $lock );
	}

	public function test_ownership_conflict_on_one_variant_does_not_offload_or_cleanup(): void {
		$files = array(
			'2026/08/photo.jpg'         => $this->touch_file( '2026/08/photo.jpg', 8 ),
			'2026/08/photo-150x150.jpg' => $this->touch_file( '2026/08/photo-150x150.jpg', 4 ),
		);
		WpStubs::set_meta( 31, '_wp_attached_file', '2026/08/photo.jpg' );
		WpStubs::set_meta(
			31,
			'_wp_attachment_metadata',
			array(
				'file'  => '2026/08/photo.jpg',
				'sizes' => array(
					'thumbnail' => array( 'file' => 'photo-150x150.jpg' ),
				),
			)
		);

		$settings = $this->settings_map(
			array(
				'delete_local_after_offload' => true,
				'cache_control'             => 'public, max-age=31536000',
			)
		);
		$keys = new \Kazcode\WpStorage\Storage\S3KeyResolver( $settings );
		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( $keys );
		$storage->method( 'head_key' )->willReturn( array( 'exists' => false ) );
		$storage->method( 'content_type_for_local_file' )->willReturn( 'image/jpeg' );
		$storage->expects( $this->never() )->method( 'delete_keys' );

		$file_resolver = $this->getMockBuilder( AttachmentFileResolver::class )
			->setConstructorArgs( array( $keys ) )
			->onlyMethods( array( 'existing_local_files', 'relative_paths' ) )
			->getMock();
		$file_resolver->method( 'existing_local_files' )->willReturn( $files );
		$file_resolver->method( 'relative_paths' )->willReturn( array_keys( $files ) );

		$transfer = new ObjectOffloadTransferCallbackFake(
			static function () use ( $files ): array {
				return array(
					'success'           => true,
					'verified_complete' => true,
					'session_uuid'      => 'unit',
					'resumed'           => false,
					'message'           => 'ok',
				);
			}
		);

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

		$rows    = array();
		$next_id = 1;
		// Pre-existing PRESENT rows on a different profile must not satisfy coverage.
		$rows['9:old/uploads/2026/08/photo.jpg'] = array(
			'id'                  => $next_id++,
			'attachment_id'       => 31,
			'storage_profile_id'  => 9,
			'local_relative_path' => '2026/08/photo.jpg',
			'object_key'          => 'old/uploads/2026/08/photo.jpg',
			'remote_status'       => ObjectRemoteStatus::PRESENT,
			'variant_type'        => 'original',
		);
		$rows['9:old/uploads/2026/08/photo-150x150.jpg'] = array(
			'id'                  => $next_id++,
			'attachment_id'       => 31,
			'storage_profile_id'  => 9,
			'local_relative_path' => '2026/08/photo-150x150.jpg',
			'object_key'          => 'old/uploads/2026/08/photo-150x150.jpg',
			'remote_status'       => ObjectRemoteStatus::PRESENT,
			'variant_type'        => 'size',
		);
		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'insert_owned_or_conflict' )->willReturnCallback(
			static function ( array $data ) use ( &$rows, &$next_id ): array {
				$key = (string) ( $data['object_key'] ?? '' );
				if ( str_contains( $key, '150x150' ) && str_starts_with( $key, 'uploads/' ) ) {
					return array(
						'ok'       => false,
						'reason'   => 'key_owned_by_other_attachment',
						'existing' => array( 'attachment_id' => 999 ),
					);
				}
				$rows[ (int) ( $data['storage_profile_id'] ?? 0 ) . ':' . $key ] = array_merge( $data, array( 'id' => $next_id++ ) );
				return array( 'ok' => true, 'id' => $next_id - 1 );
			}
		);
		$objects->method( 'find_by_attachment' )->willReturnCallback(
			static function () use ( &$rows ): array {
				return array_values( $rows );
			}
		);

		$service = new ObjectOffloadService( $settings, $storage, $file_resolver, null, $objects, $profiles, $transfer );
		$result  = $service->offload( 31, true );

		$this->assertFalse( $result['success'] );
		$this->assertSame( AttachmentOffloader::STATUS_PARTIAL, $result['status'] ?? null );
		$this->assertSame( AttachmentOffloader::STATUS_PARTIAL, WpStubs::$post_meta[31]['_s3ms_status'] ?? null );
		foreach ( $files as $absolute ) {
			$this->assertFileExists( $absolute );
		}
	}

	public function test_missing_local_manifest_path_without_inventory_blocks_offload_cleanup(): void {
		$files = array(
			'2026/08/photo.jpg' => $this->touch_file( '2026/08/photo.jpg', 8 ),
		);
		$manifest_paths = array(
			'2026/08/photo.jpg',
			'2026/08/photo-150x150.jpg',
		);
		WpStubs::set_meta( 32, '_wp_attached_file', '2026/08/photo.jpg' );
		WpStubs::set_meta(
			32,
			'_wp_attachment_metadata',
			array(
				'file'  => '2026/08/photo.jpg',
				'sizes' => array(
					'thumbnail' => array( 'file' => 'photo-150x150.jpg' ),
				),
			)
		);

		$settings = $this->createMock( Settings::class );
		$settings->method( 'should_delete_local' )->willReturn( true );
		$settings->method( 'should_verify_before_delete' )->willReturn( true );
		$settings->method( 'get' )->willReturnCallback(
			static function ( string $key, mixed $default = null ): mixed {
				return match ( $key ) {
					'object_prefix' => 'uploads/',
					'cache_control' => 'public, max-age=31536000',
					default         => $default,
				};
			}
		);

		$keys    = new S3KeyResolver( $settings );
		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( $keys );
		$storage->method( 'head_key' )->willReturn( array( 'exists' => false ) );
		$storage->method( 'content_type_for_local_file' )->willReturn( 'image/jpeg' );
		$storage->expects( $this->never() )->method( 'delete_keys' );

		$file_resolver = $this->getMockBuilder( AttachmentFileResolver::class )
			->setConstructorArgs( array( $keys ) )
			->onlyMethods( array( 'existing_local_files', 'relative_paths' ) )
			->getMock();
		$file_resolver->method( 'existing_local_files' )->willReturn( $files );
		$file_resolver->method( 'relative_paths' )->willReturn( $manifest_paths );

		$transfer = new ObjectOffloadTransferCallbackFake(
			static function () use ( $files ): array {
				return array(
					'success'           => true,
					'verified_complete' => true,
					'session_uuid'      => 'unit',
					'resumed'           => false,
					'message'           => 'ok',
				);
			}
		);

		$service = $this->build_service_from_parts( $settings, $storage, $file_resolver, $transfer );
		$result  = $service->offload( 32, true );

		$this->assertFalse( $result['success'] );
		$this->assertNotSame( AttachmentOffloader::STATUS_OFFLOADED, $result['status'] ?? null );
		$this->assertNotSame( AttachmentOffloader::STATUS_OFFLOADED, WpStubs::$post_meta[32]['_s3ms_status'] ?? null );
		$this->assertFileExists( $files['2026/08/photo.jpg'] );
	}

	/**
	 * wp_save_image() saves edited sizes before it records the pre-edit files as
	 * `_wp_attachment_backup_sizes`, so the reconciler marks those files stale
	 * although they become required again. Offload must revive such a row from an
	 * exact-key, size-matching HEAD (there is no local copy to re-upload), or the
	 * attachment stays `partial` forever after any image edit (#54 E2E finding).
	 */
	public function test_required_backup_variant_with_only_a_stale_row_is_revived_from_exact_head(): void {
		$this->assert_stale_backup_revival( array( 'exists' => true, 'content_length' => 7 ), true );
	}

	public function test_required_stale_variant_is_not_revived_when_head_size_differs(): void {
		$this->assert_stale_backup_revival( array( 'exists' => true, 'content_length' => 99 ), false );
	}

	public function test_required_stale_variant_is_not_revived_on_uncertain_head(): void {
		$this->assert_stale_backup_revival( array( 'exists' => false, 'error' => 'timeout' ), false );
	}

	/**
	 * @param array<string, mixed> $backup_head HEAD result for the backup object.
	 */
	private function assert_stale_backup_revival( array $backup_head, bool $expect_offloaded ): void {
		$files = array(
			'2026/08/photo-e123.jpg' => $this->touch_file( '2026/08/photo-e123.jpg', 8 ),
		);
		WpStubs::set_meta( 33, '_wp_attached_file', '2026/08/photo-e123.jpg' );

		$settings = $this->settings_map( array( 'object_prefix' => 'uploads/', 'cache_control' => 'public' ) );
		$keys     = new S3KeyResolver( $settings );
		$storage  = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( $keys );
		$storage->method( 'content_type_for_local_file' )->willReturn( 'image/jpeg' );
		$storage->method( 'head_key' )->willReturnCallback(
			static fn( string $key ): array => $key === 'uploads/2026/08/photo.jpg'
				? $backup_head
				: array( 'exists' => true, 'content_length' => 8 )
		);

		$file_resolver = $this->getMockBuilder( AttachmentFileResolver::class )
			->setConstructorArgs( array( $keys ) )
			->onlyMethods( array( 'existing_local_files', 'relative_paths' ) )
			->getMock();
		$file_resolver->method( 'existing_local_files' )->willReturn( $files );
		// Current edited file + the pre-edit original WordPress keeps as a backup.
		$file_resolver->method( 'relative_paths' )->willReturn( array( '2026/08/photo-e123.jpg', '2026/08/photo.jpg' ) );

		$transfer = new ObjectOffloadTransferCallbackFake(
			static fn(): array => array( 'success' => true, 'verified_complete' => true, 'session_uuid' => 'unit', 'resumed' => false, 'message' => 'ok' )
		);
		$seed = array(
			'1:uploads/2026/08/photo.jpg' => array(
				'id'                  => 1,
				'attachment_id'       => 33,
				'storage_profile_id'  => 1,
				'local_relative_path' => '2026/08/photo.jpg',
				'object_key'          => 'uploads/2026/08/photo.jpg',
				'variant_type'        => 'original',
				'size_bytes'          => 7,
				'remote_status'       => ObjectRemoteStatus::STALE,
			),
		);

		$service = $this->build_service_from_parts( $settings, $storage, $file_resolver, $transfer, $seed );
		$result  = $service->offload( 33, false );

		if ( $expect_offloaded ) {
			$this->assertTrue( $result['success'], $result['message'] );
			$this->assertSame( AttachmentOffloader::STATUS_OFFLOADED, WpStubs::$post_meta[33]['_s3ms_status'] ?? null );
		} else {
			$this->assertFalse( $result['success'] );
			$this->assertNotSame( AttachmentOffloader::STATUS_OFFLOADED, WpStubs::$post_meta[33]['_s3ms_status'] ?? null );
		}
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

final class ObjectOffloadTransferCallbackFake {

	/** @var callable */
	private $callback;

	public function __construct( callable $callback ) {
		$this->callback = $callback;
	}

	/**
	 * @return array{success: bool, verified_complete: bool, session_uuid: string, resumed: bool, message: string}
	 */
	public function upload_local( int $attachment_id, int $destination_profile_id, string $relative_path, string $destination_key, string $content_type, string $cache_control ): array {
		return ( $this->callback )( $attachment_id, $destination_profile_id, $relative_path, $destination_key, $content_type, $cache_control );
	}
}
