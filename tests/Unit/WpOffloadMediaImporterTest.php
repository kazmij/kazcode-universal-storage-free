<?php
/**
 * WP Offload Media importer tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

use Kazcode\WpStorage\Import\WpOffloadMediaImporter;
use Kazcode\WpStorage\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class WpOffloadMediaImporterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WpStubs::reset();
		$GLOBALS['wpdb'] = new WpOffloadMediaWpdbStub();
	}

	public function test_detection_reads_non_secret_provider_configuration_and_warns_for_unknowns(): void {
		WpStubs::$options['tantan_wordpress_s3'] = array(
			'provider'               => 'aws',
			'access-key-id'          => 'AKIA_SHOULD_NOT_LEAK',
			'secret-access-key'      => 'secret-should-not-leak',
			'session-token'          => 'token-should-not-leak',
			'bucket'                 => 'media-bucket',
			'region'                 => 'eu-central-1',
			'enable-object-prefix'   => true,
			'object-prefix'          => 'wp-content/uploads/',
			'delivery-provider'      => 'aws-cloudfront',
			'enable-delivery-domain' => true,
			'delivery-domain'        => 'cdn.example.test',
		);

		$detection = ( new WpOffloadMediaImporter() )->detect()->to_array();

		$this->assertSame( 'wp-offload-media', $detection['source_id'] );
		$this->assertSame( 'WP Offload Media', $detection['source_label'] );
		$this->assertSame( 's3', $detection['provider_family'] );
		$this->assertSame( 'media-bucket', $detection['bucket'] );
		$this->assertSame( 'eu-central-1', $detection['region'] );
		$this->assertNull( $detection['endpoint'] );
		$this->assertSame( 'wp-content/uploads/', $detection['object_prefix'] );
		$this->assertSame( 'exact', $detection['object_key_style'] );
		$this->assertSame( 'https://cdn.example.test', $detection['delivery_base'] );
		$this->assertTrue( $detection['requires_credential_reentry'] );
		$this->assertContains( 'Endpoint was not discoverable from WP Offload Media settings.', $detection['warnings'] );
		$encoded = (string) json_encode( $detection );
		$this->assertStringNotContainsString( 'AKIA_SHOULD_NOT_LEAK', $encoded );
		$this->assertStringNotContainsString( 'secret-should-not-leak', $encoded );
		$this->assertStringNotContainsString( 'token-should-not-leak', $encoded );
	}

	public function test_detection_maps_explicit_s3_compatible_provider_keys_without_endpoint_guessing(): void {
		foreach (
			array(
				'r2'     => 's3',
				'wasabi' => 's3',
				'b2'     => 's3',
				'spaces' => 'digitalocean',
			) as $provider => $family
		) {
			WpStubs::$options['tantan_wordpress_s3'] = array(
				'provider' => $provider,
				'bucket'   => 'media-bucket',
				'region'   => 'auto',
			);

			$detection = ( new WpOffloadMediaImporter() )->detect()->to_array();

			$this->assertSame( $family, $detection['provider_family'], $provider );
		}
	}

	public function test_detection_reports_unsupported_provider_without_guessing_from_endpoint(): void {
		WpStubs::$options['tantan_wordpress_s3'] = array(
			'provider' => 'unknown-compatible-provider',
			'bucket'   => 'media-bucket',
			'region'   => 'us-east-1',
			'endpoint' => 'https://s3.wasabisys.com',
		);

		$detection = ( new WpOffloadMediaImporter() )->detect()->to_array();

		$this->assertNull( $detection['provider_family'] );
		$this->assertContains( 'Storage provider was not discoverable from WP Offload Media settings.', $detection['warnings'] );
	}


	public function test_scan_page_returns_exact_current_table_keys_for_original_and_generated_sizes_in_cursor_order(): void {
		$this->seed_attachment(
			101,
			'image/jpeg',
			'2026/09/source-one.jpg',
			array(
				'file'           => '2026/09/source-one-scaled.jpg',
				'filesize'       => 500000,
				'original_image' => 'source-one.jpg',
				'sizes'          => array(
					'thumbnail' => array(
						'file'      => 'source-one-150x150.jpg',
						'filesize'  => 15000,
						'mime-type' => 'image/jpeg',
					),
					'medium'    => array(
						'file'      => 'source-one-300x200.jpg',
						'filesize'  => 45000,
						'mime-type' => 'image/jpeg',
					),
				),
			)
		);
		$this->seed_attachment( 102, 'image/jpeg', '2026/09/local-only.jpg', array( 'file' => '2026/09/local-only.jpg' ) );
		$this->seed_attachment( 103, 'image/jpeg', '2026/09/malformed.jpg', array( 'file' => '2026/09/malformed.jpg' ) );
		$this->seed_attachment( 104, 'application/pdf', '2026/09/doc.pdf', array( 'file' => '2026/09/doc.pdf', 'filesize' => 75000 ) );

		$GLOBALS['wpdb']->as3cf_items = array(
			101 => array(
				'id'                   => 11,
				'source_type'          => 'media-library',
				'source_id'            => 101,
				'provider'             => 'aws',
				'region'               => 'eu-central-1',
				'bucket'               => 'media-bucket',
				'path'                 => 'custom-prefix/2026/09/1693560000/source-one-scaled.jpg',
				'original_path'        => 'custom-prefix/2026/09/1693560000/source-one.jpg',
				'is_private'           => 0,
				'source_path'          => '2026/09/source-one-scaled.jpg',
				'original_source_path' => '2026/09/source-one.jpg',
				'extra_info'           => serialize(
					array(
						'objects' => array(
							'thumbnail' => array(
								'source_file' => 'source-one-150x150.jpg',
								'path'        => 'custom-prefix/thumb-bucket-key/source-one-150x150.jpg',
								'is_private'  => false,
							),
							'medium'    => array(
								'source_file' => 'source-one-300x200.jpg',
								'path'        => 'custom-prefix/manual-medium/source-one-300x200.jpg',
								'is_private'  => false,
							),
						),
					)
				),
			),
			103 => array(
				'id'          => 13,
				'source_type' => 'media-library',
				'source_id'   => 103,
				'provider'    => 'aws',
				'region'      => 'eu-central-1',
				'bucket'      => 'media-bucket',
				'path'        => '',
				'extra_info'  => 'not serialized metadata',
			),
			104 => array(
				'id'                   => 14,
				'source_type'          => 'media-library',
				'source_id'            => 104,
				'provider'             => 'aws',
				'region'               => 'eu-central-1',
				'bucket'               => 'media-bucket',
				'path'                 => 'documents/exact-doc.pdf',
				'original_path'        => '',
				'is_private'           => 0,
				'source_path'          => '2026/09/doc.pdf',
				'original_source_path' => '',
				'extra_info'           => serialize( array( 'objects' => array() ) ),
			),
		);

		$page = ( new WpOffloadMediaImporter() )->scan_page( 2, 100 );

		$this->assertSame( 103, $page->cursor() );
		$this->assertSame(
			array(
				array(
					'attachment_id'      => 101,
					'wp_relative_path'   => '2026/09/source-one-scaled.jpg',
					'variant_type'       => 'original',
					'source_object_key'  => 'custom-prefix/2026/09/1693560000/source-one-scaled.jpg',
					'source_size'        => 500000,
					'source_mime'        => 'image/jpeg',
					'source_fingerprint' => 'wp-offload-media:aws:media-bucket:eu-central-1',
				),
				array(
					'attachment_id'      => 101,
					'wp_relative_path'   => '2026/09/source-one.jpg',
					'variant_type'       => 'original-image',
					'source_object_key'  => 'custom-prefix/2026/09/1693560000/source-one.jpg',
					'source_size'        => null,
					'source_mime'        => 'image/jpeg',
					'source_fingerprint' => 'wp-offload-media:aws:media-bucket:eu-central-1',
				),
				array(
					'attachment_id'      => 101,
					'wp_relative_path'   => '2026/09/source-one-150x150.jpg',
					'variant_type'       => 'thumbnail',
					'source_object_key'  => 'custom-prefix/thumb-bucket-key/source-one-150x150.jpg',
					'source_size'        => 15000,
					'source_mime'        => 'image/jpeg',
					'source_fingerprint' => 'wp-offload-media:aws:media-bucket:eu-central-1',
				),
				array(
					'attachment_id'      => 101,
					'wp_relative_path'   => '2026/09/source-one-300x200.jpg',
					'variant_type'       => 'medium',
					'source_object_key'  => 'custom-prefix/manual-medium/source-one-300x200.jpg',
					'source_size'        => 45000,
					'source_mime'        => 'image/jpeg',
					'source_fingerprint' => 'wp-offload-media:aws:media-bucket:eu-central-1',
				),
			),
			array_map( static fn( $descriptor ): array => $descriptor->to_array(), $page->descriptors() )
		);
	}

	public function test_scan_page_prefers_wp_offload_media_3_4_file_rows_for_each_remote_file(): void {
		$this->seed_attachment(
			301,
			'image/jpeg',
			'2026/09/source-two-scaled.jpg',
			array(
				'file'                  => '2026/09/source-two-scaled.jpg',
				'filesize'              => 600000,
				'original_image'        => 'source-two.jpg',
				'source_image'          => 'source-two.heic',
				'animated_video'        => 'source-two.mp4',
				'animated_video_poster' => 'source-two-poster.jpg',
				'sizes'                 => array(
					'thumbnail' => array(
						'file'      => 'source-two-150x150.jpg',
						'filesize'  => 16000,
						'mime-type' => 'image/jpeg',
					),
				),
			)
		);
		$GLOBALS['wpdb']->as3cf_items = array(
			301 => array(
				'id'                   => 31,
				'source_type'          => 'media-library',
				'source_id'            => 301,
				'provider'             => 'aws',
				'region'               => 'eu-central-1',
				'bucket'               => 'media-bucket',
				'path'                 => 'legacy/should-not-be-used/source-two-scaled.jpg',
				'original_path'        => 'legacy/should-not-be-used/source-two.jpg',
				'source_path'          => '2026/09/source-two-scaled.jpg',
				'original_source_path' => '2026/09/source-two.jpg',
				'extra_info'           => serialize( array( 'objects' => array() ) ),
			),
		);
		$GLOBALS['wpdb']->as3cf_files = array(
			array(
				'id'          => 1,
				'item_id'     => 31,
				'source_id'   => 301,
				'source_type' => 'media-library',
				'source_path' => '2026/09/source-two-scaled.jpg',
				'path'        => 'v34/full/source-two-scaled.jpg',
				'file_type'   => 'full',
				'size_bytes'  => '600000',
				'mime_type'   => 'image/jpeg',
			),
			array(
				'id'          => 2,
				'item_id'     => 31,
				'source_id'   => 301,
				'source_type' => 'media-library',
				'source_path' => '2026/09/source-two.jpg',
				'path'        => 'v34/original/source-two.jpg',
				'file_type'   => 'original_image',
			),
			array(
				'id'          => 3,
				'item_id'     => 31,
				'source_id'   => 301,
				'source_type' => 'media-library',
				'source_path' => '2026/09/source-two-150x150.jpg',
				'path'        => 'v34/thumb/source-two-150x150.jpg',
				'file_type'   => 'thumbnail',
				'size_bytes'  => '16000',
				'mime_type'   => 'image/jpeg',
			),
			array(
				'id'          => 4,
				'item_id'     => 31,
				'source_id'   => 301,
				'source_type' => 'media-library',
				'source_path' => '2026/09/source-two.heic',
				'path'        => 'v34/source/source-two.heic',
				'file_type'   => 'source_image',
			),
		);

		$page = ( new WpOffloadMediaImporter() )->scan_page( 10, 300 );

		$this->assertSame(
			array(
				array(
					'attachment_id'      => 301,
					'wp_relative_path'   => '2026/09/source-two-scaled.jpg',
					'variant_type'       => 'original',
					'source_object_key'  => 'v34/full/source-two-scaled.jpg',
					'source_size'        => 600000,
					'source_mime'        => 'image/jpeg',
					'source_fingerprint' => 'wp-offload-media:aws:media-bucket:eu-central-1',
				),
				array(
					'attachment_id'      => 301,
					'wp_relative_path'   => '2026/09/source-two.jpg',
					'variant_type'       => 'original-image',
					'source_object_key'  => 'v34/original/source-two.jpg',
					'source_size'        => null,
					'source_mime'        => 'image/jpeg',
					'source_fingerprint' => 'wp-offload-media:aws:media-bucket:eu-central-1',
				),
				array(
					'attachment_id'      => 301,
					'wp_relative_path'   => '2026/09/source-two-150x150.jpg',
					'variant_type'       => 'thumbnail',
					'source_object_key'  => 'v34/thumb/source-two-150x150.jpg',
					'source_size'        => 16000,
					'source_mime'        => 'image/jpeg',
					'source_fingerprint' => 'wp-offload-media:aws:media-bucket:eu-central-1',
				),
				array(
					'attachment_id'      => 301,
					'wp_relative_path'   => '2026/09/source-two.heic',
					'variant_type'       => 'source_image',
					'source_object_key'  => 'v34/source/source-two.heic',
					'source_size'        => null,
					'source_mime'        => 'image/jpeg',
					'source_fingerprint' => 'wp-offload-media:aws:media-bucket:eu-central-1',
				),
			),
			array_map( static fn( $descriptor ): array => $descriptor->to_array(), $page->descriptors() )
		);
	}

	public function test_read_only_detection_and_scan_do_not_mutate_competitor_options_or_metadata(): void {
		WpStubs::$options['tantan_wordpress_s3'] = array(
			'provider' => 'aws',
			'bucket'   => 'media-bucket',
			'region'   => 'eu-central-1',
		);
		$this->seed_attachment( 201, 'image/jpeg', '2026/09/safe.jpg', array( 'file' => '2026/09/safe.jpg' ) );
		WpStubs::set_meta( 201, 'amazonS3_info', array( 'bucket' => 'legacy-bucket', 'key' => 'legacy/key.jpg' ) );
		$GLOBALS['wpdb']->as3cf_items = array(
			201 => array(
				'id'          => 21,
				'source_type' => 'media-library',
				'source_id'   => 201,
				'provider'    => 'aws',
				'region'      => 'eu-central-1',
				'bucket'      => 'media-bucket',
				'path'        => 'exact/safe.jpg',
				'extra_info'  => serialize( array( 'objects' => array() ) ),
			),
		);
		$options_before = WpStubs::$options;
		$meta_before    = WpStubs::$post_meta;
		$items_before   = $GLOBALS['wpdb']->as3cf_items;

		$importer = new WpOffloadMediaImporter();
		$importer->detect();
		$importer->scan_page( 10, 0 );

		$this->assertSame( $options_before, WpStubs::$options );
		$this->assertSame( $meta_before, WpStubs::$post_meta );
		$this->assertSame( $items_before, $GLOBALS['wpdb']->as3cf_items );
		$this->assertSame( array(), $GLOBALS['wpdb']->writes );
	}

	/** @param array<string, mixed> $metadata */
	private function seed_attachment( int $id, string $mime, string $attached_file, array $metadata ): void {
		WpStubs::$posts[ $id ] = (object) array(
			'ID'             => $id,
			'post_type'      => 'attachment',
			'post_mime_type' => $mime,
		);
		WpStubs::set_meta( $id, '_wp_attached_file', $attached_file );
		WpStubs::set_meta( $id, '_wp_attachment_metadata', $metadata );
	}
}

