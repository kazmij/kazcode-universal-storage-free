<?php
/**
 * Persistence for resumable multipart transfer state.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Domain\TransferSessionStatus;

/**
 * wpdb repository for the plugin-owned transfer session table.
 */
final class TransferSessionRepository {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 's3ms_transfer_sessions';
	}

	/** @param array<string, mixed> $data */
	public function create( array $data ): int {
		$this->require_fields( $data, array( 'uuid', 'kind', 'destination_profile_id', 'source_ref', 'destination_key', 'size_bytes', 'source_fingerprint', 'part_size_bytes', 'status' ) );
		$this->validate_source_ref( (string) $data['source_ref'] );
		global $wpdb;
		$now                     = gmdate( 'Y-m-d H:i:s' );
		$data['completed_parts'] = $this->encode_completed_parts( $data['completed_parts'] ?? array() );
		$data['updated_at']      = $data['updated_at'] ?? $now;
		$data['attempt_count']   = $data['attempt_count'] ?? 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned table; $wpdb->insert() parameterizes values.
		$wpdb->insert( $this->table(), $data );
		return (int) $wpdb->insert_id;
	}

	/** @return array<string, mixed>|null */
	public function find_by_uuid( string $uuid ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table; table names cannot be bound.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE uuid = %s", $uuid ), ARRAY_A );
		return is_array( $row ) ? $this->decode_row( $row ) : null;
	}

	/** @return array<string, mixed>|null */
	public function find_resumable( string $kind, int $destination_profile_id, string $destination_key, string $source_fingerprint ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table; table names cannot be bound.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE kind = %s AND destination_profile_id = %d AND destination_key = %s AND source_fingerprint = %s AND status IN (%s, %s, %s) ORDER BY id DESC LIMIT 1", $kind, $destination_profile_id, $destination_key, $source_fingerprint, TransferSessionStatus::PENDING, TransferSessionStatus::UPLOADING, TransferSessionStatus::COMPLETING ), ARRAY_A );
		return is_array( $row ) ? $this->decode_row( $row ) : null;
	}

	/** @return array<string, mixed>|null */
	public function find_resumable_by_source( string $kind, int $destination_profile_id, string $destination_key, string $source_ref ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table; table names cannot be bound.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE kind = %s AND destination_profile_id = %d AND destination_key = %s AND source_ref = %s AND status IN (%s, %s, %s) ORDER BY id DESC LIMIT 1", $kind, $destination_profile_id, $destination_key, $source_ref, TransferSessionStatus::PENDING, TransferSessionStatus::UPLOADING, TransferSessionStatus::COMPLETING ), ARRAY_A );
		return is_array( $row ) ? $this->decode_row( $row ) : null;
	}

	/** @param array<string, mixed> $data */
	public function update( int $id, array $data ): void {
		if ( $id <= 0 ) {
			throw new \InvalidArgumentException( 'Transfer session id must be positive.' );
		}
		global $wpdb;
		if ( array_key_exists( 'completed_parts', $data ) ) {
			$data['completed_parts'] = $this->encode_completed_parts( $data['completed_parts'] );
		}
		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned table; $wpdb->update() parameterizes values.
		$wpdb->update( $this->table(), $data, array( 'id' => $id ) );
	}

	/** @return list<array<string, mixed>> */
	public function list_stale_incomplete( int $before_timestamp, int $after_id ): array {
		global $wpdb;
		$before = gmdate( 'Y-m-d H:i:s', $before_timestamp );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table; table names cannot be bound.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id > %d AND updated_at < %s AND status NOT IN (%s, %s) ORDER BY id ASC", $after_id, $before, TransferSessionStatus::COMPLETED, TransferSessionStatus::ABORTED ), ARRAY_A );
		return is_array( $rows ) ? array_map( array( $this, 'decode_row' ), $rows ) : array();
	}

	/** @param array<string, mixed> $data @param list<string> $fields */
	private function require_fields( array $data, array $fields ): void {
		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field, $data ) || $data[ $field ] === '' || $data[ $field ] === null ) {
				throw new \InvalidArgumentException( esc_html( $field ) . ' is required.' );
			}
		}
	}

	private function validate_source_ref( string $source_ref ): void {
		if ( str_contains( $source_ref, "\0" ) || preg_match( '/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $source_ref ) ) {
			throw new \InvalidArgumentException( 'source_ref must be a relative path or remote object key.' );
		}
	}

	/** @param mixed $parts */
	private function encode_completed_parts( $parts ): string {
		if ( ! is_array( $parts ) ) {
			throw new \InvalidArgumentException( 'completed_parts must be an array.' );
		}
		return (string) json_encode( $parts, JSON_THROW_ON_ERROR );
	}

	/** @param array<string, mixed> $row @return array<string, mixed> */
	private function decode_row( array $row ): array {
		$decoded                = json_decode( (string) ( $row['completed_parts'] ?? '' ), true );
		$row['completed_parts'] = is_array( $decoded ) ? $decoded : array();
		return $row;
	}
}
