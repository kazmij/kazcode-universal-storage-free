<?php
/**
 * Profile-scoped public URLs from s3ms_objects + storage_profiles (v2 P2-T07).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Storage;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;

/**
 * Resolve delivery URL from the object's bound Storage Profile, not live Settings.
 */
final class ProfileDeliveryUrlResolver {

	public function __construct(
		private ?ObjectRepository $objects = null,
		private ?WpdbStorageProfileRepository $profiles = null,
		private ?PublicUrlResolver $fallback = null,
	) {
		$this->objects  = $objects ?? new ObjectRepository();
		$this->profiles = $profiles ?? new WpdbStorageProfileRepository();
	}

	/**
	 * URL for one attachment variant; falls back to global Settings when no object row.
	 */
	public function url_for_attachment_relative( int $attachment_id, string $relative_path ): string {
		$relative = trim( str_replace( '\\', '/', $relative_path ), '/' );
		if ( $relative === '' ) {
			return '';
		}

		$row = $this->find_row_for_relative( $attachment_id, $relative );
		if ( $row === null ) {
			return $this->fallback()->url_for_relative( $relative );
		}

		$profile_id = (int) ( $row['storage_profile_id'] ?? 0 );
		$profile    = $profile_id > 0 ? $this->profiles->find( $profile_id ) : null;
		if ( $profile === null ) {
			return $this->fallback()->url_for_relative( $relative );
		}

		$object_key = (string) ( $row['object_key'] ?? '' );
		if ( $object_key === '' ) {
			return $this->fallback()->url_for_relative( $relative );
		}

		return $this->url_for_profile_key( $profile, $object_key, $relative );
	}

	/**
	 * Whether Verify has independently confirmed this exact attachment variant.
	 *
	 * Partial attachments are deliberately stricter than fully offloaded ones:
	 * delivery may use only an inventory row whose own remote status is present.
	 * A present original must never make a missing thumbnail (or vice versa)
	 * eligible for a remote URL.
	 */
	public function has_present_attachment_relative( int $attachment_id, string $relative_path ): bool {
		$relative = trim( str_replace( '\\', '/', $relative_path ), '/' );
		return $relative !== '' && $this->find_present_row_for_relative( $attachment_id, $relative ) !== null;
	}

	/**
	 * Return an attachment only after a delivery URL has been proven against the
	 * current profile-bound inventory. The first implementation deliberately
	 * fails closed; the lookup rules are added with their own regression tests.
	 */
	public function attachment_id_for_delivery_url( string $url ): int {
		$url = trim( $url );
		if ( $url === '' ) {
			return 0;
		}

		$candidates = array();
		foreach ( $this->profiles->all() as $profile ) {
			if ( ! $profile instanceof StorageProfile || $profile->id === null || $profile->delivery_type !== 'cdn' ) {
				continue;
			}
			$relative = $this->cdn_relative_from_delivery_url( $profile, $url );
			if ( $relative === '' ) {
				continue;
			}
			foreach ( $this->objects->find_present_by_profile_and_local_relative( (int) $profile->id, $relative ) as $row ) {
				if ( (string) ( $row['remote_status'] ?? '' ) !== ObjectRemoteStatus::PRESENT ) {
					continue;
				}
				$expected = $this->url_for_profile_key( $profile, (string) ( $row['object_key'] ?? '' ), $relative );
				if ( $expected === '' || ! $this->delivery_urls_match( $expected, $url ) ) {
					continue;
				}
				$id = (int) ( $row['attachment_id'] ?? 0 );
				if ( $id > 0 ) {
					$candidates[ $id ] = true;
				}
			}
		}

		return count( $candidates ) === 1 ? (int) array_key_first( $candidates ) : 0;
	}

	private function cdn_relative_from_delivery_url( StorageProfile $profile, string $url ): string {
		$base = $this->url_parts_without_query_or_fragment( $profile->delivery_base_url );
		$target = $this->url_parts_without_query_or_fragment( $url );
		if ( $base === null || $target === null || $base['host'] !== $target['host'] || ( $base['port'] !== null && $base['port'] !== $target['port'] ) ) {
			return '';
		}
		$base_path = rtrim( $base['path'], '/' );
		$path = $target['path'];
		if ( $base_path !== '' && ! str_starts_with( $path . '/', $base_path . '/' ) ) {
			return '';
		}
		$relative = ltrim( substr( $path, strlen( $base_path ) ), '/' );
		if ( $relative === '' ) {
			return '';
		}
		if ( $profile->cdn_includes_prefix ) {
			$prefix = trim( str_replace( '\\', '/', $profile->prefix ), '/' );
			if ( $prefix === '' || ! str_starts_with( $relative . '/', $prefix . '/' ) ) {
				return '';
			}
			$relative = ltrim( substr( $relative, strlen( $prefix ) ), '/' );
		}
		return $relative === '' ? '' : rawurldecode( $relative );
	}

