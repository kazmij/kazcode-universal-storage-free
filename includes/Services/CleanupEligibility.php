<?php
/**
 * Single cleanup-eligibility decision for an attachment's current manifest.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Domain\RemoteObservation;

/**
 * Local files may be removed only when every *current* required artifact is
 * durably in inventory, exact-key verified on its recorded profile, and
 * delivered from remote storage (#53). Unknown is never success.
 */
final class CleanupEligibility {

	public const MANIFEST_EMPTY          = 'manifest_empty';
	public const TARGET_OUTSIDE_MANIFEST = 'target_outside_manifest';
	public const INVENTORY_NOT_CONFIRMED = 'inventory_not_confirmed';
	public const PROFILE_MISMATCH        = 'profile_mismatch';
	public const EXPECTED_SIZE_UNKNOWN   = 'expected_size_unknown';
	public const REMOTE_MISSING          = 'remote_confirmed_missing';
	public const REMOTE_NOT_VERIFIED     = 'remote_not_verified';
	public const DELIVERY_NOT_REMOTE     = 'delivery_not_remote';
	public const EVALUATION_FAILED       = 'evaluation_failed';

	private ProfileObjectLocator $locator;
	private ProfileAwareObjectOperations $objects;

	public function __construct(
		private CleanupDeliveryProbe $delivery,
		?ProfileObjectLocator $locator = null,
		?ProfileAwareObjectOperations $objects = null,
	) {
		$this->locator = $locator ?? new ProfileObjectLocator();
		$this->objects = $objects ?? new ProfileAwareObjectOperations( $this->locator );
	}

	/**
	 * @param list<array{relative:string,variant_type?:string}> $manifest_items Current required artifacts.
	 * @param array<string, string>                             $local_files    relative => absolute (existing locals).
	 * @param array<string, string>                             $targets        relative => absolute to delete.
	 * @param int|null                                          $profile_id     When set, every artifact must be bound to this profile.
	 * @return array{eligible:bool,reason:string,relative:string}
	 */
	public function evaluate(
		int $attachment_id,
		array $manifest_items,
		array $local_files,
		array $targets,
		?int $profile_id = null,
	): array {
		try {
			return $this->evaluate_unsafe( $attachment_id, $manifest_items, $local_files, $targets, $profile_id );
		} catch ( \Throwable $e ) {
			return self::blocked( self::EVALUATION_FAILED, '' );
		}
	}

	/**
	 * @param list<array{relative:string,variant_type?:string}> $manifest_items
	 * @param array<string, string>                             $local_files
	 * @param array<string, string>                             $targets
	 * @return array{eligible:bool,reason:string,relative:string}
	 */
	private function evaluate_unsafe(
		int $attachment_id,
		array $manifest_items,
		array $local_files,
		array $targets,
		?int $profile_id,
	): array {
		$required = self::relative_paths( $manifest_items );
		if ( $required === array() ) {
			return self::blocked( self::MANIFEST_EMPTY, '' );
		}

		foreach ( array_keys( $targets ) as $relative ) {
			if ( ! in_array( (string) $relative, $required, true ) ) {
				return self::blocked( self::TARGET_OUTSIDE_MANIFEST, (string) $relative );
			}
		}

		// Inventory is re-read from the database here, not taken from the caller,
		// so only durably persisted rows can authorize deletion.
		$this->locator->forget_attachment( $attachment_id );

		foreach ( $required as $relative ) {
			$location = $this->locator->locate( $attachment_id, $relative );
			if ( ! $location->is_found() ) {
				return self::blocked( self::INVENTORY_NOT_CONFIRMED, $relative );
			}
			if ( $profile_id !== null && $location->storage_profile_id !== $profile_id ) {
				return self::blocked( self::PROFILE_MISMATCH, $relative );
			}

			$expected_size = self::expected_size( $relative, $local_files, $location->object_row ?? array() );
			if ( $expected_size === null ) {
				return self::blocked( self::EXPECTED_SIZE_UNKNOWN, $relative );
			}

			$head        = $this->objects->head_attachment_relative( $attachment_id, $relative, false );
			$observation = RemoteObservation::from_head_result( $head, $expected_size );
			if ( $observation->is_confirmed_missing() ) {
				return self::blocked( self::REMOTE_MISSING, $relative );
			}
			if ( ! $observation->is_size_verified() ) {
				return self::blocked( self::REMOTE_NOT_VERIFIED, $relative );
			}

			if ( ! $this->delivery->resolves_remotely( $attachment_id, $relative ) ) {
				return self::blocked( self::DELIVERY_NOT_REMOTE, $relative );
			}
		}

		return array(
			'eligible' => true,
			'reason'   => '',
			'relative' => '',
		);
	}

	/**
	 * @param list<array{relative:string,variant_type?:string}> $manifest_items
	 * @return list<string> Unique normalized relative paths.
	 */
	public static function relative_paths( array $manifest_items ): array {
		$paths = array();
		foreach ( $manifest_items as $item ) {
			$relative = trim( str_replace( '\\', '/', (string) ( $item['relative'] ?? '' ) ), '/' );
			if ( $relative !== '' ) {
				$paths[ $relative ] = true;
			}
		}
		$paths = array_keys( $paths );
		sort( $paths );
		return $paths;
	}

	/**
	 * Local bytes are authoritative while the file exists; otherwise the size
	 * recorded when the object was verified.
	 *
	 * @param array<string, string> $local_files
	 * @param array<string, mixed>  $row
	 */
	private static function expected_size( string $relative, array $local_files, array $row ): ?int {
		if ( isset( $local_files[ $relative ] ) && is_file( $local_files[ $relative ] ) ) {
			$size = filesize( $local_files[ $relative ] );
			return $size === false ? null : (int) $size;
		}
		if ( isset( $row['size_bytes'] ) && $row['size_bytes'] !== null && $row['size_bytes'] !== '' ) {
			return (int) $row['size_bytes'];
		}
		return null;
	}

	/**
	 * @return array{eligible:bool,reason:string,relative:string}
	 */
	private static function blocked( string $reason, string $relative ): array {
		return array(
			'eligible' => false,
			'reason'   => $reason,
			'relative' => $relative,
		);
	}
}
