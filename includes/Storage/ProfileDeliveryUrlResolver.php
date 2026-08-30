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
use Kazcode\WpStorage\Services\ProfileObjectLocation;
use Kazcode\WpStorage\Services\ProfileObjectLocator;

/**
 * Resolve delivery URL from the object's bound Storage Profile, not live Settings.
 */
final class ProfileDeliveryUrlResolver {

	public function __construct(
		private ?ObjectRepository $objects = null,
		private ?WpdbStorageProfileRepository $profiles = null,
		private ?PublicUrlResolver $fallback = null,
		private ?ProfileObjectLocator $locator = null,
	) {
		$this->objects  = $objects ?? new ObjectRepository();
		$this->profiles = $profiles ?? new WpdbStorageProfileRepository();
		$this->locator  = $locator ?? new ProfileObjectLocator( $this->objects, $this->profiles );
	}

	/**
	 * URL for one attachment variant; falls back to global Settings when no object row.
	 */
	public function url_for_attachment_relative( int $attachment_id, string $relative_path ): string {
		$relative = trim( str_replace( '\\', '/', $relative_path ), '/' );
		if ( $relative === '' ) {
			return '';
		}

		$location = $this->locator->locate( $attachment_id, $relative );
		if ( $location->status === ProfileObjectLocation::NOT_IN_INVENTORY ) {
			return $this->fallback()->url_for_relative( $relative );
		}
		if ( ! $location->is_found() || $location->storage_profile === null || $location->object_key === '' ) {
			return '';
		}

		return $this->url_for_profile_key( $location->storage_profile, $location->object_key, $relative );
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
