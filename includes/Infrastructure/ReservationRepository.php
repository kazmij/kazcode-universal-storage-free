<?php
/**
 * Durable ownership reservations for storage paths and object keys.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Domain\ReservationState;

/**
 * The registry intentionally never treats a lapsed worker lease as release
 * authority. A lease only fences workers; settlement remains explicit.
 */
final class ReservationRepository {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 's3ms_storage_reservations';
	}

	/** @param array<string, mixed> $reservation @return array<string, mixed>|\WP_Error */
	public function acquire( array $reservation ) {
		$blog_id  = (int) ( $reservation['blog_id'] ?? 0 );
		$owner    = (string) ( $reservation['owner_uuid'] ?? '' );
		$relative = (string) ( $reservation['relative_path'] ?? '' );
		$profile  = (int) ( $reservation['profile_id'] ?? 0 );
		$key      = (string) ( $reservation['object_key'] ?? '' );
		$state    = (string) ( $reservation['state'] ?? ReservationState::HELD );
		if ( $blog_id <= 0 || $owner === '' || ! ReservationState::is_valid( $state ) || ( $relative === '' && ( $profile <= 0 || $key === '' ) ) || ( ( $profile <= 0 ) !== ( $key === '' ) ) ) {
			return new \WP_Error( 'invalid_reservation', 'A reservation needs an owner and exactly one complete path or object-key locator.' );
		}

		global $wpdb;
		$now  = gmdate( 'Y-m-d H:i:s' );
		$data = array(
			'uuid'               => (string) ( $reservation['uuid'] ?? wp_generate_uuid4() ),
			'blog_id'            => $blog_id,
			'relative_path'      => $relative !== '' ? $relative : null,
			'relative_path_hash' => $relative !== '' ? hash( 'sha256', $relative ) : null,
			'profile_id'         => $profile > 0 ? $profile : null,
			'object_key'         => $key !== '' ? $key : null,
			'object_key_hash'    => $key !== '' ? hash( 'sha256', $key ) : null,
			'owner_uuid'         => $owner,
			'state'              => $state,
			'created_at'         => $now,
			'updated_at'         => $now,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned durable registry; wpdb::insert parameterizes values.
		$inserted = $wpdb->insert( $this->table(), $data );
		if ( $inserted === false ) {
			$error = strtolower( (string) ( $wpdb->last_error ?? '' ) );
			$errno = (int) ( $wpdb->last_errno ?? 0 );
			if ( $errno === 1062 || str_contains( $error, 'duplicate entry' ) || str_contains( $error, 'duplicate key' ) || str_contains( $error, 'unique constraint' ) ) {
				return new \WP_Error( 'reservation_conflict', 'The path or object key is already reserved.' );
			}
			return new \WP_Error( 'reservation_write_failed', 'The path or object-key reservation could not be written.' );
		}

		return $this->find_by_id( (int) $wpdb->insert_id ) ?? new \WP_Error( 'reservation_read_failed', 'Reservation was created but could not be read back.' );
	}

	/** @return array<string, mixed>|null */
	public function find_by_id( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed plugin-owned table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * True while a relative upload path is pinned by an unfinished operation.
	 *
	 * A worker lease is deliberately not part of this test: its expiry may fence
	 * a worker, but it must never make a Direct Upload destination reusable.
	 */
	public function has_pinned_relative_path( int $blog_id, string $relative_path ): bool {
		if ( $blog_id <= 0 || $relative_path === '' ) {
			return false;
		}
		global $wpdb;
		$hash = hash( 'sha256', $relative_path );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed plugin-owned registry table.
		$row = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$this->table()} WHERE blog_id = %d AND relative_path_hash = %s AND BINARY relative_path = BINARY %s AND state IN (%s, %s) LIMIT 1", $blog_id, $hash, $relative_path, ReservationState::PINNED, ReservationState::OWNED ) );
		return (bool) $row;
	}

	/** @return array<string, mixed>|\WP_Error */
	public function claim( int $id, string $owner_uuid, string $worker_token, int $lease_seconds = 300 ) {
		if ( $id <= 0 || $owner_uuid === '' || $worker_token === '' || $lease_seconds < 1 ) {
			return new \WP_Error( 'invalid_claim', 'A reservation claim requires an id, owner, worker token, and positive lease.' );
		}
		global $wpdb;
		$now     = gmdate( 'Y-m-d H:i:s' );
		$expires = gmdate( 'Y-m-d H:i:s', time() + $lease_seconds );
		// A previous worker may be replaced after its lease, but a PINNED/OWNED reservation itself is never released by that expiry.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed plugin-owned table.
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET worker_token = %s, fencing_epoch = fencing_epoch + 1, lease_expires_at = %s, updated_at = %s WHERE id = %d AND owner_uuid = %s AND state IN (%s, %s) AND (worker_token IS NULL OR worker_token = %s OR lease_expires_at < %s)", $worker_token, $expires, $now, $id, $owner_uuid, ReservationState::HELD, ReservationState::PINNED, $worker_token, $now ) );
		if ( $result !== 1 ) {
			return new \WP_Error( 'reservation_claim_lost', 'Reservation claim was not acquired.' );
		}
		return $this->find_by_id( $id ) ?? new \WP_Error( 'reservation_read_failed', 'Claimed reservation could not be read back.' );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function handoff_to_owned( int $id, string $owner_uuid, string $worker_token, int $fencing_epoch ) {
		return $this->transition_with_claim( $id, $owner_uuid, $worker_token, $fencing_epoch, ReservationState::OWNED, null );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function retire_settled_attempt( int $id, string $owner_uuid, string $worker_token, int $fencing_epoch, string $settlement_proof ) {
		if ( $settlement_proof === '' ) {
			return new \WP_Error( 'settlement_required', 'A durable settlement proof is required before a reservation may be retired.' );
		}
		return $this->transition_with_claim( $id, $owner_uuid, $worker_token, $fencing_epoch, ReservationState::HELD, $settlement_proof );
	}

	/**
	 * Releases the two final Direct Upload locators only after their attachment's
	 * remote object and inventory row have been deleted. The persisted binding
	 * supplies every locator, so a stale, foreign, or partial binding cannot
	 * release an unrelated reservation.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function release_owned_attachment_pair( int $blog_id, string $owner_uuid, int $path_reservation_id, int $key_reservation_id, string $relative_path, int $profile_id, string $object_key, string $settlement_proof ) {
		return $this->release_attachment_pair_in_state( $blog_id, $owner_uuid, $path_reservation_id, $key_reservation_id, $relative_path, $profile_id, $object_key, $settlement_proof, ReservationState::OWNED );
	}

	/**
	 * Releases an exact pair that was durably PINNED before READY only after the
	 * caller has recorded exact attachment-deletion proof. This deliberately
	 * does not broaden the normal OWNED attachment-release transition: callers
	 * must opt into this pre-READY recovery mode after verified remote deletion.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function release_pre_ready_pinned_attachment_pair( int $blog_id, string $owner_uuid, int $path_reservation_id, int $key_reservation_id, string $relative_path, int $profile_id, string $object_key, string $settlement_proof ) {
		return $this->release_attachment_pair_in_state( $blog_id, $owner_uuid, $path_reservation_id, $key_reservation_id, $relative_path, $profile_id, $object_key, $settlement_proof, ReservationState::PINNED );
	}

	/**
	 * Proves whether the exact final attachment pair remains PINNED without
	 * changing it. Callers use this narrow result before durably recording a
	 * pre-READY retirement obligation; ordinary release failures are not proof
	 * of a pre-READY state.
	 *
	 * @return array{status:'pinned'|'not_pinned'}|\WP_Error
	 */
	public function inspect_pre_ready_pinned_attachment_pair( int $blog_id, string $owner_uuid, int $path_reservation_id, int $key_reservation_id, string $relative_path, int $profile_id, string $object_key, string $settlement_proof ) {
		if ( $blog_id <= 0 || $owner_uuid === '' || $path_reservation_id <= 0 || $key_reservation_id <= 0 || $path_reservation_id === $key_reservation_id || $relative_path === '' || $profile_id <= 0 || $object_key === '' || $settlement_proof === '' ) {
			return new \WP_Error( 'invalid_reservation_attachment_inspection', 'An attachment reservation inspection requires a complete, distinct path and object-key binding.' );
		}
		global $wpdb;
		$path_hash = hash( 'sha256', $relative_path );
		$key_hash  = hash( 'sha256', $object_key );
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE owner_uuid = %s AND state = %s AND ((id = %d AND blog_id = %d AND relative_path_hash = %s AND BINARY relative_path = BINARY %s) OR (id = %d AND blog_id = %d AND profile_id = %d AND object_key_hash = %s AND BINARY object_key = BINARY %s))", $owner_uuid, ReservationState::PINNED, $path_reservation_id, $blog_id, $path_hash, $relative_path, $key_reservation_id, $blog_id, $profile_id, $key_hash, $object_key ) );
		if ( $count === null && (string) ( $wpdb->last_error ?? '' ) !== '' ) {
			return new \WP_Error( 'reservation_attachment_inspection_failed', 'Attachment reservation state could not be inspected.' );
		}
		return array( 'status' => (int) $count === 2 ? 'pinned' : 'not_pinned' );
	}

	/** @return array<string, mixed>|\WP_Error */
	private function release_attachment_pair_in_state( int $blog_id, string $owner_uuid, int $path_reservation_id, int $key_reservation_id, string $relative_path, int $profile_id, string $object_key, string $settlement_proof, string $required_state ) {
		if ( $blog_id <= 0 || $owner_uuid === '' || $path_reservation_id <= 0 || $key_reservation_id <= 0 || $path_reservation_id === $key_reservation_id || $relative_path === '' || $profile_id <= 0 || $object_key === '' || $settlement_proof === '' ) {
			return new \WP_Error( 'invalid_reservation_attachment_release', 'An attachment reservation release requires a complete, distinct path and object-key binding.' );
		}
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new \WP_Error( 'reservation_attachment_release_failed', 'Attachment reservation release could not start its transaction.' );
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$path_hash = hash( 'sha256', $relative_path );
		$key_hash = hash( 'sha256', $object_key );
		// Both complete locators and the session owner must match in one atomic
		// update. Anything less rolls back and leaves both OWNED reservations
		// pinned for manual/retry recovery rather than risking a foreign release.
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET state = %s, settled_at = %s, settlement_proof = %s, worker_token = NULL, lease_expires_at = NULL, relative_path = NULL, relative_path_hash = NULL, profile_id = NULL, object_key = NULL, object_key_hash = NULL, updated_at = %s WHERE owner_uuid = %s AND state = %s AND ((id = %d AND blog_id = %d AND relative_path_hash = %s AND BINARY relative_path = BINARY %s) OR (id = %d AND blog_id = %d AND profile_id = %d AND object_key_hash = %s AND BINARY object_key = BINARY %s))", ReservationState::HELD, $now, $settlement_proof, $now, $owner_uuid, $required_state, $path_reservation_id, $blog_id, $path_hash, $relative_path, $key_reservation_id, $blog_id, $profile_id, $key_hash, $object_key ) );
		if ( $result !== 2 ) {
			$wpdb->query( 'ROLLBACK' );
			// A cleanup-row completion failure can retry after this exact pair was
			// already settled. Treat only the same two exact locators, owner, and
			// proof as success; this never converts a foreign reservation release.
			$settled = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE owner_uuid = %s AND state = %s AND settlement_proof = %s AND ((id = %d AND blog_id = %d) OR (id = %d AND blog_id = %d))", $owner_uuid, ReservationState::HELD, $settlement_proof, $path_reservation_id, $blog_id, $key_reservation_id, $blog_id ) );
			if ( (int) $settled === 2 ) {
				return array( 'path_reservation_id' => $path_reservation_id, 'key_reservation_id' => $key_reservation_id );
			}
			return new \WP_Error( 'reservation_attachment_release_lost', 'Attachment reservation ownership could not be verified for release.' );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'reservation_attachment_release_failed', 'Attachment reservation release could not be committed.' );
		}
		return array( 'path_reservation_id' => $path_reservation_id, 'key_reservation_id' => $key_reservation_id );
	}

	/** @return array<string, mixed>|\WP_Error */
	private function transition_with_claim( int $id, string $owner, string $worker, int $epoch, string $state, ?string $proof ) {
		if ( $id <= 0 || $owner === '' || $worker === '' || $epoch <= 0 ) {
			return new \WP_Error( 'invalid_reservation_transition', 'Reservation transition claim is invalid.' );
		}
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$release_object_locator = ReservationState::HELD === $state && null !== $proof;
		$locator_reset          = $release_object_locator ? ', relative_path = NULL, relative_path_hash = NULL, profile_id = NULL, object_key = NULL, object_key_hash = NULL' : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed plugin-owned table.
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET state = %s, settled_at = %s, settlement_proof = %s, worker_token = NULL, lease_expires_at = NULL{$locator_reset}, updated_at = %s WHERE id = %d AND owner_uuid = %s AND worker_token = %s AND fencing_epoch = %d", $state, $proof === null ? null : $now, $proof, $now, $id, $owner, $worker, $epoch ) );
		if ( $result !== 1 ) {
			return new \WP_Error( 'reservation_transition_lost', 'Reservation transition claim was lost.' );
		}
		return $this->find_by_id( $id ) ?? new \WP_Error( 'reservation_read_failed', 'Transitioned reservation could not be read back.' );
	}
}