final class WpOffloadMediaWpdbStub {
	public string $prefix = 'wp_';
	/** @var array<int, array<string, mixed>> */
	public array $as3cf_items = array();
	/** @var list<array<string, mixed>> */
	public array $as3cf_files = array();
	/** @var list<array<string, mixed>> */
	public array $writes = array();

	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "\\'", (string) $arg ) . "'";
			$query       = preg_replace( '/%[ds]/', $replacement, $query, 1 ) ?? $query;
		}
		return $query;
	}

	/** @return list<array<string, mixed>> */
	public function get_results( string $query, string $output = ARRAY_A ): array {
		if ( str_contains( $query, 'as3cf_files' ) ) {
			preg_match( '/item_id = (\\d+)/', $query, $item );
			preg_match( '/source_id = (\\d+)/', $query, $source );
			$rows = array_values(
				array_filter(
					$this->as3cf_files,
					static function ( array $row ) use ( $item, $source ): bool {
						$item_match   = isset( $item[1] ) && (int) ( $row['item_id'] ?? 0 ) === (int) $item[1];
						$source_match = isset( $source[1] ) && (int) ( $row['source_id'] ?? 0 ) === (int) $source[1];
						return ( $item_match || $source_match ) && ( $row['source_type'] ?? 'media-library' ) === 'media-library';
					}
				)
			);
			usort( $rows, static fn( array $left, array $right ): int => (int) ( $left['id'] ?? 0 ) <=> (int) ( $right['id'] ?? 0 ) );
			return $rows;
		}

		preg_match( '/source_id > (\\d+)/', $query, $after );
		preg_match( '/LIMIT (\\d+)/', $query, $limit );
		$rows = array_values(
			array_filter(
				$this->as3cf_items,
				static fn( array $row ): bool => ( $row['source_type'] ?? '' ) === 'media-library' && (int) $row['source_id'] > (int) ( $after[1] ?? 0 )
			)
		);
		usort( $rows, static fn( array $left, array $right ): int => (int) $left['source_id'] <=> (int) $right['source_id'] );

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
