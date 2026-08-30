<?php
/**
 * Profile-affinity regressions for existing remote objects.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Attachment\AttachmentRestorer;
use Kazcode\WpStorage\Attachment\AttachmentUrlFilter;
use Kazcode\WpStorage\Attachment\LocalFileProvider;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Services\ProfileAwareObjectOperations;
use Kazcode\WpStorage\Services\VerificationService;
use Kazcode\WpStorage\Storage\PublicUrlResolver;
use Kazcode\WpStorage\Storage\S3KeyResolver;
use Kazcode\WpStorage\Storage\S3Storage;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class ProfileAffinityHotspotTest extends TestCase {

	private string $uploads;

	protected function setUp(): void {
		WpStubs::reset();
		$this->uploads              = sys_get_temp_dir() . '/kazus-profile-affinity-' . bin2hex( random_bytes( 4 ) );
		WpStubs::$uploads_basedir   = $this->uploads;
	}

	protected function tearDown(): void {
		$this->remove_dir( $this->uploads );
		WpStubs::reset();
	}

	public function test_private_signed_url_uses_inventory_profile_not_current_default_storage(): void {
		$settings = $this->settings();
		$settings->method( 'is_serve_enabled' )->willReturn( true );
		$settings->method( 'has_public_url_config' )->willReturn( true );
		$settings->method( 'is_private_media' )->willReturn( true );
		$settings->method( 'signed_url_ttl' )->willReturn( 300 );

		$legacy = $this->legacy_storage( $settings );
		$legacy->expects( $this->never() )->method( 'presigned_url_for_relative' );

		$ops = $this->createMock( ProfileAwareObjectOperations::class );
		$ops->expects( $this->once() )
			->method( 'presigned_url_for_attachment_relative' )
			->with( 77, '2026/08/private.jpg', 300 )
			->willReturn( 'https://r2.example/private.jpg?test-token=redacted' );

		$this->attachment( 77, '2026/08/private.jpg' );
		$filter = new AttachmentUrlFilter(
			$settings,
			$this->createMock( PublicUrlResolver::class ),
			new S3KeyResolver( $settings ),
			$legacy,
			null,
			$ops
		);

		$this->assertSame(
			'https://r2.example/private.jpg?test-token=redacted',
			$filter->filter_attachment_url( 'https://default.example/private.jpg', 77 )
		);
	}

	public function test_restore_uses_inventory_profile_and_preserves_partial_restore_safety(): void {
		$settings = $this->settings();
		$legacy   = $this->legacy_storage( $settings );
		$legacy->expects( $this->never() )->method( 'head_relative' );
		$legacy->expects( $this->never() )->method( 'download_relative' );

		$this->attachment(
			88,
			'2026/08/photo.jpg',
			array(
				'thumbnail' => array( 'file' => 'photo-150x150.jpg' ),
			)
		);

		$ops = $this->createMock( ProfileAwareObjectOperations::class );
		$ops->method( 'head_attachment_relative' )->willReturnCallback(
			static function ( int $attachment_id, string $relative ): array {
				return array(
					'exists'          => $relative === '2026/08/photo.jpg',
					'confirmed_missing' => $relative !== '2026/08/photo.jpg',
					'location_status' => 'found',
				);
			}
		);
		$ops->method( 'download_attachment_relative_to_local' )->willReturnCallback(
			static function ( int $attachment_id, string $relative, string $absolute ): array {
				if ( $relative !== '2026/08/photo.jpg' ) {
					return array( 'success' => false );
				}
				if ( ! is_dir( dirname( $absolute ) ) ) {
					mkdir( dirname( $absolute ), 0777, true );
				}
				file_put_contents( $absolute, 'r2-bytes' );
				return array( 'success' => true );
			}
		);

		$restorer = new AttachmentRestorer( $settings, $legacy, null, null, $ops );
		$result   = $restorer->restore( 88 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'partial', $result['status'] ?? '' );
		$this->assertSame( 'offloaded', WpStubs::$post_meta[88]['_s3ms_status'] ?? '' );
	}

	public function test_local_materialization_uses_inventory_profile_not_current_default_storage(): void {
		$settings = $this->settings();
		$legacy   = $this->legacy_storage( $settings );
		$legacy->expects( $this->never() )->method( 'head_relative' );
		$legacy->expects( $this->never() )->method( 'download_relative' );

		$this->attachment( 91, '2026/08/editor.jpg' );

		$ops = $this->createMock( ProfileAwareObjectOperations::class );
		$ops->method( 'head_attachment_relative' )->with( 91, '2026/08/editor.jpg' )->willReturn( array( 'exists' => true ) );
		$ops->method( 'download_attachment_relative_to_local' )->willReturnCallback(
			static function ( int $attachment_id, string $relative, string $absolute ): array {
				if ( ! is_dir( dirname( $absolute ) ) ) {
					mkdir( dirname( $absolute ), 0777, true );
				}
				file_put_contents( $absolute, 'profile-bound-bytes' );
				return array( 'success' => true );
			}
		);

		$provider   = new LocalFileProvider( $settings, $legacy, $ops );
		$downloaded = $provider->ensure_local( 91, true );

		$this->assertSame( array( '2026/08/editor.jpg' ), $downloaded );
		$this->assertSame( 'profile-bound-bytes', file_get_contents( $this->uploads . '/2026/08/editor.jpg' ) );
	}

	public function test_verification_uses_inventory_profile_not_current_default_storage(): void {
		$settings = $this->settings();
		$legacy   = $this->legacy_storage( $settings );
		$legacy->expects( $this->never() )->method( 'head_relative' );

		$this->attachment( 92, '2026/08/verify.jpg' );

		$ops = $this->createMock( ProfileAwareObjectOperations::class );
		$ops->expects( $this->once() )
			->method( 'head_attachment_relative' )
			->with( 92, '2026/08/verify.jpg' )
			->willReturn( array( 'exists' => true, 'storage_profile_id' => 2 ) );

		$result = ( new VerificationService( $settings, $legacy, null, $ops ) )->verify( 92 );

		$this->assertSame( 's3_only', $result['status'] );
		$this->assertArrayNotHasKey( '2026/08/verify.jpg', WpStubs::$deleted_files );
	}

	private function settings(): Settings {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_enabled' )->willReturn( true );
		$settings->method( 'is_aws_configured' )->willReturn( true );
		$settings->method( 'get' )->willReturnCallback(
			static fn( string $key, mixed $default = null ): mixed => $key === 'object_prefix' ? 'aws-default/' : $default
		);
		return $settings;
	}

	private function legacy_storage( Settings $settings ): S3Storage {
		$legacy = $this->createMock( S3Storage::class );
		$legacy->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		return $legacy;
	}

	/**
	 * @param array<string, array<string, mixed>> $sizes
	 */
	private function attachment( int $id, string $attached, array $sizes = array() ): void {
		$post            = new \stdClass();
		$post->ID        = $id;
		$post->post_type = 'attachment';
		WpStubs::$posts[ $id ] = $post;
		WpStubs::set_meta( $id, '_s3ms_status', 'offloaded' );
		WpStubs::set_meta( $id, '_s3ms_original_key', 'r2/' . $attached );
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

	private function remove_dir( string $dir ): void {
		if ( $dir === '' || ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( $item === '.' || $item === '..' ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->remove_dir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}
}
