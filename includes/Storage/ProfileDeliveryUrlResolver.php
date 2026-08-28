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
	 * A migration (or crop/regenerate) leaves the superseded row behind as `stale`
	 * rather than deleting it, so the same relative path can match more than one
	 * row — e.g. one `stale` row still on the old profile plus one `present` row
	 * on the new one after Pro storage-profile migration. Rows are ordered by id
	 * ascending, so a naive first-match would keep resolving to the old (stale)
	 * profile's URL right after a successful migration. Prefer the first
	 * non-stale/non-deleted match; only fall back to a stale/deleted row if
	 * nothing else matches this path at all.
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
			if ( $status === ObjectRemoteStatus::STALE || $status === ObjectRemoteStatus::DELETED ) {
				$fallback ??= $row;
				continue;
			}
			return $row;
		}
		return $fallback;
	}

	public function url_for_profile_key( StorageProfile $profile, string $object_key, string $relative = '' ): string {
		$base           = untrailingslashit( $profile->delivery_base_url );
		$include_prefix = $profile->cdn_includes_prefix;

		if ( $profile->delivery_type === 'cdn' && $base !== '' ) {
			$path = $include_prefix ? $object_key : ( $relative !== '' ? $relative : $object_key );
			return $base . '/' . ltrim( $this->encode_path( $path ), '/' );
		}

		return $this->default_s3_url_for_profile( $profile, $object_key );
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
