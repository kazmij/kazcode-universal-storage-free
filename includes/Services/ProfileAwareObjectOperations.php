<?php
/**
 * Profile-aware operations for existing remote objects.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\SecretRedactor;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\RemoteObservation;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Storage\ProfileStorageGateway;
use Kazcode\WpStorage\Storage\ObjectKeyService;
use Kazcode\WpStorage\Storage\S3Storage;

/**
 * Reads existing objects from their persisted storage profile binding.
 */
final class ProfileAwareObjectOperations {

	private ProfileObjectLocator $locator;
	private ProfileStorageGatewayResolver $gateways;
	private ?S3Storage $legacy;

	/**
	 * @param callable(StorageProfile):ProfileStorageGateway|ProfileStorageGatewayResolver|null $gateway_resolver
	 */
	public function __construct(
		?ProfileObjectLocator $locator = null,
		$gateway_resolver = null,
		?S3Storage $legacy = null,
		?Settings $settings = null
	) {
		$this->locator  = $locator ?? new ProfileObjectLocator();
		$this->legacy   = $legacy;
		$this->gateways = $gateway_resolver instanceof ProfileStorageGatewayResolver
			? $gateway_resolver
			: new ProfileStorageGatewayResolver( $settings ?? new Settings(), is_callable( $gateway_resolver ) ? $gateway_resolver : null );
	}

	/**
	 * @return array{exists:bool,confirmed_missing?:bool,error?:string,content_length?:int,content_type?:string,location_status:string,legacy_fallback:bool,storage_profile_id?:int,object_key?:string}
	 */
	public function head_attachment_relative( int $attachment_id, string $relative, bool $allow_legacy_fallback = true ): array {
		$location = $this->locator->locate( $attachment_id, $relative );
		if ( $location->is_found() && $location->storage_profile !== null ) {
			$result = $this->gateway( $location )->head_key( $location->object_key );
			$result['location_status']    = $location->status;
			$result['legacy_fallback']    = false;
			$result['storage_profile_id'] = $location->storage_profile->id;
			$result['object_key']         = $location->object_key;
			return $result;
		}

		if ( $this->can_use_legacy_fallback( $location, $allow_legacy_fallback ) ) {
			$result = $this->legacy->head_relative( $relative );
			$result['location_status'] = $location->status;
			$result['legacy_fallback'] = true;
			return $result;
		}

		return $this->unresolved_result( $location );
	}

	/**
	 * HEAD the exact object an inventory row is bound to (its own profile, never a default fallback).
	 *
	 * @param array<string, mixed> $row Object row from s3ms_objects.
	 * @return array{exists:bool,confirmed_missing?:bool,error?:string,content_length?:int,location_status:string,legacy_fallback:bool,storage_profile_id?:int,object_key?:string}
	 */
	public function head_object_row( array $row ): array {
		$location = $this->locator->locate_inventory_row( $row );
		if ( ! $location->is_found() || $location->storage_profile === null ) {
			return $this->unresolved_result( $location );
		}
		$result = $this->gateway( $location )->head_key( $location->object_key );
		$result['location_status']    = $location->status;
		$result['legacy_fallback']    = false;
		$result['storage_profile_id'] = $location->storage_profile->id;
		$result['object_key']         = $location->object_key;
		return $result;
	}

