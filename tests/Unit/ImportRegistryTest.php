<?php
/**
 * Competitor import contract and registry tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Import\ImportedObjectDescriptor;
use Kazcode\WpStorage\Import\ImporterInterface;
use Kazcode\WpStorage\Import\ImportDetection;
use Kazcode\WpStorage\Import\ImportRegistry;
use Kazcode\WpStorage\Import\ImportScanPage;
use PHPUnit\Framework\TestCase;

final class ImportRegistryTest extends TestCase {

	public function test_registry_rejects_duplicate_importer_ids(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Duplicate importer id: wp_offload_media' );

		new ImportRegistry(
			array(
				new FakeImporter( 'wp_offload_media', 'WP Offload Media' ),
				new FakeImporter( 'wp_offload_media', 'Duplicate WP Offload Media' ),
			)
		);
	}

	public function test_unknown_importer_id_returns_null(): void {
		$registry = new ImportRegistry( array( new FakeImporter( 'advanced_media_offloader', 'Advanced Media Offloader' ) ) );

		$this->assertNull( $registry->get( 'wp_offload_media' ) );
	}

	public function test_defaults_register_supported_importers_exactly_once(): void {
		$ids = array_keys( ImportRegistry::defaults()->all() );

		$this->assertSame( 1, count( array_keys( $ids, 'wp-offload-media', true ) ) );
		$this->assertSame( 1, count( array_keys( $ids, 'advanced-media-offloader', true ) ) );
		$this->assertSame( 1, count( array_keys( $ids, 'media-cloud', true ) ) );
	}

	public function test_detection_serialization_contains_only_approved_non_secret_fields(): void {
		$detection = new ImportDetection(
			'wp_offload_media',
			'WP Offload Media',
			'3.2.1',
			's3',
			'media-bucket',
			'eu-central-1',
			'https://s3.eu-central-1.amazonaws.com',
			'uploads/',
			'exact',
			'https://cdn.example.test/uploads',
			true,
			0.8,
			array( 'Credential material must be re-entered.' )
		);

		$serialized = $detection->to_array();

		$this->assertSame(
			array(
				'source_id',
				'source_label',
				'source_version',
				'provider_family',
				'bucket',
				'region',
				'endpoint',
				'object_prefix',
				'object_key_style',
				'delivery_base',
				'requires_credential_reentry',
				'confidence',
				'warnings',
			),
			array_keys( $serialized )
		);
		$this->assertSame( 'wp_offload_media', $serialized['source_id'] );
		$this->assertSame( 'media-bucket', $serialized['bucket'] );
		$this->assertTrue( $serialized['requires_credential_reentry'] );
		$this->assertArrayNotHasKey( 'access_key', $serialized );
		$this->assertArrayNotHasKey( 'secret', $serialized );
		$this->assertArrayNotHasKey( 'token', $serialized );
		$this->assertArrayNotHasKey( 'password', $serialized );
	}

	public function test_descriptor_preserves_exact_source_key_and_validates_blank_keys(): void {
		$descriptor = new ImportedObjectDescriptor(
			123,
			'2026/09/photo.jpg',
			'original',
			'  source/plugins/exact photo.jpg  ',
			2048,
			'image/jpeg',
			'wpom:bucket:prefix'
		);

		$this->assertSame( '  source/plugins/exact photo.jpg  ', $descriptor->source_object_key() );
		$this->assertSame(
			array(
				'attachment_id'          => 123,
				'wp_relative_path'       => '2026/09/photo.jpg',
				'variant_type'           => 'original',
				'source_object_key'      => '  source/plugins/exact photo.jpg  ',
				'source_size'            => 2048,
				'source_mime'            => 'image/jpeg',
				'source_fingerprint'     => 'wpom:bucket:prefix',
			),
			$descriptor->to_array()
		);

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Source object key must not be blank.' );

		new ImportedObjectDescriptor( 123, '2026/09/photo.jpg', 'original', " \t\n ", null, null, 'wpom:bucket:prefix' );
	}

	public function test_descriptor_allows_double_dot_filename_but_rejects_parent_segments(): void {
		$descriptor = new ImportedObjectDescriptor(
			123,
			'2026/09/photo..jpg',
			'original',
			'source/plugins/photo..jpg',
			2048,
			'image/jpeg',
			'wpom:bucket:photo..jpg'
		);

		$this->assertSame( '2026/09/photo..jpg', $descriptor->wp_relative_path() );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'WordPress-relative path must be a relative path.' );

		new ImportedObjectDescriptor( 123, '2026/../secret.jpg', 'original', 'source/plugins/secret.jpg', null, null, 'wpom:bucket:secret' );
	}

	public function test_scan_page_validates_cursor_limit_and_descriptor_types(): void {
		$descriptor = new ImportedObjectDescriptor(
			123,
			'2026/09/photo.jpg',
			'original',
			'source/plugins/exact-photo.jpg',
			null,
			null,
			'wpom:bucket:prefix'
		);
		$page       = new ImportScanPage( 50, 123, array( $descriptor ) );

		$this->assertSame( 50, $page->limit() );
		$this->assertSame( 123, $page->cursor() );
		$this->assertSame( array( $descriptor ), $page->descriptors() );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Import scan descriptors must be ImportedObjectDescriptor instances.' );

		new ImportScanPage( 50, 123, array( array( 'attachment_id' => 123 ) ) );
	}
}

final class FakeImporter implements ImporterInterface {

	public function __construct(
		private string $id,
		private string $label
	) {
	}

	public function id(): string {
		return $this->id;
	}

	public function label(): string {
		return $this->label;
	}

	public function detect(): ImportDetection {
		return new ImportDetection( $this->id, $this->label );
	}

	public function scan_page( int $limit, int $after_attachment_id = 0 ): ImportScanPage {
		return new ImportScanPage( $limit, $after_attachment_id, array() );
	}

	public function descriptors_for_attachment( int $attachment_id ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return array();
	}
}
