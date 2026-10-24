<?php
/**
 * Integrity Center Verify (#28): observation-only dry-run plus a narrow apply.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Domain\AttachmentSyncDeriver;
use Kazcode\WpStorage\Core\SecretRedactor;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Domain\RemoteObservation;
use Kazcode\WpStorage\Infrastructure\AttachmentLeaseHandle;
use Kazcode\WpStorage\Infrastructure\AttachmentLock;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Storage\PathGuard;

/**
 * HEADs each current inventory row on its own profile and reports what it sees.
 *
 * Expected size precedence (MEDIA-INTEGRITY-CENTER §6.1): local file size, then
 * the inventory `size_bytes`, otherwise existence-only. Only a size-verified HEAD
 * may mark a row present and only a definitive 404 may mark it missing; transient
 * or auth errors, size conflicts and existence-only results never change inventory,
 * and `size_bytes` is never written.
 */
final class IntegrityVerifyService {

	public const VERIFIED      = 'verified';
	public const EXISTS_ONLY   = 'exists_only';
	public const SIZE_CONFLICT = 'size_conflict';
	public const MISSING       = 'missing';
	public const UNKNOWN       = 'unknown';
	public const UNRESOLVED    = 'unresolved';

	public const CHANGE_MARK_PRESENT = 'mark_present';
	public const CHANGE_MARK_MISSING = 'mark_missing';

	/** Rows in flight or superseded are not verify targets. */
	private const VERIFIABLE = array( ObjectRemoteStatus::PRESENT, ObjectRemoteStatus::FAILED, ObjectRemoteStatus::MISSING );

	private ObjectRepository $objects;
	private ProfileAwareObjectOperations $locations;
	private AttachmentLock $lock;

	public function __construct(
		?ObjectRepository $objects = null,
		?ProfileAwareObjectOperations $locations = null,
		?AttachmentLock $lock = null,
		private ?AuditLog $audit = null,
	) {
		$this->objects   = $objects ?? new ObjectRepository();
		$this->locations = $locations ?? new ProfileAwareObjectOperations();
		$this->lock      = $lock ?? new AttachmentLock();
	}

	/**
	 * Dry-run: observe one page of inventory rows. Never writes.
	 *
	 * @return array{scanned:int,next_after_id:int,has_more:bool,counts:array<string,int>,items:list<array<string,mixed>>}
	 */
	public function observe_page( int $limit = 50, int $after_id = 0 ): array {
		$limit  = max( 1, min( 200, $limit ) );
		$rows   = $this->objects->scan_page( $limit, $after_id );
		$counts = array_fill_keys( array( self::VERIFIED, self::EXISTS_ONLY, self::SIZE_CONFLICT, self::MISSING, self::UNKNOWN, self::UNRESOLVED ), 0 );
		$items  = array();
		$last   = $after_id;

		foreach ( $rows as $row ) {
			$last = max( $last, (int) ( $row['id'] ?? 0 ) );
			if ( ! in_array( (string) ( $row['remote_status'] ?? '' ), self::VERIFIABLE, true ) ) {
				continue;
			}
			$item = $this->observe_row( $row );
			++$counts[ $item['outcome'] ];
			$items[] = $item;
		}

		return array(
			'scanned'       => count( $rows ),
			'next_after_id' => $rows === array() ? $after_id : $last,
			'has_more'      => count( $rows ) >= $limit,
			'counts'        => $counts,
			'items'         => $items,
		);
	}

