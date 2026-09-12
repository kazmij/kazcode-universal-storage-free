<?php
/**
 * Object offload behavior around resumable transfer verification.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

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
use PHPUnit\Framework\TestCase;

final class ObjectOffloadResumableMultipartTest extends TestCase {

	private string $uploads;

	protected function setUp(): void {
		WpStubs::reset();
		$this->uploads = sys_get_temp_dir() . '/s3ms-resumable-offload-' . uniqid( '', true );
		wp_mkdir_p( $this->uploads . '/2026/09' );
		WpStubs::$uploads_basedir = $this->uploads;
	}

	protected function tearDown(): void {
		$this->rmTree( $this->uploads );
		WpStubs::reset();
	}

	public function test_unverified_transfer_does_not_commit_object_or_cleanup_local_file(): void {
		$file = $this->touch_file( '2026/09/photo.jpg', 10 );
		$this->given_original_attachment( 100, '2026/09/photo.jpg' );

		$rows    = array();
		$service = $this->build_service(
			array( '2026/09/photo.jpg' => $file ),
			new TransferFake(
				array(
					'uploads/2026/09/photo.jpg' => array(
						'success'           => false,
						'verified_complete' => false,
						'session_uuid'      => 'transfer-unverified',
						'resumed'           => true,
						'message'           => 'Destination verification failed.',
					),
				)
			),
			$rows
		);

		$result = $this->run_offload_allowing_failure( $service, 100, true );

		$this->assertFalse( $result['success'] );
		$this->assertSame( ObjectRemoteStatus::UPLOADING, $rows['1:uploads/2026/09/photo.jpg']['remote_status'] ?? null );
		$this->assertNotSame( AttachmentOffloader::STATUS_OFFLOADED, WpStubs::$post_meta[100]['_s3ms_status'] ?? null );
		$this->assertArrayNotHasKey( '_s3ms_offloaded_at', WpStubs::$post_meta[100] ?? array() );
		$this->assertArrayNotHasKey( '_s3ms_verified_at', WpStubs::$post_meta[100] ?? array() );
		$this->assertSame( array(), WpStubs::$deleted_files );
		$this->assertFileExists( $file );
	}

	public function test_head_without_expected_size_still_requires_verified_transfer(): void {
		$file = $this->touch_file( '2026/09/photo.jpg', 10 );
		$this->given_original_attachment( 102, '2026/09/photo.jpg' );

		$transfer = new TransferFake(
			array(
				'uploads/2026/09/photo.jpg' => array(
					'success'           => false,
					'verified_complete' => false,
					'session_uuid'      => 'transfer-unverified-size',
					'resumed'           => false,
					'message'           => 'Destination verification did not include size.',
				),
			)
		);
		$rows     = array();
		$service  = $this->build_service(
			array( '2026/09/photo.jpg' => $file ),
			$transfer,
			$rows,
			array(
				'uploads/2026/09/photo.jpg' => array( 'exists' => true ),
			)
		);

		$result = $this->run_offload_allowing_failure( $service, 102, true );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'uploads/2026/09/photo.jpg' ), $transfer->uploaded_keys );
		$this->assertSame( ObjectRemoteStatus::UPLOADING, $rows['1:uploads/2026/09/photo.jpg']['remote_status'] ?? null );
		$this->assertNotSame( AttachmentOffloader::STATUS_OFFLOADED, WpStubs::$post_meta[102]['_s3ms_status'] ?? null );
		$this->assertSame( array(), WpStubs::$deleted_files );
		$this->assertFileExists( $file );
	}

	public function test_partial_attachment_retry_continues_only_missing_work(): void {
		$files = array(
			'2026/09/photo.jpg'         => $this->touch_file( '2026/09/photo.jpg', 10 ),
			'2026/09/photo-150x150.jpg' => $this->touch_file( '2026/09/photo-150x150.jpg', 5 ),
		);
		$this->given_image_attachment_with_thumbnail( 101 );

		$transfer = new TransferFake(
			array(
				'uploads/2026/09/photo.jpg' => array(
					array(
						'success'           => true,
						'verified_complete' => true,
						'session_uuid'      => 'original-complete-1',
						'resumed'           => false,
						'message'           => 'Destination verified.',
					),
					array(
						'success'           => true,
						'verified_complete' => true,
						'session_uuid'      => 'original-complete-2',
						'resumed'           => false,
						'message'           => 'Destination verified.',
					),
				),
				'uploads/2026/09/photo-150x150.jpg' => array(
					array(
						'success'           => false,
						'verified_complete' => false,
						'session_uuid'      => 'thumb-resumable',
						'resumed'           => true,
						'message'           => 'Multipart part upload failed; session remains resumable.',
					),
					array(
						'success'           => true,
						'verified_complete' => true,
						'session_uuid'      => 'thumb-complete',
						'resumed'           => true,
						'message'           => 'Destination verified.',
					),
				),
			)
		);
		$rows     = array();
		$service  = $this->build_service( $files, $transfer, $rows );

		$first = $service->offload( 101, false );

		$this->assertFalse( $first['success'] );
		$this->assertSame( AttachmentOffloader::STATUS_PARTIAL, $first['status'] ?? null );
		$this->assertSame( AttachmentOffloader::STATUS_PARTIAL, WpStubs::$post_meta[101]['_s3ms_status'] ?? null );
		$this->assertSame( ObjectRemoteStatus::PRESENT, $rows['1:uploads/2026/09/photo.jpg']['remote_status'] ?? null );
		$this->assertSame( ObjectRemoteStatus::UPLOADING, $rows['1:uploads/2026/09/photo-150x150.jpg']['remote_status'] ?? null );
		$this->assertSame( array(), WpStubs::$deleted_files );

		$second = $service->offload( 101, false );

		$this->assertTrue( $second['success'] );
		$this->assertSame( AttachmentOffloader::STATUS_OFFLOADED, $second['status'] ?? null );
		$this->assertSame(
			array(
				'uploads/2026/09/photo.jpg',
				'uploads/2026/09/photo-150x150.jpg',
				'uploads/2026/09/photo-150x150.jpg',
			),
			$transfer->uploaded_keys
		);
		$this->assertSame( ObjectRemoteStatus::PRESENT, $rows['1:uploads/2026/09/photo.jpg']['remote_status'] ?? null );
		$this->assertSame( ObjectRemoteStatus::PRESENT, $rows['1:uploads/2026/09/photo-150x150.jpg']['remote_status'] ?? null );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function run_offload_allowing_failure( ObjectOffloadService $service, int $attachment_id, bool $delete_local ): array {
		try {
			return $service->offload( $attachment_id, $delete_local );
		} catch ( \RuntimeException $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * @param array<string, string> $files relative => absolute
	 * @param array<string, array<string, mixed>> $rows
	 */
	private function build_service( array $files, TransferFake $transfer, array &$rows, array $initial_remote_heads = array() ): ObjectOffloadService {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'should_delete_local' )->willReturn( true );
		$settings->method( 'should_verify_before_delete' )->willReturn( true );
		$settings->method( 'get' )->willReturnCallback(
			static function ( string $key, mixed $default = null ): mixed {
				return $key === 'cache_control' ? 'public, max-age=31536000' : $default;
			}
		);

		$keys     = new S3KeyResolver( $this->settings_map( array( 'object_prefix' => 'uploads/' ) ) );
		$remote_heads = $initial_remote_heads;
		$transfer->set_verified_callback(
			static function ( string $relative, string $key ) use ( &$remote_heads, $files ): void {
				$remote_heads[ $key ] = array(
					'exists'         => true,
					'content_length' => (int) filesize( $files[ $relative ] ),
				);
			}
		);
		$storage  = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( $keys );
		$storage->method( 'head_key' )->willReturnCallback(
			static function ( string $key ) use ( &$remote_heads ): array {
				return $remote_heads[ $key ] ?? array( 'exists' => false );
			}
		);
		$storage->method( 'upload_file_to_key' )->willReturnCallback(
			static function ( string $absolute, string $key ) use ( &$remote_heads ): void {
				$remote_heads[ $key ] = array(
					'exists'         => true,
					'content_length' => (int) filesize( $absolute ),
				);
			}
		);

		$file_resolver = $this->getMockBuilder( AttachmentFileResolver::class )
			->setConstructorArgs( array( $keys ) )
			->onlyMethods( array( 'existing_local_files', 'relative_paths' ) )
			->getMock();
		$file_resolver->method( 'existing_local_files' )->willReturn( $files );
		$file_resolver->method( 'relative_paths' )->willReturn( array_keys( $files ) );

		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find_default_upload_target' )->willReturn( $this->storage_profile() );

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
			$profiles,
			$transfer
		);
	}

	private function given_original_attachment( int $attachment_id, string $relative ): void {
		WpStubs::set_meta( $attachment_id, '_wp_attached_file', $relative );
		WpStubs::set_meta(
			$attachment_id,
			'_wp_attachment_metadata',
			array(
				'file'  => $relative,
				'sizes' => array(),
			)
		);
	}

	private function given_image_attachment_with_thumbnail( int $attachment_id ): void {
		WpStubs::set_meta( $attachment_id, '_wp_attached_file', '2026/09/photo.jpg' );
		WpStubs::set_meta(
			$attachment_id,
			'_wp_attachment_metadata',
			array(
				'file'  => '2026/09/photo.jpg',
				'sizes' => array(
					'thumbnail' => array( 'file' => 'photo-150x150.jpg' ),
				),
			)
		);
	}

	private function storage_profile(): StorageProfile {
		return new StorageProfile(
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
	}

	private function touch_file( string $relative, int $size ): string {
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

final class TransferFake {

	/** @var array<string, array<string, mixed>> */
	private array $results_by_key;

	/** @var list<string> */
	public array $uploaded_keys = array();

	/** @var callable|null */
	private $on_verified;

	/**
	 * @param array<string, array<string, mixed>> $results_by_key
	 */
	public function __construct( array $results_by_key, ?callable $on_verified = null ) {
		$this->results_by_key = $results_by_key;
		$this->on_verified    = $on_verified;
	}

	public function set_verified_callback( callable $on_verified ): void {
		$this->on_verified = $on_verified;
	}

	/**
	 * @return array{success: bool, verified_complete: bool, session_uuid: string, resumed: bool, message: string}
	 */
	public function upload_local( int $attachment_id, int $destination_profile_id, string $relative_path, string $destination_key, string $content_type, string $cache_control ): array {
		$this->uploaded_keys[] = $destination_key;
		$configured = $this->results_by_key[ $destination_key ] ?? null;
		if ( is_array( $configured ) && array_is_list( $configured ) ) {
			$result = array_shift( $configured );
			$this->results_by_key[ $destination_key ] = $configured;
			if ( is_array( $result ) ) {
				$this->record_verified_remote( $result, $relative_path, $destination_key );
				return $result;
			}
		}
		if ( is_array( $configured ) ) {
			$this->record_verified_remote( $configured, $relative_path, $destination_key );
			return $configured;
		}
		return array(
			'success'           => false,
			'verified_complete' => false,
			'session_uuid'      => 'missing-fake-result',
			'resumed'           => false,
			'message'           => 'No fake result configured.',
		);
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private function record_verified_remote( array $result, string $relative_path, string $destination_key ): void {
		if ( ! empty( $result['verified_complete'] ) && $this->on_verified !== null ) {
			( $this->on_verified )( $relative_path, $destination_key );
		}
	}
}
