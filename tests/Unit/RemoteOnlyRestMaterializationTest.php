<?php
/**
 * Remote-only REST media materialization regressions.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Attachment\AttachmentOffloader;
use Kazcode\WpStorage\Attachment\LocalFileProvider;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\LocalStoragePolicy;
use Kazcode\WpStorage\Services\ProfileAwareObjectOperations;
use Kazcode\WpStorage\Storage\S3KeyResolver;
use Kazcode\WpStorage\Storage\S3Storage;
use Kazcode\WpStorage\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class RemoteOnlyRestMaterializationTest extends TestCase {

	private string $uploads;

	protected function setUp(): void {
		WpStubs::reset();
		$this->uploads            = sys_get_temp_dir() . '/kazus-rest-materialize-' . bin2hex( random_bytes( 4 ) );
		WpStubs::$uploads_basedir = $this->uploads;
	}

	protected function tearDown(): void {
		$this->remove_dir( $this->uploads );
		WpStubs::reset();
	}

	public function test_authorized_rest_media_edit_materializes_and_cleans_up_remote_only_original(): void {
		WpStubs::$current_user_caps['edit_post'] = true;
		$this->attachment( 101, '2026/08/large-scaled.jpg', 'large.jpg' );

		$ops = $this->createMock( ProfileAwareObjectOperations::class );
		$ops->method( 'head_attachment_relative' )->willReturn( array( 'exists' => true ) );
		$ops->method( 'download_attachment_relative_to_local' )->willReturnCallback(
			static function ( int $attachment_id, string $relative, string $absolute ): array {
				if ( ! is_dir( dirname( $absolute ) ) ) {
					mkdir( dirname( $absolute ), 0777, true );
				}
				file_put_contents( $absolute, 'profile-bound-rest-bytes' );
				return array( 'success' => true );
			}
		);

		$provider = new LocalFileProvider( $this->settings( LocalStoragePolicy::REMOTE_ONLY ), $this->legacy_storage(), $ops );
		$provider->filter_rest_request_before_callbacks( null, array(), $this->request( '/wp/v2/media/101', 'edit' ) );

		$filtered = $provider->filter_get_attached_file( $this->uploads . '/2026/08/large-scaled.jpg', 101 );

		$this->assertSame( $this->uploads . '/2026/08/large-scaled.jpg', $filtered );
		$this->assertSame( 'profile-bound-rest-bytes', file_get_contents( $this->uploads . '/2026/08/large-scaled.jpg' ) );
		$this->assertSame( 'profile-bound-rest-bytes', file_get_contents( $this->uploads . '/2026/08/large.jpg' ) );

		$provider->filter_rest_request_after_callbacks( null, array(), $this->request( '/wp/v2/media/101', 'edit' ) );

		$this->assertFileDoesNotExist( $this->uploads . '/2026/08/large-scaled.jpg' );
		$this->assertFileDoesNotExist( $this->uploads . '/2026/08/large.jpg' );
	}

	public function test_rest_view_context_does_not_materialize_remote_file(): void {
		WpStubs::$current_user_caps['edit_post'] = true;
		$this->attachment( 102, '2026/08/view.jpg' );

		$ops = $this->createMock( ProfileAwareObjectOperations::class );
		$ops->expects( $this->never() )->method( 'head_attachment_relative' );
		$ops->expects( $this->never() )->method( 'download_attachment_relative_to_local' );

		$provider = new LocalFileProvider( $this->settings( LocalStoragePolicy::REMOTE_ONLY ), $this->legacy_storage(), $ops );
		$provider->filter_rest_request_before_callbacks( null, array(), $this->request( '/wp/v2/media/102', 'view' ) );

		$provider->filter_get_attached_file( $this->uploads . '/2026/08/view.jpg', 102 );

		$this->assertFileDoesNotExist( $this->uploads . '/2026/08/view.jpg' );
	}

	public function test_unauthorized_rest_edit_context_does_not_materialize_remote_file(): void {
		$this->attachment( 103, '2026/08/unauthorized.jpg' );

		$ops = $this->createMock( ProfileAwareObjectOperations::class );
		$ops->expects( $this->never() )->method( 'head_attachment_relative' );
		$ops->expects( $this->never() )->method( 'download_attachment_relative_to_local' );

		$provider = new LocalFileProvider( $this->settings( LocalStoragePolicy::REMOTE_ONLY ), $this->legacy_storage(), $ops );
		$provider->filter_rest_request_before_callbacks( null, array(), $this->request( '/wp/v2/media/103', 'edit' ) );

		$provider->filter_get_attached_file( $this->uploads . '/2026/08/unauthorized.jpg', 103 );

		$this->assertFileDoesNotExist( $this->uploads . '/2026/08/unauthorized.jpg' );
	}

	public function test_keep_all_rest_materialization_keeps_downloaded_file(): void {
		WpStubs::$current_user_caps['edit_post'] = true;
		$this->attachment( 104, '2026/08/keep.jpg' );

		$ops = $this->createMock( ProfileAwareObjectOperations::class );
		$ops->method( 'head_attachment_relative' )->willReturn( array( 'exists' => true ) );
		$ops->method( 'download_attachment_relative_to_local' )->willReturnCallback(
			static function ( int $attachment_id, string $relative, string $absolute ): array {
				if ( ! is_dir( dirname( $absolute ) ) ) {
					mkdir( dirname( $absolute ), 0777, true );
				}
				file_put_contents( $absolute, 'kept' );
				return array( 'success' => true );
			}
		);

		$provider = new LocalFileProvider( $this->settings( LocalStoragePolicy::KEEP_ALL ), $this->legacy_storage(), $ops );
		$provider->filter_rest_request_before_callbacks( null, array(), $this->request( '/wp/v2/media/104', 'edit' ) );
		$provider->filter_get_attached_file( $this->uploads . '/2026/08/keep.jpg', 104 );
		$provider->filter_rest_request_after_callbacks( null, array(), $this->request( '/wp/v2/media/104', 'edit' ) );

		$this->assertSame( 'kept', file_get_contents( $this->uploads . '/2026/08/keep.jpg' ) );
	}

	public function test_keep_originals_rest_materialization_keeps_downloaded_file(): void {
		WpStubs::$current_user_caps['edit_post'] = true;
		$this->attachment( 105, '2026/08/keep-originals.jpg' );

		$ops = $this->createMock( ProfileAwareObjectOperations::class );
		$ops->method( 'head_attachment_relative' )->willReturn( array( 'exists' => true ) );
		$ops->method( 'download_attachment_relative_to_local' )->willReturnCallback(
			static function ( int $attachment_id, string $relative, string $absolute ): array {
				if ( ! is_dir( dirname( $absolute ) ) ) {
					mkdir( dirname( $absolute ), 0777, true );
				}
				file_put_contents( $absolute, 'kept-originals' );
				return array( 'success' => true );
			}
		);

		$provider = new LocalFileProvider( $this->settings( LocalStoragePolicy::KEEP_ORIGINALS ), $this->legacy_storage(), $ops );
		$provider->filter_rest_request_before_callbacks( null, array(), $this->request( '/wp/v2/media/105', 'edit' ) );
		$provider->filter_get_attached_file( $this->uploads . '/2026/08/keep-originals.jpg', 105 );
		$provider->filter_rest_request_after_callbacks( null, array(), $this->request( '/wp/v2/media/105', 'edit' ) );

		$this->assertSame( 'kept-originals', file_get_contents( $this->uploads . '/2026/08/keep-originals.jpg' ) );
	}

	private function settings( string $policy ): Settings {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_enabled' )->willReturn( true );
		$settings->method( 'is_aws_configured' )->willReturn( true );
		$settings->method( 'local_storage_policy' )->willReturn( $policy );
		$settings->method( 'get' )->willReturnCallback(
			static fn( string $key, mixed $default = null ): mixed => $key === 'object_prefix' ? 'rest/' : $default
		);
		return $settings;
	}

	private function legacy_storage(): S3Storage {
		$settings = $this->settings( LocalStoragePolicy::REMOTE_ONLY );
		$legacy   = $this->createMock( S3Storage::class );
		$legacy->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		return $legacy;
	}

	private function request( string $route, string $context ): object {
		return new class($route, $context) {
			public function __construct(private string $route, private string $context) {}
			public function get_route(): string {
				return $this->route;
			}
			public function get_param( string $key ): ?string {
				return $key === 'context' ? $this->context : null;
			}
		};
	}

	private function attachment( int $id, string $attached, string $original = '' ): void {
		$post            = new \stdClass();
		$post->ID        = $id;
		$post->post_type = 'attachment';
		WpStubs::$posts[ $id ] = $post;
		WpStubs::set_meta( $id, '_s3ms_status', AttachmentOffloader::STATUS_OFFLOADED );
		WpStubs::set_meta( $id, '_wp_attached_file', $attached );
		$meta = array(
			'file'  => $attached,
			'sizes' => array(),
		);
		if ( $original !== '' ) {
			$meta['original_image'] = $original;
		}
		WpStubs::set_meta( $id, '_wp_attachment_metadata', $meta );
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
