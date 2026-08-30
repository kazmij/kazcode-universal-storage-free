<?php
/**
 * Fail-closed safety checks before physical remote deletion.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Attachment\AttachmentFileResolver;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;

/**
 * Decides whether an attachment delete may delete physical remote objects.
 *
 * Current 1.0.x inventory cannot authoritatively model shared attachment
 * references, so this guard combines inventory rows with WordPress attachment
 * metadata and fails closed on ambiguity.
 */
final class RemoteDeleteSafetyGuard {

	public const SAFE_TO_DELETE   = 'safe_to_delete';
	public const SHARED_REFERENCE = 'shared_reference';
	public const UNKNOWN          = 'unknown';

	private ObjectRepository $objects;
	private WpdbStorageProfileRepository $profiles;
	private AttachmentFileResolver $files;
	private ProfileObjectLocator $locator;

	public function __construct(
		?ObjectRepository $objects = null,
		?WpdbStorageProfileRepository $profiles = null,
		?AttachmentFileResolver $files = null,
		?ProfileObjectLocator $locator = null
	) {
		$this->objects  = $objects ?? new ObjectRepository();
		$this->profiles = $profiles ?? new WpdbStorageProfileRepository();
		$this->files    = $files ?? new AttachmentFileResolver();
		$this->locator  = $locator ?? new ProfileObjectLocator( $this->objects, $this->profiles );
	}

	/**
	 * @return array{status:string,reason:string,keys:list<string>,locations:list<ProfileObjectLocation>}
	 */
	public function evaluate( int $attachment_id ): array {
		$relatives = $this->files->relative_paths( $attachment_id );
		if ( $relatives === array() ) {
			return $this->unknown( 'no_manifest' );
		}

		if ( $this->has_same_attached_file_reference( $attachment_id ) ) {
			return array(
				'status' => self::SHARED_REFERENCE,
				'reason' => 'same_attached_file',
				'keys'   => array(),
			);
		}

		$keys      = array();
		$locations = array();
		foreach ( $relatives as $relative ) {
			$location = $this->locator->locate( $attachment_id, $relative );
			if ( ! $location->is_found() ) {
				return $this->unknown( $this->reason_for_location_failure( $location ) );
			}
			$row = $location->object_row ?? array();
			if ( (int) ( $row['attachment_id'] ?? 0 ) !== $attachment_id ) {
				return $this->unknown( 'ambiguous_owner' );
			}
			$keys[]      = $location->object_key;
			$locations[] = $location;
		}

		$keys = array_values( array_unique( $keys ) );
		if ( $keys === array() ) {
			return $this->unknown( 'no_object_keys' );
		}

		return array(
			'status' => self::SAFE_TO_DELETE,
			'reason' => 'unshared_present_inventory',
			'keys'   => $keys,
			'locations' => $locations,
		);
	}

	/**
	 * WordPress attachment source of truth check for WPML-style duplicates.
	 */
	private function has_same_attached_file_reference( int $attachment_id ): bool {
		$attached = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( $attached === '' || ! function_exists( 'get_posts' ) ) {
			return false;
		}

		// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- attachment deletion is rare/admin-side; fail-closed shared-reference safety requires checking WordPress' attachment meta source of truth for another post using the same attached file.
		$others = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'numberposts'    => 1,
				'post__not_in'   => array( $attachment_id ),
				'meta_key'       => '_wp_attached_file',
				'meta_value'     => $attached,
				'no_found_rows'  => true,
				'cache_results'  => false,
			)
		);

		return is_array( $others ) && $others !== array();
	}

	/**
	 * @return array{status:string,reason:string,keys:list<string>,locations:list<ProfileObjectLocation>}
	 */
	private function unknown( string $reason ): array {
		return array(
			'status'    => self::UNKNOWN,
			'reason'    => $reason,
			'keys'      => array(),
			'locations' => array(),
		);
	}

	private function reason_for_location_failure( ProfileObjectLocation $location ): string {
		return match ( $location->status ) {
			ProfileObjectLocation::NOT_IN_INVENTORY => 'no_inventory',
			ProfileObjectLocation::AMBIGUOUS_OBJECT_LOCATION => 'ambiguous_inventory',
			ProfileObjectLocation::PROFILE_MISSING => 'profile_missing',
			ProfileObjectLocation::OBJECT_KEY_MISSING => 'missing_object_key',
			ProfileObjectLocation::OBJECT_NOT_PRESENT => 'not_present',
			default => 'object_location_unknown',
		};
	}
}
