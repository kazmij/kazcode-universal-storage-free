<?php
/**
 * REST attachment URL response compatibility tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace {
	if ( ! class_exists( 'WP_Post' ) ) {
		class WP_Post {
			public function __construct( public int $ID ) {}
		}
	}

	if ( ! class_exists( 'WP_REST_Response' ) ) {
		class WP_REST_Response {
			public function __construct( private mixed $data = null, private int $status = 200 ) {}
			public function get_data(): mixed { return $this->data; }
			public function set_data( mixed $data ): void { $this->data = $data; }
			public function get_status(): int { return $this->status; }
		}
	}
}

namespace Kazcode\WpStorage\Tests {

	use Kazcode\WpStorage\Attachment\AttachmentUrlFilter;
	use Kazcode\WpStorage\Attachment\AttachmentOffloader;
	use Kazcode\WpStorage\Core\Settings;
	use Kazcode\WpStorage\Storage\ProfileDeliveryUrlResolver;
	use Kazcode\WpStorage\Storage\PublicUrlResolver;
	use Kazcode\WpStorage\Storage\S3KeyResolver;
	use Kazcode\WpStorage\Tests\Support\WpStubs;
	use PHPUnit\Framework\TestCase;

	final class AttachmentUrlFilterRestResponseTest extends TestCase {
		protected function setUp(): void {
			WpStubs::reset();
			WpStubs::$post_meta[ 71 ] = array(
				'_s3ms_status'      => AttachmentOffloader::STATUS_OFFLOADED,
				'_wp_attached_file' => '2026/10/browser-lifecycle.txt',
			);
		}

		public function test_rest_prepare_rewrites_object_shaped_media_details_without_fatal_error(): void {
			$settings = $this->createMock( Settings::class );
			$settings->method( 'is_serve_enabled' )->willReturn( true );
			$profiles = $this->createMock( ProfileDeliveryUrlResolver::class );
			$profiles->method( 'url_for_attachment_relative' )->willReturn( 'http://minio-loopback/example' );
			$filter = new AttachmentUrlFilter(
				$settings,
				$this->createMock( PublicUrlResolver::class ),
				$this->createMock( S3KeyResolver::class ),
				null,
				$profiles
			);
			$response = new \WP_REST_Response(
				array(
					'source_url'    => 'http://127.0.0.1/uploads/browser-lifecycle.txt',
					'media_details' => (object) array( 'sizes' => array() ),
				)
			);

			$result = $filter->filter_rest_prepare( $response, new \WP_Post( 71 ), null );

			$this->assertSame( $response, $result );
			$this->assertSame( 'http://minio-loopback/example', $result->get_data()['source_url'] );
		}

		/**
		 * Regression: an offloaded ZIP with no thumbnail metadata must keep WordPress's
		 * native archive icon. resolve_size_url( thumbnail ) falls back to the full
		 * object in that case — that fallback must never replace response[icon].
		 */
		public function test_prepare_for_js_keeps_native_zip_icon_when_thumbnail_metadata_is_absent(): void {
			WpStubs::$post_meta[72] = array(
				'_s3ms_status'      => AttachmentOffloader::STATUS_OFFLOADED,
				'_wp_attached_file' => '2026/10/browser-lifecycle.zip',
			);

			$native_icon = 'http://127.0.0.1/wp-includes/images/media/archive.png';
			$filter      = $this->filter();

			$result = $filter->filter_prepare_for_js(
				array(
					'id'   => 72,
					'url'  => 'http://127.0.0.1/uploads/2026/10/browser-lifecycle.zip',
					'icon' => $native_icon,
				),
				new \WP_Post( 72 ),
				null
			);

			$this->assertSame( $native_icon, $result['icon'] );
			$this->assertSame( 'http://minio-loopback/2026/10/browser-lifecycle.zip', $result['url'] );
			$this->assertNotSame( $result['url'], $result['icon'] );
			$this->assertStringEndsWith( 'archive.png', $result['icon'] );
		}

		/**
		 * When a real intermediate thumbnail exists, prepare_for_js may replace icon
		 * with that thumbnail's remote URL (not the full object).
		 */
		public function test_prepare_for_js_replaces_icon_with_remote_thumbnail_when_intermediate_exists(): void {
			WpStubs::$post_meta[73] = array(
				'_s3ms_status'            => AttachmentOffloader::STATUS_OFFLOADED,
				'_wp_attached_file'       => '2026/10/photo.jpg',
				'_wp_attachment_metadata' => array(
					'width'  => 2400,
					'height' => 1600,
					'sizes'  => array(
						'thumbnail' => array(
							'file'   => 'photo-150x150.jpg',
							'width'  => 150,
							'height' => 150,
						),
					),
				),
			);

			$filter = $this->filter();

			$result = $filter->filter_prepare_for_js(
				array(
					'id'   => 73,
					'url'  => 'http://127.0.0.1/uploads/2026/10/photo.jpg',
					'icon' => 'http://127.0.0.1/uploads/2026/10/photo.jpg',
				),
				new \WP_Post( 73 ),
				null
			);

			$this->assertSame( 'http://minio-loopback/2026/10/photo-150x150.jpg', $result['icon'] );
			$this->assertSame( 'http://minio-loopback/2026/10/photo.jpg', $result['url'] );
			$this->assertNotSame( $result['url'], $result['icon'] );
		}

		/**
		 * Media Library list mode renders wp_get_attachment_image( $id, 'thumbnail', true ):
		 * for a ZIP without intermediate sizes the filters must not hand WordPress the
		 * full object URL as an <img>, or the native archive icon is replaced by a
		 * broken image (#73 E2E finding).
		 */
		public function test_image_downsize_and_src_leave_non_image_without_intermediate_to_native_icon(): void {
			WpStubs::$post_meta[74] = array(
				'_s3ms_status'      => AttachmentOffloader::STATUS_OFFLOADED,
				'_wp_attached_file' => '2026/10/archive.zip',
			);
			$filter      = $this->filter();
			$native_icon = array( 'http://127.0.0.1/wp-includes/images/media/archive.png', 48, 64, false );

			$this->assertFalse( $filter->filter_image_downsize( false, 74, 'thumbnail' ) );
			$this->assertSame( $native_icon, $filter->filter_image_src( $native_icon, 74, 'thumbnail', true ) );
		}

		public function test_image_downsize_still_serves_full_remote_image_when_no_intermediate_exists(): void {
			WpStubs::$post_meta[75] = array(
				'_s3ms_status'      => AttachmentOffloader::STATUS_OFFLOADED,
				'_wp_attached_file' => '2026/10/small.png',
			);

			$downsize = $this->filter()->filter_image_downsize( false, 75, 'thumbnail' );

			$this->assertIsArray( $downsize );
			$this->assertSame( 'http://minio-loopback/2026/10/small.png', $downsize[0] );
		}

		public function test_image_downsize_uses_real_intermediate_preview_for_non_image(): void {
			WpStubs::$post_meta[76] = array(
				'_s3ms_status'            => AttachmentOffloader::STATUS_OFFLOADED,
				'_wp_attached_file'       => '2026/10/manual.pdf',
				'_wp_attachment_metadata' => array(
					'sizes' => array(
						'thumbnail' => array(
							'file'   => 'manual-pdf-116x150.jpg',
							'width'  => 116,
							'height' => 150,
						),
					),
				),
			);

			$downsize = $this->filter()->filter_image_downsize( false, 76, 'thumbnail' );

			$this->assertIsArray( $downsize );
			$this->assertSame( 'http://minio-loopback/2026/10/manual-pdf-116x150.jpg', $downsize[0] );
		}

		private function seed_photo( int $id ): void {
			WpStubs::$post_meta[ $id ] = array(
				'_s3ms_status'                => AttachmentOffloader::STATUS_OFFLOADED,
				'_wp_attached_file'           => '2026/10/photo-e123.jpg',
				'_wp_attachment_metadata'     => array(
					'file'  => '2026/10/photo-e123.jpg',
					'sizes' => array(
						'medium' => array( 'file' => 'photo-e123-300x200.jpg', 'width' => 300, 'height' => 200 ),
					),
				),
				'_wp_attachment_backup_sizes' => array(
					'full-orig' => array( 'file' => 'photo.jpg', 'width' => 1200, 'height' => 800 ),
				),
			);
		}

		/**
		 * #65: post content keeps the canonical uploads URL; the rendered src is
		 * pointed at the delivery URL so it survives local cleanup.
		 */
		public function test_content_img_src_owned_by_the_attachment_is_rendered_from_delivery(): void {
			$this->seed_photo( 80 );
			$tag = '<img src="http://example.test/uploads/2026/10/photo-e123-300x200.jpg" alt="" class="wp-image-80" />';

			$result = $this->filter()->filter_content_img_tag( $tag, 'the_content', 80 );

			$this->assertSame( '<img src="http://minio-loopback/2026/10/photo-e123-300x200.jpg" alt="" class="wp-image-80" />', $result );
		}

		public function test_https_and_query_variants_of_the_uploads_url_are_recognised(): void {
			$this->seed_photo( 81 );
			$tag = '<img class="wp-image-81" src="https://example.test/uploads/2026/10/photo.jpg?ver=2">';

			$result = $this->filter()->filter_content_img_tag( $tag, 'the_content', 81 );

			$this->assertStringContainsString( 'src="http://minio-loopback/2026/10/photo.jpg"', $result, 'pre-edit backup file is still owned' );
		}

		public function test_class_id_and_src_file_mismatch_is_left_untouched_and_reported(): void {
			$this->seed_photo( 82 );
			$reported = array();
			add_action(
				'kazus_content_image_identity_mismatch',
				static function ( int $id, string $relative ) use ( &$reported ): void {
					$reported[] = array( $id, $relative );
				},
				10,
				2
			);
			$tag = '<img src="http://example.test/uploads/2026/10/someone-else.jpg" class="wp-image-82" />';

			$this->assertSame( $tag, $this->filter()->filter_content_img_tag( $tag, 'the_content', 82 ) );
			$this->assertSame( array( array( 82, '2026/10/someone-else.jpg' ) ), $reported );
		}

		public function test_non_offloaded_external_or_classless_images_are_untouched(): void {
			$this->seed_photo( 83 );
			WpStubs::$post_meta[83]['_s3ms_status'] = 'partial';
			$local    = '<img src="http://example.test/uploads/2026/10/photo-e123.jpg" class="wp-image-83" />';
			$external = '<img src="https://cdn.other.test/photo-e123.jpg" class="wp-image-84" />';
			$this->seed_photo( 84 );

			$filter = $this->filter();
			$this->assertSame( $local, $filter->filter_content_img_tag( $local, 'the_content', 83 ) );
			$this->assertSame( $external, $filter->filter_content_img_tag( $external, 'the_content', 84 ) );
			$this->assertSame( $local, $filter->filter_content_img_tag( $local, 'the_content', 0 ) );
		}

		private function filter(): AttachmentUrlFilter {
			$settings = $this->createMock( Settings::class );
			$settings->method( 'is_serve_enabled' )->willReturn( true );
			$settings->method( 'has_public_url_config' )->willReturn( true );
			$profiles = $this->createMock( ProfileDeliveryUrlResolver::class );
			$profiles->method( 'url_for_attachment_relative' )->willReturnCallback(
				static function ( int $attachment_id, string $relative ): string {
					unset( $attachment_id );
					return 'http://minio-loopback/' . ltrim( str_replace( '\\', '/', $relative ), '/' );
				}
			);
			return new AttachmentUrlFilter(
				$settings,
				$this->createMock( PublicUrlResolver::class ),
				new S3KeyResolver( $settings ),
				null,
				$profiles
			);
		}
	}
}