	/**
	 * Apply: re-observe each selected row under its attachment lease and write only
	 * the change the fresh observation still proposes.
	 *
	 * @param list<int> $object_ids Rows listed by a preceding dry-run.
	 * @return array{applied:int,unchanged:int,skipped:int,results:list<array<string,mixed>>}
	 */
	public function apply( array $object_ids ): array {
		$summary = array( 'applied' => 0, 'unchanged' => 0, 'skipped' => 0, 'results' => array() );
		$touched = array();

		foreach ( array_values( array_unique( array_filter( array_map( 'intval', $object_ids ), static fn( int $id ): bool => $id > 0 ) ) ) as $object_id ) {
			$result = $this->apply_one( $object_id );
			++$summary[ $result['result'] ];
			$summary['results'][] = $result;
			if ( $result['result'] === 'applied' ) {
				$touched[ (int) $result['attachment_id'] ] = true;
			}
		}

		foreach ( array_keys( $touched ) as $attachment_id ) {
			update_post_meta( $attachment_id, '_s3ms_status', AttachmentSyncDeriver::derive_status( $this->objects->find_by_attachment( $attachment_id ) ) );
		}
		if ( $touched !== array() ) {
			( new ObjectStatsAggregator( $this->objects ) )->invalidate();
		}

		( $this->audit ?? new AuditLog() )->record(
			'integrity_verify_apply',
			array(
				'requested' => count( $object_ids ),
				'applied'   => $summary['applied'],
				'unchanged' => $summary['unchanged'],
				'skipped'   => $summary['skipped'],
			)
		);

		return $summary;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public function observe_row( array $row ): array {
		[ $expected, $source ] = $this->expected_size( $row );
		$head = $this->locations->head_object_row( $row );
		$item = array(
			'object_id'     => (int) ( $row['id'] ?? 0 ),
			'attachment_id' => (int) ( $row['attachment_id'] ?? 0 ),
			'relative'      => (string) ( $row['local_relative_path'] ?? '' ),
			'remote_status' => (string) ( $row['remote_status'] ?? '' ),
			'expected_size' => $expected,
			'size_source'   => $source,
			'remote_size'   => isset( $head['content_length'] ) ? (int) $head['content_length'] : null,
			'error_class'   => null,
			'change'        => null,
		);

		if ( ! isset( $head['storage_profile_id'] ) ) {
			$item['outcome'] = self::UNRESOLVED;
			$item['message'] = 'Inventory profile/location unresolved: ' . (string) ( $head['location_status'] ?? 'unknown' );
			return $item;
		}

		$observation = RemoteObservation::from_head_result( $head, $expected );
		if ( $observation->is_size_verified() ) {
			$item['outcome'] = self::VERIFIED;
			$item['change']  = $item['remote_status'] !== ObjectRemoteStatus::PRESENT || empty( $row['verified_at'] ) ? self::CHANGE_MARK_PRESENT : null;
		} elseif ( $observation->is_confirmed_missing() ) {
			$item['outcome'] = self::MISSING;
			$item['change']  = $item['remote_status'] !== ObjectRemoteStatus::MISSING ? self::CHANGE_MARK_MISSING : null;
		} elseif ( $observation->verification_level === RemoteObservation::SIZE_MISMATCH ) {
			$item['outcome'] = self::SIZE_CONFLICT;
		} elseif ( $observation->verification_level === RemoteObservation::EXISTS_VERIFIED ) {
			$item['outcome'] = self::EXISTS_ONLY;
		} else {
			$item['outcome']     = self::UNKNOWN;
			$item['error_class'] = $observation->error_class;
		}
		$item['message'] = SecretRedactor::redact( $observation->message, 200 );

		return $item;
	}

	/**
	 * @return array{result:string,object_id:int,attachment_id?:int,change?:?string,message?:string}
	 */
	private function apply_one( int $object_id ): array {
		$row = $this->objects->find_by_id( $object_id );
		if ( $row === null ) {
			return array( 'result' => 'skipped', 'object_id' => $object_id, 'message' => 'Object row not found.' );
		}
		$attachment_id = (int) ( $row['attachment_id'] ?? 0 );
		$lease         = $attachment_id > 0 ? $this->lock->acquire_lease( $attachment_id, 'verify' ) : null;
		if ( ! $lease instanceof AttachmentLeaseHandle ) {
			return array( 'result' => 'skipped', 'object_id' => $object_id, 'attachment_id' => $attachment_id, 'message' => 'Attachment is locked by another operation.' );
		}

		try {
			$row = $this->objects->find_by_id( $object_id );
			if ( $row === null || (int) ( $row['attachment_id'] ?? 0 ) !== $attachment_id || ! in_array( (string) ( $row['remote_status'] ?? '' ), self::VERIFIABLE, true ) ) {
				return array( 'result' => 'skipped', 'object_id' => $object_id, 'attachment_id' => $attachment_id, 'message' => 'Object row changed; run the dry-run again.' );
			}

			$item = $this->observe_row( $row );
			if ( $item['change'] === null ) {
				return array( 'result' => 'unchanged', 'object_id' => $object_id, 'attachment_id' => $attachment_id, 'change' => null, 'message' => $item['outcome'] );
			}
			if ( ! $this->lock->renew( $lease ) ) {
				return array( 'result' => 'skipped', 'object_id' => $object_id, 'attachment_id' => $attachment_id, 'message' => 'Attachment lease lost before write.' );
			}

			$data = array(
				'attachment_id'       => $attachment_id,
				'storage_profile_id'  => (int) $row['storage_profile_id'],
				'local_relative_path' => (string) $row['local_relative_path'],
				'object_key'          => (string) $row['object_key'],
				'variant_type'        => (string) ( $row['variant_type'] ?? 'size' ),
			);
			if ( $item['change'] === self::CHANGE_MARK_PRESENT ) {
				$data['remote_status']      = ObjectRemoteStatus::PRESENT;
				$data['verified_at']        = gmdate( 'Y-m-d H:i:s' );
				$data['last_error_code']    = null;
				$data['last_error_message'] = null;
			} else {
				$data['remote_status']      = ObjectRemoteStatus::MISSING;
				$data['last_error_code']    = 'remote_confirmed_missing';
				$data['last_error_message'] = 'Verify: remote object not found (404).';
			}
			$this->objects->upsert( $data );

			return array( 'result' => 'applied', 'object_id' => $object_id, 'attachment_id' => $attachment_id, 'change' => $item['change'] );
		} finally {
			$this->lock->release_lease( $lease );
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{0:?int,1:string}
	 */
	private function expected_size( array $row ): array {
		$relative = (string) ( $row['local_relative_path'] ?? '' );
		if ( $relative !== '' ) {
			try {
				$absolute = PathGuard::absolute_under_uploads( $relative );
			} catch ( \InvalidArgumentException $e ) {
				$absolute = null;
			}
			if ( $absolute !== null && is_file( $absolute ) ) {
				$size = filesize( $absolute );
				if ( $size !== false ) {
					return array( (int) $size, 'local' );
				}
			}
		}
		$inventory = $row['size_bytes'] ?? null;
		if ( is_numeric( $inventory ) && (int) $inventory > 0 ) {
			return array( (int) $inventory, 'inventory' );
		}
		return array( null, 'none' );
	}
}
