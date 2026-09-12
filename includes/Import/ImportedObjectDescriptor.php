<?php
/**
 * Object descriptor emitted by a competitor importer.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Import;

use Kazcode\WpStorage\Storage\PathGuard;

defined( 'ABSPATH' ) || exit;

final class ImportedObjectDescriptor {

	public function __construct(
		private int $attachment_id,
		private string $wp_relative_path,
		private string $variant_type,
		private string $source_object_key,
		private ?int $source_size,
		private ?string $source_mime,
		private string $source_fingerprint
	) {
		if ( $attachment_id <= 0 ) {
			throw new \InvalidArgumentException( 'Attachment id must be positive.' );
		}
		$this->assert_relative_path( $wp_relative_path );
		ImportDetection::assert_identifier( $variant_type, 'Variant type' );
		if ( trim( $source_object_key ) === '' ) {
			throw new \InvalidArgumentException( 'Source object key must not be blank.' );
		}
		if ( $source_size !== null && $source_size < 0 ) {
			throw new \InvalidArgumentException( 'Source size must not be negative.' );
		}
		if ( $source_mime !== null && trim( $source_mime ) === '' ) {
			throw new \InvalidArgumentException( 'Source MIME must not be blank.' );
		}
		if ( trim( $source_fingerprint ) === '' ) {
			throw new \InvalidArgumentException( 'Source fingerprint must not be blank.' );
		}
	}

	public function attachment_id(): int {
		return $this->attachment_id;
	}

	public function wp_relative_path(): string {
		return $this->wp_relative_path;
	}

	public function variant_type(): string {
		return $this->variant_type;
	}

	public function source_object_key(): string {
		return $this->source_object_key;
	}

	public function source_size(): ?int {
		return $this->source_size;
	}

	public function source_mime(): ?string {
		return $this->source_mime;
	}

	public function source_fingerprint(): string {
		return $this->source_fingerprint;
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return array(
			'attachment_id'      => $this->attachment_id,
			'wp_relative_path'   => $this->wp_relative_path,
			'variant_type'       => $this->variant_type,
			'source_object_key'  => $this->source_object_key,
			'source_size'        => $this->source_size,
			'source_mime'        => $this->source_mime,
			'source_fingerprint' => $this->source_fingerprint,
		);
	}

	private function assert_relative_path( string $path ): void {
		if ( trim( $path ) === '' || str_starts_with( $path, '/' ) || str_contains( $path, "\0" ) || PathGuard::has_parent_segment( $path ) ) {
			throw new \InvalidArgumentException( 'WordPress-relative path must be a relative path.' );
		}
	}
}