	/**
	 * Download using a preflight-resolved profile/key (or legacy fallback).
	 *
	 * Does not re-locate: profile_id / object_key / legacy_fallback are authoritative.
	 * When expected_size is an int (including 0), the download is size-validated
	 * before the final path is replaced.
	 *
	 * @return array{success:bool,location_status:string,legacy_fallback:bool,error?:string}
	 */
	public function download_resolved_to_local(
		int $attachment_id,
		string $relative,
		string $local_path,
		?int $profile_id,
		string $object_key,
		bool $legacy_fallback,
		?int $expected_size = null
	): array {
		unset( $attachment_id );
		if ( $legacy_fallback ) {
			if ( $this->legacy === null ) {
				return array(
					'success'         => false,
					'location_status' => ProfileObjectLocation::NOT_IN_INVENTORY,
					'legacy_fallback' => true,
					'error'           => 'legacy_storage_unavailable',
				);
			}
			try {
				$this->legacy->download_relative( $relative, $local_path, $expected_size );
			} catch ( \Throwable $e ) {
				return array(
					'success'         => false,
					'location_status' => ProfileObjectLocation::NOT_IN_INVENTORY,
					'legacy_fallback' => true,
					'error'           => SecretRedactor::redact( $e ),
				);
			}
			return array(
				'success'         => true,
				'location_status' => ProfileObjectLocation::NOT_IN_INVENTORY,
				'legacy_fallback' => true,
			);
		}

		if ( $profile_id === null || $profile_id <= 0 || $object_key === '' ) {
			return array(
				'success'         => false,
				'location_status' => ProfileObjectLocation::OBJECT_KEY_MISSING,
				'legacy_fallback' => false,
				'error'           => 'resolved_location_incomplete',
			);
		}

		$profiles = new \Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository();
		$profile  = $profiles->find( $profile_id );
		if ( $profile === null ) {
			return array(
				'success'         => false,
				'location_status' => ProfileObjectLocation::PROFILE_MISSING,
				'legacy_fallback' => false,
				'error'           => 'profile_missing',
			);
		}

		try {
			$this->gateways->gateway_for_profile( $profile )->download_key_to_local( $object_key, $local_path, $expected_size );
		} catch ( \Throwable $e ) {
			return array(
				'success'         => false,
				'location_status' => ProfileObjectLocation::FOUND,
				'legacy_fallback' => false,
				'error'           => SecretRedactor::redact( $e ),
			);
		}
		return array(
			'success'         => true,
			'location_status' => ProfileObjectLocation::FOUND,
			'legacy_fallback' => false,
		);
	}

	/**
	 * @return array{success:bool,location_status:string,legacy_fallback:bool}
	 */
	public function download_attachment_relative_to_local(
		int $attachment_id,
		string $relative,
		string $local_path,
		bool $allow_legacy_fallback = true
	): array {
		$location = $this->locator->locate( $attachment_id, $relative );
		if ( $location->is_found() && $location->storage_profile !== null ) {
			$this->gateway( $location )->download_key_to_local( $location->object_key, $local_path );
			return array(
				'success'         => true,
				'location_status' => $location->status,
				'legacy_fallback' => false,
			);
		}

		if ( $this->can_use_legacy_fallback( $location, $allow_legacy_fallback ) ) {
			$this->legacy->download_relative( $relative, $local_path );
			return array(
				'success'         => true,
				'location_status' => $location->status,
				'legacy_fallback' => true,
			);
		}

		return array(
			'success'         => false,
			'location_status' => $location->status,
			'legacy_fallback' => false,
		);
	}

	public function presigned_url_for_attachment_relative( int $attachment_id, string $relative, int $ttl ): string {
		$location = $this->locator->locate( $attachment_id, $relative );
		try {
			if ( $location->is_found() && $location->storage_profile !== null ) {
				return $this->gateway( $location )->presigned_url_for_key( $location->object_key, $ttl );
			}
			if ( $this->can_use_legacy_fallback( $location, true ) ) {
				return $this->legacy->presigned_url_for_relative( $relative, $ttl );
			}
		} catch ( \Throwable $e ) {
			return '';
		}
		return '';
	}

	/**
	 * Delete only already-resolved, authoritative locations.
	 *
	 * @param list<ProfileObjectLocation> $locations
	 */
	public function delete_locations( array $locations ): void {
		foreach ( $locations as $location ) {
			if ( ! $location instanceof ProfileObjectLocation || ! $location->is_found() || $location->storage_profile === null ) {
				throw new \RuntimeException( 'Cannot delete unresolved profile object location.' );
			}
			$this->gateway( $location )->delete_key( $location->object_key );
		}
	}

	/** Delete one immutable Direct Upload binding only if its profile location is unchanged. */
	public function delete_exact_profile_key( int $profile_id, string $object_key, string $profile_fingerprint ): bool {
		if ( $profile_id < 1 || $object_key === '' || $profile_fingerprint === '' ) {
			return false;
		}
		try {
			$profile = ( new \Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository() )->find( $profile_id );
			if ( $profile === null || ! hash_equals( $profile_fingerprint, $this->profile_location_fingerprint( $profile ) ) ) {
				return false;
			}
			$this->gateways->gateway_for_profile( $profile )->delete_key( $object_key );
		} catch ( \Throwable $error ) {
			return false;
		}
		return true;
	}

