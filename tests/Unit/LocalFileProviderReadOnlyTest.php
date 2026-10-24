<?php
/**
 * LocalFileProvider must not re-download cloud-only originals for read-only callers.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace {
	if ( ! function_exists( 'wp_doing_cron' ) ) {
		function wp_doing_cron() {
			return ! empty( $GLOBALS['kazus_test_doing_cron'] );
		}
	}
	if ( ! function_exists( 'wp_attachment_is' ) ) {
		// Mirrors core: reads the attached file path only to inspect its extension.
		function wp_attachment_is( $type, $post = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			return $GLOBALS['kazus_test_provider']->filter_get_attached_file( $GLOBALS['kazus_test_path'], (int) $post );
		}
	}
	if ( ! function_exists( 'load_image_to_edit' ) ) {
		// Mirrors core: the Image Editor needs the bytes.
		function load_image_to_edit( $post_id ) {
			return $GLOBALS['kazus_test_provider']->filter_get_attached_file( $GLOBALS['kazus_test_path'], (int) $post_id );
		}
	}
	if ( ! function_exists( 'wp_prepare_attachment_for_js' ) ) {
		function wp_prepare_attachment_for_js( $post ) {
			return $GLOBALS['kazus_test_provider']->filter_get_attached_file( $GLOBALS['kazus_test_path'], (int) $post );
		}
	}
}

namespace Kazcode\WpStorage\Tests {

	use Kazcode\WpStorage\Attachment\LocalFileProvider;
	use Kazcode\WpStorage\Core\Settings;
	use Kazcode\WpStorage\Storage\S3KeyResolver;
	use Kazcode\WpStorage\Storage\S3Storage;
	use Kazcode\WpStorage\Tests\Support\WpStubs;
	use PHPUnit\Framework\TestCase;

	final class LocalFileProviderReadOnlyTest extends TestCase {

		private string $uploads;

		protected function setUp(): void {
			WpStubs::reset();
			$this->uploads = sys_get_temp_dir() . '/s3ms-lfp-' . uniqid( '', true );
			mkdir( $this->uploads . '/2026/08', 0777, true );
			WpStubs::$uploads_basedir = $this->uploads;
			WpStubs::$post_meta[9]['_s3ms_status']      = 'offloaded';
			WpStubs::$post_meta[9]['_wp_attached_file'] = '2026/08/photo.jpg';
			$GLOBALS['kazus_test_doing_cron'] = true;
			$GLOBALS['kazus_test_path']       = $this->uploads . '/2026/08/photo.jpg';
		}

		protected function tearDown(): void {
			@unlink( $this->uploads . '/2026/08/photo.jpg' );
			@rmdir( $this->uploads . '/2026/08' );
			@rmdir( $this->uploads . '/2026' );
			@rmdir( $this->uploads );
			unset( $GLOBALS['kazus_test_doing_cron'], $GLOBALS['kazus_test_path'], $GLOBALS['kazus_test_provider'] );
			WpStubs::reset();
		}

		public function test_type_check_and_media_modal_never_download(): void {
			$GLOBALS['kazus_test_provider'] = $this->provider( $this->never() );

			\wp_attachment_is( 'image', 9 );
			\wp_prepare_attachment_for_js( 9 );

			$this->assertFileDoesNotExist( $GLOBALS['kazus_test_path'] );
		}

		public function test_an_unknown_caller_does_not_download(): void {
			$provider = $this->provider( $this->never() );
			$provider->filter_get_attached_file( $GLOBALS['kazus_test_path'], 9 );
			$this->assertFileDoesNotExist( $GLOBALS['kazus_test_path'] );
		}

		public function test_the_image_editor_still_materializes(): void {
			$GLOBALS['kazus_test_provider'] = $this->provider( $this->once() );

			\load_image_to_edit( 9 );

			$this->assertFileExists( $GLOBALS['kazus_test_path'] );
		}

		public function test_integrations_can_opt_in(): void {
			$provider = $this->provider( $this->once() );
			add_filter( 'kazus_materialize_on_demand', static fn( bool $reads, int $id ): bool => $reads || $id === 9, 10, 2 );

			$provider->filter_get_attached_file( $GLOBALS['kazus_test_path'], 9 );

			$this->assertFileExists( $GLOBALS['kazus_test_path'] );
		}

		private function provider( \PHPUnit\Framework\MockObject\Rule\InvocationOrder $downloads ): LocalFileProvider {
			$settings = $this->createMock( Settings::class );
			$settings->method( 'is_enabled' )->willReturn( true );
			$settings->method( 'is_aws_configured' )->willReturn( true );
			$keys = $this->createMock( S3KeyResolver::class );
			$keys->method( 'normalize_relative' )->willReturnArgument( 0 );
			$storage = $this->createMock( S3Storage::class );
			$storage->method( 'keys' )->willReturn( $keys );
			$storage->method( 'head_relative' )->willReturn( array( 'exists' => true ) );
			$storage->expects( $downloads )->method( 'download_relative' )->willReturnCallback(
				static function ( string $relative, string $absolute ): void {
					unset( $relative );
					file_put_contents( $absolute, 'bytes' );
				}
			);
			return new LocalFileProvider( $settings, $storage );
		}
	}
}