	/** @return array{host:string,port:?int,path:string}|null */
	private function url_parts_without_query_or_fragment( string $url ): ?array {
		// rawurldecode() leaves malformed escapes unchanged. Without this guard a
		// literal inventory path containing '%' could make a malformed external
		// URL look like its encoded delivery URL, which violates fail-closed
		// ownership resolution.
		if ( preg_match( '/%(?![0-9A-Fa-f]{2})/', $url ) ) {
			return null;
		}
		// Unit/runtime consumers outside a fully loaded WordPress request retain
		// the same URL grammar while WordPress requests use its parser.
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}
		return array(
			'host' => strtolower( (string) $parts['host'] ),
			'port' => isset( $parts['port'] ) ? (int) $parts['port'] : null,
			'path' => '/' . ltrim( rawurldecode( (string) ( $parts['path'] ?? '' ) ), '/' ),
		);
	}

	private function delivery_urls_match( string $expected, string $actual ): bool {
		$expected_parts = $this->url_parts_without_query_or_fragment( $expected );
		$actual_parts   = $this->url_parts_without_query_or_fragment( $actual );
		return $expected_parts !== null && $actual_parts !== null && $expected_parts === $actual_parts;
	}

	/**
	 * A migration (or crop/regenerate) leaves the superseded row behind as `stale`
	 * rather than deleting it, so the same relative path can match more than one
	 * row — e.g. one `stale` row still on the old profile plus one `present` row
	 * on the new one after Pro storage-profile migration. During an incomplete
	 * migration there can also be an `uploading` destination row. Prefer the
	 * first verified `present` match; only fall back to a stale/deleted row if
	 * nothing verified matches this path at all.
	 *
	 * @return array<string, mixed>|null
	 */
	private function find_row_for_relative( int $attachment_id, string $relative ): ?array {
		$fallback = null;
		foreach ( $this->objects->find_by_attachment( $attachment_id ) as $row ) {
			$row_rel = trim( str_replace( '\\', '/', (string) ( $row['local_relative_path'] ?? '' ) ), '/' );
			if ( $row_rel !== $relative ) {
				continue;
			}
			$status = (string) ( $row['remote_status'] ?? '' );
			if ( $status === ObjectRemoteStatus::PRESENT ) {
				return $row;
			}
			if ( $status === ObjectRemoteStatus::STALE || $status === ObjectRemoteStatus::DELETED ) {
				$fallback ??= $row;
			}
		}
		return $fallback;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function find_present_row_for_relative( int $attachment_id, string $relative ): ?array {
		foreach ( $this->objects->find_by_attachment( $attachment_id ) as $row ) {
			$row_rel = trim( str_replace( '\\', '/', (string) ( $row['local_relative_path'] ?? '' ) ), '/' );
			if ( $row_rel === $relative && (string) ( $row['remote_status'] ?? '' ) === ObjectRemoteStatus::PRESENT ) {
				return $row;
			}
		}
		return null;
	}

	public function url_for_profile_key( StorageProfile $profile, string $object_key, string $relative = '' ): string {
		$base           = untrailingslashit( $profile->delivery_base_url );
		$include_prefix = $profile->cdn_includes_prefix;

		if ( $profile->delivery_type === 'cdn' && $base !== '' ) {
			$path = $include_prefix ? $object_key : $this->delivery_path_without_profile_prefix( $profile, $object_key, $relative );
			return $base . '/' . ltrim( $this->encode_path( $path ), '/' );
		}

		return $this->default_s3_url_for_profile( $profile, $object_key );
	}

	/**
	 * A CDN base without the configured profile prefix still needs the exact
	 * inventory object identity. Canonical offloads reduce to their uploads
	 * relative path, while Direct Upload originals retain their private attempt
	 * directory rather than being rewritten to a nonexistent canonical key.
	 */
	private function delivery_path_without_profile_prefix( StorageProfile $profile, string $object_key, string $relative ): string {
		$key    = ltrim( str_replace( '\\', '/', $object_key ), '/' );
		$prefix = trim( str_replace( '\\', '/', $profile->prefix ), '/' );
		if ( $prefix !== '' && str_starts_with( $key . '/', $prefix . '/' ) ) {
			return ltrim( substr( $key, strlen( $prefix ) ), '/' );
		}
		return $relative !== '' ? $relative : $key;
	}

	private function default_s3_url_for_profile( StorageProfile $profile, string $object_key ): string {
		$bucket   = $profile->bucket;
		$region   = $profile->region !== '' ? $profile->region : 'us-east-1';
		$endpoint = untrailingslashit( $profile->endpoint );
		$path     = $this->encode_path( $object_key );

		if ( $bucket === '' ) {
			return '';
		}

		if ( $endpoint !== '' ) {
			if ( $profile->path_style ) {
				return $endpoint . '/' . rawurlencode( $bucket ) . '/' . $path;
			}
			return $endpoint . '/' . $path;
		}

		if ( $region === 'us-east-1' ) {
			// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- this is the plugin's core, disclosed purpose: building the delivery URL for the administrator's own configured S3 bucket/region, not loading a remote script/style/library.
			return 'https://' . $bucket . '.s3.amazonaws.com/' . $path;
		}

		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- same as above; region-specific S3 endpoint form.
		return 'https://' . $bucket . '.s3.' . $region . '.amazonaws.com/' . $path;
	}

	private function encode_path( string $path ): string {
		$path     = ltrim( str_replace( '\\', '/', $path ), '/' );
		$segments = explode( '/', $path );
		$encoded  = array_map(
			static fn( string $segment ): string => rawurlencode( $segment ),
			$segments
		);
		return implode( '/', $encoded );
	}

	private function fallback(): PublicUrlResolver {
		if ( $this->fallback === null ) {
			$this->fallback = new PublicUrlResolver( \Kazcode\WpStorage\Plugin::instance()->settings() );
		}
		return $this->fallback;
	}
}
