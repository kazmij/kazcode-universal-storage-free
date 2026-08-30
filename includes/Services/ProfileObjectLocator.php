<?php
/**
 * Resolves attachment files to profile-bound remote objects.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;

/**
 * Existing object location is authoritative from s3ms_objects, not defaults.
 */
final class ProfileObjectLocator {

	private ObjectRepository $objects;
	private WpdbStorageProfileRepository $profiles;

	/** @var array<int, list<array<string, mixed>>> */
	private array $rows_by_attachment = array();

	/** @var array<int, StorageProfile|null> */
	private array $profiles_by_id = array();

	/** @var array<string, ProfileObjectLocation> */
	private array $locations = array();

	public function __construct(
		?ObjectRepository $objects = null,
		?WpdbStorageProfileRepository $profiles = null
	) {
		$this->objects  = $objects ?? new ObjectRepository();
		$this->profiles = $profiles ?? new WpdbStorageProfileRepository();
	}

	public function locate( int $attachment_id, string $relative_path ): ProfileObjectLocation {
		$relative  = $this->normalize_relative( $relative_path );
		$cache_key = $attachment_id . "\n" . $relative;
		if ( isset( $this->locations[ $cache_key ] ) ) {
			return $this->locations[ $cache_key ];
		}

		$candidates = array();
		foreach ( $this->rows_for_attachment( $attachment_id ) as $row ) {
			$row_relative = $this->normalize_relative( (string) ( $row['local_relative_path'] ?? '' ) );
			if ( $row_relative === $relative ) {
				$candidates[] = $row;
			}
		}

		if ( $candidates === array() ) {
			return $this->locations[ $cache_key ] = ProfileObjectLocation::not_in_inventory( $relative );
		}

		$present = array_values(
			array_filter(
				$candidates,
				static fn( array $row ): bool => (string) ( $row['remote_status'] ?? '' ) === ObjectRemoteStatus::PRESENT
			)
		);

		if ( $present === array() ) {
			return $this->locations[ $cache_key ] = ProfileObjectLocation::object_not_present( $relative );
		}

		$selected = $this->single_authoritative_present( $present );
		if ( $selected === null ) {
			return $this->locations[ $cache_key ] = ProfileObjectLocation::ambiguous( $relative );
		}

		$profile_id = (int) ( $selected['storage_profile_id'] ?? 0 );
		if ( $profile_id <= 0 ) {
			return $this->locations[ $cache_key ] = ProfileObjectLocation::profile_missing( 0, $relative );
		}

		$object_key = (string) ( $selected['object_key'] ?? '' );
		if ( $object_key === '' ) {
			return $this->locations[ $cache_key ] = ProfileObjectLocation::object_key_missing( $profile_id, $relative );
		}

		$profile = $this->profile( $profile_id );
		if ( $profile === null ) {
			return $this->locations[ $cache_key ] = ProfileObjectLocation::profile_missing( $profile_id, $relative );
		}

		return $this->locations[ $cache_key ] = ProfileObjectLocation::found( $selected, $profile, $object_key, $relative );
	}

	/**
	 * Resolve one existing inventory row by its own stored profile/key binding.
	 *
	 * @param array<string, mixed> $row
	 */
	public function locate_inventory_row( array $row ): ProfileObjectLocation {
		$relative   = $this->normalize_relative( (string) ( $row['local_relative_path'] ?? '' ) );
		$profile_id = (int) ( $row['storage_profile_id'] ?? 0 );
		if ( $profile_id <= 0 ) {
			return ProfileObjectLocation::profile_missing( 0, $relative );
		}
		$object_key = (string) ( $row['object_key'] ?? '' );
		if ( $object_key === '' ) {
			return ProfileObjectLocation::object_key_missing( $profile_id, $relative );
		}
		$profile = $this->profile( $profile_id );
		if ( $profile === null ) {
			return ProfileObjectLocation::profile_missing( $profile_id, $relative );
		}
		return ProfileObjectLocation::found( $row, $profile, $object_key, $relative );
	}

	public function forget_attachment( int $attachment_id ): void {
		unset( $this->rows_by_attachment[ $attachment_id ] );
		foreach ( array_keys( $this->locations ) as $key ) {
			if ( str_starts_with( $key, $attachment_id . "\n" ) ) {
				unset( $this->locations[ $key ] );
			}
		}
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function rows_for_attachment( int $attachment_id ): array {
		if ( ! array_key_exists( $attachment_id, $this->rows_by_attachment ) ) {
			$this->rows_by_attachment[ $attachment_id ] = $this->objects->find_by_attachment( $attachment_id );
		}
		return $this->rows_by_attachment[ $attachment_id ];
	}

	private function profile( int $profile_id ): ?StorageProfile {
		if ( ! array_key_exists( $profile_id, $this->profiles_by_id ) ) {
			$this->profiles_by_id[ $profile_id ] = $this->profiles->find( $profile_id );
		}
		return $this->profiles_by_id[ $profile_id ];
	}

	/**
	 * @param list<array<string, mixed>> $present
	 * @return array<string, mixed>|null
	 */
	private function single_authoritative_present( array $present ): ?array {
		$seen = array();
		foreach ( $present as $row ) {
			$identity = (int) ( $row['storage_profile_id'] ?? 0 ) . "\n" . (string) ( $row['object_key'] ?? '' );
			$seen[ $identity ] = true;
		}

		return count( $seen ) === 1 ? $present[0] : null;
	}

	private function normalize_relative( string $relative ): string {
		return trim( str_replace( '\\', '/', $relative ), '/' );
	}
}
