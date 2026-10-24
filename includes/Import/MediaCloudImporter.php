<?php
/**
 * Read-only Media Cloud (Interfacelab) importer.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Import;

use Kazcode\WpStorage\Storage\PathGuard;

defined( 'ABSPATH' ) || exit;

final class MediaCloudImporter implements ImporterInterface {

	private const SOURCE_ID    = 'media-cloud';
	private const SOURCE_LABEL = 'Media Cloud';
	private const META_KEY     = 'ilab_s3_info';

	/** S3-compatible Media Cloud driver identifiers that KAZCODE can HEAD. */
	private const SUPPORTED_PROVIDERS = array(
		's3',
		'other-s3',
		'do',
		'wasabi',
		'backblaze-s3',
		'cloudflare',
		'minio',
	);

	public function id(): string {
		return self::SOURCE_ID;
	}

	public function label(): string {
		return self::SOURCE_LABEL;
	}

	public function detect(): ImportDetection {
		$provider_key    = $this->string_or_null( get_option( 'mcloud-storage-provider', null ) );
		$provider_family = $this->provider_family( $provider_key );
		$bucket          = $this->string_or_null( get_option( 'mcloud-storage-s3-bucket', null ) );
		$region          = $this->detect_region();
		$endpoint        = $this->string_or_null( get_option( 'mcloud-storage-s3-endpoint', null ) );
		$prefix          = $this->prefix_value( get_option( 'mcloud-storage-prefix', null ) );
		$delivery_base   = $this->delivery_base(
			$this->string_or_null( get_option( 'mcloud-storage-cdn-base', null ) )
			?? $this->string_or_null( get_option( 'mcloud-storage-cloudflare-r2-public-url', null ) )
		);
		$warnings        = array();

		if ( $provider_key === null && $bucket === null ) {
			$warnings[] = 'Media Cloud storage settings were not found (mcloud-storage-provider / mcloud-storage-s3-bucket).';
		}
		if ( $provider_key !== null && $provider_family === null ) {
			$warnings[] = sprintf(
				'Media Cloud storage provider "%s" is not supported for exact-key S3 adoption.',
				$provider_key
			);
		}
		if ( $bucket === null ) {
			$warnings[] = 'Bucket was not discoverable from Media Cloud settings.';
		}
		if ( $region === null ) {
			$warnings[] = 'Region was not discoverable from Media Cloud settings.';
		}
		if ( $endpoint === null ) {
			$warnings[] = 'Endpoint was not discoverable from Media Cloud settings.';
		}

		$known_facts = count(
			array_filter(
				array( $provider_family, $bucket, $region, $endpoint, $prefix, $delivery_base ),
				static fn( ?string $value ): bool => $value !== null
			)
		);
		$confidence = ( $provider_key === null && $bucket === null )
			? 0.0
			: min( 0.95, 0.35 + ( $known_facts * 0.1 ) );

		return new ImportDetection(
			self::SOURCE_ID,
			self::SOURCE_LABEL,
			$this->source_version(),
			$provider_family,
			$bucket,
			$region,
			$endpoint,
			$prefix,
			'exact',
			$delivery_base,
			$this->has_credential_material(),
			$confidence,
			$warnings
		);
	}

	public function scan_page( int $limit, int $after_attachment_id = 0 ): ImportScanPage {
		if ( $limit <= 0 ) {
			throw new \InvalidArgumentException( 'Import scan limit must be positive.' );
		}
		if ( $after_attachment_id < 0 ) {
			throw new \InvalidArgumentException( 'Import scan cursor must not be negative.' );
		}

		$rows                      = $this->attachments_after( $limit, $after_attachment_id );
		$cursor                    = $after_attachment_id;
		$descriptors               = array();
		$rejected_attachment_count = 0;

		foreach ( $rows as $row ) {
			$attachment_id = (int) ( $row['ID'] ?? 0 );
			$cursor        = max( $cursor, $attachment_id );
			$attachment_descriptors = $this->descriptors_for_attachment( $attachment_id );
			if ( $attachment_descriptors === array() ) {
				++$rejected_attachment_count;
				continue;
			}
			foreach ( $attachment_descriptors as $descriptor ) {
				$descriptors[] = $descriptor;
			}
		}

		return new ImportScanPage( $limit, $cursor, $descriptors, $rejected_attachment_count );
	}

	/** @return list<ImportedObjectDescriptor> */
	public function descriptors_for_attachment( int $attachment_id ): array {
		if ( $attachment_id <= 0 ) {
			return array();
		}

		$cloud = $this->cloud_info( $attachment_id );
		if ( $cloud === null ) {
			return array();
		}

		$original_s3 = is_array( $cloud['s3'] ?? null ) ? $cloud['s3'] : null;
		if ( $original_s3 === null ) {
			return array();
		}

		$key = $this->string_or_null( $original_s3['key'] ?? null );
		if ( $key === null ) {
			return array();
		}

		$provider = $this->string_or_null( $original_s3['provider'] ?? null );
		if ( ! $this->is_supported_provider( $provider ) ) {
			return array();
		}

		$wp_relative_path = $this->wp_relative_primary_path( $attachment_id, $key );
		if ( $wp_relative_path === null ) {
			return array();
		}

		$post        = get_post( $attachment_id );
		$post_mime   = is_object( $post ) && isset( $post->post_mime_type ) && is_string( $post->post_mime_type ) && trim( $post->post_mime_type ) !== ''
			? $post->post_mime_type
			: $this->string_or_null( $original_s3['mime-type'] ?? null );
		$metadata    = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
		$metadata    = is_array( $metadata ) ? $metadata : array();
		$fingerprint = $this->fingerprint_from_s3( $original_s3 );
		$descriptors = array(
			new ImportedObjectDescriptor(
				$attachment_id,
				$wp_relative_path,
				'original',
				$key,
				isset( $metadata['filesize'] ) && is_int( $metadata['filesize'] ) ? $metadata['filesize'] : null,
				$post_mime,
				$fingerprint
			),
		);

		if (
			isset( $metadata['original_image'] )
			&& is_string( $metadata['original_image'] )
			&& $this->is_safe_sibling_filename( $metadata['original_image'] )
		) {
			$original_image_s3 = null;
			if ( is_array( $cloud['original_image'] ?? null ) && is_array( $cloud['original_image']['s3'] ?? null ) ) {
				$original_image_s3 = $cloud['original_image']['s3'];
			}
			$oi_key = $original_image_s3 !== null ? $this->string_or_null( $original_image_s3['key'] ?? null ) : null;
			if ( $oi_key !== null
				&& $this->is_supported_provider( $this->string_or_null( $original_image_s3['provider'] ?? $provider ) )
				&& $this->location_compatible( $original_image_s3, $original_s3 ) ) {
				$descriptors[] = new ImportedObjectDescriptor(
					$attachment_id,
					$this->sibling_path( $wp_relative_path, $metadata['original_image'] ),
					'original-image',
					$oi_key,
					null,
					$post_mime,
					$this->fingerprint_from_s3( $original_image_s3, $original_s3 )
				);
			}
		}

		$meta_sizes  = is_array( $metadata['sizes'] ?? null ) ? $metadata['sizes'] : array();
		$cloud_sizes = is_array( $cloud['sizes'] ?? null ) ? $cloud['sizes'] : array();
		$sizes       = $this->merge_size_maps( $meta_sizes, $cloud_sizes );

		foreach ( $sizes as $size => $size_meta ) {
			if (
				! is_string( $size )
				|| preg_match( '/\A[a-z0-9][a-z0-9_-]*\z/', $size ) !== 1
				|| ! is_array( $size_meta )
			) {
				continue;
			}
			$size_s3 = is_array( $size_meta['s3'] ?? null ) ? $size_meta['s3'] : null;
			if ( $size_s3 === null ) {
				continue;
			}
			$size_key = $this->string_or_null( $size_s3['key'] ?? null );
			if ( $size_key === null ) {
				continue;
			}
			$size_provider = $this->string_or_null( $size_s3['provider'] ?? $provider );
			if ( ! $this->is_supported_provider( $size_provider ) ) {
				continue;
			}
			if ( ! $this->location_compatible( $size_s3, $original_s3 ) ) {
				continue;
			}

			$file = $this->string_or_null( $size_meta['file'] ?? null );
			if ( $file === null ) {
				$file = basename( str_replace( '\\', '/', $size_key ) );
			}
			if ( ! $this->is_safe_sibling_filename( $file ) ) {
				continue;
			}

			$filesize = isset( $size_meta['filesize'] ) && is_int( $size_meta['filesize'] ) ? $size_meta['filesize'] : null;

			$descriptors[] = new ImportedObjectDescriptor(
				$attachment_id,
				$this->sibling_path( $wp_relative_path, $file ),
				$size,
				$size_key,
				$filesize,
				$this->string_or_null( $size_meta['mime-type'] ?? null )
					?? $this->string_or_null( $size_s3['mime-type'] ?? null )
					?? $post_mime,
				$this->fingerprint_from_s3( $size_s3, $original_s3 )
			);
		}

		return $descriptors;
	}

	/**
	 * Merge Media Cloud dual layouts: metadata sizes + ilab sizes by size name.
	 * Prefer the side that retains a usable `s3.key` when nested s3 arrays conflict.
	 *
	 * @param array<string, mixed> $meta_sizes
	 * @param array<string, mixed> $cloud_sizes
	 * @return array<string, array<string, mixed>>
	 */
	private function merge_size_maps( array $meta_sizes, array $cloud_sizes ): array {
		$names = array_values(
			array_unique(
				array_merge( array_keys( $meta_sizes ), array_keys( $cloud_sizes ) )
			)
		);
		$out = array();
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			$base = is_array( $meta_sizes[ $name ] ?? null ) ? $meta_sizes[ $name ] : array();
			$over = is_array( $cloud_sizes[ $name ] ?? null ) ? $cloud_sizes[ $name ] : array();
			$out[ $name ] = $this->prefer_size_entry( $base, $over );
		}
		return $out;
	}

	/**
	 * Prefer the size entry whose nested s3 retains a usable key; fill absent scalar fields from the other.
	 *
	 * @param array<string, mixed> $first
	 * @param array<string, mixed> $second
	 * @return array<string, mixed>
	 */
	private function prefer_size_entry( array $first, array $second ): array {
		$first_ok  = $this->s3_has_key( $first['s3'] ?? null );
		$second_ok = $this->s3_has_key( $second['s3'] ?? null );

		if ( $first_ok && ! $second_ok ) {
			$primary   = $first;
			$secondary = $second;
		} elseif ( $second_ok && ! $first_ok ) {
			$primary   = $second;
			$secondary = $first;
		} elseif ( $second_ok && $first_ok ) {
			// Both retain exact keys — never hybridize nested location fields across layouts.
			$s1 = is_array( $first['s3'] ) ? $first['s3'] : array();
			$s2 = is_array( $second['s3'] ) ? $second['s3'] : array();
			// A missing provider inherits the original's provider later. An explicit
			// unsupported provider must not hide the other layout's usable exact key.
			$first_supported  = ! isset( $s1['provider'] )
				|| $this->is_supported_provider( $this->string_or_null( $s1['provider'] ) );
			$second_supported = ! isset( $s2['provider'] )
				|| $this->is_supported_provider( $this->string_or_null( $s2['provider'] ) );
			if ( $first_supported !== $second_supported ) {
				return $first_supported ? $first : $second;
			}
			$same_key = $this->string_or_null( $s1['key'] ?? null ) === $this->string_or_null( $s2['key'] ?? null );
			if ( $same_key && $this->location_compatible( $s2, $s1 ) ) {
				$primary         = array_merge( $first, $second );
				$primary['s3']   = array_merge( $s1, $s2 );
				return $primary;
			}
			// Prefer the cloud/ilab side as a whole size entry when keys or locations conflict.
			$primary   = $second;
			$secondary = $first;
		} else {
			return array_merge( $first, $second );
		}

		$merged = $primary;
		foreach ( $secondary as $key => $value ) {
			if ( $key === 's3' ) {
				continue;
			}
			if ( ! array_key_exists( $key, $merged ) || $merged[ $key ] === null || $merged[ $key ] === '' ) {
				$merged[ $key ] = $value;
			}
		}
		return $merged;
	}

	/**
	 * @param array<string, mixed>|null $first
	 * @param array<string, mixed>|null $second
	 * @return array<string, mixed>|null
	 */
	private function prefer_s3_with_key( ?array $first, ?array $second ): ?array {
		$first_ok  = $this->s3_is_adoptable( $first );
		$second_ok = $this->s3_is_adoptable( $second );
		if ( $first_ok && $second_ok ) {
			// If only one complete layout names a supported S3-compatible provider, prefer
			// that whole record before applying the historical ilab/second precedence.
			// Otherwise an unsupported ilab provider can hide usable exact-key metadata.
			$first_supported  = $this->is_supported_provider( $this->string_or_null( $first['provider'] ?? null ) );
			$second_supported = $this->is_supported_provider( $this->string_or_null( $second['provider'] ?? null ) );
			if ( $first_supported !== $second_supported ) {
				return $first_supported ? $first : $second;
			}

			// Never hybridize two exact keys from different locations (wrong-bucket HEAD).
			if ( ! $this->location_compatible( $second, $first ) ) {
				return $second;
			}
			$first_key  = $this->string_or_null( $first['key'] ?? null );
			$second_key = $this->string_or_null( $second['key'] ?? null );
			// Same location but different keys: pick one whole record (prefer ilab/second).
			if ( $first_key !== null && $second_key !== null && $first_key !== $second_key ) {
				return $second;
			}
			return array_merge( $first, $second );
		}
		if ( $second_ok ) {
			return $second;
		}
		if ( $first_ok ) {
			return $first;
		}
		return null;
	}

	/** @return array<string, mixed>|null */
	private function cloud_info( int $attachment_id ): ?array {
		$ilab     = get_post_meta( $attachment_id, self::META_KEY, true );
		$ilab     = is_array( $ilab ) ? $ilab : null;
		$metadata = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
		$metadata = is_array( $metadata ) ? $metadata : array();

		$ilab_s3   = is_array( $ilab['s3'] ?? null ) ? $ilab['s3'] : null;
		$meta_s3   = is_array( $metadata['s3'] ?? null ) ? $metadata['s3'] : null;
		$merged_s3 = $this->prefer_s3_with_key( $meta_s3, $ilab_s3 );
		if ( $merged_s3 === null || $this->string_or_null( $merged_s3['key'] ?? null ) === null ) {
			return null;
		}

		$base         = $ilab ?? $metadata ?? array();
		$base['s3']   = $merged_s3;
		$merged_sizes = $this->merge_size_maps(
			is_array( $metadata['sizes'] ?? null ) ? $metadata['sizes'] : array(),
			is_array( $ilab['sizes'] ?? null ) ? $ilab['sizes'] : array()
		);
		if ( $merged_sizes !== array() ) {
			$base['sizes'] = $merged_sizes;
		}

		return $base;
	}

	private function s3_has_key( mixed $s3 ): bool {
		return is_array( $s3 ) && $this->string_or_null( $s3['key'] ?? null ) !== null;
	}

	/** Exact-key adoption also needs provider + bucket evidence (no unknown-* fingerprints). */
	private function s3_is_adoptable( mixed $s3 ): bool {
		return is_array( $s3 )
			&& $this->string_or_null( $s3['key'] ?? null ) !== null
			&& $this->string_or_null( $s3['bucket'] ?? null ) !== null
			&& $this->string_or_null( $s3['provider'] ?? null ) !== null;
	}

	/**
	 * Import commit is single-profile. Skip retained variants whose location does not match
	 * the original object's provider/bucket/region/endpoint so adopt never HEADs the wrong store.
	 *
	 * @param array<string, mixed> $candidate
	 * @param array<string, mixed> $baseline
	 */
	private function location_compatible( array $candidate, array $baseline ): bool {
		$cand_provider = $this->string_or_null( $candidate['provider'] ?? null )
			?? $this->string_or_null( $baseline['provider'] ?? null );
		$base_provider = $this->string_or_null( $baseline['provider'] ?? null );
		if ( $cand_provider === null || $base_provider === null || $cand_provider !== $base_provider ) {
			return false;
		}

		$cand_bucket = $this->string_or_null( $candidate['bucket'] ?? null )
			?? $this->string_or_null( $baseline['bucket'] ?? null );
		$base_bucket = $this->string_or_null( $baseline['bucket'] ?? null );
		if ( $cand_bucket === null || $base_bucket === null || $cand_bucket !== $base_bucket ) {
			return false;
		}

		$cand_region = $this->string_or_null( $candidate['region'] ?? null );
		$base_region = $this->string_or_null( $baseline['region'] ?? null );
		if ( $cand_region !== null && $base_region !== null && $cand_region !== $base_region ) {
			return false;
		}

		$cand_endpoint = $this->string_or_null( $candidate['endpoint'] ?? null );
		$base_endpoint = $this->string_or_null( $baseline['endpoint'] ?? null );
		if ( $cand_endpoint !== null || $base_endpoint !== null ) {
			if ( $cand_endpoint === null ) {
				return true;
			}
			$cand_endpoint = untrailingslashit( $cand_endpoint );
			$base_endpoint = $base_endpoint !== null ? untrailingslashit( $base_endpoint ) : '';
			if ( $cand_endpoint !== $base_endpoint ) {
				return false;
			}
		}

		return true;
	}

	/** @return list<array<string, mixed>> */
	private function attachments_after( int $limit, int $after_attachment_id ): array {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return array();
		}

		// Attachments with ilab_s3_info OR metadata that embeds s3.key (Media Cloud dual layout).
		$sql = $wpdb->prepare(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = %s AND p.post_status != %s AND p.ID > %d
			AND (
				pm.meta_key = %s
				OR ( pm.meta_key = %s AND pm.meta_value LIKE %s )
			)
			ORDER BY p.ID ASC LIMIT %d",
			'attachment',
			'trash',
			$after_attachment_id,
			self::META_KEY,
			'_wp_attachment_metadata',
			'%s:2:"s3";%',
			$limit
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- read-only cursor scan; $sql is produced by $wpdb->prepare().
		$rows = $wpdb->get_results( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );

		return is_array( $rows ) ? $rows : array();
	}

	private function wp_relative_primary_path( int $attachment_id, string $source_object_key ): ?string {
		$metadata = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
		$attached = get_post_meta( $attachment_id, '_wp_attached_file', true );
		// Prefer WordPress's attached path: Media Cloud can retain a full object key
		// in metadata['file']. Normalize local paths only, never remote object keys.
		$remote_keys = array( $source_object_key );
		$ilab        = get_post_meta( $attachment_id, self::META_KEY, true );
		foreach ( array( $metadata, $ilab ) as $layout ) {
			if ( is_array( $layout ) && is_array( $layout['s3'] ?? null ) ) {
				$retained_key = $this->string_or_null( $layout['s3']['key'] ?? null );
				if ( $retained_key !== null ) {
					$remote_keys[] = $retained_key;
				}
			}
		}
		// Canonical copies are used only to reject ambiguous local evidence. The
		// retained keys themselves stay opaque and unchanged for HEAD/adoption.
		$local_comparison_keys = $remote_keys;
		foreach ( $remote_keys as $remote_key ) {
			try {
				$local_comparison_keys[] = PathGuard::normalize_relative( $remote_key );
			} catch ( \InvalidArgumentException $e ) {
				// An opaque remote key need not be a valid local path; keep its raw
				// comparison without letting local validation reject the remote key.
			}
		}
		$candidates = array(
			'attached' => $attached,
			'metadata' => is_array( $metadata ) ? ( $metadata['file'] ?? null ) : null,
		);
		foreach ( $candidates as $source => $path ) {
			if ( ! is_string( $path ) || ! $this->is_relative_local_path( $path ) ) {
				continue;
			}
			try {
				$normalized = PathGuard::normalize_relative( $path );
			} catch ( \InvalidArgumentException $e ) {
				// Degenerate paths such as '.' must not abort the attachment/page.
				continue;
			}
			// A metadata-only path equal to a retained key is ambiguous, including
			// prefix-free keys. Do not guess/strip a remote prefix to invent a path.
			if ( $source === 'metadata'
				&& ( in_array( $path, $local_comparison_keys, true ) || in_array( $normalized, $local_comparison_keys, true ) ) ) {
				continue;
			}
			// Removing dot segments can expose a scheme/drive prefix; validate again.
			if ( $this->is_relative_local_path( $normalized ) ) {
				return $normalized;
			}
		}
		return null;
	}

	/**
	 * local_relative_path must be a real relative local path — never a URI.
	 * Reject schemes case-insensitively (HTTPS://, ftp://, s3://, …), not only lowercase http(s).
	 */
	private function is_relative_local_path( string $path ): bool {
		$path = trim( $path );
		if ( $path === '' || str_contains( $path, "\0" ) ) {
			return false;
		}

		// Match ImportedObjectDescriptor / PathGuard safety before constructing a
		// descriptor: absolute filesystem paths and parent traversal are never local
		// WordPress-relative media paths. Normalize slashes only for validation.
		$slash_path = str_replace( '\\', '/', $path );
		if (
			preg_match( '#^(?:[a-zA-Z]:)?/#', $slash_path ) === 1
			|| str_starts_with( $slash_path, '//' )
			|| PathGuard::has_parent_segment( $path )
		) {
			return false;
		}

		// RFC 3986 scheme: ALPHA *( ALPHA / DIGIT / "+" / "-" / "." ) ":"
		if ( preg_match( '/^[a-z][a-z0-9+.-]*:/i', $path ) === 1 ) {
			return false;
		}
		return true;
	}

	/** @param array<string, mixed> $s3 */
	private function fingerprint_from_s3( array $s3, ?array $fallback = null ): string {
		$provider = $this->string_or_null( $s3['provider'] ?? null )
			?? ( $fallback !== null ? $this->string_or_null( $fallback['provider'] ?? null ) : null )
			?? 'unknown-provider';
		$bucket   = $this->string_or_null( $s3['bucket'] ?? null )
			?? ( $fallback !== null ? $this->string_or_null( $fallback['bucket'] ?? null ) : null )
			?? 'unknown-bucket';
		$parts    = array( self::SOURCE_ID, $provider, $bucket );
		$region   = $this->string_or_null( $s3['region'] ?? null )
			?? ( $fallback !== null ? $this->string_or_null( $fallback['region'] ?? null ) : null );
		$endpoint = $this->string_or_null( $s3['endpoint'] ?? null )
			?? ( $fallback !== null ? $this->string_or_null( $fallback['endpoint'] ?? null ) : null );
		if ( $region !== null || $endpoint !== null ) {
			$parts[] = $region ?? 'unknown-region';
		}
		if ( $endpoint !== null ) {
			$parts[] = untrailingslashit( $endpoint );
		}
		return implode( ':', $parts );
	}

	private function is_supported_provider( ?string $provider ): bool {
		return $provider !== null && in_array( $provider, self::SUPPORTED_PROVIDERS, true );
	}

	private function provider_family( ?string $provider_key ): ?string {
		return match ( $provider_key ) {
			's3', 'other-s3', 'wasabi', 'backblaze-s3', 'cloudflare', 'minio' => 's3',
			'do' => 'digitalocean',
			default => null,
		};
	}

	private function detect_region(): ?string {
		$region = $this->string_or_null( get_option( 'mcloud-storage-s3-region', null ) );
		if ( $region === null || $region === 'auto' ) {
			$custom = $this->string_or_null( get_option( 'mcloud-storage-s3-custom-region', null ) );
			return $custom;
		}
		return $region;
	}

	private function prefix_value( mixed $value ): ?string {
		$prefix = $this->string_or_null( $value );
		if ( $prefix === null ) {
			return null;
		}
		return str_ends_with( $prefix, '/' ) ? $prefix : $prefix . '/';
	}

	private function delivery_base( ?string $domain ): ?string {
		if ( $domain === null ) {
			return null;
		}
		$domain = str_starts_with( $domain, 'http://' ) || str_starts_with( $domain, 'https://' ) ? $domain : 'https://' . $domain;
		return rtrim( $domain, '/' );
	}

	private function has_credential_material(): bool {
		foreach ( array(
			'mcloud-storage-s3-access-key',
			'mcloud-storage-s3-secret',
			'mcloud-storage-google-credentials',
			'mcloud-storage-cloudflare-r2-access-key',
			'mcloud-storage-cloudflare-r2-secret',
		) as $option ) {
			if ( $this->string_or_null( get_option( $option, null ) ) !== null ) {
				return true;
			}
		}
		return false;
	}

	private function source_version(): ?string {
		foreach ( array( 'MEDIA_CLOUD_VERSION', 'ILAB_MEDIA_TOOLS_VERSION' ) as $constant ) {
			if ( defined( $constant ) ) {
				return $this->string_or_null( constant( $constant ) );
			}
		}
		return null;
	}

	private function is_safe_sibling_filename( string $filename ): bool {
		$filename = trim( $filename );
		if ( $filename === '' || str_contains( $filename, "\0" ) ) {
			return false;
		}
		$normalized = str_replace( '\\', '/', $filename );
		if (
			$normalized === '.'
			|| $normalized === '..'
			|| $normalized !== basename( $normalized )
			|| PathGuard::has_parent_segment( $normalized )
			|| preg_match( '/^[a-z][a-z0-9+.-]*:/i', $normalized ) === 1
		) {
			return false;
		}
		return true;
	}

	private function sibling_path( string $base_path, string $filename ): string {
		$directory = dirname( $base_path );
		return $directory === '.' ? $filename : $directory . '/' . $filename;
	}

	private function string_or_null( mixed $value ): ?string {
		return is_string( $value ) && trim( $value ) !== '' ? $value : null;
	}
}
