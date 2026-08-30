<?php
/**
 * Profile-aware operations for existing remote objects.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\RemoteObservation;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Storage\ProfileStorageGateway;
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
