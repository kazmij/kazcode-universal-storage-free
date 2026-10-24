<?php
/** Durable, short-lived migration plan shared with Direct Upload deletion cleanup. */
declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class DirectUploadMigrationFenceRepository {
	private function table(): string { global $wpdb; return $wpdb->prefix . 's3ms_direct_upload_migration_fences'; }

	/** @param array<string,mixed> $plan */
	public function authorize( array $plan ): bool {
		if ( ! $this->valid( $plan ) ) return false;
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) return true;
		if ( $wpdb->query( 'START TRANSACTION' ) === false ) return false;
		try {
			$attachment_id = (int) $plan['attachment_id'];
			$fence_read = $this->locking_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE attachment_id = %d FOR UPDATE", $attachment_id ) );
			if ( ! $fence_read['ok'] ) throw new \RuntimeException( 'Migration authorization lock could not be read.' );
			$current = $fence_read['row'];
			if ( is_array( $current ) && ! $this->same_plan( $current, $plan ) ) throw new \RuntimeException( 'A different migration plan is still durable.' );
			// Both migration authorization and cleanup recording take the per-attachment
			// fence first, then inspect/lock cleanup rows. Keeping that order prevents a
			// cleanup insert from deadlocking against an authorization that has already
			// locked the cleanup-table range.
			$cleanup_read = $this->locking_results( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}s3ms_reservation_cleanup WHERE state IN ('intent', 'remote_deleted', 'ready') AND JSON_UNQUOTE(JSON_EXTRACT(binding_json, '$.attachment_id')) = %d LIMIT 1 FOR UPDATE", $attachment_id ) );
			if ( ! $cleanup_read['ok'] ) throw new \RuntimeException( 'Cleanup authorization lock could not be read.' );
			if ( $cleanup_read['rows'] !== array() ) throw new \RuntimeException( 'Cleanup already owns this attachment.' );
			$now = gmdate( 'Y-m-d H:i:s' );
			if ( ! is_array( $current ) && false === $wpdb->insert( $this->table(), array_merge( $plan, array( 'created_at' => $now, 'updated_at' => $now ) ) ) ) throw new \RuntimeException( 'Unable to persist migration authorization.' );
			if ( $wpdb->query( 'COMMIT' ) === false ) throw new \RuntimeException( 'Unable to commit migration authorization.' );
			return true;
		} catch ( \Throwable $error ) { $wpdb->query( 'ROLLBACK' ); return false; }
	}

	/**
	 * Must run inside the cleanup record transaction. It locks the same row that
	 * migration authorization locks, so neither side can observe a stale plan.
	 *
	 * @param array<string,mixed> $binding @return array<string,mixed>|null
	 */
	public function bind_cleanup_in_transaction( array $binding ): ?array {
		$attachment_id = (int) ( $binding['attachment_id'] ?? 0 );
		if ( $attachment_id < 1 ) return $binding;
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_row' ) ) return $binding;
		try {
			$wpdb->last_error = '';
			$fence = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE attachment_id = %d FOR UPDATE", $attachment_id ), ARRAY_A );
			if ( $wpdb->last_error !== '' ) return null;
			if ( is_array( $fence ) ) {
				$binding['migration_cleanup_location'] = array( 'profile_id' => (int) $fence['destination_profile_id'], 'object_key' => (string) $fence['destination_object_key'], 'profile_fingerprint' => (string) $fence['destination_fingerprint'] );
				$upload_id = (string) ( $fence['multipart_upload_id'] ?? '' );
				if ( $upload_id === '' ) {
					$session = $wpdb->get_row( $wpdb->prepare( "SELECT multipart_upload_id FROM {$wpdb->prefix}s3ms_transfer_sessions WHERE kind = 'remote_copy' AND attachment_id = %d AND destination_profile_id = %d AND destination_key = %s AND multipart_upload_id <> '' AND status IN ('pending', 'uploading', 'completing') ORDER BY id DESC LIMIT 1 FOR UPDATE", $attachment_id, (int) $fence['destination_profile_id'], (string) $fence['destination_object_key'] ), ARRAY_A );
					if ( $wpdb->last_error !== '' ) return null;
					$upload_id = is_array( $session ) ? (string) ( $session['multipart_upload_id'] ?? '' ) : '';
				}
				if ( $upload_id !== '' ) $binding['migration_multipart_upload_id'] = $upload_id;
			}
			return $binding;
		} catch ( \Throwable $error ) { return null; }
	}

	public function complete( int $attachment_id ): bool {
		if ( $attachment_id < 1 ) return false;
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'delete' ) ) return true;
		return $wpdb->delete( $this->table(), array( 'attachment_id' => $attachment_id ), array( '%d' ) ) !== false;
	}

	/** Persist the provider identity immediately after a migration creates it. */
	public function record_multipart( int $attachment_id, int $profile_id, string $object_key, string $fingerprint, string $upload_id ): bool {
		if ( $attachment_id < 1 || $profile_id < 1 || $object_key === '' || strlen( $fingerprint ) !== 64 || $upload_id === '' ) return false;
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) return false;
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET multipart_upload_id = %s, updated_at = %s WHERE attachment_id = %d AND destination_profile_id = %d AND destination_object_key = %s AND destination_fingerprint = %s AND (multipart_upload_id = '' OR multipart_upload_id = %s)", $upload_id, gmdate( 'Y-m-d H:i:s' ), $attachment_id, $profile_id, $object_key, $fingerprint, $upload_id ) );
		if ( $result === false ) return false;
		if ( $result === 1 ) return true;
		if ( ! method_exists( $wpdb, 'get_var' ) ) return false;
		$previous_error = (string) $wpdb->last_error;
		$wpdb->last_error = '';
		$recorded = $wpdb->get_var( $wpdb->prepare( "SELECT multipart_upload_id FROM {$this->table()} WHERE attachment_id = %d AND destination_profile_id = %d AND destination_object_key = %s AND destination_fingerprint = %s", $attachment_id, $profile_id, $object_key, $fingerprint ) );
		$failed = $wpdb->last_error !== '';
		$wpdb->last_error = $previous_error;
		return ! $failed && is_string( $recorded ) && hash_equals( $upload_id, $recorded );
	}

	/** Add the latest durable multipart identity to an already-absorbed cleanup binding. */
	public function enrich_cleanup_binding( array $binding ): ?array {
		$location = $binding['migration_cleanup_location'] ?? null;
		if ( ! is_array( $location ) ) return $binding;
		$attachment_id = (int) ( $binding['attachment_id'] ?? 0 );
		if ( $attachment_id < 1 ) return null;
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_row' ) ) return $binding;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT destination_profile_id, destination_object_key, multipart_upload_id FROM {$this->table()} WHERE attachment_id = %d FOR UPDATE", $attachment_id ), ARRAY_A );
		if ( $wpdb->last_error !== '' ) return null;
		if ( is_array( $row ) && ( (int) $row['destination_profile_id'] !== (int) $location['profile_id'] || ! hash_equals( (string) $row['destination_object_key'], (string) $location['object_key'] ) ) ) return null;
		if ( is_array( $row ) && (string) ( $row['multipart_upload_id'] ?? '' ) !== '' ) {
			$binding['migration_multipart_upload_id'] = (string) $row['multipart_upload_id'];
		} elseif ( is_array( $row ) ) {
			// The generic resumable session is written immediately after CreateMultipart
			// and before the fence update callback. It is a fail-closed recovery source
			// if that callback lost its database acknowledgement.
			$session = $wpdb->get_row( $wpdb->prepare( "SELECT multipart_upload_id FROM {$wpdb->prefix}s3ms_transfer_sessions WHERE kind = 'remote_copy' AND attachment_id = %d AND destination_profile_id = %d AND destination_key = %s AND multipart_upload_id <> '' AND status IN ('pending', 'uploading', 'completing') ORDER BY id DESC LIMIT 1 FOR UPDATE", $attachment_id, (int) $row['destination_profile_id'], (string) $row['destination_object_key'] ), ARRAY_A );
			if ( $wpdb->last_error !== '' ) return null;
			if ( is_array( $session ) && (string) ( $session['multipart_upload_id'] ?? '' ) !== '' ) $binding['migration_multipart_upload_id'] = (string) $session['multipart_upload_id'];
		}
		return $binding;
	}

	/**
	 * Return only the durable plans for this exact migration direction.
	 *
	 * A completed Direct Upload handoff marks its source inventory row STALE.
	 * These plans therefore provide the bounded reconciliation queue that keeps a
	 * failed fence deletion from being hidden by the normal PRESENT-row scan.
	 *
	 * @return list<array<string,mixed>>|null Null means the lookup failed and
	 *                                        callers must not assume no fence exists.
	 */
	public function pending_for_migration( int $source_profile_id, int $destination_profile_id, int $limit = 100 ): ?array {
		if ( $source_profile_id < 1 || $destination_profile_id < 1 ) return array();
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) return array();
		$limit = max( 1, min( 500, $limit ) );
		$previous_error = (string) $wpdb->last_error;
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE source_profile_id = %d AND destination_profile_id = %d ORDER BY attachment_id ASC LIMIT %d", $source_profile_id, $destination_profile_id, $limit ), ARRAY_A );
		$failed = $wpdb->last_error !== '';
		$wpdb->last_error = $previous_error;
		return ! $failed && is_array( $rows ) ? $rows : null;
	}

	/** Remove only the durable plan whose exact destination was absorbed by cleanup. */
	public function complete_cleanup( array $binding ): bool {
		$location = $binding['migration_cleanup_location'] ?? null;
		if ( ! is_array( $location ) ) return true;
		$attachment_id = (int) ( $binding['attachment_id'] ?? 0 );
		$profile_id = (int) ( $location['profile_id'] ?? 0 );
		$object_key = (string) ( $location['object_key'] ?? '' );
		$fingerprint = (string) ( $location['profile_fingerprint'] ?? '' );
		if ( $attachment_id < 1 || $profile_id < 1 || $object_key === '' || strlen( $fingerprint ) !== 64 ) return false;
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) return true;
		$upload_id = (string) ( $binding['migration_multipart_upload_id'] ?? '' );
		// A recovery binding may have obtained this exact upload ID from the locked
		// remote-copy session after record_multipart() lost its acknowledgement.
		// Only an empty fence identity may be reconciled this way; a non-empty
		// different ID remains an authoritative mismatch and must stay pinned.
		$result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE attachment_id = %d AND destination_profile_id = %d AND destination_object_key = %s AND destination_fingerprint = %s AND (multipart_upload_id = %s OR (multipart_upload_id = '' AND %s <> ''))", $attachment_id, $profile_id, $object_key, $fingerprint, $upload_id, $upload_id ) );
		if ( $result === 1 ) return true;
		if ( $result !== 0 ) return false;
		if ( ! method_exists( $wpdb, 'get_row' ) ) return false;
		// A completed cleanup can be retried after the exact fence delete committed
		// but before the durable cleanup row was removed. Zero affected rows prove
		// neither success nor a harmless race: only a clean read that confirms no
		// attachment fence remains can make this retry idempotently successful.
		$confirmation = $this->locking_row( $wpdb->prepare( "SELECT destination_profile_id, destination_object_key, destination_fingerprint, multipart_upload_id FROM {$this->table()} WHERE attachment_id = %d", $attachment_id ) );
		return $confirmation['ok'] && $confirmation['row'] === null;
	}

	/** @return array{ok:bool,rows:list<array<string,mixed>>} */
	private function locking_results( string $query ): array {
		global $wpdb;
		$previous_error = (string) $wpdb->last_error;
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $query, ARRAY_A );
		$failed = $wpdb->last_error !== '';
		$wpdb->last_error = $previous_error;
		return array( 'ok' => ! $failed && is_array( $rows ), 'rows' => is_array( $rows ) ? $rows : array() );
	}

	/** @return array{ok:bool,row:array<string,mixed>|null} */
	private function locking_row( string $query ): array {
		global $wpdb;
		$previous_error = (string) $wpdb->last_error;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $query, ARRAY_A );
		$failed = $wpdb->last_error !== '';
		$wpdb->last_error = $previous_error;
		return array( 'ok' => ! $failed, 'row' => is_array( $row ) ? $row : null );
	}

	/** @param array<string,mixed> $plan */
	private function valid( array $plan ): bool {
		return (int) ( $plan['attachment_id'] ?? 0 ) > 0 && (int) ( $plan['source_profile_id'] ?? 0 ) > 0 && (string) ( $plan['source_object_key'] ?? '' ) !== '' && strlen( (string) ( $plan['source_fingerprint'] ?? '' ) ) === 64 && (int) ( $plan['destination_profile_id'] ?? 0 ) > 0 && (string) ( $plan['destination_object_key'] ?? '' ) !== '' && strlen( (string) ( $plan['destination_fingerprint'] ?? '' ) ) === 64;
	}
	/** @param array<string,mixed> $current @param array<string,mixed> $plan */
	private function same_plan( array $current, array $plan ): bool {
		foreach ( array( 'attachment_id', 'source_profile_id', 'source_object_key', 'source_fingerprint', 'destination_profile_id', 'destination_object_key', 'destination_fingerprint' ) as $field ) if ( (string) $current[ $field ] !== (string) $plan[ $field ] ) return false;
		return true;
	}
}
