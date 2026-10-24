<?php
/**
 * Read-only Advanced Media Offloader importer.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Import;

defined( 'ABSPATH' ) || exit;

final class AdvancedMediaOffloaderImporter implements ImporterInterface {

	private const SOURCE_ID       = 'advanced-media-offloader';
	private const SOURCE_LABEL    = 'Advanced Media Offloader';
	private const SETTINGS_KEY    = 'advmo_settings';
	private const CREDENTIALS_KEY = 'advmo_credentials';

	public function id(): string {
		return self::SOURCE_ID;
	}

	public function label(): string {
		return self::SOURCE_LABEL;
	}

	public function detect(): ImportDetection {
		$settings        = $this->read_option_array( self::SETTINGS_KEY );
		$provider_key    = $this->string_or_null( $settings['cloud_provider'] ?? null );
		$credential_key  = $this->credential_key( $provider_key );
		$credentials     = $credential_key === null ? array() : $this->provider_credentials( $credential_key );
		$provider_family = $this->provider_family( $provider_key );
		$bucket          = $this->credential_value( $credential_key, $credentials, 'bucket' );
		$region          = $this->credential_value( $credential_key, $credentials, 'region' );
		$endpoint        = $this->credential_value( $credential_key, $credentials, 'endpoint' );
		$prefix          = $this->enabled( $settings['path_prefix_active'] ?? false ) ? $this->prefix_value( $settings['path_prefix'] ?? null ) : null;
		$delivery_base   = $this->delivery_base( $this->credential_value( $credential_key, $credentials, 'domain' ) );
		$warnings        = array();

		if ( $settings === array() ) {
			$warnings[] = 'Advanced Media Offloader settings were not found in advmo_settings.';
		}
		if ( $provider_family === null ) {
			$warnings[] = 'Storage provider was not discoverable from Advanced Media Offloader settings.';
		}
		if ( $bucket === null ) {
			$warnings[] = 'Bucket was not discoverable from Advanced Media Offloader settings.';
		}
		if ( $region === null ) {
			$warnings[] = 'Region was not discoverable from Advanced Media Offloader settings.';
		}
		if ( $endpoint === null ) {
			$warnings[] = 'Endpoint was not discoverable from Advanced Media Offloader settings.';
		}

		$known_facts = count( array_filter( array( $provider_family, $bucket, $region, $endpoint, $prefix, $delivery_base ), static fn( ?string $value ): bool => $value !== null ) );
		$confidence  = $settings === array() ? 0.0 : min( 0.95, 0.35 + ( $known_facts * 0.1 ) );

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
			$this->has_credential_material( $credential_key, $credentials ),
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

		$rows        = $this->attachments_after( $limit, $after_attachment_id );
		$cursor      = $after_attachment_id;
		$descriptors = array();

		foreach ( $rows as $row ) {
			$attachment_id = (int) ( $row['ID'] ?? 0 );
			$cursor        = max( $cursor, $attachment_id );
			foreach ( $this->descriptors_for_attachment( $attachment_id ) as $descriptor ) {
				$descriptors[] = $descriptor;
			}
		}

		return new ImportScanPage( $limit, $cursor, $descriptors );
	}

	/** @return array<string, mixed> */
	private function read_option_array( string $option ): array {
		$value = get_option( $option, array() );
		return is_array( $value ) ? $value : array();
	}

	/** @return array<string, mixed> */
	private function provider_credentials( string $credential_key ): array {
		$all = $this->read_option_array( self::CREDENTIALS_KEY );
		return is_array( $all[ $credential_key ] ?? null ) ? $all[ $credential_key ] : array();
	}

	private function credential_value( ?string $credential_key, array $credentials, string $field ): ?string {
		if ( $credential_key !== null ) {
			$constant = 'ADVMO_' . strtoupper( $credential_key ) . '_' . strtoupper( $field );
			if ( defined( $constant ) ) {
				$value = constant( $constant );
				if ( is_bool( $value ) ) {
					return $value ? '1' : '0';
				}
				if ( is_int( $value ) || is_float( $value ) ) {
					return (string) $value;
				}
				return $this->string_or_null( $value );
			}
		}

		return $this->string_or_null( $credentials[ $field ] ?? null );
	}

	private function credential_key( ?string $provider_key ): ?string {
		return match ( $provider_key ) {
			'aws_s3' => 'aws',
			'cloudflare_r2', 'digitalocean', 'minio', 'wasabi', 'backblaze_b2' => $provider_key,
			default => null,
		};
	}

	private function provider_family( ?string $provider_key ): ?string {
		return match ( $provider_key ) {
			'aws_s3', 'cloudflare_r2', 'minio', 'wasabi', 'backblaze_b2' => 's3',
			'digitalocean' => 'digitalocean',
			default => null,
		};
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

	/** @return list<array<string, mixed>> */
	private function attachments_after( int $limit, int $after_attachment_id ): array {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return array();
		}

		$sql  = $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id WHERE p.post_type = %s AND p.post_status != %s AND pm.meta_key = %s AND pm.meta_value != %s AND p.ID > %d ORDER BY p.ID ASC LIMIT %d",
			'attachment',
			'trash',
			'advmo_offloaded',
			'',
			$after_attachment_id,
			$limit
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- read-only cursor scan of core attachment/meta tables; $sql is produced by $wpdb->prepare() with every value bound, and caching would make importer progress stale.
		$rows = $wpdb->get_results( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );

		return is_array( $rows ) ? $rows : array();
	}

	/** @return list<ImportedObjectDescriptor> */
	public function descriptors_for_attachment( int $attachment_id ): array {
		if ( $attachment_id <= 0 ) {
			return array();
		}

		$cloud_prefix = $this->string_or_null( get_post_meta( $attachment_id, 'advmo_path', true ) );
		if ( $cloud_prefix === null ) {
			return array();
		}
		if ( ! str_ends_with( $cloud_prefix, '/' ) ) {
			return array();
		}

		$wp_relative_path = $this->wp_relative_primary_path( $attachment_id );
		if ( $wp_relative_path === null ) {
			return array();
		}

		$post        = get_post( $attachment_id );
		$post_mime   = is_object( $post ) && isset( $post->post_mime_type ) && is_string( $post->post_mime_type ) && trim( $post->post_mime_type ) !== '' ? $post->post_mime_type : null;
		$metadata    = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
		$metadata    = is_array( $metadata ) ? $metadata : array();
		$fingerprint = $this->fingerprint( $attachment_id );
		$descriptors = array(
			new ImportedObjectDescriptor(
				$attachment_id,
				$wp_relative_path,
				'original',
				$cloud_prefix . basename( $wp_relative_path ),
				isset( $metadata['filesize'] ) && is_int( $metadata['filesize'] ) ? $metadata['filesize'] : null,
				$post_mime,
				$fingerprint
			),
		);

		if ( isset( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) && trim( $metadata['original_image'] ) !== '' ) {
			$descriptors[] = new ImportedObjectDescriptor(
				$attachment_id,
				$this->sibling_path( $wp_relative_path, $metadata['original_image'] ),
				'original-image',
				$cloud_prefix . $metadata['original_image'],
				null,
				$post_mime,
				$fingerprint
			);
		}

		$sizes = is_array( $metadata['sizes'] ?? null ) ? $metadata['sizes'] : array();
		foreach ( $sizes as $size => $size_meta ) {
			if ( ! is_string( $size ) || ! is_array( $size_meta ) ) {
				continue;
			}
			$file = $this->string_or_null( $size_meta['file'] ?? null );
			if ( $file === null ) {
				continue;
			}

			$descriptors[] = new ImportedObjectDescriptor(
				$attachment_id,
				$this->sibling_path( $wp_relative_path, $file ),
				$size,
				$cloud_prefix . $file,
				isset( $size_meta['filesize'] ) && is_int( $size_meta['filesize'] ) ? $size_meta['filesize'] : null,
				$this->string_or_null( $size_meta['mime-type'] ?? null ) ?? $post_mime,
				$fingerprint
			);
		}

		return $descriptors;
	}

	private function wp_relative_primary_path( int $attachment_id ): ?string {
		$metadata = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
		if ( is_array( $metadata ) && isset( $metadata['file'] ) && is_string( $metadata['file'] ) && trim( $metadata['file'] ) !== '' ) {
			return $metadata['file'];
		}

		$attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		return is_string( $attached_file ) && trim( $attached_file ) !== '' ? $attached_file : null;
	}

	private function fingerprint( int $attachment_id ): string {
		$parts = array(
			self::SOURCE_ID,
			$this->string_or_null( get_post_meta( $attachment_id, 'advmo_provider', true ) ) ?? 'unknown-provider',
			$this->string_or_null( get_post_meta( $attachment_id, 'advmo_bucket', true ) ) ?? 'unknown-bucket',
		);
		$region   = $this->string_or_null( get_post_meta( $attachment_id, 'advmo_region', true ) );
		// Only attachment-level endpoint meta is historical evidence — never current detect().
		$endpoint = $this->string_or_null( get_post_meta( $attachment_id, 'advmo_endpoint', true ) );
		// Keep legacy provider:bucket fingerprints when neither region nor endpoint is known.
		if ( $region !== null || $endpoint !== null ) {
			$parts[] = $region ?? 'unknown-region';
		}
		if ( $endpoint !== null ) {
			$parts[] = untrailingslashit( $endpoint );
		}
		return implode( ':', $parts );
	}

	private function has_credential_material( ?string $credential_key, array $credentials ): bool {
		foreach ( array( 'key', 'secret', 'token', 'password' ) as $field ) {
			if ( $this->string_or_null( $credentials[ $field ] ?? null ) !== null ) {
				return true;
			}
			if ( $credential_key !== null && defined( 'ADVMO_' . strtoupper( $credential_key ) . '_' . strtoupper( $field ) ) ) {
				return true;
			}
		}

		return false;
	}

	private function source_version(): ?string {
		if ( defined( 'ADVMO_VERSION' ) ) {
			return $this->string_or_null( constant( 'ADVMO_VERSION' ) );
		}

		return null;
	}

	private function sibling_path( string $base_path, string $filename ): string {
		$directory = dirname( $base_path );
		return $directory === '.' ? $filename : $directory . '/' . $filename;
	}

	private function enabled( mixed $value ): bool {
		return $value === true || $value === 1 || $value === '1' || $value === 'on';
	}

	private function string_or_null( mixed $value ): ?string {
		return is_string( $value ) && trim( $value ) !== '' ? $value : null;
	}
}
