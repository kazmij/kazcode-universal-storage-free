<?php
/**
 * Persistence for durable competitor import runs.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Domain\ImportRunStatus;

/**
 * wpdb repository for the plugin-owned import run table.
 */
final class ImportRunRepository {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 's3ms_import_runs';
	}

	/** @param array<string, mixed> $data */
	public function create( array $data ): int {
		$this->require_fields( $data, array( 'uuid', 'source_type', 'profile_id', 'mode', 'status', 'source_fingerprint' ) );
		global $wpdb;
		$data['updated_at'] = $data['updated_at'] ?? gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned table; $wpdb->insert() parameterizes values.
		$wpdb->insert( $this->table(), $data );
		return (int) $wpdb->insert_id;
	}

	/** @param array<string, mixed> $data */
	public function create_if_no_active( array $data ): int {
		$this->require_fields( $data, array( 'uuid', 'source_type', 'profile_id', 'mode', 'status', 'source_fingerprint' ) );
		global $wpdb;

		$lock_name = 'kazus_import_run_start_' . md5( $this->table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- short DB advisory lock serializes import start; correctness is database-authoritative, not cache-backed.
		$locked = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
		if ( $locked !== 1 ) {
			return 0;
		}

		try {
			if ( $this->find_active() !== null ) {
				return 0;
			}
			return $this->create( $data );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- releases the short advisory lock acquired above.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/** @return array<string, mixed>|null */
	public function find_by_uuid( string $uuid ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table; table names cannot be bound.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE uuid = %s", $uuid ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	public function find_by_id( int $id ): ?array {
		if ( $id <= 0 ) return null;
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table with prepared id.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** @return array<string, mixed>|null */
	public function find_latest_successful_dry_run( string $source_type, int $profile_id, string $source_fingerprint ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table; table names cannot be bound.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE source_type = %s AND profile_id = %d AND source_fingerprint = %s AND mode = %s AND status = %s ORDER BY id DESC LIMIT 1", $source_type, $profile_id, $source_fingerprint, 'dry_run', ImportRunStatus::COMPLETED ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** @return array<string, mixed>|null */
	public function find_active(): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table; table names cannot be bound.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE status IN (%s, %s) ORDER BY id DESC LIMIT 1", ImportRunStatus::RUNNING, ImportRunStatus::PAUSED ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** @param array<string, mixed> $data */
	public function update( int $id, array $data ): void {
		if ( $id <= 0 ) {
			throw new \InvalidArgumentException( 'Import run id must be positive.' );
		}
		global $wpdb;
		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned table; $wpdb->update() parameterizes values.
		$wpdb->update( $this->table(), $data, array( 'id' => $id ) );
	}

	/** @param array<string, mixed> $data @param list<string> $fields */
	private function require_fields( array $data, array $fields ): void {
		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field, $data ) || $data[ $field ] === '' || $data[ $field ] === null ) {
				throw new \InvalidArgumentException( esc_html( $field ) . ' is required.' );
			}
		}
	}
}
