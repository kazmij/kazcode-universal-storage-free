<?php
/**
 * Admin CRUD for storage profiles (v2 P11-T03).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\ProServices;
use Kazcode\WpStorage\Core\ProviderPresets;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
use Kazcode\WpStorage\Storage\ObjectKeyService;

/**
 * Create, update, delete, and summarize storage profiles for admin UI.
 */
final class StorageProfileAdminService {

	public function __construct(
		private Settings $settings,
		private ?WpdbStorageProfileRepository $profiles = null,
		private ?ObjectRepository $objects = null,
		private ?ProfileCredentialStore $credentials = null,
	) {
		$this->profiles    = $profiles ?? new WpdbStorageProfileRepository();
		$this->objects     = $objects ?? new ObjectRepository();
		$this->credentials = $credentials ?? new ProfileCredentialStore();
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function list_summaries(): array {
		$out = array();
		foreach ( $this->profiles->all() as $profile ) {
			$out[] = $this->summarize( $profile );
		}
		return $out;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_summary( int $id ): ?array {
		$profile = $this->profiles->find( $id );
		return $profile !== null ? $this->summarize( $profile ) : null;
	}

	/**
	 * @param array<string, mixed> $input Form payload.
	 * @return array{success:bool,profile?:array<string,mixed>,message?:string}
	 */
	public function create( array $input ): array {
		try {
			// A 2nd+ profile is a Pro product operation — Free only ever
			// bootstraps the one profile it needs for its own single-profile
			// workflow. Pro provides the actual create implementation (see
			// AdditionalStorageProfileService in the Pro plugin); Free has no
			// working code path for this once a profile already exists.
			if ( $this->profiles->count() >= 1 ) {
				$additional = ProServices::require( 'additional_storage_profile', $this->settings );
				return $additional->create( $input );
			}

			$fields          = $this->sanitize_input( $input, null );
			$credentials_ref = $this->resolve_credentials( null );
			$now     = gmdate( 'Y-m-d H:i:s' );
			$profile = new StorageProfile(
				null,
				$this->generate_uuid(),
				$fields['name'],
				$fields['provider_type'],
				$fields['bucket'],
				$fields['region'],
				$fields['endpoint'],
				$fields['path_style'],
				$fields['prefix'],
				$fields['delivery_type'],
				$fields['delivery_base_url'],
				$fields['cdn_includes_prefix'],
				$fields['credential_mode'],
				$credentials_ref,
				$this->profiles->count() === 0,
				false,
				false,
				$now,
				$now,
			);

			$id = $this->profiles->insert( $profile );
			if ( $profile->is_default_upload_target ) {
				$this->profiles->set_default_upload_target( $id );
			}

			$saved = $this->profiles->find( $id );
			if ( $saved === null ) {
				throw new \RuntimeException( 'Failed to load profile after create.' );
			}
			$this->maybe_sync_legacy_settings( $saved );

			return array(
				'success' => true,
				'profile' => $this->summarize( $saved ),
			);
		} catch ( \Throwable $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * @param array<string, mixed> $input Form payload.
	 * @return array{success:bool,profile?:array<string,mixed>,message?:string}
	 */
	public function update( int $id, array $input ): array {
		try {
			// Managing one of several profiles (as opposed to Free's single
			// configurable profile) is a Pro product operation. This only
			// triggers on sites that have 2+ profiles, which only happens
			// when Pro created them — a fresh single-profile Free site is
			// never affected.
			if ( $this->profiles->count() > 1 ) {
				$additional = ProServices::require( 'additional_storage_profile', $this->settings );
				return $additional->update( $id, $input );
			}

			$existing = $this->profiles->find( $id );
			if ( $existing === null ) {
				throw new \RuntimeException( 'Storage profile not found.' );
			}

			$object_count      = $this->objects->count_by_profile( $id );
			// An empty bucket is never a real "location" worth protecting from
			// being overwritten — it means location was never actually set, not
			// that a deliberate choice must be preserved (see
			// LegacyProfileMigrator::sync_default_profile_from_settings()'s
			// matching exception and its docblock for the bug this prevents).
			$location_editable = ! $existing->location_locked && ( $object_count === 0 || $existing->bucket === '' );
			$fields            = $this->sanitize_input( $input, $existing, $location_editable );
			$credentials_ref   = $this->resolve_credentials( $existing );

			$updated = $existing->with(
				array_merge(
					$fields,
					array(
						'credentials_ref' => $credentials_ref,
						'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
					)
				)
			);
			$this->profiles->update( $updated );
			$this->maybe_sync_legacy_settings( $updated );

			return array(
				'success' => true,
				'profile' => $this->summarize( $updated ),
			);
		} catch ( \Throwable $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * @return array{success:bool,message?:string}
	 */
	public function delete( int $id ): array {
		try {
			if ( $this->profiles->count() <= 1 ) {
				throw new \RuntimeException( 'Cannot delete the only storage profile.' );
			}

			// Deleting one of several profiles is the same Pro-managed
			// operation as update() above — it can only be reached once 2+
			// profiles already exist.
			$additional = ProServices::require( 'additional_storage_profile', $this->settings );
			return $additional->delete( $id );
		} catch ( \Throwable $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * @return array{success:bool,profile?:array<string,mixed>,message?:string}
	 */
	public function set_default( int $id ): array {
		try {
			$profile = $this->profiles->find( $id );
			if ( $profile === null ) {
				throw new \RuntimeException( 'Storage profile not found.' );
			}

			// Choosing among several profiles is only meaningful once 2+
			// exist — the same Pro-managed boundary as update()/delete().
			// With a single profile it's already the (only) default.
			if ( $this->profiles->count() > 1 ) {
				$additional = ProServices::require( 'additional_storage_profile', $this->settings );
				return $additional->set_default( $id );
			}

			$this->profiles->set_default_upload_target( $id );
			$refreshed = $this->profiles->find( $id );
			if ( $refreshed === null ) {
				throw new \RuntimeException( 'Failed to reload profile after default switch.' );
			}

			return array(
				'success' => true,
				'profile' => $this->summarize( $refreshed ),
			);
		} catch ( \Throwable $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function summarize( StorageProfile $profile ): array {
		$id           = (int) ( $profile->id ?? 0 );
		$object_count = $id > 0 ? $this->objects->count_by_profile( $id ) : 0;

		return array(
			'id'                       => $id,
			'uuid'                     => $profile->uuid,
			'name'                     => $profile->name,
			'provider_type'            => $profile->provider_type,
			'bucket'                   => $profile->bucket,
			'region'                   => $profile->region,
			'endpoint'                 => $profile->endpoint,
			'path_style'               => $profile->path_style,
			'prefix'                   => $profile->prefix,
			'delivery_type'            => $profile->delivery_type,
			'delivery_base_url'        => $profile->delivery_base_url,
			'cdn_includes_prefix'      => $profile->cdn_includes_prefix,
			'credential_mode'          => $profile->credential_mode,
			'credentials_ref'          => $profile->credentials_ref,
			'access_key_id'            => $profile->credentials_ref === LegacyProfileMigrator::CREDENTIALS_REF
				? (string) $this->settings->get( 'access_key_id', '' )
				: $this->credentials->get_access_key_id( $profile->credentials_ref ),
			'has_secret'               => $profile->credentials_ref === LegacyProfileMigrator::CREDENTIALS_REF
				? $this->settings->has_secret_access_key()
				: $this->credentials->has_secret( $profile->credentials_ref ),
			'is_default_upload_target' => $profile->is_default_upload_target,
			'is_read_only'             => $profile->is_read_only,
			'location_locked'          => $profile->location_locked,
			'object_count'             => $object_count,
			'location_editable'        => ! $profile->location_locked && ( $object_count === 0 || $profile->bucket === '' ),
			'can_delete'               => $object_count === 0
				&& ! $profile->is_default_upload_target
				&& $this->profiles->count() > 1,
			'uses_site_credentials'    => $profile->credentials_ref === LegacyProfileMigrator::CREDENTIALS_REF,
		);
	}

	/**
	 * @param array<string, mixed>   $input             Raw input.
	 * @param StorageProfile|null    $existing          Existing profile on update.
	 * @param bool|null              $location_editable Whether location fields may change.
	 * @return array<string, mixed>
	 */
	private function sanitize_input( array $input, ?StorageProfile $existing, ?bool $location_editable = true ): array {
		$name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
		if ( $name === '' ) {
			throw new \InvalidArgumentException( 'Profile name is required.' );
		}

		$presets = ProviderPresets::all();
		$provider = sanitize_key( (string) ( $input['provider_type'] ?? 'aws' ) );
		if ( ! isset( $presets[ $provider ] ) ) {
			$provider = 'aws';
		}

		$delivery_type = sanitize_key( (string) ( $input['delivery_type'] ?? 'storage' ) );
		if ( ! in_array( $delivery_type, array( 'storage', 'cdn' ), true ) ) {
			$delivery_type = 'storage';
		}

		$delivery_url = esc_url_raw( untrailingslashit( (string) ( $input['delivery_base_url'] ?? '' ) ) );
		if ( $delivery_type === 'storage' ) {
			$delivery_url = '';
		}

		$fields = array(
			'name'                => $name,
			'delivery_type'       => $delivery_type,
			'delivery_base_url'   => $delivery_url,
			'cdn_includes_prefix' => ! empty( $input['cdn_includes_prefix'] ),
		);

		if ( $location_editable ) {
			$bucket = sanitize_text_field( (string) ( $input['bucket'] ?? '' ) );
			if ( $bucket === '' ) {
				throw new \InvalidArgumentException( 'Bucket name is required.' );
			}

			$prefix_raw = (string) ( $input['prefix'] ?? '' );
			try {
				$prefix = ObjectKeyService::normalize_prefix( $prefix_raw );
			} catch ( \InvalidArgumentException $e ) {
				throw new \InvalidArgumentException( 'Invalid object prefix.' );
			}

			$fields['provider_type'] = $provider;
			$fields['bucket']        = $bucket;
			$fields['region']        = sanitize_text_field( (string) ( $input['region'] ?? 'us-east-1' ) );
			$fields['endpoint']      = esc_url_raw( untrailingslashit( (string) ( $input['endpoint'] ?? '' ) ) );
			$fields['path_style']    = ! empty( $input['path_style'] );
			$fields['prefix']      = $prefix;
		} elseif ( $existing !== null ) {
			$fields['provider_type'] = $existing->provider_type;
			$fields['bucket']        = $existing->bucket;
			$fields['region']        = $existing->region;
			$fields['endpoint']      = $existing->endpoint;
			$fields['path_style']    = $existing->path_style;
			$fields['prefix']        = $existing->prefix;
		}

		if ( isset( $input['credential_mode'] ) ) {
			$mode = sanitize_key( (string) $input['credential_mode'] );
			$fields['credential_mode'] = in_array( $mode, array( 'keys', 'iam' ), true ) ? $mode : 'keys';
		} elseif ( $existing === null ) {
			$cred = (string) $this->settings->get( 'credential_mode', 'keys' );
			$fields['credential_mode'] = $cred === 'iam_role' ? 'iam' : 'keys';
		}

		return $fields;
	}

	/**
	 * Resolve which credential set this profile should use. This method only
	 * ever runs for Free's single profile (create()/update() delegate to Pro
	 * for a 2nd+ profile before reaching here) — a lone profile always uses
	 * the shared site-wide (Settings) credentials, since per-profile custom
	 * credentials only have a purpose once there's more than one profile to
	 * differentiate (Pro-owned: see AdditionalStorageProfileService).
	 *
	 */
	private function resolve_credentials( ?StorageProfile $existing ): string {
		if ( $existing !== null && $existing->credentials_ref !== LegacyProfileMigrator::CREDENTIALS_REF ) {
			$this->credentials->delete( $existing->credentials_ref );
		}
		return LegacyProfileMigrator::CREDENTIALS_REF;
	}

	private function maybe_sync_legacy_settings( StorageProfile $profile ): void {
		if ( $profile->credentials_ref !== LegacyProfileMigrator::CREDENTIALS_REF || ! $profile->is_default_upload_target ) {
			return;
		}

		$map = LegacyProfileMigrator::map_settings_to_profile_fields(
			array(
				'provider_preset'     => $profile->provider_type,
				'bucket'              => $profile->bucket,
				'region'              => $profile->region,
				'endpoint'            => $profile->endpoint,
				'force_path_style'    => $profile->path_style,
				'object_prefix'       => $profile->prefix,
				'cdn_url'             => $profile->delivery_type === 'cdn' ? $profile->delivery_base_url : '',
				'public_base_url'     => '',
				'cdn_includes_prefix' => $profile->cdn_includes_prefix,
				'credential_mode'     => $profile->credential_mode === 'iam' ? 'iam_role' : 'keys',
			)
		);

		$this->settings->update(
			array(
				'provider_preset'     => $map['provider_type'],
				'bucket'              => $map['bucket'],
				'region'              => $map['region'],
				'endpoint'            => $map['endpoint'],
				'force_path_style'    => $map['path_style'],
				'object_prefix'       => $map['prefix'],
				'cdn_url'             => $profile->delivery_type === 'cdn' ? $profile->delivery_base_url : '',
				'public_base_url'     => '',
				'cdn_includes_prefix' => $profile->cdn_includes_prefix,
			)
		);
	}

	private function generate_uuid(): string {
		$data    = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}
}
