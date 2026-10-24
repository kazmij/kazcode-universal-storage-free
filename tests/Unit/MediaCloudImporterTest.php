<?php
/**
 * Media Cloud importer tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

use Kazcode\WpStorage\Import\MediaCloudImporter;
use Kazcode\WpStorage\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class MediaCloudImporterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WpStubs::reset();
		$GLOBALS['wpdb'] = new MediaCloudWpdbStub();
	}

	public function test_detection_reads_s3_configuration_without_exposing_credentials(): void {
		WpStubs::$options['mcloud-storage-provider']      = 's3';
		WpStubs::$options['mcloud-storage-s3-bucket']     = 'media-bucket';
		WpStubs::$options['mcloud-storage-s3-region']     = 'eu-central-1';
		WpStubs::$options['mcloud-storage-prefix']        = 'uploads/';
		WpStubs::$options['mcloud-storage-cdn-base']      = 'cdn.example.test';
		WpStubs::$options['mcloud-storage-s3-access-key'] = 'AKIA_SHOULD_NOT_LEAK';
		WpStubs::$options['mcloud-storage-s3-secret']     = 'secret-should-not-leak';

		$detection = ( new MediaCloudImporter() )->detect()->to_array();

		$this->assertSame( 'media-cloud', $detection['source_id'] );
		$this->assertSame( 'Media Cloud', $detection['source_label'] );
		$this->assertSame( 's3', $detection['provider_family'] );
		$this->assertSame( 'media-bucket', $detection['bucket'] );
		$this->assertSame( 'eu-central-1', $detection['region'] );
		$this->assertNull( $detection['endpoint'] );
		$this->assertSame( 'uploads/', $detection['object_prefix'] );
		$this->assertSame( 'exact', $detection['object_key_style'] );
		$this->assertSame( 'https://cdn.example.test', $detection['delivery_base'] );
		$this->assertTrue( $detection['requires_credential_reentry'] );
		$encoded = (string) json_encode( $detection );
		$this->assertStringNotContainsString( 'AKIA_SHOULD_NOT_LEAK', $encoded );
		$this->assertStringNotContainsString( 'secret-should-not-leak', $encoded );
	}

	public function test_detection_reads_s3_compatible_endpoint_and_warns_on_unsupported_driver(): void {
		WpStubs::$options['mcloud-storage-provider']   = 'other-s3';
		WpStubs::$options['mcloud-storage-s3-bucket']  = 'compatible-bucket';
		WpStubs::$options['mcloud-storage-s3-endpoint'] = 'https://s3.gra.io.cloud.ovh.net';

		$compatible = ( new MediaCloudImporter() )->detect()->to_array();
		$this->assertSame( 's3', $compatible['provider_family'] );
		$this->assertSame( 'compatible-bucket', $compatible['bucket'] );
		$this->assertSame( 'https://s3.gra.io.cloud.ovh.net', $compatible['endpoint'] );

		WpStubs::$options['mcloud-storage-provider'] = 'google';
		$unsupported = ( new MediaCloudImporter() )->detect()->to_array();
		$this->assertNull( $unsupported['provider_family'] );
		$this->assertContains(
			'Media Cloud storage provider "google" is not supported for exact-key S3 adoption.',
			$unsupported['warnings']
		);
	}

	public function test_scan_page_returns_exact_ilab_s3_info_keys_for_original_and_sizes(): void {
		$this->seed_attachment(
			701,
			'image/jpeg',
			'2026/09/photo-scaled.jpg',
			array(
				'file'     => '2026/09/photo-scaled.jpg',
				'filesize' => 500000,
				'sizes'    => array(
					'thumbnail' => array(
						'file'      => 'photo-150x150.jpg',
						'filesize'  => 15000,
						'mime-type' => 'image/jpeg',
					),
				),
			)
		);
		$this->seed_ilab_s3_info(
			701,
			array(
				's3'    => array(
					'key'      => 'custom/2026/09/photo-scaled.jpg',
					'bucket'   => 'media-bucket',
					'provider' => 's3',
					'region'   => 'eu-central-1',
				),
				'sizes' => array(
					'thumbnail' => array(
						'file' => 'photo-150x150.jpg',
						's3'   => array(
							'key'      => 'custom/2026/09/photo-150x150.jpg',
							'bucket'   => 'media-bucket',
							'provider' => 's3',
						),
					),
					'imgix-only' => array(
						'file' => 'photo-imgix.jpg',
						// No s3.key — must be skipped.
					),
				),
			)
		);
		$this->seed_attachment( 702, 'image/jpeg', '2026/09/local-only.jpg', array( 'file' => '2026/09/local-only.jpg' ) );
		$this->seed_attachment( 703, 'application/pdf', '2026/09/doc.pdf', array( 'filesize' => 75000 ) );
		$this->seed_ilab_s3_info(
			703,
			array(
				's3' => array(
					'key'      => 'docs/2026/09/doc.pdf',
					'bucket'   => 'docs-bucket',
					'provider' => 'other-s3',
					'endpoint' => 'http://minio-a.example:9000',
				),
			)
		);

		$page = ( new MediaCloudImporter() )->scan_page( 2, 700 );

		// 702 has no Media Cloud evidence and is skipped by the scan query.
		$this->assertSame( 703, $page->cursor() );
		$this->assertSame(
			array(
				array(
					'attachment_id'      => 701,
					'wp_relative_path'   => '2026/09/photo-scaled.jpg',
					'variant_type'       => 'original',
					'source_object_key'  => 'custom/2026/09/photo-scaled.jpg',
					'source_size'        => 500000,
					'source_mime'        => 'image/jpeg',
					'source_fingerprint' => 'media-cloud:s3:media-bucket:eu-central-1',
				),
				array(
					'attachment_id'      => 701,
					'wp_relative_path'   => '2026/09/photo-150x150.jpg',
					'variant_type'       => 'thumbnail',
					'source_object_key'  => 'custom/2026/09/photo-150x150.jpg',
					'source_size'        => 15000,
					'source_mime'        => 'image/jpeg',
					'source_fingerprint' => 'media-cloud:s3:media-bucket:eu-central-1',
				),
				array(
					'attachment_id'      => 703,
					'wp_relative_path'   => '2026/09/doc.pdf',
					'variant_type'       => 'original',
					'source_object_key'  => 'docs/2026/09/doc.pdf',
					'source_size'        => 75000,
					'source_mime'        => 'application/pdf',
					'source_fingerprint' => 'media-cloud:other-s3:docs-bucket:unknown-region:http://minio-a.example:9000',
				),
			),
			array_map( static fn( $descriptor ): array => $descriptor->to_array(), $page->descriptors() )
		);
	}

	public function test_descriptors_fall_back_to_attachment_metadata_s3_when_ilab_meta_absent(): void {
		$this->seed_attachment(
			711,
			'image/jpeg',
			'2026/09/meta-only.jpg',
			array(
				'file' => '2026/09/meta-only.jpg',
				's3'   => array(
					'key'      => 'retained/2026/09/meta-only.jpg',
					'bucket'   => 'media-bucket',
					'provider' => 'cloudflare',
				),
				'sizes' => array(
					'medium' => array(
						'file'      => 'meta-only-300x200.jpg',
						'mime-type' => 'image/jpeg',
						's3'        => array(
							'key'      => 'retained/2026/09/meta-only-300x200.jpg',
							'bucket'   => 'media-bucket',
							'provider' => 'cloudflare',
						),
					),
				),
			)
		);
		// Mark scanable via empty-looking ilab presence? Scan uses ilab_s3_info OR metadata s3.
		WpStubs::set_meta( 711, 'ilab_s3_info', array() );

		$descriptors = ( new MediaCloudImporter() )->descriptors_for_attachment( 711 );
		$this->assertSame(
			array(
				'retained/2026/09/meta-only.jpg',
				'retained/2026/09/meta-only-300x200.jpg',
			),
			array_map( static fn( $d ) => $d->source_object_key(), $descriptors )
		);
		$this->assertSame( 'media-cloud:cloudflare:media-bucket', $descriptors[0]->source_fingerprint() );
	}

	public function test_scan_skips_unsupported_provider_and_missing_keys(): void {
		$this->seed_attachment( 721, 'image/jpeg', '2026/09/gcs.jpg', array( 'file' => '2026/09/gcs.jpg' ) );
		$this->seed_ilab_s3_info(
			721,
			array(
				's3' => array(
					'key'      => 'gcs/path.jpg',
					'bucket'   => 'gcs-bucket',
					'provider' => 'google',
				),
			)
		);
		$this->seed_attachment( 722, 'image/jpeg', '2026/09/nokey.jpg', array( 'file' => '2026/09/nokey.jpg' ) );
		$this->seed_ilab_s3_info(
			722,
			array(
				's3' => array(
					'bucket'   => 'media-bucket',
					'provider' => 's3',
					// key missing
				),
			)
		);
		$this->seed_attachment( 723, 'image/jpeg', '2026/09/ok.jpg', array( 'file' => '2026/09/ok.jpg' ) );
		$this->seed_ilab_s3_info(
			723,
			array(
				's3' => array(
					'key'      => 'ok/ok.jpg',
					'bucket'   => 'media-bucket',
					'provider' => 'wasabi',
				),
			)
		);

		$page = ( new MediaCloudImporter() )->scan_page( 10, 720 );
		$this->assertSame( 723, $page->cursor() );
		$this->assertCount( 1, $page->descriptors() );
		$this->assertSame( 'ok/ok.jpg', $page->descriptors()[0]->source_object_key() );
		$this->assertSame( 'media-cloud:wasabi:media-bucket', $page->descriptors()[0]->source_fingerprint() );
		$this->assertSame( 2, $page->rejected_attachment_count() );
	}

	public function test_fingerprint_embeds_attachment_endpoint_not_live_detect_options(): void {
		WpStubs::$options['mcloud-storage-provider']    = 'other-s3';
		WpStubs::$options['mcloud-storage-s3-endpoint'] = 'http://minio-b.example:9000';
		$this->seed_attachment( 731, 'image/jpeg', '2026/09/hist.jpg', array( 'file' => '2026/09/hist.jpg' ) );
		$this->seed_ilab_s3_info(
			731,
			array(
				's3' => array(
					'key'      => 'hist/hist.jpg',
					'bucket'   => 'media-bucket',
					'provider' => 'other-s3',
					'region'   => 'us-east-1',
					'endpoint' => 'http://minio-a.example:9000',
				),
			)
		);

		$page = ( new MediaCloudImporter() )->scan_page( 1, 730 );
		$this->assertSame(
			'media-cloud:other-s3:media-bucket:us-east-1:http://minio-a.example:9000',
			$page->descriptors()[0]->source_fingerprint()
		);
		$this->assertStringNotContainsString( 'minio-b.example', $page->descriptors()[0]->source_fingerprint() );
	}

	public function test_size_fingerprint_uses_variant_location_not_only_original(): void {
		$this->seed_attachment(
			751,
			'image/jpeg',
			'2026/09/moved.jpg',
			array(
				'file'     => '2026/09/moved.jpg',
				'filesize' => 100,
				'sizes'    => array(
					'thumbnail' => array(
						'file'      => 'moved-150x150.jpg',
						'filesize'  => 10,
						'mime-type' => 'image/jpeg',
					),
				),
			)
		);
		$this->seed_ilab_s3_info(
			751,
			array(
				's3'    => array(
					'key'      => 'new/2026/09/moved.jpg',
					'bucket'   => 'new-bucket',
					'provider' => 's3',
					'region'   => 'eu-west-1',
				),
				'sizes' => array(
					'thumbnail' => array(
						'file' => 'moved-150x150.jpg',
						's3'   => array(
							'key'      => 'old/2026/09/moved-150x150.jpg',
							'bucket'   => 'old-bucket',
							'provider' => 's3',
							'region'   => 'us-east-1',
						),
					),
				),
			)
		);

		// Single-profile import cannot route a size in another bucket; skip it fail-closed.
		$descriptors = ( new MediaCloudImporter() )->descriptors_for_attachment( 751 );
		$this->assertCount( 1, $descriptors );
		$this->assertSame( 'new/2026/09/moved.jpg', $descriptors[0]->source_object_key() );
		$this->assertSame( 'media-cloud:s3:new-bucket:eu-west-1', $descriptors[0]->source_fingerprint() );
	}

	public function test_incomplete_ilab_s3_falls_back_to_metadata_key(): void {
		$this->seed_attachment(
			771,
			'image/jpeg',
			'2026/09/fallback.jpg',
			array(
				'file' => '2026/09/fallback.jpg',
				's3'   => array(
					'key'      => 'meta/fallback.jpg',
					'bucket'   => 'media-bucket',
					'provider' => 's3',
				),
			)
		);
		$this->seed_ilab_s3_info(
			771,
			array(
				's3' => array(
					'bucket'   => 'media-bucket',
					'provider' => 's3',
					// key missing / stale
				),
			)
		);

		$descriptors = ( new MediaCloudImporter() )->descriptors_for_attachment( 771 );
		$this->assertCount( 1, $descriptors );
		$this->assertSame( 'meta/fallback.jpg', $descriptors[0]->source_object_key() );
	}

	public function test_merge_includes_metadata_only_sizes_when_ilab_sizes_incomplete(): void {
		$this->seed_attachment(
			761,
			'image/jpeg',
			'2026/09/mixed.jpg',
			array(
				'file'  => '2026/09/mixed.jpg',
				'sizes' => array(
					'medium' => array(
						'file'      => 'mixed-300x200.jpg',
						'filesize'  => 20000,
						'mime-type' => 'image/jpeg',
						's3'        => array(
							'key'      => 'meta/mixed-300x200.jpg',
							'bucket'   => 'media-bucket',
							'provider' => 's3',
						),
					),
				),
			)
		);
		$this->seed_ilab_s3_info(
			761,
			array(
				's3'    => array(
					'key'      => 'ilab/mixed.jpg',
					'bucket'   => 'media-bucket',
					'provider' => 's3',
				),
				'sizes' => array(
					'thumbnail' => array(
						'file' => 'mixed-150x150.jpg',
						's3'   => array(
							'key'      => 'ilab/mixed-150x150.jpg',
							'bucket'   => 'media-bucket',
							'provider' => 's3',
						),
					),
				),
			)
		);

		$keys = array_map(
			static fn( $d ) => $d->source_object_key(),
			( new MediaCloudImporter() )->descriptors_for_attachment( 761 )
		);
		$this->assertCount( 3, $keys );
		$this->assertContains( 'ilab/mixed.jpg', $keys );
		$this->assertContains( 'ilab/mixed-150x150.jpg', $keys );
		$this->assertContains( 'meta/mixed-300x200.jpg', $keys );
	}

	public function test_incomplete_ilab_preserves_ilab_only_sizes_when_falling_back_to_metadata_key(): void {
		$this->seed_attachment(
			781,
			'image/jpeg',
			'2026/09/merge.jpg',
			array(
				'file' => '2026/09/merge.jpg',
				's3'   => array(
					'key'      => 'meta/merge.jpg',
					'bucket'   => 'media-bucket',
					'provider' => 's3',
				),
			)
		);
		$this->seed_ilab_s3_info(
			781,
			array(
				's3'    => array(
					'bucket'   => 'media-bucket',
					'provider' => 's3',
				),
				'sizes' => array(
					'thumbnail' => array(
						'file' => 'merge-150x150.jpg',
						's3'   => array(
							'key'      => 'ilab/merge-150x150.jpg',
							'bucket'   => 'media-bucket',
							'provider' => 's3',
						),
					),
				),
			)
		);

		$keys = array_map(
			static fn( $d ) => $d->source_object_key(),
			( new MediaCloudImporter() )->descriptors_for_attachment( 781 )
		);
		$this->assertContains( 'meta/merge.jpg', $keys );
		$this->assertContains( 'ilab/merge-150x150.jpg', $keys );
	}

	public function test_size_merge_prefers_metadata_s3_key_when_cloud_s3_incomplete(): void {
		$this->seed_attachment(
			791,
			'image/jpeg',
			'2026/09/pref.jpg',
			array(
				'file'  => '2026/09/pref.jpg',
				'sizes' => array(
					'thumbnail' => array(
						'file'     => 'pref-150x150.jpg',
						'filesize' => 12,
						's3'       => array(
							'key'      => 'meta/pref-150x150.jpg',
							'bucket'   => 'media-bucket',
							'provider' => 's3',
						),
					),
				),
			)
		);
		$this->seed_ilab_s3_info(
			791,
			array(
				's3'    => array(
					'key'      => 'ilab/pref.jpg',
					'bucket'   => 'media-bucket',
					'provider' => 's3',
				),
				'sizes' => array(
					'thumbnail' => array(
						'file' => 'pref-150x150.jpg',
						's3'   => array(
							'bucket'   => 'media-bucket',
							'provider' => 's3',
							// incomplete — no key
						),
					),
				),
			)
		);

		$keys = array_map(
			static fn( $d ) => $d->source_object_key(),
			( new MediaCloudImporter() )->descriptors_for_attachment( 791 )
		);
		$this->assertContains( 'ilab/pref.jpg', $keys );
		$this->assertContains( 'meta/pref-150x150.jpg', $keys );
	}

	public function test_conflicting_keyed_layouts_prefer_complete_record_without_hybrid_bucket(): void {
		$this->seed_attachment(
			801,
			'image/jpeg',
			'2026/09/conflict.jpg',
			array(
				'file' => '2026/09/conflict.jpg',
				's3'   => array(
					'key'      => 'old/conflict.jpg',
					'bucket'   => 'old-bucket',
					'provider' => 's3',
					'region'   => 'us-east-1',
				),
			)
		);
		$this->seed_ilab_s3_info(
			801,
			array(
				's3' => array(
					'key'      => 'new/conflict.jpg',
					'provider' => 's3',
					'region'   => 'eu-west-1',
					// bucket omitted — incomplete; must not hybridize with old-bucket
				),
			)
		);

		$descriptors = ( new MediaCloudImporter() )->descriptors_for_attachment( 801 );
		$this->assertCount( 1, $descriptors );
		$this->assertSame( 'old/conflict.jpg', $descriptors[0]->source_object_key() );
		$this->assertSame( 'media-cloud:s3:old-bucket:us-east-1', $descriptors[0]->source_fingerprint() );
	}

	public function test_conflicting_original_layouts_prefer_supported_provider_record(): void {
		$this->seed_attachment(
			806,
			'image/jpeg',
			'2026/09/supported-wins.jpg',
			array(
				'file' => '2026/09/supported-wins.jpg',
				's3'   => array(
					'key'      => 'meta/supported-wins.jpg',
					'bucket'   => 'media-bucket',
					'provider' => 's3',
					'region'   => 'eu-central-1',
				),
			)
		);
		$this->seed_ilab_s3_info(
			806,
			array(
				's3' => array(
					'key'      => 'ilab/unsupported.jpg',
					'bucket'   => 'media-bucket',
					'provider' => 'google',
				),
			)
		);

		$descriptors = ( new MediaCloudImporter() )->descriptors_for_attachment( 806 );

		$this->assertCount( 1, $descriptors );
		$this->assertSame( 'meta/supported-wins.jpg', $descriptors[0]->source_object_key() );
		$this->assertSame( 'media-cloud:s3:media-bucket:eu-central-1', $descriptors[0]->source_fingerprint() );
	}

	public function test_conflicting_size_keys_do_not_hybridize_nested_location(): void {
		$this->seed_attachment(
			811,
			'image/jpeg',
			'2026/09/size-conflict.jpg',
			array(
				'file'  => '2026/09/size-conflict.jpg',
				'sizes' => array(
					'thumbnail' => array(
						'file'     => 'size-conflict-150x150.jpg',
						'filesize' => 11,
						's3'       => array(
							'key'      => 'meta/size-conflict-150x150.jpg',
							'bucket'   => 'meta-bucket',
							'provider' => 's3',
							'region'   => 'us-east-1',
						),
					),
				),
			)
		);
		$this->seed_ilab_s3_info(
			811,
			array(
				's3'    => array(
					'key'      => 'ilab/size-conflict.jpg',
					'bucket'   => 'ilab-bucket',
					'provider' => 's3',
					'region'   => 'eu-west-1',
				),
				'sizes' => array(
					'thumbnail' => array(
						'file'     => 'stale-name.jpg',
						'filesize' => 99,
						's3'       => array(
							'key'      => 'ilab/size-conflict-150x150.jpg',
							'provider' => 's3',
							'region'   => 'eu-west-1',
							// bucket omitted — inherit from original, never from meta-bucket
						),
					),
				),
			)
		);

		$descriptors = ( new MediaCloudImporter() )->descriptors_for_attachment( 811 );
		$by_key      = array();
		foreach ( $descriptors as $d ) {
			$by_key[ $d->source_object_key() ] = $d->source_fingerprint();
		}
		$this->assertArrayHasKey( 'ilab/size-conflict.jpg', $by_key );
		$this->assertArrayHasKey( 'ilab/size-conflict-150x150.jpg', $by_key );
		$this->assertArrayNotHasKey( 'meta/size-conflict-150x150.jpg', $by_key );
		$this->assertSame( 'media-cloud:s3:ilab-bucket:eu-west-1', $by_key['ilab/size-conflict-150x150.jpg'] );
		$this->assertStringNotContainsString( 'meta-bucket', $by_key['ilab/size-conflict-150x150.jpg'] );
	}

	public function test_compatible_size_uses_own_location_fingerprint(): void {
		$this->seed_attachment(
			821,
			'image/jpeg',
			'2026/09/own-fp.jpg',
			array( 'file' => '2026/09/own-fp.jpg' )
		);
		$this->seed_ilab_s3_info(
			821,
			array(
				's3'    => array(
					'key'      => 'own/own-fp.jpg',
					'bucket'   => 'media-bucket',
					'provider' => 's3',
					'region'   => 'eu-west-1',
				),
				'sizes' => array(
					'thumbnail' => array(
						'file' => 'own-fp-150x150.jpg',
						's3'   => array(
							'key'      => 'own/own-fp-150x150.jpg',
							'bucket'   => 'media-bucket',
							'provider' => 's3',
							'region'   => 'eu-west-1',
						),
					),
				),
			)
		);

		$descriptors = ( new MediaCloudImporter() )->descriptors_for_attachment( 821 );
		$this->assertCount( 2, $descriptors );
		$this->assertSame( 'media-cloud:s3:media-bucket:eu-west-1', $descriptors[0]->source_fingerprint() );
		$this->assertSame( 'media-cloud:s3:media-bucket:eu-west-1', $descriptors[1]->source_fingerprint() );
		$this->assertSame( 'thumbnail', $descriptors[1]->variant_type() );
	}

	public function test_unsafe_original_image_and_size_filenames_are_skipped_without_aborting_attachment(): void {
		$this->seed_attachment(
			825,
			'image/jpeg',
			'2026/09/variants.jpg',
			array(
				'file'           => '2026/09/variants.jpg',
				'original_image' => '../escape-original.jpg',
				'sizes'          => array(
					'thumbnail' => array(
						'file'      => '../escape-thumb.jpg',
						'filesize'  => 10,
						'mime-type' => 'image/jpeg',
					),
				),
			)
		);
		$this->seed_ilab_s3_info(
			825,
			array(
				's3'             => array(
					'key'      => 'safe/variants.jpg',
					'bucket'   => 'media-bucket',
					'provider' => 's3',
				),
				'original_image' => array(
					's3' => array(
						'key'      => 'safe/original.jpg',
						'bucket'   => 'media-bucket',
						'provider' => 's3',
					),
				),
				'sizes'          => array(
					'thumbnail' => array(
						'file' => '../escape-thumb.jpg',
						's3'   => array(
							'key'      => 'safe/thumb.jpg',
							'bucket'   => 'media-bucket',
							'provider' => 's3',
						),
					),
				),
			)
		);

		$descriptors = ( new MediaCloudImporter() )->descriptors_for_attachment( 825 );

		$this->assertCount( 1, $descriptors );
		$this->assertSame( 'original', $descriptors[0]->variant_type() );
		$this->assertSame( 'safe/variants.jpg', $descriptors[0]->source_object_key() );
	}

	public function test_http_attached_file_fallback_is_rejected(): void {
		$this->seed_attachment(
			831,
			'image/jpeg',
			'https://cdn.example/2026/09/remote-only.jpg',
			array(
				// metadata file also a URL — both gates must reject
				'file' => 'https://cdn.example/2026/09/remote-only.jpg',
			)
		);
		$this->seed_ilab_s3_info(
			831,
			array(
				's3' => array(
					'key'      => 'remote/remote-only.jpg',
					'bucket'   => 'media-bucket',
					'provider' => 's3',
				),
			)
		);

		$this->assertSame(
			array(),
			( new MediaCloudImporter() )->descriptors_for_attachment( 831 )
		);
	}

	/**
	 * @dataProvider uri_scheme_attached_file_provider
	 */
	public function test_uri_scheme_attached_files_are_rejected_case_insensitively( string $uri ): void {
		$this->seed_attachment(
			832,
			'image/jpeg',
			$uri,
			array(
				'file' => $uri,
			)
		);
		$this->seed_ilab_s3_info(
			832,
			array(
				's3' => array(
					'key'      => 'remote/scheme.jpg',
					'bucket'   => 'media-bucket',
					'provider' => 's3',
				),
			)
		);

		$this->assertSame(
			array(),
			( new MediaCloudImporter() )->descriptors_for_attachment( 832 ),
			$uri
		);
	}

	/** @return list<array{0:string}> */
	public static function uri_scheme_attached_file_provider(): array {
		return array(
			array( 'HTTPS://cdn.example/2026/09/upper.jpg' ),
			array( 'Http://cdn.example/2026/09/mixed.jpg' ),
			array( 'ftp://files.example/2026/09/ftp.jpg' ),
			array( 's3://media-bucket/2026/09/s3.jpg' ),
			array( 'file:///var/www/uploads/2026/09/file.jpg' ),
		);
	}

	/**
	 * @dataProvider unsafe_local_path_provider
	 */
	public function test_absolute_and_parent_traversing_local_paths_are_rejected( string $path ): void {
		$this->seed_attachment(
			834,
			'image/jpeg',
			$path,
			array( 'file' => $path )
		);
		$this->seed_ilab_s3_info(
			834,
			array(
				's3' => array(
					'key'      => 'remote/unsafe-path.jpg',
					'bucket'   => 'media-bucket',
					'provider' => 's3',
				),
			)
		);

		$this->assertSame(
			array(),
			( new MediaCloudImporter() )->descriptors_for_attachment( 834 ),
			$path
		);
	}

	/** @return list<array{0:string}> */
	public static function unsafe_local_path_provider(): array {
		return array(
			array( '/var/www/uploads/2026/09/absolute.jpg' ),
			array( '../escape.jpg' ),
			array( '2026/09/../../escape.jpg' ),
			array( '\\server\\share\\absolute.jpg' ),
		);
	}

	public function test_relative_attached_file_still_accepted(): void {
		$this->seed_attachment(
			833,
			'image/jpeg',
			'2026/09/local-relative.jpg',
			array(
				'file' => '2026/09/local-relative.jpg',
			)
		);
		$this->seed_ilab_s3_info(
			833,
			array(
				's3' => array(
					'key'      => 'local/local-relative.jpg',
					'bucket'   => 'media-bucket',
					'provider' => 's3',
				),
			)
		);

		$descriptors = ( new MediaCloudImporter() )->descriptors_for_attachment( 833 );
		$this->assertCount( 1, $descriptors );
		$this->assertSame( '2026/09/local-relative.jpg', $descriptors[0]->wp_relative_path() );
	}

	public function test_read_only_detection_and_scan_do_not_mutate_competitor_options_or_metadata(): void {
		WpStubs::$options['mcloud-storage-provider']      = 's3';
		WpStubs::$options['mcloud-storage-s3-bucket']     = 'media-bucket';
		WpStubs::$options['mcloud-storage-s3-access-key'] = 'AKIA_SHOULD_NOT_LEAK';
		$this->seed_attachment( 741, 'image/jpeg', '2026/09/safe.jpg', array( 'file' => '2026/09/safe.jpg' ) );
		$this->seed_ilab_s3_info(
			741,
			array(
				's3' => array(
					'key'      => 'safe/safe.jpg',
					'bucket'   => 'media-bucket',
					'provider' => 's3',
				),
			)
		);
		$options_before = WpStubs::$options;
		$meta_before    = WpStubs::$post_meta;

		$importer = new MediaCloudImporter();
		$importer->detect();
		$importer->scan_page( 10, 0 );

		$this->assertSame( $options_before, WpStubs::$options );
		$this->assertSame( $meta_before, WpStubs::$post_meta );
		$this->assertSame( array(), $GLOBALS['wpdb']->writes );
	}

	/** @param array<string, mixed> $metadata */
	private function seed_attachment( int $id, string $mime, string $attached_file, array $metadata ): void {
		WpStubs::$posts[ $id ] = (object) array(
			'ID'             => $id,
			'post_type'      => 'attachment',
			'post_mime_type' => $mime,
			'post_status'    => 'inherit',
		);
		WpStubs::set_meta( $id, '_wp_attached_file', $attached_file );
		WpStubs::set_meta( $id, '_wp_attachment_metadata', $metadata );
	}

	/** @param array<string, mixed> $ilab */
	private function seed_ilab_s3_info( int $id, array $ilab ): void {
		WpStubs::set_meta( $id, 'ilab_s3_info', $ilab );
	}
}

