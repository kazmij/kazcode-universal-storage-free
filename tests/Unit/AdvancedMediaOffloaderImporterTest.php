<?php
/**
 * Advanced Media Offloader importer tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

use Kazcode\WpStorage\Import\AdvancedMediaOffloaderImporter;
use Kazcode\WpStorage\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class AdvancedMediaOffloaderImporterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WpStubs::reset();
		$GLOBALS['wpdb'] = new AdvancedMediaOffloaderWpdbStub();
	}

	public function test_detection_reads_aws_s3_configuration_without_exposing_credentials(): void {
		WpStubs::$options['advmo_settings']    = array(
			'cloud_provider'     => 'aws_s3',
			'path_prefix_active' => 1,
			'path_prefix'        => 'wp-content/uploads/',
		);
		WpStubs::$options['advmo_credentials'] = array(
			'aws' => array(
				'key'    => 'AKIA_SHOULD_NOT_LEAK',
				'secret' => 'secret-should-not-leak',
				'bucket' => 'media-bucket',
				'region' => 'eu-central-1',
				'domain' => 'cdn.example.test',
			),
		);

		$detection = ( new AdvancedMediaOffloaderImporter() )->detect()->to_array();

		$this->assertSame( 'advanced-media-offloader', $detection['source_id'] );
		$this->assertSame( 'Advanced Media Offloader', $detection['source_label'] );
		$this->assertSame( 's3', $detection['provider_family'] );
		$this->assertSame( 'media-bucket', $detection['bucket'] );
		$this->assertSame( 'eu-central-1', $detection['region'] );
		$this->assertNull( $detection['endpoint'] );
		$this->assertSame( 'wp-content/uploads/', $detection['object_prefix'] );
		$this->assertSame( 'exact', $detection['object_key_style'] );
		$this->assertSame( 'https://cdn.example.test', $detection['delivery_base'] );
		$this->assertTrue( $detection['requires_credential_reentry'] );
		$this->assertContains( 'Endpoint was not discoverable from Advanced Media Offloader settings.', $detection['warnings'] );
		$encoded = (string) json_encode( $detection );
		$this->assertStringNotContainsString( 'AKIA_SHOULD_NOT_LEAK', $encoded );
		$this->assertStringNotContainsString( 'secret-should-not-leak', $encoded );
	}

	public function test_detection_reads_s3_compatible_endpoint_without_guessing_missing_values(): void {
		WpStubs::$options['advmo_settings']    = array(
			'cloud_provider'     => 'minio',
			'path_prefix_active' => 0,
			'path_prefix'        => 'ignored-prefix',
		);
		WpStubs::$options['advmo_credentials'] = array(
			'minio' => array(
				'name'     => 'OVH Object Storage',
				'key'      => 'minio-access-key',
				'secret'   => 'minio-secret-key',
				'bucket'   => 'compatible-bucket',
				'endpoint' => 'https://s3.gra.io.cloud.ovh.net',
				'domain'   => 'https://cdn-compatible.example.test/assets',
			),
		);

		$detection = ( new AdvancedMediaOffloaderImporter() )->detect()->to_array();

		$this->assertSame( 's3', $detection['provider_family'] );
		$this->assertSame( 'compatible-bucket', $detection['bucket'] );
		$this->assertNull( $detection['region'] );
		$this->assertSame( 'https://s3.gra.io.cloud.ovh.net', $detection['endpoint'] );
		$this->assertNull( $detection['object_prefix'] );
		$this->assertSame( 'https://cdn-compatible.example.test/assets', $detection['delivery_base'] );
		$this->assertContains( 'Region was not discoverable from Advanced Media Offloader settings.', $detection['warnings'] );
		$encoded = (string) json_encode( $detection );
		$this->assertStringNotContainsString( 'minio-access-key', $encoded );
		$this->assertStringNotContainsString( 'minio-secret-key', $encoded );
	}

	public function test_scan_page_returns_exact_advmo_path_keys_for_original_generated_and_original_image_variants(): void {
		$this->seed_attachment(
			301,
			'image/jpeg',
			'2026/09/source-one-scaled.jpg',
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
		$this->seed_advmo_meta( 301, 'aws_s3', 'media-bucket', 'custom-prefix/2026/09/091200/' );
		$this->seed_attachment( 302, 'image/jpeg', '2026/09/local-only.jpg', array( 'file' => '2026/09/local-only.jpg' ) );
		$this->seed_attachment( 303, 'image/jpeg', '2026/09/malformed.jpg', array( 'file' => '2026/09/malformed.jpg' ) );
		WpStubs::set_meta( 303, 'advmo_offloaded', '1' );
		WpStubs::set_meta( 303, 'advmo_path', " \n " );
		$this->seed_attachment( 304, 'application/pdf', '2026/09/doc.pdf', array( 'filesize' => 75000 ) );
		$this->seed_advmo_meta( 304, 'minio', 'docs-bucket', 'docs/2026/09/' );

		$page = ( new AdvancedMediaOffloaderImporter() )->scan_page( 2, 300 );

		$this->assertSame( 303, $page->cursor() );
		$this->assertSame(
			array(
				array(
					'attachment_id'      => 301,
					'wp_relative_path'   => '2026/09/source-one-scaled.jpg',
					'variant_type'       => 'original',
					'source_object_key'  => 'custom-prefix/2026/09/091200/source-one-scaled.jpg',
					'source_size'        => 500000,
					'source_mime'        => 'image/jpeg',
					'source_fingerprint' => 'advanced-media-offloader:aws_s3:media-bucket',
				),
				array(
					'attachment_id'      => 301,
					'wp_relative_path'   => '2026/09/source-one.jpg',
					'variant_type'       => 'original-image',
					'source_object_key'  => 'custom-prefix/2026/09/091200/source-one.jpg',
					'source_size'        => null,
					'source_mime'        => 'image/jpeg',
					'source_fingerprint' => 'advanced-media-offloader:aws_s3:media-bucket',
				),
				array(
					'attachment_id'      => 301,
					'wp_relative_path'   => '2026/09/source-one-150x150.jpg',
					'variant_type'       => 'thumbnail',
					'source_object_key'  => 'custom-prefix/2026/09/091200/source-one-150x150.jpg',
					'source_size'        => 15000,
					'source_mime'        => 'image/jpeg',
					'source_fingerprint' => 'advanced-media-offloader:aws_s3:media-bucket',
				),
				array(
					'attachment_id'      => 301,
					'wp_relative_path'   => '2026/09/source-one-300x200.jpg',
					'variant_type'       => 'medium',
					'source_object_key'  => 'custom-prefix/2026/09/091200/source-one-300x200.jpg',
					'source_size'        => 45000,
					'source_mime'        => 'image/jpeg',
					'source_fingerprint' => 'advanced-media-offloader:aws_s3:media-bucket',
				),
			),
			array_map( static fn( $descriptor ): array => $descriptor->to_array(), $page->descriptors() )
		);
	}

	public function test_scan_page_uses_cursor_pagination_and_skips_attachments_without_source_metadata(): void {
		$this->seed_attachment( 401, 'image/jpeg', '2026/09/one.jpg', array( 'file' => '2026/09/one.jpg' ) );
		$this->seed_advmo_meta( 401, 'aws_s3', 'media-bucket', 'first/' );
		$this->seed_attachment( 402, 'image/jpeg', '2026/09/no-path.jpg', array( 'file' => '2026/09/no-path.jpg' ) );
		WpStubs::set_meta( 402, 'advmo_offloaded', '1' );
		$this->seed_attachment( 403, 'image/jpeg', '2026/09/two.jpg', array( 'file' => '2026/09/two.jpg' ) );
		$this->seed_advmo_meta( 403, 'aws_s3', 'media-bucket', 'second/' );

		$page = ( new AdvancedMediaOffloaderImporter() )->scan_page( 2, 401 );

		$this->assertSame( 403, $page->cursor() );
		$this->assertSame(
			array(
				array(
					'attachment_id'      => 403,
					'wp_relative_path'   => '2026/09/two.jpg',
					'variant_type'       => 'original',
					'source_object_key'  => 'second/two.jpg',
					'source_size'        => null,
					'source_mime'        => 'image/jpeg',
					'source_fingerprint' => 'advanced-media-offloader:aws_s3:media-bucket',
				),
			),
			array_map( static fn( $descriptor ): array => $descriptor->to_array(), $page->descriptors() )
		);
	}

	public function test_scan_page_skips_malformed_nonblank_advmo_path_without_trailing_separator(): void {
		$this->seed_attachment(
			451,
			'image/jpeg',
			'2026/09/photo.jpg',
			array(
				'file'           => '2026/09/photo.jpg',
				'original_image' => 'photo-original.jpg',
				'sizes'          => array(
					'thumbnail' => array(
						'file'      => 'photo-150x150.jpg',
						'filesize'  => 12000,
						'mime-type' => 'image/jpeg',
					),
				),
			)
		);
		$this->seed_advmo_meta( 451, 'aws_s3', 'media-bucket', 'custom-prefix/2026/09' );
		$this->seed_attachment( 452, 'image/jpeg', '2026/09/next.jpg', array( 'file' => '2026/09/next.jpg' ) );
		$this->seed_advmo_meta( 452, 'aws_s3', 'media-bucket', 'valid-prefix/2026/09/' );

		$page = ( new AdvancedMediaOffloaderImporter() )->scan_page( 2, 450 );

		$this->assertSame( 452, $page->cursor() );
		$this->assertSame(
			array(
				array(
					'attachment_id'      => 452,
					'wp_relative_path'   => '2026/09/next.jpg',
					'variant_type'       => 'original',
					'source_object_key'  => 'valid-prefix/2026/09/next.jpg',
					'source_size'        => null,
					'source_mime'        => 'image/jpeg',
					'source_fingerprint' => 'advanced-media-offloader:aws_s3:media-bucket',
				),
			),
			array_map( static fn( $descriptor ): array => $descriptor->to_array(), $page->descriptors() )
		);
	}

	public function test_read_only_detection_and_scan_do_not_mutate_competitor_options_or_metadata(): void {
		WpStubs::$options['advmo_settings']    = array(
			'cloud_provider' => 'aws_s3',
		);
		WpStubs::$options['advmo_credentials'] = array(
			'aws' => array(
				'key'    => 'AKIA_SHOULD_NOT_LEAK',
				'secret' => 'secret-should-not-leak',
				'bucket' => 'media-bucket',
				'region' => 'eu-central-1',
			),
		);
		$this->seed_attachment( 501, 'image/jpeg', '2026/09/safe.jpg', array( 'file' => '2026/09/safe.jpg' ) );
		$this->seed_advmo_meta( 501, 'aws_s3', 'media-bucket', 'exact/' );
		$options_before = WpStubs::$options;
		$meta_before    = WpStubs::$post_meta;

		$importer = new AdvancedMediaOffloaderImporter();
		$importer->detect();
		$importer->scan_page( 10, 0 );

		$this->assertSame( $options_before, WpStubs::$options );
		$this->assertSame( $meta_before, WpStubs::$post_meta );
		$this->assertSame( array(), $GLOBALS['wpdb']->writes );
	}

	/** @param array<string, mixed> $metadata */

	public function test_fingerprint_does_not_infer_endpoint_from_current_detect(): void {
		WpStubs::$options['advmo_settings'] = array(
			'cloud_provider' => 'minio',
		);
		WpStubs::$options['advmo_credentials'] = array(
			'minio' => array(
				'bucket'   => 'media-bucket',
				'region'   => 'us-east-1',
				'endpoint' => 'http://minio-b.example:9000',
			),
		);
		$this->seed_attachment( 601, 'image/jpeg', '2026/09/hist.jpg', array( 'file' => '2026/09/hist.jpg', 'filesize' => 40 ) );
		$this->seed_advmo_meta( 601, 'minio', 'media-bucket', 'hist/' );
		// No advmo_endpoint meta — fingerprint must stay endpoint-unknown.

		$page = ( new AdvancedMediaOffloaderImporter() )->scan_page( 1, 600 );
		$this->assertCount( 1, $page->descriptors() );
		$this->assertSame(
			'advanced-media-offloader:minio:media-bucket',
			$page->descriptors()[0]->source_fingerprint()
		);
		$this->assertStringNotContainsString( 'minio-b.example', $page->descriptors()[0]->source_fingerprint() );
	}

	public function test_fingerprint_embeds_attachment_endpoint_meta_when_present(): void {
		$this->seed_attachment( 602, 'image/jpeg', '2026/09/with-ep.jpg', array( 'file' => '2026/09/with-ep.jpg', 'filesize' => 40 ) );
		$this->seed_advmo_meta( 602, 'minio', 'media-bucket', 'hist/' );
		WpStubs::set_meta( 602, 'advmo_region', 'us-east-1' );
		WpStubs::set_meta( 602, 'advmo_endpoint', 'http://minio-a.example:9000' );

		$page = ( new AdvancedMediaOffloaderImporter() )->scan_page( 1, 601 );
		$this->assertSame(
			'advanced-media-offloader:minio:media-bucket:us-east-1:http://minio-a.example:9000',
			$page->descriptors()[0]->source_fingerprint()
		);
	}

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

	private function seed_advmo_meta( int $id, string $provider, string $bucket, string $path ): void {
		WpStubs::set_meta( $id, 'advmo_offloaded', '1' );
		WpStubs::set_meta( $id, 'advmo_provider', $provider );
		WpStubs::set_meta( $id, 'advmo_bucket', $bucket );
		WpStubs::set_meta( $id, 'advmo_path', $path );
	}
}

final class AdvancedMediaOffloaderWpdbStub {
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
			if ( (string) ( WpStubs::$post_meta[ (int) $id ]['advmo_offloaded'] ?? '' ) === '' ) {
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
