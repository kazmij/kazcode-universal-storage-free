<?php
/**
 * Read-only WP Offload Media importer.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Import;

defined( 'ABSPATH' ) || exit;

final class WpOffloadMediaImporter implements ImporterInterface {

	private const SOURCE_ID    = 'wp-offload-media';
	private const SOURCE_LABEL = 'WP Offload Media';
	private const OPTION_KEY   = 'tantan_wordpress_s3';
	private const SOURCE_TYPE  = 'media-library';

	private ?bool $items_table_exists = null;
	private ?bool $files_table_exists = null;

	public function id(): string {
		return self::SOURCE_ID;
	}

	public function label(): string {
		return self::SOURCE_LABEL;
	}

	public function detect(): ImportDetection {
		$settings = $this->read_settings();
		$warnings = array();

		$provider        = $this->string_or_null( $settings['provider'] ?? null );
		$provider_family = $this->provider_family( $provider );
		$bucket          = $this->string_or_null( $settings['bucket'] ?? null );
		$region          = $this->string_or_null( $settings['region'] ?? null );
		$endpoint        = $this->string_or_null( $settings['endpoint'] ?? $settings['storage-endpoint'] ?? null );
		$prefix          = $this->enabled( $settings['enable-object-prefix'] ?? false ) ? $this->string_or_null( $settings['object-prefix'] ?? null ) : null;
		$delivery_base   = $this->delivery_base( $settings );

		if ( $settings === array() ) {
			$warnings[] = 'WP Offload Media settings were not found in tantan_wordpress_s3.';
		}
		if ( $provider_family === null ) {
			$warnings[] = 'Storage provider was not discoverable from WP Offload Media settings.';
		}
		if ( $bucket === null ) {
			$warnings[] = 'Bucket was not discoverable from WP Offload Media settings.';
		}
		if ( $region === null ) {
			$warnings[] = 'Region was not discoverable from WP Offload Media settings.';
		}
		if ( $endpoint === null ) {
			$warnings[] = 'Endpoint was not discoverable from WP Offload Media settings.';
		}

		$known_facts = count( array_filter( array( $provider_family, $bucket, $region, $prefix, $delivery_base ), static fn( ?string $value ): bool => $value !== null ) );
		$confidence  = $settings === array() ? 0.0 : min( 0.95, 0.35 + ( $known_facts * 0.12 ) );

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
			$this->settings_contain_secret_reference( $settings ),
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

		$rows        = $this->items_after( $limit, $after_attachment_id );
		$cursor      = $after_attachment_id;
		$descriptors = array();

		foreach ( $rows as $row ) {
			$attachment_id = (int) ( $row['source_id'] ?? 0 );
			$cursor        = max( $cursor, $attachment_id );
			foreach ( $this->descriptors_for_row( $row ) as $descriptor ) {
				$descriptors[] = $descriptor;
			}
		}

		return new ImportScanPage( $limit, $cursor, $descriptors );
	}

	/**
	 * Exact retained WP Offload Media keys for one attachment.
	 *
	 * @return list<ImportedObjectDescriptor>
	 */
	public function descriptors_for_attachment( int $attachment_id ): array {
		if ( $attachment_id <= 0 ) {
			return array();
		}
		$row = $this->item_for_attachment( $attachment_id );
		if ( $row === null ) {
			return array();
		}
		return $this->descriptors_for_row( $row );
	}

	/** @return array<string, mixed> */
	private function read_settings(): array {
		$settings = get_option( self::OPTION_KEY, array() );
		if ( is_string( $settings ) ) {
			$decoded = $this->maybe_unserialize( $settings );
			$settings = is_array( $decoded ) ? $decoded : array();
		}

		return is_array( $settings ) ? $settings : array();
	}

	/** @return list<array<string, mixed>> */
	private function items_after( int $limit, int $after_attachment_id ): array {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! $this->as3cf_items_table_exists() ) {
			return array();
		}

		$table = $wpdb->prefix . 'as3cf_items';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table contains only $wpdb->prefix plus the literal WP Offload Media suffix; SQL values remain bound below.
		$sql   = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE source_type = %s AND source_id > %d ORDER BY source_id ASC LIMIT %d",
			self::SOURCE_TYPE,
			$after_attachment_id,
			$limit
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- read-only cursor scan of WP Offload Media's fixed-prefix table; every value is bound by $wpdb->prepare(), and caching would make importer progress stale.
		$rows  = $wpdb->get_results( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function item_for_attachment( int $attachment_id ): ?array {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! $this->as3cf_items_table_exists() ) {
			return null;
		}

		$table = $wpdb->prefix . 'as3cf_items';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table contains only $wpdb->prefix plus the literal WP Offload Media suffix; SQL values remain bound below.
		$sql   = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE source_type = %s AND source_id = %d ORDER BY id ASC LIMIT 1",
			self::SOURCE_TYPE,
			$attachment_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- read-only lookup of WP Offload Media's fixed-prefix table; every value is bound by $wpdb->prepare().
		$row = $wpdb->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );

		return is_array( $row ) ? $row : null;
	}

	private function as3cf_items_table_exists(): bool {
		if ( $this->items_table_exists !== null ) {
			return $this->items_table_exists;
		}

		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return $this->items_table_exists = false;
		}

		$table = $wpdb->prefix . 'as3cf_items';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- optional competitor table probe; SHOW TABLES is the cheapest durable existence check.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $this->items_table_exists = is_string( $found ) && $found === $table;
	}

	private function as3cf_files_table_exists(): bool {
		if ( $this->files_table_exists !== null ) {
			return $this->files_table_exists;
		}

		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return $this->files_table_exists = false;
		}

		$table = $wpdb->prefix . 'as3cf_files';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- optional competitor table probe for WP Offload Media 3.4+ sidecars.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $this->files_table_exists = is_string( $found ) && $found === $table;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return list<ImportedObjectDescriptor>
	 */
	private function descriptors_for_row( array $row ): array {
		$attachment_id = (int) ( $row['source_id'] ?? 0 );
		$path          = $this->string_or_null( $row['path'] ?? null );
		if ( $attachment_id <= 0 || $path === null ) {
			return array();
		}

		$wp_relative_path = $this->wp_relative_primary_path( $attachment_id, $row );
		if ( $wp_relative_path === null ) {
			return array();
		}

		$metadata    = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
		$metadata    = is_array( $metadata ) ? $metadata : array();
		$post        = get_post( $attachment_id );
		$post_mime   = is_object( $post ) && isset( $post->post_mime_type ) && is_string( $post->post_mime_type ) && trim( $post->post_mime_type ) !== '' ? $post->post_mime_type : null;
		$fingerprint = $this->fingerprint( $row );
		$file_rows   = $this->files_for_item( (int) ( $row['id'] ?? 0 ), $attachment_id );
		if ( $file_rows !== array() ) {
			return $this->descriptors_for_file_rows( $attachment_id, $metadata, $post_mime, $fingerprint, $file_rows );
		}

		$descriptors = array(
			new ImportedObjectDescriptor(
				$attachment_id,
				$wp_relative_path,
				'original',
				$path,
				isset( $metadata['filesize'] ) && is_int( $metadata['filesize'] ) ? $metadata['filesize'] : null,
				$post_mime,
				$fingerprint
			),
		);

		$original_path = $this->string_or_null( $row['original_path'] ?? null );
		if ( $original_path !== null && isset( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) && trim( $metadata['original_image'] ) !== '' ) {
			$descriptors[] = new ImportedObjectDescriptor(
				$attachment_id,
				$this->sibling_path( $wp_relative_path, $metadata['original_image'] ),
				'original-image',
				$original_path,
				null,
				$post_mime,
				$fingerprint
			);
		}

		$extra_info = $this->extra_info( $row['extra_info'] ?? null );
		foreach ( $this->objects( $extra_info ) as $size => $object ) {
			if ( ! is_string( $size ) || ! is_array( $object ) ) {
				continue;
			}
			$source_file = $this->string_or_null( $object['source_file'] ?? null );
			$key         = $this->string_or_null( $object['path'] ?? null );
			if ( $source_file === null ) {
				continue;
			}
			if ( $key === null ) {
				$key = $this->path_directory( $path ) . $source_file;
			}
			$size_meta = is_array( $metadata['sizes'][ $size ] ?? null ) ? $metadata['sizes'][ $size ] : array();

			$descriptors[] = new ImportedObjectDescriptor(
				$attachment_id,
				$this->sibling_path( $wp_relative_path, $source_file ),
				$size,
				$key,
				isset( $size_meta['filesize'] ) && is_int( $size_meta['filesize'] ) ? $size_meta['filesize'] : null,
				$this->string_or_null( $size_meta['mime-type'] ?? null ) ?? $post_mime,
				$fingerprint
			);
		}

		return $descriptors;
	}

	/**
	 * WP Offload Media 3.4+ stores 1-to-1 local-file to object-key rows in
	 * as3cf_files while keeping as3cf_items in sync. Prefer these rows when
	 * present so newer media sidecars are imported from explicit source data.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function files_for_item( int $item_id, int $attachment_id ): array {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! $this->as3cf_files_table_exists() ) {
			return array();
		}

		$table = $wpdb->prefix . 'as3cf_files';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is the fixed-prefix WP Offload Media files table; values are bound.
		$sql   = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE item_id = %d OR (source_type = %s AND source_id = %d) ORDER BY id ASC",
			$item_id,
			self::SOURCE_TYPE,
			$attachment_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- read-only import scan of competitor-owned fixed-prefix metadata table; SQL values are prepared above, and caching would make import progress stale.
		$rows  = $wpdb->get_results( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array<string, mixed>       $metadata
	 * @param list<array<string, mixed>> $rows
	 * @return list<ImportedObjectDescriptor>
	 */
	private function descriptors_for_file_rows( int $attachment_id, array $metadata, ?string $post_mime, string $fingerprint, array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$wp_relative = $this->string_or_null( $row['source_path'] ?? $row['local_path'] ?? $row['file_path'] ?? null );
			$key         = $this->string_or_null( $row['path'] ?? $row['object_key'] ?? null );
			if ( $wp_relative === null || $key === null ) {
				continue;
			}
			$variant = $this->variant_for_file_row( $wp_relative, $metadata, $row );
			$size    = $row['size_bytes'] ?? $row['filesize'] ?? null;
			$mime    = $this->string_or_null( $row['mime_type'] ?? null ) ?? $this->mime_for_relative( $wp_relative, $metadata, $post_mime );
			$out[]   = new ImportedObjectDescriptor(
				$attachment_id,
				$wp_relative,
				$variant,
				$key,
				$this->int_or_null( $size ),
				$mime,
				$fingerprint
			);
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $metadata
	 * @param array<string, mixed> $row
	 */
	private function variant_for_file_row( string $wp_relative, array $metadata, array $row ): string {
		if ( isset( $metadata['file'] ) && is_string( $metadata['file'] ) && $wp_relative === $metadata['file'] ) {
			return 'original';
		}
		$filename = basename( $wp_relative );
		if ( isset( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) && $filename === $metadata['original_image'] ) {
			return 'original-image';
		}
		foreach ( array( 'source_image', 'animated_video', 'animated_video_poster' ) as $metadata_key ) {
			if ( isset( $metadata[ $metadata_key ] ) && is_string( $metadata[ $metadata_key ] ) && $filename === $metadata[ $metadata_key ] ) {
				return $metadata_key;
			}
		}
		$sizes = is_array( $metadata['sizes'] ?? null ) ? $metadata['sizes'] : array();
		foreach ( $sizes as $size => $size_meta ) {
			if ( is_string( $size ) && is_array( $size_meta ) && isset( $size_meta['file'] ) && is_string( $size_meta['file'] ) && $filename === $size_meta['file'] ) {
				return $size;
			}
		}
		$type = $this->string_or_null( $row['file_type'] ?? $row['variant_type'] ?? $row['size'] ?? null );
		return $type ?? 'other';
	}

	/**
	 * @param array<string, mixed> $metadata
	 */
	private function mime_for_relative( string $wp_relative, array $metadata, ?string $post_mime ): ?string {
		$filename = basename( $wp_relative );
		$sizes    = is_array( $metadata['sizes'] ?? null ) ? $metadata['sizes'] : array();
		foreach ( $sizes as $size_meta ) {
			if ( is_array( $size_meta ) && isset( $size_meta['file'] ) && is_string( $size_meta['file'] ) && $filename === $size_meta['file'] ) {
				return $this->string_or_null( $size_meta['mime-type'] ?? null ) ?? $post_mime;
			}
		}
		return $post_mime;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function wp_relative_primary_path( int $attachment_id, array $row ): ?string {
		$metadata = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
		if ( is_array( $metadata ) && isset( $metadata['file'] ) && is_string( $metadata['file'] ) && trim( $metadata['file'] ) !== '' ) {
			return $metadata['file'];
		}

		$source_path = $this->string_or_null( $row['source_path'] ?? null );
		if ( $source_path !== null ) {
			return $source_path;
		}

		$attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		return is_string( $attached_file ) && trim( $attached_file ) !== '' ? $attached_file : null;
	}

	/**
	 * @param mixed $extra_info
	 * @return array<string, mixed>
	 */
	private function extra_info( mixed $extra_info ): array {
		if ( is_string( $extra_info ) ) {
			$extra_info = $this->maybe_unserialize( $extra_info );
		}

		return is_array( $extra_info ) ? $extra_info : array();
	}

	/**
	 * @param array<string, mixed> $extra_info
	 * @return array<string, mixed>
	 */
	private function objects( array $extra_info ): array {
		return isset( $extra_info['objects'] ) && is_array( $extra_info['objects'] ) ? $extra_info['objects'] : array();
	}

	private function maybe_unserialize( string $value ): mixed {
		$decoded = @unserialize( $value, array( 'allowed_classes' => false ) );
		if ( $decoded !== false || $value === 'b:0;' ) {
			return $decoded;
		}

		return $value;
	}

	private function provider_family( ?string $provider ): ?string {
		return match ( $provider ) {
			'aws', 's3', 'r2', 'wasabi', 'b2' => 's3',
			'do', 'spaces'                    => 'digitalocean',
			'gcp'                             => 'gcs',
			default                           => null,
		};
	}

	/** @param array<string, mixed> $settings */
	private function delivery_base( array $settings ): ?string {
		if ( ! $this->enabled( $settings['enable-delivery-domain'] ?? false ) ) {
			return null;
		}
		$domain = $this->string_or_null( $settings['delivery-domain'] ?? $settings['cloudfront'] ?? null );
		if ( $domain === null ) {
			return null;
		}

		return str_starts_with( $domain, 'http://' ) || str_starts_with( $domain, 'https://' ) ? $domain : 'https://' . $domain;
	}

	private function enabled( mixed $value ): bool {
		return $value === true || $value === 1 || $value === '1' || $value === 'on';
	}

	/** @param array<string, mixed> $settings */
	private function settings_contain_secret_reference( array $settings ): bool {
		foreach ( array_keys( $settings ) as $key ) {
			$normalized = strtolower( (string) $key );
			if ( str_contains( $normalized, 'access' ) || str_contains( $normalized, 'secret' ) || str_contains( $normalized, 'token' ) || str_contains( $normalized, 'password' ) || str_contains( $normalized, 'key-file' ) ) {
				return true;
			}
		}

		return false;
	}

	private function source_version(): ?string {
		$version = $GLOBALS['aws_meta']['amazon-s3-and-cloudfront']['version'] ?? null;
		return is_string( $version ) && trim( $version ) !== '' ? $version : null;
	}

	/** @param array<string, mixed> $row */
	private function fingerprint( array $row ): string {
		$parts = array(
			self::SOURCE_ID,
			$this->string_or_null( $row['provider'] ?? null ) ?? 'unknown-provider',
			$this->string_or_null( $row['bucket'] ?? null ) ?? 'unknown-bucket',
			$this->string_or_null( $row['region'] ?? null ) ?? 'unknown-region',
		);
		// Only row-level endpoint evidence may enter the fingerprint. Current importer
		// settings are not historical proof (settings may have moved A→B after the row).
		$endpoint = $this->string_or_null( $row['endpoint'] ?? $row['storage-endpoint'] ?? null );
		if ( $endpoint !== null ) {
			$parts[] = untrailingslashit( $endpoint );
		}
		return implode( ':', $parts );
	}

	private function sibling_path( string $base_path, string $filename ): string {
		$directory = dirname( $base_path );
		return $directory === '.' ? $filename : $directory . '/' . $filename;
	}

	private function path_directory( string $path ): string {
		$position = strrpos( $path, '/' );
		return $position === false ? '' : substr( $path, 0, $position + 1 );
	}

	private function string_or_null( mixed $value ): ?string {
		return is_string( $value ) && trim( $value ) !== '' ? $value : null;
	}

	private function int_or_null( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && ctype_digit( $value ) ) {
			return (int) $value;
		}

		return null;
	}
}
