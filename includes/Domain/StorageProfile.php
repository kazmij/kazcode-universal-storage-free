<?php
/**
 * Storage profile domain entity.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Named remote storage + delivery configuration.
 */
final class StorageProfile {

	public function __construct(
		public readonly ?int $id,
		public readonly string $uuid,
		public readonly string $name,
		public readonly string $provider_type,
		public readonly string $bucket,
		public readonly string $region,
		public readonly string $endpoint,
		public readonly bool $path_style,
		public readonly string $prefix,
		public readonly string $delivery_type,
		public readonly string $delivery_base_url,
		public readonly bool $cdn_includes_prefix,
		public readonly string $credential_mode,
		public readonly string $credentials_ref,
		public readonly bool $is_default_upload_target,
		public readonly bool $is_read_only,
		public readonly bool $location_locked,
		public readonly string $created_at,
		public readonly string $updated_at,
	) {
	}

	/**
	 * @param array<string, mixed> $row DB row.
	 */
	public static function from_row( array $row ): self {
		return new self(
			isset( $row['id'] ) ? (int) $row['id'] : null,
			(string) ( $row['uuid'] ?? '' ),
			(string) ( $row['name'] ?? '' ),
			(string) ( $row['provider_type'] ?? 'aws' ),
			(string) ( $row['bucket'] ?? '' ),
			(string) ( $row['region'] ?? '' ),
			(string) ( $row['endpoint'] ?? '' ),
			(bool) (int) ( $row['path_style'] ?? 0 ),
			(string) ( $row['prefix'] ?? '' ),
			(string) ( $row['delivery_type'] ?? 'storage' ),
			(string) ( $row['delivery_base_url'] ?? '' ),
			(bool) (int) ( $row['cdn_includes_prefix'] ?? 0 ),
			(string) ( $row['credential_mode'] ?? 'keys' ),
			(string) ( $row['credentials_ref'] ?? '' ),
			(bool) (int) ( $row['is_default_upload_target'] ?? 0 ),
			(bool) (int) ( $row['is_read_only'] ?? 0 ),
			(bool) (int) ( $row['location_locked'] ?? 0 ),
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['updated_at'] ?? '' ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_row(): array {
		return array(
			'uuid'                     => $this->uuid,
			'name'                     => $this->name,
			'provider_type'            => $this->provider_type,
			'bucket'                   => $this->bucket,
			'region'                   => $this->region,
			'endpoint'                 => $this->endpoint,
			'path_style'               => $this->path_style ? 1 : 0,
			'prefix'                   => $this->prefix,
			'delivery_type'            => $this->delivery_type,
			'delivery_base_url'        => $this->delivery_base_url !== '' ? $this->delivery_base_url : null,
			'cdn_includes_prefix'      => $this->cdn_includes_prefix ? 1 : 0,
			'credential_mode'          => $this->credential_mode,
			'credentials_ref'          => $this->credentials_ref,
			'is_default_upload_target' => $this->is_default_upload_target ? 1 : 0,
			'is_read_only'             => $this->is_read_only ? 1 : 0,
			'location_locked'          => $this->location_locked ? 1 : 0,
			'created_at'               => $this->created_at,
			'updated_at'               => $this->updated_at,
		);
	}

	/**
	 * Copy with selected field overrides (immutable-friendly).
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 */
	public function with( array $overrides ): self {
		$base = $this->to_row();
		$base['id'] = $this->id;
		foreach ( $overrides as $key => $value ) {
			$base[ $key ] = $value;
		}
		return self::from_row( $base );
	}
}
