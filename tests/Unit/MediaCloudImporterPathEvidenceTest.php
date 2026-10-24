<?php
/**
 * Regression coverage for canonical comparisons of opaque Media Cloud keys.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Import\MediaCloudImporter;
use Kazcode\WpStorage\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class MediaCloudImporterPathEvidenceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WpStubs::reset();
	}

	public function test_metadata_fallback_compares_canonical_copies_of_all_retained_keys(): void {
		foreach ( array( 'custom/./2026/09/photo.jpg', 'custom//2026/09/photo.jpg', 'custom\\2026\\09\\photo.jpg', 'wp-content/uploads/custom/2026/09/photo.jpg' ) as $key ) {
			foreach ( array( 'selected', 'metadata', 'unsupported-ilab' ) as $key_source ) {
				foreach ( array( '', '../invalid.jpg' ) as $attached ) {
					$this->seed( $attached, 'custom/2026/09/photo.jpg', $key, $key_source );
					$this->assertSame( array(), ( new MediaCloudImporter() )->descriptors_for_attachment( 902 ) );
					$layout = $key_source === 'metadata' ? '_wp_attachment_metadata' : 'ilab_s3_info';
					$this->assertSame( $key, get_post_meta( 902, $layout, true )['s3']['key'] );
				}
			}
		}
	}

	public function test_comparison_copies_never_change_the_selected_exact_object_key(): void {
		foreach ( array( 'custom/./2026/09/photo.jpg', 'custom//2026/09/photo.jpg', 'custom\\2026\\09\\photo.jpg', '../opaque.jpg', '/opaque.jpg', 'wp-content/uploads/opaque.jpg' ) as $key ) {
			$this->seed( '2026/09/photo.jpg', 'custom/2026/09/photo.jpg', $key, 'selected' );
			$descriptors = ( new MediaCloudImporter() )->descriptors_for_attachment( 902 );
			$this->assertCount( 1, $descriptors );
			$this->assertSame( '2026/09/photo.jpg', $descriptors[0]->wp_relative_path() );
			$this->assertSame( $key, $descriptors[0]->source_object_key() );
		}
	}

	public function test_nonlocal_remote_key_does_not_abort_distinct_local_metadata_fallback(): void {
		foreach ( array( '../opaque.jpg', '/opaque.jpg', '.', 'wp-content/uploads/' ) as $key ) {
			$this->seed( '', '2026/09/photo.jpg', $key, 'selected' );
			$descriptors = ( new MediaCloudImporter() )->descriptors_for_attachment( 902 );
			$this->assertCount( 1, $descriptors );
			$this->assertSame( '2026/09/photo.jpg', $descriptors[0]->wp_relative_path() );
			$this->assertSame( $key, $descriptors[0]->source_object_key() );
		}
	}

	private function seed( string $attached, string $local, string $key, string $key_source ): void {
		$record = array( 'key' => 'other/original.jpg', 'provider' => 's3', 'bucket' => 'media-bucket', 'region' => 'eu-central-1' );
		$metadata = array( 'file' => $local, 's3' => $record );
		$ilab     = array( 's3' => $record );
		if ( $key_source === 'metadata' ) {
			$metadata['s3']['key'] = $key;
		} else {
			$ilab['s3']['key'] = $key;
			if ( $key_source === 'unsupported-ilab' ) {
				$ilab['s3']['provider'] = 'google';
			}
		}
		WpStubs::set_meta( 902, '_wp_attached_file', $attached );
		WpStubs::set_meta( 902, '_wp_attachment_metadata', $metadata );
		WpStubs::set_meta( 902, 'ilab_s3_info', $ilab );
	}
}
