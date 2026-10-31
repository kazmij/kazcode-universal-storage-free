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
	use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
	use Kazcode\WpStorage\Domain\StorageProfile;
	use Kazcode\WpStorage\Infrastructure\ObjectRepository;
	use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
	use Kazcode\WpStorage\Services\ProfileAwareObjectOperations;
	use Kazcode\WpStorage\Services\ProfileObjectLocation;
	use Kazcode\WpStorage\Services\ProfileObjectLocator;
	use Kazcode\WpStorage\Storage\ProfileDeliveryUrlResolver;
	use Kazcode\WpStorage\Storage\ProfileStorageGateway;
	use Kazcode\WpStorage\Storage\PublicUrlResolver;
	use Kazcode\WpStorage\Storage\S3KeyResolver;
	use Kazcode\WpStorage\Storage\S3Storage;
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

		public function test_reverse_lookup_preserves_a_core_attachment_match(): void {
			$profiles = $this->createMock( ProfileDeliveryUrlResolver::class );
			$profiles->expects( $this->never() )->method( 'attachment_id_for_delivery_url' );
			$filter = new AttachmentUrlFilter(
				$this->createMock( Settings::class ),
				$this->createMock( PublicUrlResolver::class ),
				$this->createMock( S3KeyResolver::class ),
				null,
				$profiles
			);

			$this->assertSame( 88, $filter->filter_attachment_url_to_postid( 88, 'https://media.example.test/2026/10/photo.jpg' ) );
		}

		public function test_reverse_lookup_returns_the_single_profile_proven_owner(): void {
			$profiles = $this->createMock( ProfileDeliveryUrlResolver::class );
			$profiles->expects( $this->once() )
				->method( 'attachment_id_for_delivery_url' )
				->with( 'https://media.example.test/2026/10/photo.jpg' )
				->willReturn( 89 );
			$filter = new AttachmentUrlFilter(
				$this->createMock( Settings::class ),
				$this->createMock( PublicUrlResolver::class ),
				$this->createMock( S3KeyResolver::class ),
				null,
				$profiles
			);

			$this->assertSame( 89, $filter->filter_attachment_url_to_postid( 0, 'https://media.example.test/2026/10/photo.jpg' ) );
		}

		public function test_reverse_lookup_accepts_wordpress_null_miss_value(): void {
			$resolver = $this->createMock( ProfileDeliveryUrlResolver::class );
			$resolver->expects( $this->once() )
				->method( 'attachment_id_for_delivery_url' )
				->with( 'https://media.example.test/2026/10/photo.jpg' )
				->willReturn( 90 );
			$filter = new AttachmentUrlFilter(
				$this->createMock( Settings::class ),
				$this->createMock( PublicUrlResolver::class ),
				$this->createMock( S3KeyResolver::class ),
				null,
				$resolver
			);

			$this->assertSame( 90, $filter->filter_attachment_url_to_postid( null, 'https://media.example.test/2026/10/photo.jpg' ) );
		}

		/**
		 * Third-party media integrations occasionally send arbitrary values to
		 * WordPress filters. They are not attachment IDs merely because PHP can
		 * coerce them: Core callbacks must preserve the preceding result without a
		 * fatal error or an accidental lookup of attachment 71/1 (#164).
		 */
		public function test_media_url_callbacks_preserve_original_values_for_invalid_external_attachment_ids(): void {
			$filter  = $this->filter();
			$image   = array( 'https://upstream.example/image.jpg', 320, 180, true );
			$sources = array( '320' => array( 'url' => 'https://upstream.example/image.jpg', 'descriptor' => 'w', 'value' => 320 ) );
			$tag     = '<img src="http://example.test/uploads/2026/10/photo.jpg" class="wp-image-71" />';

			foreach ( array( null, '', '71', '71abc', 0, -1, true, new \stdClass() ) as $invalid_id ) {
				$this->assertSame( 'https://upstream.example/original.jpg', $filter->filter_attachment_url( 'https://upstream.example/original.jpg', $invalid_id ) );
				$this->assertSame( $image, $filter->filter_image_src( $image, $invalid_id, 'medium', false ) );
				$this->assertFalse( $filter->filter_image_downsize( false, $invalid_id, 'medium' ) );
				$this->assertSame( $sources, $filter->filter_srcset( $sources, array( 320, 180 ), $image[0], array(), $invalid_id ) );
				$this->assertSame( $tag, $filter->filter_content_img_tag( $tag, 'the_content', $invalid_id ) );
			}
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

		/**
		 * A provider, optimizer, or page builder may have resolved its own exact
		 * variant before KAZCODE runs. That usable WordPress result owns the
		 * request and must survive our callback unchanged.
		 */
		public function test_image_downsize_preserves_a_valid_upstream_tuple_byte_for_byte(): void {
			$upstream = array( 'https://images.example.test/custom-150x150.webp', 150, 150, true );

			$this->assertSame( $upstream, $this->filter()->filter_image_downsize( $upstream, 75, 'thumbnail' ) );
		}

		/**
		 * A cloud-only image may use a remote derivative only when its exact
		 * requested size is present in attachment metadata.
		 */
		public function test_image_downsize_serves_the_exact_remote_thumbnail_variant_for_a_cloud_only_image(): void {
			WpStubs::$post_meta[77] = array(
				'_s3ms_status'            => AttachmentOffloader::STATUS_OFFLOADED,
				'_wp_attached_file'       => '2026/10/cloud-only.jpg',
				'_wp_attachment_metadata' => array(
					'width'  => 1800,
					'height' => 1200,
					'sizes'  => array(
						'thumbnail' => array(
							'file'   => 'cloud-only-150x150.jpg',
							'width'  => 150,
							'height' => 150,
						),
					),
				),
			);

			$this->assertSame(
				array( 'http://minio-loopback/2026/10/cloud-only-150x150.jpg', 150, 150, true ),
				$this->filter()->filter_image_downsize( false, 77, 'thumbnail' )
			);
		}

		/**
		 * wp_get_attachment_image_src runs after image_downsize. It must not undo a
		 * usable remote tuple supplied by an earlier integration by rewriting it to
		 * KAZCODE's own delivery URL.
		 */
		public function test_image_src_preserves_a_pre_resolved_upstream_tuple_after_image_downsize(): void {
			WpStubs::$post_meta[79] = array(
				'_s3ms_status'            => AttachmentOffloader::STATUS_OFFLOADED,
				'_wp_attached_file'       => '2026/10/cloud-only.jpg',
				'_wp_attachment_metadata' => array(
					'sizes' => array(
						'thumbnail' => array( 'file' => 'cloud-only-150x150.jpg', 'width' => 150, 'height' => 150 ),
					),
				),
			);
			$upstream = array( 'http://example.test/uploads/2026/10/upstream-150x150.webp?provider=upstream', 150, 150, true );
			$filter   = $this->filter();

			$this->assertSame( $upstream, $filter->filter_image_downsize( $upstream, 79, 'thumbnail' ) );
			$this->assertSame( $upstream, $filter->filter_image_src( $upstream, 79, 'thumbnail', false ) );
		}

		/**
		 * KAZCODE must not substitute the original object for an unrecorded custom
		 * size: WordPress can then continue its own local/icon fallback instead of
		 * rendering the wrong media dimensions.
		 */
		public function test_image_downsize_leaves_an_unknown_image_variant_for_wordpress_fallback(): void {
			WpStubs::$post_meta[78] = array(
				'_s3ms_status'            => AttachmentOffloader::STATUS_OFFLOADED,
				'_wp_attached_file'       => '2026/10/cloud-only.jpg',
				'_wp_attachment_metadata' => array(
					'width'  => 1800,
					'height' => 1200,
					'sizes'  => array(
						'thumbnail' => array( 'file' => 'cloud-only-150x150.jpg', 'width' => 150, 'height' => 150 ),
					),
				),
			);

			$this->assertFalse( $this->filter()->filter_image_downsize( false, 78, 'third-party-custom-size' ) );
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

		/**
		 * #152: Verify may mark the original missing while a generated size remains
		 * size-verified in storage. The generated size must keep its delivery URL,
		 * otherwise a cloud-only site falls back to bytes that cleanup removed.
		 */
		public function test_partial_attachment_keeps_present_generated_size_remote_when_original_is_missing(): void {
			WpStubs::$post_meta[85] = array(
				'_s3ms_status'            => 'partial',
				'_wp_attached_file'       => '2026/10/partial-photo.jpg',
				'_wp_attachment_metadata' => array(
					'sizes' => array(
						'medium' => array( 'file' => 'partial-photo-300x200.jpg', 'width' => 300, 'height' => 200 ),
					),
				),
			);

			$result = $this->partial_filter(
				85,
				array(
					array( 'local_relative_path' => '2026/10/partial-photo.jpg', 'object_key' => 'uploads/2026/10/partial-photo.jpg', 'storage_profile_id' => 8, 'remote_status' => ObjectRemoteStatus::MISSING ),
					array( 'local_relative_path' => '2026/10/partial-photo-300x200.jpg', 'object_key' => 'uploads/2026/10/partial-photo-300x200.jpg', 'storage_profile_id' => 8, 'remote_status' => ObjectRemoteStatus::PRESENT ),
				)
			)->filter_image_src(
				array( 'http://example.test/uploads/2026/10/partial-photo-300x200.jpg', 300, 200, true ),
				85,
				'medium',
				false
			);

			$this->assertSame( 'https://delivery.example/uploads/2026/10/partial-photo-300x200.jpg', $result[0] );
		}

		/**
		 * #152: a missing generated size must not prevent its independently present
		 * original from being served remotely, while the missing size remains local
		 * for Integrity to report instead of being falsely claimed as deliverable.
		 */
		public function test_partial_attachment_keeps_present_original_remote_when_generated_size_is_missing(): void {
			WpStubs::$post_meta[86] = array(
				'_s3ms_status'            => 'partial',
				'_wp_attached_file'       => '2026/10/partial-original.jpg',
				'_wp_attachment_metadata' => array(
					'sizes' => array(
						'medium' => array( 'file' => 'partial-original-300x200.jpg', 'width' => 300, 'height' => 200 ),
					),
				),
			);

			$result = $this->partial_filter(
				86,
				array(
					array( 'local_relative_path' => '2026/10/partial-original.jpg', 'object_key' => 'uploads/2026/10/partial-original.jpg', 'storage_profile_id' => 8, 'remote_status' => ObjectRemoteStatus::PRESENT ),
					array( 'local_relative_path' => '2026/10/partial-original-300x200.jpg', 'object_key' => 'uploads/2026/10/partial-original-300x200.jpg', 'storage_profile_id' => 8, 'remote_status' => ObjectRemoteStatus::MISSING ),
				)
			)->filter_prepare_for_js(
				array(
					'url' => 'http://example.test/uploads/2026/10/partial-original.jpg',
					'sizes' => array(
						'medium' => array( 'url' => 'http://example.test/uploads/2026/10/partial-original-300x200.jpg' ),
					),
				),
				new \WP_Post( 86 ),
				null
			);

			$this->assertSame( 'https://delivery.example/uploads/2026/10/partial-original.jpg', $result['url'] );
			$this->assertSame( 'http://example.test/uploads/2026/10/partial-original-300x200.jpg', $result['sizes']['medium']['url'] );
		}

		/**
		 * WordPress may derive a size from the remote original before our image
		 * filter runs. A missing partial size must put that inherited URL back to
		 * the canonical uploads URL rather than silently retaining it.
		 */
		public function test_partial_missing_size_reverts_an_inherited_remote_image_url_to_uploads_url(): void {
			WpStubs::$post_meta[88] = array(
				'_s3ms_status'            => 'partial',
				'_wp_attached_file'       => '2026/10/inherited-original.jpg',
				'_wp_attachment_metadata' => array(
					'sizes' => array(
						'medium' => array( 'file' => 'inherited-original-300x200.jpg', 'width' => 300, 'height' => 200 ),
					),
				),
			);
			$filter = $this->partial_filter(
				88,
				array(
					array( 'local_relative_path' => '2026/10/inherited-original.jpg', 'object_key' => 'uploads/2026/10/inherited-original.jpg', 'storage_profile_id' => 8, 'remote_status' => ObjectRemoteStatus::PRESENT ),
					array( 'local_relative_path' => '2026/10/inherited-original-300x200.jpg', 'object_key' => 'uploads/2026/10/inherited-original-300x200.jpg', 'storage_profile_id' => 8, 'remote_status' => ObjectRemoteStatus::MISSING ),
				)
			);

			$result = $filter->filter_image_src(
				array( 'https://delivery.example/uploads/2026/10/inherited-original-300x200.jpg', 300, 200, true ),
				88,
				'medium',
				false
			);

			$this->assertSame( 'http://example.test/uploads/2026/10/inherited-original-300x200.jpg', $result[0] );
		}

		/**
		 * Partial delivery must apply the same exact-row safety rule before creating
		 * a private signed URL. A missing sibling may not receive a signed URL.
		 */
		public function test_partial_attachment_uses_signed_url_only_for_its_present_variant(): void {
			WpStubs::$post_meta[87] = array(
				'_s3ms_status'      => 'partial',
				'_wp_attached_file' => '2026/10/private-original.jpg',
				'_wp_attachment_metadata' => array(
					'sizes' => array(
						'medium' => array( 'file' => 'private-original-300x200.jpg', 'width' => 300, 'height' => 200 ),
					),
				),
			);

			$settings = $this->createMock( Settings::class );
			$settings->method( 'is_serve_enabled' )->willReturn( true );
			$settings->method( 'has_public_url_config' )->willReturn( true );
			$settings->method( 'is_private_media' )->willReturn( true );
			$settings->method( 'signed_url_ttl' )->willReturn( 900 );
			$storage = $this->createMock( S3Storage::class );
			$storage->expects( $this->once() )
				->method( 'presigned_url_for_relative' )
				->with( '2026/10/private-original.jpg', 900 )
				->willReturn( 'https://private.example/signed-original' );

			$filter = $this->partial_filter(
				87,
				array(
					array( 'local_relative_path' => '2026/10/private-original.jpg', 'object_key' => 'uploads/2026/10/private-original.jpg', 'storage_profile_id' => 8, 'remote_status' => ObjectRemoteStatus::PRESENT ),
					array( 'local_relative_path' => '2026/10/private-original-300x200.jpg', 'object_key' => 'uploads/2026/10/private-original-300x200.jpg', 'storage_profile_id' => 8, 'remote_status' => ObjectRemoteStatus::MISSING ),
				),
				$settings,
				$storage
			);

			$this->assertSame( 'https://private.example/signed-original', $filter->filter_attachment_url( 'http://example.test/uploads/2026/10/private-original.jpg', 87 ) );
			$this->assertSame(
				'http://example.test/uploads/2026/10/private-original-300x200.jpg',
				$filter->filter_image_src(
					array( 'https://private.example/signed-stale-size', 300, 200, true ),
					87,
					'medium',
					false
				)[0],
				'A missing sibling must never be presigned.'
			);
		}

		public function test_private_direct_upload_original_is_presigned_from_its_exact_inventory_key(): void {
			WpStubs::$post_meta[ 88 ] = array(
				'_s3ms_status'      => 'offloaded',
				'_wp_attached_file' => '2026/10/direct-original.png',
			);
			$settings = $this->createMock( Settings::class );
			$settings->method( 'is_serve_enabled' )->willReturn( true );
			$settings->method( 'has_public_url_config' )->willReturn( true );
			$settings->method( 'is_private_media' )->willReturn( true );
			$settings->method( 'signed_url_ttl' )->willReturn( 900 );
			$legacy = $this->createMock( S3Storage::class );
			$legacy->expects( $this->never() )->method( 'presigned_url_for_relative' );
			$profile = new StorageProfile( 8, 'p8', 'Delivery', 'r2', 'bucket', 'auto', 'https://r2.example.test', true, 'uploads/', 'cdn', '', false, 'keys', 'ref', true, false, false, '', '' );
			$locator = $this->createMock( ProfileObjectLocator::class );
			$locator->expects( $this->once() )->method( 'locate' )->with( 88, '2026/10/direct-original.png' )->willReturn(
				ProfileObjectLocation::found( array( 'id' => 801 ), $profile, 'uploads/direct-upload/session/attempt/direct-original.png', '2026/10/direct-original.png' )
			);
			$gateway = $this->createMock( ProfileStorageGateway::class );
			$gateway->expects( $this->once() )->method( 'presigned_url_for_key' )
				->with( 'uploads/direct-upload/session/attempt/direct-original.png', 900 )
				->willReturn( 'https://private.example/direct-original?signature=test' );
			$profile_ops = new ProfileAwareObjectOperations( $locator, static fn( StorageProfile $resolved ): ProfileStorageGateway => $gateway, $legacy, $settings );
			$filter = $this->partial_filter(
				88,
				array( array( 'local_relative_path' => '2026/10/direct-original.png', 'object_key' => 'uploads/direct-upload/session/attempt/direct-original.png', 'storage_profile_id' => 8, 'remote_status' => ObjectRemoteStatus::PRESENT ) ),
				$settings,
				$legacy,
				$profile_ops
			);

			$this->assertSame(
				'https://private.example/direct-original?signature=test',
				$filter->filter_attachment_url( 'http://example.test/uploads/2026/10/direct-original.png', 88 )
			);
		}

		public function test_private_direct_upload_original_adapts_srcset_metadata_to_its_signed_object_path(): void {
			WpStubs::$post_meta[ 89 ] = array(
				'_s3ms_status'       => 'offloaded',
				'_wp_attached_file'  => '2026/10/direct-original.png',
				'_s3ms_original_key' => 'uploads/direct-upload/session/attempt/direct-original.png',
			);
			$settings = $this->createMock( Settings::class );
			$settings->method( 'is_serve_enabled' )->willReturn( true );
			$settings->method( 'is_private_media' )->willReturn( true );
			$filter = new AttachmentUrlFilter( $settings, $this->createMock( PublicUrlResolver::class ), new S3KeyResolver( $settings ) );
			$meta = array( 'file' => '2026/10/direct-original.png', 'width' => 1600, 'height' => 1000, 'sizes' => array( 'medium' => array( 'file' => 'direct-original-333x208.png', 'width' => 333, 'height' => 208 ) ) );

			$result = $filter->filter_srcset_metadata( $meta, array( 1600, 1000 ), 'https://private.example/uploads/direct-upload/session/attempt/direct-original.png?signature=test', 89 );

			$this->assertSame( 'uploads/direct-upload/session/attempt/direct-original.png', $result['file'] );
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

		/**
		 * @param list<array<string, mixed>> $rows
		 */
		private function partial_filter( int $attachment_id, array $rows, ?Settings $settings = null, ?S3Storage $storage = null, ?ProfileAwareObjectOperations $profile_ops = null ): AttachmentUrlFilter {
			$settings ??= $this->createMock( Settings::class );
			$settings->method( 'is_serve_enabled' )->willReturn( true );
			$settings->method( 'has_public_url_config' )->willReturn( true );

			$objects = $this->createMock( ObjectRepository::class );
			$objects->method( 'find_by_attachment' )->with( $attachment_id )->willReturn( $rows );
			$profiles = $this->createMock( WpdbStorageProfileRepository::class );
			$profiles->method( 'find' )->with( 8 )->willReturn(
				new StorageProfile( 8, 'p8', 'Delivery', 'aws', 'bucket', 'us-east-1', '', false, 'uploads/', 'cdn', 'https://delivery.example', true, 'keys', 'ref', true, false, false, '', '' )
			);

			return new AttachmentUrlFilter(
				$settings,
				$this->createMock( PublicUrlResolver::class ),
				new S3KeyResolver( $settings ),
				$storage,
				new ProfileDeliveryUrlResolver( $objects, $profiles ),
				$profile_ops
			);
		}
	}
}