final class MediaCloudWpdbStub {
	public string $prefix = 'wp_';
	public string $posts = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	/** @var list<array<string, mixed>> */
	public array $writes = array();

	public function prepare( string $query, ...$args ): string {
		if ( count( $args ) === 1 && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "\\'", (string) $arg ) . "'";
			$query       = preg_replace( '/%[ds]/', $replacement, $query, 1 ) ?? $query;
		}
		return $query;
	}

	/** @return list<array<string, mixed>> */
	public function get_results( string $query, string $output = ARRAY_A ): array {
		preg_match( '/p\\.ID > (\\d+)/', $query, $after );
		preg_match( '/LIMIT (\\d+)/', $query, $limit );
		$rows = array();
		foreach ( WpStubs::$posts as $id => $post ) {
			if ( (int) $id <= (int) ( $after[1] ?? 0 ) ) {
				continue;
			}
			if ( ( $post->post_type ?? '' ) !== 'attachment' || ( $post->post_status ?? '' ) === 'trash' ) {
				continue;
			}
			$ilab = WpStubs::$post_meta[ (int) $id ]['ilab_s3_info'] ?? null;
			$meta = WpStubs::$post_meta[ (int) $id ]['_wp_attachment_metadata'] ?? null;
			$has_ilab = is_array( $ilab ) && $ilab !== array();
			$has_meta_s3 = is_array( $meta ) && isset( $meta['s3'] ) && is_array( $meta['s3'] );
			if ( ! $has_ilab && ! $has_meta_s3 ) {
				continue;
			}
			$rows[] = array( 'ID' => (int) $id );
		}
		usort( $rows, static fn( array $left, array $right ): int => (int) $left['ID'] <=> (int) $right['ID'] );

		return array_slice( $rows, 0, (int) ( $limit[1] ?? 100 ) );
	}

	public function insert( string $table, array $data ): void {
		$this->writes[] = array( 'insert', $table, $data );
	}

	public function update( string $table, array $data, array $where ): void {
		$this->writes[] = array( 'update', $table, $data, $where );
	}

	public function delete( string $table, array $where ): void {
		$this->writes[] = array( 'delete', $table, $where );
	}
}
