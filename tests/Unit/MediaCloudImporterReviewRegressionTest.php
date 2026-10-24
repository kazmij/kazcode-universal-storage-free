<?php
/**
 * Regression coverage for Media Cloud dual-layout selection and local paths.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Import\MediaCloudImporter;
use Kazcode\WpStorage\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class MediaCloudImporterReviewRegressionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WpStubs::reset();
	}

	public function test_supported_size_wins_in_both_layout_orders_without_mixing_fields(): void {
		foreach ( array( true, false ) as $supported_in_metadata ) {
			foreach ( array( true, false ) as $same_key ) {
				foreach ( array( 's3', null ) as $supported_provider ) {
					$supported = $this->size_entry( 'retained/good.jpg', $supported_provider, 'good.jpg', 123 );
					$unsupported = $this->size_entry( $same_key ? 'retained/good.jpg' : 'old/bad.jpg', 'google', 'bad.jpg', 999 );
					$unsupported['s3']['bucket'] = 'old-bucket';
					$metadata_size = $supported_in_metadata ? $supported : $unsupported;
					$ilab_size     = $supported_in_metadata ? $unsupported : $supported;
					$this->seed( '2026/09/photo.jpg', '2026/09/photo.jpg', $metadata_size, $ilab_size );

					$descriptors = ( new MediaCloudImporter() )->descriptors_for_attachment( 901 );

					$this->assertCount( 2, $descriptors );
					$this->assertSame(
						array(
							'attachment_id'      => 901,
							'wp_relative_path'   => '2026/09/good.jpg',
							'variant_type'       => 'thumbnail',
							'source_object_key'  => 'retained/good.jpg',
							'source_size'        => 123,
							'source_mime'        => 'image/jpeg',
							'source_fingerprint' => 'media-cloud:s3:media-bucket:eu-central-1',
						),
						$descriptors[1]->to_array()
					);
					$this->assertSame( $metadata_size, get_post_meta( 901, '_wp_attachment_metadata', true )['sizes']['thumbnail'] );
					$this->assertSame( $ilab_size, get_post_meta( 901, 'ilab_s3_info', true )['sizes']['thumbnail'] );
				}
			}
		}
	}

	public function test_two_supported_conflicting_size_keys_keep_ilab_precedence(): void {
		$this->seed(
			'2026/09/photo.jpg',
			'2026/09/photo.jpg',
			$this->size_entry( 'retained/old.jpg', 's3', 'old.jpg', 123 ),
			$this->size_entry( 'retained/new.jpg', null, 'new.jpg', 456 )
		);
		$descriptors = ( new MediaCloudImporter() )->descriptors_for_attachment( 901 );
		$this->assertCount( 2, $descriptors );
		$this->assertSame( 'retained/new.jpg', $descriptors[1]->source_object_key() );
		$this->assertSame( '2026/09/new.jpg', $descriptors[1]->wp_relative_path() );
		$this->assertSame( 456, $descriptors[1]->source_size() );
	}

	public function test_unsupported_sizes_and_cross_location_supported_sizes_still_fail_closed(): void {
		foreach ( array( 'google', 's3' ) as $provider ) {
			$ilab = $this->size_entry( 'other/location.jpg', $provider, 'location.jpg', 123 );
			$ilab['s3']['bucket'] = 'other-bucket';
			$this->seed(
				'2026/09/photo.jpg',
				'2026/09/photo.jpg',
				$this->size_entry( 'gcs/old.jpg', 'google', 'old.jpg', 999 ),
				$ilab
			);
			$descriptors = ( new MediaCloudImporter() )->descriptors_for_attachment( 901 );
			$this->assertCount( 1, $descriptors );
			$this->assertSame( 'original', $descriptors[0]->variant_type() );
		}
	}

	public function test_all_primary_path_sources_emit_canonical_paths_and_correct_siblings(): void {
		foreach ( array( '2026\\09\\photo.jpg', '2026/./09/photo.jpg', '2026//09/photo.jpg', 'wp-content/uploads/2026/09/photo.jpg' ) as $path ) {
			foreach ( array( array( $path, 'different/metadata.jpg' ), array( $path, null ), array( '', $path ) ) as $sources ) {
				$this->seed( $sources[0], $sources[1] );
				$descriptors = ( new MediaCloudImporter() )->descriptors_for_attachment( 901 );
				$this->assertCount( 2, $descriptors );
				$this->assertSame( '2026/09/photo.jpg', $descriptors[0]->wp_relative_path() );
				$this->assertSame( '2026/09/thumb.jpg', $descriptors[1]->wp_relative_path() );
				$this->assertSame( 'retained//./original.jpg', $descriptors[0]->source_object_key() );
			}
		}
	}

	public function test_invalid_or_empty_normalized_paths_are_skipped_without_throwing(): void {
		foreach ( array( '.', './', 'wp-content/uploads/', '../escape.jpg', '2026/../escape.jpg', '/tmp/a.jpg', 'C:\\tmp\\a.jpg', 'HTTPS://cdn.example/a.jpg', './HTTPS://cdn.example/a.jpg', './C:/tmp/a.jpg', "2026/\0a.jpg" ) as $path ) {
			$this->seed( $path, null );
			$this->assertSame( array(), ( new MediaCloudImporter() )->descriptors_for_attachment( 901 ), $path );
		}
	}

	public function test_invalid_attached_path_can_fall_back_to_normalized_metadata(): void {
		foreach ( array( '.', './', '../escape.jpg', 'HTTPS://cdn.example/a.jpg' ) as $attached ) {
			$this->seed( $attached, '2026/./09/photo.jpg' );
			$descriptors = ( new MediaCloudImporter() )->descriptors_for_attachment( 901 );
			$this->assertCount( 2, $descriptors );
			$this->assertSame( '2026/09/photo.jpg', $descriptors[0]->wp_relative_path() );
			$this->assertSame( '2026/09/thumb.jpg', $descriptors[1]->wp_relative_path() );
		}
	}

	public function test_normalized_primary_path_is_used_for_retained_original_image(): void {
		$this->seed( '2026\\09\\photo.jpg', '2026/09/photo.jpg' );
		$metadata = get_post_meta( 901, '_wp_attachment_metadata', true );
		$metadata['original_image'] = 'unscaled.jpg';
		WpStubs::set_meta( 901, '_wp_attachment_metadata', $metadata );
		$ilab = get_post_meta( 901, 'ilab_s3_info', true );
		$ilab['original_image'] = array( 's3' => array( 'key' => 'exact/unscaled.jpg' ) );
		WpStubs::set_meta( 901, 'ilab_s3_info', $ilab );

		$descriptors = ( new MediaCloudImporter() )->descriptors_for_attachment( 901 );
		$this->assertCount( 3, $descriptors );
		$this->assertSame( 'original-image', $descriptors[1]->variant_type() );
		$this->assertSame( '2026/09/unscaled.jpg', $descriptors[1]->wp_relative_path() );
		$this->assertSame( 'exact/unscaled.jpg', $descriptors[1]->source_object_key() );
	}

	public function test_invalid_size_identifiers_do_not_abort_valid_variants(): void {
		$this->seed( '2026/09/photo.jpg', null );
		$ilab = get_post_meta( 901, 'ilab_s3_info', true );
		foreach ( array( 'Hero.Large', '../bad', '', 'UPPER' ) as $name ) {
			$ilab['sizes'][ $name ] = $this->size_entry( 'retained/bad.jpg', 's3', 'bad.jpg', 12 );
		}
		WpStubs::set_meta( 901, 'ilab_s3_info', $ilab );
		$descriptors = ( new MediaCloudImporter() )->descriptors_for_attachment( 901 );
		$this->assertSame( array( 'original', 'thumbnail' ), array_map( static fn( $d ): string => $d->variant_type(), $descriptors ) );
	}

	public function test_metadata_fallback_rejects_exact_remote_keys_without_guessing_a_prefix(): void {
		foreach ( array( '', '../invalid.jpg', 'HTTPS://cdn.example/photo.jpg', '.' ) as $attached ) {
			foreach ( array( 'custom/2026/09/photo.jpg', 'custom/./2026/09/photo.jpg', 'custom\\2026\\09\\photo.jpg' ) as $metadata_file ) {
				$this->seed( $attached, $metadata_file );
				$ilab = get_post_meta( 901, 'ilab_s3_info', true );
				$ilab['s3']['key'] = 'custom/2026/09/photo.jpg';
				WpStubs::set_meta( 901, 'ilab_s3_info', $ilab );
				$this->assertSame( array(), ( new MediaCloudImporter() )->descriptors_for_attachment( 901 ) );
				$this->assertSame( $metadata_file, get_post_meta( 901, '_wp_attachment_metadata', true )['file'] );
			}
		}
	}

	public function test_metadata_fallback_rejects_keys_from_the_unselected_original_layout(): void {
		foreach ( array( 'metadata', 'ilab' ) as $stale_layout ) {
			$this->seed( '', 'old/2026/09/photo.jpg' );
			$metadata = get_post_meta( 901, '_wp_attachment_metadata', true );
			$ilab = get_post_meta( 901, 'ilab_s3_info', true );
			if ( $stale_layout === 'metadata' ) {
				$metadata['s3']['key'] = 'old/2026/09/photo.jpg';
			} else {
				$ilab['s3']['key'] = 'old/2026/09/photo.jpg';
				$ilab['s3']['provider'] = 'google';
			}
			WpStubs::set_meta( 901, '_wp_attachment_metadata', $metadata );
			WpStubs::set_meta( 901, 'ilab_s3_info', $ilab );
			$this->assertSame( array(), ( new MediaCloudImporter() )->descriptors_for_attachment( 901 ) );
		}
	}

	public function test_prefix_free_key_requires_attached_file_evidence_when_metadata_is_ambiguous(): void {
		foreach ( array( '', '2026/09/photo.jpg' ) as $attached ) {
			$this->seed( $attached, '2026/09/photo.jpg' );
			$ilab = get_post_meta( 901, 'ilab_s3_info', true );
			$ilab['s3']['key'] = '2026/09/photo.jpg';
			WpStubs::set_meta( 901, 'ilab_s3_info', $ilab );
			$descriptors = ( new MediaCloudImporter() )->descriptors_for_attachment( 901 );
			if ( $attached === '' ) {
				$this->assertSame( array(), $descriptors );
			} else {
				$this->assertCount( 2, $descriptors );
				$this->assertSame( '2026/09/photo.jpg', $descriptors[0]->wp_relative_path() );
				$this->assertSame( '2026/09/photo.jpg', $descriptors[0]->source_object_key() );
			}
		}
	}

	/** @return array<string, mixed> */
	private function size_entry( string $key, ?string $provider, string $file, int $bytes ): array {
		$s3 = array( 'key' => $key, 'bucket' => 'media-bucket', 'region' => 'eu-central-1' );
		if ( $provider !== null ) {
			$s3['provider'] = $provider;
		}
		return array( 'file' => $file, 'filesize' => $bytes, 'mime-type' => 'image/jpeg', 's3' => $s3 );
	}

	/**
	 * @param array<string, mixed>|null $metadata_size
	 * @param array<string, mixed>|null $ilab_size
	 */
	private function seed( string $attached, ?string $metadata_file, ?array $metadata_size = null, ?array $ilab_size = null ): void {
		$original = array( 'key' => 'retained//./original.jpg', 'bucket' => 'media-bucket', 'provider' => 's3', 'region' => 'eu-central-1' );
		$metadata = array( 's3' => $original );
		if ( $metadata_file !== null ) {
			$metadata['file'] = $metadata_file;
		}
		if ( $metadata_size !== null ) {
			$metadata['sizes'] = array( 'thumbnail' => $metadata_size );
		}
		WpStubs::set_meta( 901, '_wp_attached_file', $attached );
		WpStubs::set_meta( 901, '_wp_attachment_metadata', $metadata );
		WpStubs::set_meta( 901, 'ilab_s3_info', array(
			's3'    => $original,
			'sizes' => array( 'thumbnail' => $ilab_size ?? $this->size_entry( 'retained/thumb.jpg', 's3', 'thumb.jpg', 12 ) ),
		) );
	}
}