	/**
	 * Abort one durably-authorized multipart upload, prove its provider state is
	 * gone (or empty), then prove no object remains at its exact destination.
	 * A timeout or ambiguous list/head result deliberately retains cleanup.
	 */
	public function settle_exact_multipart_cleanup( int $profile_id, string $object_key, string $profile_fingerprint, string $upload_id ): bool {
		if ( $profile_id < 1 || $object_key === '' || $profile_fingerprint === '' || $upload_id === '' ) return false;
		try {
			$profile = ( new \Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository() )->find( $profile_id );
			if ( $profile === null || ! hash_equals( $profile_fingerprint, $this->profile_location_fingerprint( $profile ) ) ) return false;
			$gateway = $this->gateways->gateway_for_profile( $profile );
			try { $gateway->abort_multipart_upload( $object_key, $upload_id ); } catch ( \Throwable $error ) {
				// A prior cleanup attempt can have completed Abort before failing its
				// subsequent delete/HEAD proof. The provider's authoritative absence is
				// therefore settled, but unknown abort failures remain fail-closed.
				if ( ! $this->is_missing_multipart_upload_error( $error ) ) return false;
			}
			try {
				$parts = $gateway->list_parts( $object_key, $upload_id );
				if ( ! is_array( $parts ) || $parts !== array() ) return false;
			} catch ( \Throwable $error ) {
				if ( ! $this->is_missing_multipart_upload_error( $error ) ) return false;
			}
			$gateway->delete_key( $object_key );
			$head = $gateway->head_key( $object_key );
			return empty( $head['exists'] ) && ! empty( $head['confirmed_missing'] );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	private function is_missing_multipart_upload_error( \Throwable $error ): bool {
		$code = method_exists( $error, 'getAwsErrorCode' ) ? (string) $error->getAwsErrorCode() : (string) $error->getCode();
		return in_array( $code, array( 'NoSuchUpload', 'NoSuchKey', '404' ), true );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{success:bool,location_status:string,legacy_fallback:bool,head?:array<string,mixed>,storage_profile_id?:int}
	 */
	public function upload_file_for_object_row( array $row, string $local_path ): array {
		$location = $this->locator->locate_inventory_row( $row );
		if ( ! $location->is_found() || $location->storage_profile === null ) {
			return array(
				'success'         => false,
				'location_status' => $location->status,
				'legacy_fallback' => false,
			);
		}

		$gateway = $this->gateway( $location );
		$gateway->upload_file_to_key( $local_path, $location->object_key, $location->relative_path );
		$head = $gateway->head_key( $location->object_key );
		$size = filesize( $local_path );
		$observation = RemoteObservation::from_head_result( $head, $size === false ? null : (int) $size );

		return array(
			'success'            => $observation->is_size_verified(),
			'location_status'    => $location->status,
			'legacy_fallback'    => false,
			'head'               => $head,
			'remote_status'      => $observation->status,
			'verification_level' => $observation->verification_level,
			'storage_profile_id' => $location->storage_profile->id,
		);
	}

	private function gateway( ProfileObjectLocation $location ): ProfileStorageGateway {
		return $this->gateways->gateway_for_profile( $location->storage_profile );
	}

	private function profile_location_fingerprint( StorageProfile $profile ): string {
		$values = array( 'profile_id' => $profile->id, 'provider_type' => $profile->provider_type, 'bucket' => $profile->bucket, 'region' => $profile->region, 'endpoint' => $profile->endpoint, 'path_style' => $profile->path_style ? 1 : 0, 'prefix' => ObjectKeyService::normalize_prefix( $profile->prefix ), 'credentials_ref' => $profile->credentials_ref );
		return hash( 'sha256', json_encode( $values, JSON_THROW_ON_ERROR ) );
	}

	private function can_use_legacy_fallback( ProfileObjectLocation $location, bool $allow ): bool {
		return $allow
			&& $this->legacy !== null
			&& $location->status === ProfileObjectLocation::NOT_IN_INVENTORY;
	}

	/**
	 * @return array{exists:bool,confirmed_missing:bool,error:string,error_class:string,remote_status:string,verification_level:string,location_status:string,legacy_fallback:bool}
	 */
	private function unresolved_result( ProfileObjectLocation $location ): array {
		return array(
			'exists'            => false,
			'confirmed_missing' => false,
			'error'             => $location->status,
			'error_class'       => RemoteObservation::ERROR_INVALID_REQUEST,
			'remote_status'     => RemoteObservation::REMOTE_UNKNOWN,
			'verification_level' => RemoteObservation::NOT_VERIFIED,
			'location_status'   => $location->status,
			'legacy_fallback'   => false,
		);
	}
}
