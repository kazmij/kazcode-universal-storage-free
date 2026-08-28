<?php
/**
 * Builds MediaManifest from WordPress attachment metadata.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Domain;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Attachment\AttachmentFileResolver;
use Kazcode\WpStorage\Domain\MediaVariantRegistry;
use Kazcode\WpStorage\Storage\PathGuard;

/**
 * Authoritative discovery from _wp_attached_file + metadata (+ filter extension).
 */
final class ManifestBuilder {

	public function __construct(
		private ?AttachmentFileResolver $files = null,
		private ?MediaVariantRegistry $variants = null,
	) {
		$this->files    = $files ?? new AttachmentFileResolver();
		$this->variants = $variants ?? new MediaVariantRegistry();
	}

	/**
	 * @param array<string, mixed>|null $metadata_override Optional metadata.
	 */
	public function build( int $attachment_id, ?array $metadata_override = null ): MediaManifest {
		$paths = $this->files->relative_paths( $attachment_id, $metadata_override );

		$attached_raw  = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$attached_norm = $this->try_relative( $attached_raw );

		$items = array();
		$seen  = array();
		foreach ( $paths as $relative ) {
			$variant = ( $attached_norm !== '' && $relative === $attached_norm ) ? 'original' : 'size';
			$items[] = array(
				'relative'     => $relative,
				'variant_type' => $variant,
			);
			$seen[ $relative ] = true;
		}

		foreach ( $this->variants->contributions( $attachment_id, $metadata_override ) as $row ) {
			$rel = $this->try_relative( (string) $row['relative'] );
			if ( $rel === '' || isset( $seen[ $rel ] ) ) {
				continue;
			}
			$seen[ $rel ] = true;
			$items[]      = array(
				'relative'     => $rel,
				'variant_type' => isset( $row['variant_type'] ) && is_string( $row['variant_type'] ) ? $row['variant_type'] : 'other',
			);
		}

		/**
		 * Allow integrations to contribute additional sidecar files.
		 *
		 * @param list<array{relative:string,variant_type?:string}> $extra Extra items.
		 * @param int                                                 $attachment_id Attachment ID.
		 * @param array<string, mixed>|null                           $metadata_override Metadata.
		 */
		$extra = apply_filters( 'kazus_manifest_files', array(), $attachment_id, $metadata_override );
		if ( is_array( $extra ) ) {
			foreach ( $extra as $row ) {
				if ( ! is_array( $row ) || empty( $row['relative'] ) || ! is_string( $row['relative'] ) ) {
					continue;
				}
				$rel = $this->try_relative( $row['relative'] );
				if ( $rel === '' || isset( $seen[ $rel ] ) ) {
					continue;
				}
				$seen[ $rel ] = true;
				$items[]      = array(
					'relative'     => $rel,
					'variant_type' => isset( $row['variant_type'] ) && is_string( $row['variant_type'] ) ? $row['variant_type'] : 'other',
				);
			}
		}

		if ( $items !== array() && $attached_norm === '' ) {
			$items[0]['variant_type'] = 'original';
		}

		return new MediaManifest( $attachment_id, array_values( $items ) );
	}

	private function try_relative( string $path ): string {
		try {
			return PathGuard::normalize_relative( $path );
		} catch ( \InvalidArgumentException $e ) {
			return '';
		}
	}
}
