<?php
/** Durable retry records for final Direct Upload reservation releases. */
declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class ReservationCleanupRepository {
	private function table(): string { global $wpdb; return $wpdb->prefix . 's3ms_reservation_cleanup'; }

	/** @return array<string,mixed>|\WP_Error */
	public function record( array $binding, string $settlement_proof ) {
		if ( ! $this->valid_binding( $binding ) || $settlement_proof === '' ) return new \WP_Error( 'reservation_cleanup_invalid', 'Reservation cleanup needs a complete exact binding.' );
		global $wpdb;
		if ( $wpdb->query( 'START TRANSACTION' ) === false ) return new \WP_Error( 'reservation_cleanup_write_failed', 'Reservation cleanup could not start its serialization transaction.' );
		try {
			$binding = ( new DirectUploadMigrationFenceRepository() )->bind_cleanup_in_transaction( $binding );
			if ( ! is_array( $binding ) ) throw new \RuntimeException( 'Migration fence could not be read.' );
			$payload = wp_json_encode( $binding );
			if ( ! is_string( $payload ) ) throw new \RuntimeException( 'Cleanup binding could not be encoded.' );
			$identity = hash( 'sha256', $payload . "\n" . $settlement_proof );
			$now = gmdate( 'Y-m-d H:i:s' );
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT INTO {$this->table()} (identity_hash, binding_json, settlement_proof, state, created_at, updated_at) VALUES (%s, %s, %s, 'intent', %s, %s) ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)", $identity, $payload, $settlement_proof, $now, $now ) );
			if ( $inserted === false ) throw new \RuntimeException( 'Cleanup record could not be inserted.' );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE identity_hash = %s", $identity ), ARRAY_A );
			if ( ! is_array( $row ) || $wpdb->query( 'COMMIT' ) === false ) throw new \RuntimeException( 'Cleanup record could not be read back.' );
			return $row;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'reservation_cleanup_write_failed', 'Reservation cleanup could not be persisted.' );
		}
	}

	/** @return list<array<string,mixed>> */
	public function pending( int $limit = 50 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE state IN ('intent', 'remote_deleted', 'ready') ORDER BY id ASC LIMIT %d", max( 1, $limit ) ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** True when an attachment's durable destructive cleanup is still active; null fails closed on a database error. */
	public function active_for_attachment( int $attachment_id ): ?bool {
		if ( $attachment_id < 1 ) return false;
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_results' ) ) return false;
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$this->table()} WHERE state IN ('intent', 'remote_deleted', 'ready') AND JSON_UNQUOTE(JSON_EXTRACT(binding_json, '$.attachment_id')) = %d LIMIT 1", $attachment_id ), ARRAY_A );
		if ( $wpdb->last_error !== '' ) return null;
		return is_array( $rows ) && $rows !== array();
	}

	public function ready( int $id ): bool {
		if ( $id < 1 ) return false;
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET state = 'ready', updated_at = %s WHERE id = %d AND state = 'remote_deleted'", gmdate( 'Y-m-d H:i:s' ), $id ) );
		if ( $result === false ) return false;
		$state = $wpdb->get_var( $wpdb->prepare( "SELECT state FROM {$this->table()} WHERE id = %d", $id ) );
		return $state === 'ready';
	}

	/** Replace a cleanup binding only while its immutable settlement proof remains active. */
	public function replace_binding( int $id, array $binding, string $settlement_proof ): bool {
		$payload = wp_json_encode( $binding );
		if ( $id < 1 || ! is_string( $payload ) || $settlement_proof === '' ) return false;
		global $wpdb;
		return $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET binding_json = %s, updated_at = %s WHERE id = %d AND settlement_proof = %s AND state IN ('intent', 'remote_deleted', 'ready')", $payload, gmdate( 'Y-m-d H:i:s' ), $id, $settlement_proof ) ) !== false;
	}

	/** Record that destructive remote deletion completed, before inventory removal. */
	public function remote_deleted( int $id ): bool {
		if ( $id < 1 ) return false;
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET state = 'remote_deleted', updated_at = %s WHERE id = %d AND state = 'intent'", gmdate( 'Y-m-d H:i:s' ), $id ) );
		if ( $result === false ) return false;
		$state = $wpdb->get_var( $wpdb->prepare( "SELECT state FROM {$this->table()} WHERE id = %d", $id ) );
		return $state === 'remote_deleted' || $state === 'ready';
	}

	public function complete( int $id ): bool {
		global $wpdb;
		return 1 === $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Atomically settle a Direct Upload cleanup record and retire its exact
	 * finalization proof. The intent is retained unless both deletes commit.
	 *
	 * @param array<string,mixed> $binding
	 */
	public function complete_with_finalization_intent( int $id, array $binding, string $settlement_proof ): bool {
		$marker = (string) ( $binding['direct_upload_finalization_marker'] ?? '' );
		$intent_binding = (string) ( $binding['direct_upload_finalization_intent_binding'] ?? '' );
		$intent_location = (string) ( $binding['direct_upload_finalization_intent_location'] ?? '' );
		if ( $marker === '' || $intent_binding === '' || $intent_location === '' || ! hash_equals( 'attachment_deleted:' . (int) ( $binding['attachment_id'] ?? 0 ), $settlement_proof ) ) return false;
		global $wpdb;
		if ( $wpdb->query( 'START TRANSACTION' ) === false ) return false;
		try {
			$intents = $wpdb->prefix . 's3ms_du_finalization_intents';
			$removed_intent = $wpdb->query( $wpdb->prepare( "DELETE FROM {$intents} WHERE recovery_marker = %s", $marker ) );
			if ( $removed_intent !== 1 ) throw new \RuntimeException( 'Finalization intent was not exactly removable.' );
			$removed_cleanup = $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
			if ( $removed_cleanup !== 1 || $wpdb->query( 'COMMIT' ) === false ) throw new \RuntimeException( 'Cleanup record was not removable.' );
			return true;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
	}

	/** Persist that an exact PINNED settlement still requires Pro session retirement. */
	public function require_pre_ready_retirement( int $id, array $binding, string $settlement_proof ): bool {
		if ( $id < 1 || ! $this->valid_pre_ready_retirement_binding( $binding ) || ! hash_equals( 'attachment_deleted:' . (int) $binding['attachment_id'], $settlement_proof ) ) return false;
		$required = $binding;
		$required['requires_pre_ready_session_retirement'] = true;
		$original = wp_json_encode( $binding );
		$payload = wp_json_encode( $required );
		if ( ! is_string( $original ) || ! is_string( $payload ) ) return false;
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET binding_json = %s, updated_at = %s WHERE id = %d AND state = 'ready' AND settlement_proof = %s AND binding_json = %s", $payload, gmdate( 'Y-m-d H:i:s' ), $id, $settlement_proof, $original ) );
		if ( $result === false ) return false;
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT binding_json FROM {$this->table()} WHERE id = %d AND state = 'ready' AND settlement_proof = %s", $id, $settlement_proof ) );
		try { $stored = json_decode( (string) $current, true, 16, JSON_THROW_ON_ERROR ); } catch ( \Throwable $error ) { return false; }
		return is_array( $stored ) && hash_equals( $payload, (string) $current ) && ( $stored['requires_pre_ready_session_retirement'] ?? false ) === true;
	}

	/** @param array<string,mixed> $binding */
	private function valid_pre_ready_retirement_binding( array $binding ): bool {
		return $this->valid_binding( $binding )
			&& (string) ( $binding['direct_upload_session_uuid'] ?? '' ) !== ''
			&& hash_equals( (string) $binding['owner_uuid'], (string) $binding['direct_upload_session_uuid'] )
			&& (string) ( $binding['direct_upload_attempt_uuid'] ?? '' ) !== ''
			&& (int) ( $binding['direct_upload_attempt_generation'] ?? 0 ) > 0
			&& (string) ( $binding['direct_upload_profile_fingerprint'] ?? '' ) !== '';
	}

	private function valid_binding( array $binding ): bool {
		return (int) ( $binding['version'] ?? 0 ) === 1 && (int) ( $binding['attachment_id'] ?? 0 ) > 0 && (int) ( $binding['blog_id'] ?? 0 ) > 0 && (string) ( $binding['owner_uuid'] ?? '' ) !== '' && (int) ( $binding['path_reservation_id'] ?? 0 ) > 0 && (int) ( $binding['key_reservation_id'] ?? 0 ) > 0 && (int) $binding['path_reservation_id'] !== (int) $binding['key_reservation_id'] && (string) ( $binding['relative_path'] ?? '' ) !== '' && (int) ( $binding['profile_id'] ?? 0 ) > 0 && (string) ( $binding['object_key'] ?? '' ) !== '' && (string) ( $binding['profile_fingerprint'] ?? '' ) !== '';
	}
}
