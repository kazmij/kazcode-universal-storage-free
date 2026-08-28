<?php
/**
 * Seeds Legacy Default Storage profile from s3ms_settings.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Domain\StorageProfileRepositoryInterface;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Storage\ObjectKeyService;

/**
 * One-time (idempotent) migration from single Settings blob → StorageProfile row,
 * plus keeping that same profile in sync on later Settings saves (Setup Wizard,
 * Settings page — the only two places a Free-tier install configures storage).
 */
final class LegacyProfileMigrator {

	public const LEGACY_UUID_OPTION = 's3ms_legacy_profile_uuid';
	public const CREDENTIALS_REF    = 'legacy_default';

	public function __construct(
		private Settings $settings,
		private StorageProfileRepositoryInterface $profiles,
		private ?ObjectRepository $objects = null,
	) {
		$this->objects = $this->objects ?? new ObjectRepository();
	}

	/**
	 * Ensure a default profile exists. Safe to call repeatedly.
	 */
	public function ensure_legacy_profile(): StorageProfile {
		$existing = $this->profiles->find_default_upload_target();
		if ( $existing !== null ) {
			return $existing;
		}

		$uuid = (string) get_option( self::LEGACY_UUID_OPTION, '' );
		if ( $uuid !== '' ) {
			$by_uuid = $this->profiles->find_by_uuid( $uuid );
			if ( $by_uuid !== null ) {
				return $by_uuid;
			}
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$uuid = $this->generate_uuid();

		$cdn = untrailingslashit( (string) $this->settings->get( 'cdn_url', '' ) );
		$public = untrailingslashit( (string) $this->settings->get( 'public_base_url', '' ) );
		$delivery_type = 'storage';
		$delivery_url  = '';
		if ( $cdn !== '' ) {
			$delivery_type = 'cdn';
			$delivery_url  = $cdn;
		} elseif ( $public !== '' ) {
			$delivery_type = 'cdn';
			$delivery_url  = $public;
		}

		$cred = (string) $this->settings->get( 'credential_mode', 'keys' );
		if ( $cred === 'iam_role' ) {
			$cred = 'iam';
		}

		$profile = new StorageProfile(
			null,
			$uuid,
			__( 'Legacy Default Storage', 'kazcode-universal-storage' ),
			(string) $this->settings->get( 'provider_preset', 'aws' ),
			(string) $this->settings->get( 'bucket', '' ),
			(string) $this->settings->get( 'region', 'us-east-1' ),
			(string) $this->settings->get( 'endpoint', '' ),
			(bool) $this->settings->get( 'force_path_style', false ),
			ObjectKeyService::normalize_prefix( (string) $this->settings->get( 'object_prefix', '' ) ),
			$delivery_type,
			$delivery_url,
			(bool) $this->settings->get( 'cdn_includes_prefix', false ),
			$cred,
			self::CREDENTIALS_REF,
			true,
			false,
			false,
			$now,
			$now,
		);

		$id = $this->profiles->insert( $profile );
		update_option( self::LEGACY_UUID_OPTION, $uuid, false );

		$saved = $this->profiles->find( $id );
		if ( $saved === null ) {
			throw new \RuntimeException( 'Failed to load legacy storage profile after insert.' );
		}
		return $saved;
	}

	/**
	 * Push current Settings into the site-credentials-linked default profile, so a
	 * Settings-page or Setup-Wizard save (which only ever write `s3ms_settings`,
	 * never `s3ms_storage_profiles`) does not leave that profile's bucket/region/
	 * endpoint permanently stuck at whatever it was when the profile was first
	 * seeded — which silently breaks profile-scoped delivery URLs (see
	 * ProfileDeliveryUrlResolver) even though offload/verify keep working, because
	 * those still read Settings directly. No-op when no legacy profile exists yet,
	 * or when the default profile has since been detached from site credentials
	 * (credentials_ref changed via the Pro Storage Profile CRUD) — never touch a
	 * profile Settings no longer owns.
	 *
	 * Bucket/region/endpoint/path_style/prefix ("location") are only synced while
	 * the profile is still editable — i.e. no object rows reference it yet — the
	 * same rule StorageProfileAdminService::update() enforces, so a later Settings
	 * change can never silently repoint already-offloaded objects at a different
	 * bucket/endpoint out from under themselves. Once objects exist, location
	 * changes must go through the (Pro) storage-migration flow instead.
	 *
	 * Exception: an empty `bucket` on the profile is never a "location" worth
	 * protecting — it means the very first sync never actually ran (e.g. objects
	 * were offloaded, which reads Settings directly, before any settings save
	 * fired this sync), not that a real bucket was deliberately chosen and must
	 * not be silently repointed. Refusing to fill in that empty value once
	 * objects exist would otherwise brick delivery URLs permanently (see the bug
	 * this fixed: ProfileDeliveryUrlResolver reads the profile row, which stayed
	 * blank forever), with no way to recover short of the Pro storage-migration
	 * flow for what was never a real location to begin with.
	 */
	public function sync_default_profile_from_settings(): void {
		$profile = $this->profiles->find_default_upload_target();
		if ( $profile === null || $profile->credentials_ref !== self::CREDENTIALS_REF ) {
			return;
		}

		$fields  = self::map_settings_to_profile_fields( $this->settings->all() );
		$changes = array(
			'delivery_type'       => $fields['delivery_type'],
			'delivery_base_url'   => $fields['delivery_base_url'],
			'cdn_includes_prefix' => $fields['cdn_includes_prefix'],
			'credential_mode'     => $fields['credential_mode'],
		);

		$id                 = (int) ( $profile->id ?? 0 );
		$location_editable  = ! $profile->location_locked
			&& ( $id <= 0 || $this->objects->count_by_profile( $id ) === 0 || $profile->bucket === '' );
		if ( $location_editable ) {
			$changes['provider_type'] = $fields['provider_type'];
			$changes['bucket']        = $fields['bucket'];
			$changes['region']        = $fields['region'];
			$changes['endpoint']      = $fields['endpoint'];
			$changes['path_style']    = $fields['path_style'];
			$changes['prefix']        = $fields['prefix'];
		}

		$updated = $profile->with( array_merge( $changes, array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) ) );
		$this->profiles->update( $updated );
	}

