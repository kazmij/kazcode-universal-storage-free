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

	/**
	 * True when any expected contribution path fails PathGuard normalization.
	 *
	 * Covers core metadata sizes plus MediaVariantRegistry providers and the
	 * `kazus_manifest_files` filter — the same sources `build()` consults —
	 * so callers (Restore Preflight) can BLOCK instead of silently omitting.
	 *
	 * @param array<string, mixed>|null $metadata_override Optional metadata.
	 */
	public function has_invalid_expected_path( int $attachment_id, ?array $metadata_override = null ): bool {
		$candidates = array();
		$attached   = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( $attached !== '' ) {
			$candidates[] = $attached;
		}
		$meta = $metadata_override;
		if ( $meta === null ) {
			$meta = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
		}
		if ( is_array( $meta ) ) {
			if ( ! empty( $meta['file'] ) && is_string( $meta['file'] ) ) {
				$candidates[] = $meta['file'];
			}
			if ( ! empty( $meta['original_image'] ) && is_string( $meta['original_image'] ) ) {
				$candidates[] = $this->join_size_candidate( $attached, $meta['original_image'] );
			}
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size ) {
					if ( ! is_array( $size ) || empty( $size['file'] ) || ! is_string( $size['file'] ) ) {
						continue;
					}
					$candidates[] = $this->join_size_candidate( $attached, $size['file'] );
				}
			}
		}
		$backups = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );
		if ( is_array( $backups ) ) {
			foreach ( $backups as $backup ) {
				if ( ! is_array( $backup ) || empty( $backup['file'] ) || ! is_string( $backup['file'] ) ) {
					continue;
				}
				$candidates[] = $this->join_size_candidate( $attached, $backup['file'] );
			}
		}

		foreach ( $this->variants->contributions( $attachment_id, $metadata_override ) as $row ) {
			if ( ! empty( $row['relative'] ) && is_string( $row['relative'] ) ) {
				$candidates[] = $row['relative'];
			}
		}

		$extra = apply_filters( 'kazus_manifest_files', array(), $attachment_id, $metadata_override );
		if ( is_array( $extra ) ) {
			foreach ( $extra as $row ) {
				if ( is_array( $row ) && ! empty( $row['relative'] ) && is_string( $row['relative'] ) ) {
					$candidates[] = $row['relative'];
				}
			}
		}

		foreach ( $candidates as $candidate ) {
			if ( $candidate === '' ) {
				continue;
			}
			try {
				PathGuard::normalize_relative( $candidate );
			} catch ( \InvalidArgumentException $e ) {
				return true;
			}
		}
		return false;
	}

	private function join_size_candidate( string $attached_file, string $size_file ): string {
		$size_file = str_replace( '\\', '/', $size_file );
		if ( $size_file === '' ) {
			return '';
		}
		if ( str_contains( $size_file, '/' ) || str_starts_with( $size_file, '../' ) || $size_file === '..' ) {
			return $size_file;
		}
		$attached = str_replace( '\\', '/', $attached_file );
		$dir      = dirname( $attached );
		if ( $dir === '.' || $dir === '' ) {
			return $size_file;
		}
		return rtrim( $dir, '/' ) . '/' . $size_file;
	}

	private function try_relative( string $path ): string {
		try {
			return PathGuard::normalize_relative( $path );
		} catch ( \InvalidArgumentException $e ) {
			return '';
		}
	}
}
