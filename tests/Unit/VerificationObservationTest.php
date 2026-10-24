<?php
/**
 * Remote observation semantics for attachment verification.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Attachment\AttachmentOffloader;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Services\ProfileAwareObjectOperations;
use Kazcode\WpStorage\Services\VerificationService;
use Kazcode\WpStorage\Storage\S3KeyResolver;
use Kazcode\WpStorage\Storage\S3Storage;
use Kazcode\WpStorage\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class VerificationObservationTest extends TestCase {

	private string $uploads;

	protected function setUp(): void {
		WpStubs::reset();
		$this->uploads            = sys_get_temp_dir() . '/kazus-verify-observation-' . bin2hex( random_bytes( 4 ) );
		WpStubs::$uploads_basedir = $this->uploads;
		wp_mkdir_p( $this->uploads . '/2026/08' );
	}

	protected function tearDown(): void {
		$this->remove_dir( $this->uploads );
		WpStubs::reset();
	}

	public function test_transient_head_error_is_unknown_not_missing(): void {
		$this->attachment( 201, '2026/08/photo.jpg', array( 'thumbnail' => array( 'file' => 'photo-150.jpg' ) ) );

		$ops = $this->createMock( ProfileAwareObjectOperations::class );
		$ops->method( 'head_attachment_relative' )->willReturn(
			array(
				'exists'            => false,
				'confirmed_missing' => false,
				'error'             => 'Connection timed out.',
			)
		);

		$result = ( new VerificationService( $this->settings(), $this->legacy_storage(), null, $ops, $this->empty_objects() ) )->verify( 201 );

		$this->assertSame( 'remote_unknown', $result['status'] );
		$this->assertStringContainsString( 'Remote status unknown', implode( "\n", $result['details'] ) );
		$this->assertStringNotContainsString( 'Missing on S3', implode( "\n", $result['details'] ) );
		$this->assertSame( '', get_post_meta( 201, '_s3ms_verified_at', true ) );
	}

	public function test_confirmed_404_is_reported_as_missing_original(): void {
		$this->attachment( 202, '2026/08/photo.jpg' );

		$ops = $this->createMock( ProfileAwareObjectOperations::class );
		$ops->method( 'head_attachment_relative' )->willReturn(
			array(
				'exists'            => false,
				'confirmed_missing' => true,
			)
		);

		$result = ( new VerificationService( $this->settings(), $this->legacy_storage(), null, $ops, $this->empty_objects() ) )->verify( 202 );

		$this->assertSame( 'missing_s3_original', $result['status'] );
		$this->assertStringContainsString( 'Confirmed missing on S3', implode( "\n", $result['details'] ) );
	}

	public function test_size_mismatch_is_not_verified(): void {
		$this->attachment( 203, '2026/08/photo.jpg' );
		file_put_contents( $this->uploads . '/2026/08/photo.jpg', str_repeat( 'a', 1000 ) );

		$ops = $this->createMock( ProfileAwareObjectOperations::class );
		$ops->method( 'head_attachment_relative' )->willReturn(
			array(
				'exists'         => true,
				'content_length' => 900,
			)
		);

		$result = ( new VerificationService( $this->settings(), $this->legacy_storage(), null, $ops, $this->empty_objects() ) )->verify( 203 );

		$this->assertSame( 'remote_size_mismatch', $result['status'] );
		$this->assertStringContainsString( 'Remote size mismatch', implode( "\n", $result['details'] ) );
		$this->assertSame( '', get_post_meta( 203, '_s3ms_verified_at', true ) );
	}

	public function test_existence_only_partial_local_does_not_promote_offloaded(): void {
		$this->attachment(
			204,
			'2026/08/photo.jpg',
			array( 'thumbnail' => array( 'file' => 'photo-150.jpg' ) )
		);
		WpStubs::set_meta( 204, '_s3ms_status', AttachmentOffloader::STATUS_FAILED );
		file_put_contents( $this->uploads . '/2026/08/photo.jpg', str_repeat( 'a', 500 ) );
		// Thumbnail has no local file and no inventory size — HEAD can only prove existence.

		$ops = $this->createMock( ProfileAwareObjectOperations::class );
		$ops->method( 'head_attachment_relative' )->willReturnCallback(
			static function ( int $attachment_id, string $relative ): array {
				unset( $attachment_id );
				if ( str_contains( $relative, 'photo-150' ) ) {
					return array(
						'exists'         => true,
						'content_length' => 120,
					);
				}
				return array(
					'exists'         => true,
					'content_length' => 500,
				);
			}
		);

		$result = ( new VerificationService( $this->settings(), $this->legacy_storage(), null, $ops, $this->empty_objects() ) )->verify( 204 );

		$this->assertSame( 'remote_existence_only', $result['status'] );
		$this->assertSame( AttachmentOffloader::STATUS_FAILED, get_post_meta( 204, '_s3ms_status', true ) );
		$this->assertSame( '', get_post_meta( 204, '_s3ms_verified_at', true ) );
	}

	public function test_confirmed_missing_outranks_existence_only_status(): void {
		$this->attachment(
			207,
			'2026/08/photo.jpg',
			array( 'thumbnail' => array( 'file' => 'photo-150.jpg' ) )
		);
		file_put_contents( $this->uploads . '/2026/08/photo.jpg', str_repeat( 'a', 500 ) );

		$ops = $this->createMock( ProfileAwareObjectOperations::class );
		$ops->method( 'head_attachment_relative' )->willReturnCallback(
			static function ( int $attachment_id, string $relative ): array {
				unset( $attachment_id );
				if ( str_contains( $relative, 'photo-150' ) ) {
					return array(
						'exists'            => false,
						'confirmed_missing' => true,
					);
				}
				return array(
					'exists'         => true,
					'content_length' => 500,
				);
			}
		);

		$result = ( new VerificationService( $this->settings(), $this->legacy_storage(), null, $ops, $this->empty_objects() ) )->verify( 207 );

		$this->assertSame( 'partial_offload', $result['status'] );
		$this->assertNotSame( 'remote_existence_only', $result['status'] );
		$this->assertNotEmpty( $result['missing'] );
	}

	public function test_inventory_size_enables_size_verified_when_local_missing(): void {
		$this->attachment( 205, '2026/08/photo.jpg' );
		WpStubs::set_meta( 205, '_s3ms_status', AttachmentOffloader::STATUS_OFFLOADED );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->willReturn(
			array(
				array(
					'local_relative_path' => '2026/08/photo.jpg',
					'storage_profile_id'  => 3,
					'object_key'          => 'uploads/2026/08/photo.jpg',
					'remote_status'       => 'present',
					'size_bytes'          => 800,
				),
			)
		);

		$ops = $this->createMock( ProfileAwareObjectOperations::class );
		$ops->method( 'head_attachment_relative' )->willReturn(
			array(
				'exists'             => true,
				'content_length'     => 800,
				'storage_profile_id' => 3,
				'object_key'         => 'uploads/2026/08/photo.jpg',
			)
		);

		$result = ( new VerificationService( $this->settings(), $this->legacy_storage(), null, $ops, $objects ) )->verify( 205 );

		$this->assertSame( 's3_only', $result['status'] );
		$this->assertNotSame( '', get_post_meta( 205, '_s3ms_verified_at', true ) );
	}

	public function test_inventory_size_uses_head_selected_profile_not_earlier_failed_row(): void {
		$this->attachment( 206, '2026/08/photo.jpg' );
		WpStubs::set_meta( 206, '_s3ms_status', AttachmentOffloader::STATUS_FAILED );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->willReturn(
			array(
				array(
					'local_relative_path' => '2026/08/photo.jpg',
					'storage_profile_id'  => 1,
					'object_key'          => 'old/uploads/2026/08/photo.jpg',
					'remote_status'       => 'failed',
					'size_bytes'          => 100,
				),
				array(
					'local_relative_path' => '2026/08/photo.jpg',
					'storage_profile_id'  => 2,
					'object_key'          => 'new/uploads/2026/08/photo.jpg',
					'remote_status'       => 'present',
					'size_bytes'          => 500,
				),
			)
		);

		$ops = $this->createMock( ProfileAwareObjectOperations::class );
		$ops->method( 'head_attachment_relative' )->willReturn(
			array(
				'exists'             => true,
				'content_length'     => 500,
				'storage_profile_id' => 2,
				'object_key'         => 'new/uploads/2026/08/photo.jpg',
			)
		);

		$result = ( new VerificationService( $this->settings(), $this->legacy_storage(), null, $ops, $objects ) )->verify( 206 );

		$this->assertSame( 's3_only', $result['status'] );
		$this->assertNotSame( 'remote_size_mismatch', $result['status'] );
		// s3_only confirms size against the HEAD-selected row; it does not rewrite status here.
		$this->assertSame( AttachmentOffloader::STATUS_FAILED, get_post_meta( 206, '_s3ms_status', true ) );
	}

	private function attachment( int $id, string $attached, array $sizes = array() ): void {
		WpStubs::$posts[ $id ] = (object) array(
			'ID'        => $id,
			'post_type' => 'attachment',
		);
		WpStubs::set_meta( $id, '_s3ms_status', AttachmentOffloader::STATUS_OFFLOADED );
		WpStubs::set_meta( $id, '_wp_attached_file', $attached );
		WpStubs::set_meta(
			$id,
			'_wp_attachment_metadata',
			array(
				'file'  => $attached,
				'sizes' => $sizes,
			)
		);
	}

	private function empty_objects(): ObjectRepository {
		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->willReturn( array() );
		return $objects;
	}

	private function settings(): Settings {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_aws_configured' )->willReturn( true );
		return $settings;
	}

	private function legacy_storage(): S3Storage {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'get' )->willReturn( '' );
		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		return $storage;
	}

	private function remove_dir( string $dir ): void {
		if ( $dir === '' || ! is_dir( $dir ) ) {
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