	/**
	 * Build profile fields from a settings map (unit-test helper / dry preview).
	 *
	 * @param array<string, mixed> $map Settings-like map.
	 * @return array<string, mixed>
	 */
	public static function map_settings_to_profile_fields( array $map ): array {
		$cdn    = untrailingslashit( (string) ( $map['cdn_url'] ?? '' ) );
		$public = untrailingslashit( (string) ( $map['public_base_url'] ?? '' ) );
		$delivery_type = 'storage';
		$delivery_url  = '';
		if ( $cdn !== '' ) {
			$delivery_type = 'cdn';
			$delivery_url  = $cdn;
		} elseif ( $public !== '' ) {
			$delivery_type = 'cdn';
			$delivery_url  = $public;
		}
		$cred = (string) ( $map['credential_mode'] ?? 'keys' );
		if ( $cred === 'iam_role' ) {
			$cred = 'iam';
		}
		return array(
			'provider_type'       => (string) ( $map['provider_preset'] ?? 'aws' ),
			'bucket'              => (string) ( $map['bucket'] ?? '' ),
			'region'              => (string) ( $map['region'] ?? 'us-east-1' ),
			'endpoint'            => (string) ( $map['endpoint'] ?? '' ),
			'path_style'          => (bool) ( $map['force_path_style'] ?? false ),
			'prefix'              => ObjectKeyService::normalize_prefix( (string) ( $map['object_prefix'] ?? '' ) ),
			'delivery_type'       => $delivery_type,
			'delivery_base_url'   => $delivery_url,
			'cdn_includes_prefix' => (bool) ( $map['cdn_includes_prefix'] ?? false ),
			'credential_mode'     => $cred,
			'credentials_ref'     => self::CREDENTIALS_REF,
		);
	}

	private function generate_uuid(): string {
		$data = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}
}
