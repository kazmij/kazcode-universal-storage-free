<?php
/**
 * Persistence for per-object remote/local state.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Domain\ObjectRemoteStatus;

/**
 * wpdb repository for {$wpdb->prefix}s3ms_objects.
 */
final class ObjectRepository {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 's3ms_objects';
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function find_by_attachment( int $attachment_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE attachment_id = %d ORDER BY id ASC", $attachment_id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Upsert by UNIQUE(storage_profile_id, object_key).
	 *
	 * @param array<string, mixed> $data Row fields.
	 */
	public function upsert( array $data ): int {
		global $wpdb;
		$profile_id = (int) ( $data['storage_profile_id'] ?? 0 );
		$key        = (string) ( $data['object_key'] ?? '' );
		if ( $profile_id <= 0 || $key === '' ) {
			throw new \InvalidArgumentException( 'storage_profile_id and object_key are required.' );
		}

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table()} WHERE storage_profile_id = %d AND object_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$profile_id,
				$key
			)
		);

		$now = gmdate( 'Y-m-d H:i:s' );
		$data['updated_at'] = $now;
		if ( empty( $data['created_at'] ) ) {
			$data['created_at'] = $now;
		}

		if ( $existing_id ) {
			$id = (int) $existing_id;
			unset( $data['created_at'] );
			$wpdb->update( $this->table(), $data, array( 'id' => $id ) );
			return $id;
		}

		$wpdb->insert( $this->table(), $data );
		return (int) $wpdb->insert_id;
	}

	public function count_for_attachment( int $attachment_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE attachment_id = %d", $attachment_id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/**
	 * @return array<string, int> remote_status => count
	 */
	public function aggregate_remote_status(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT remote_status, COUNT(*) AS cnt FROM {$this->table()} GROUP BY remote_status", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$out[ (string) ( $row['remote_status'] ?? '' ) ] = (int) ( $row['cnt'] ?? 0 );
			}
		}
		return $out;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_by_id( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function find_by_attachment_and_statuses( int $attachment_id, array $statuses ): array {
		global $wpdb;
		if ( $statuses === array() ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$sql          = "SELECT * FROM {$this->table()} WHERE attachment_id = %d AND remote_status IN ({$placeholders}) ORDER BY id ASC"; // phpcs:ignore WordPress.DB.PreparedSQL
		$params       = array_merge( array( $attachment_id ), array_values( $statuses ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Known inventory keys for orphan scan (one profile).
	 *
	 * @return list<string>
	 */
	public function list_known_keys( int $storage_profile_id ): array {
		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT object_key FROM {$this->table()} WHERE storage_profile_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$storage_profile_id
			)
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		return array_values(
			array_unique(
				array_map( 'strval', $rows )
			)
		);
	}

	/**
	 * Paginated object rows for DB-first health scan.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function scan_page( int $limit = 500, int $after_id = 0 ): array {
		global $wpdb;
		$limit = max( 1, min( 2000, $limit ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE id > %d ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$after_id,
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	public function total_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()}" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function find_by_attachment_and_profile( int $attachment_id, int $storage_profile_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE attachment_id = %d AND storage_profile_id = %d ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL
				$attachment_id,
				$storage_profile_id
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function find_migratable_page( int $source_profile_id, int $limit = 100, int $after_id = 0 ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE storage_profile_id = %d AND id > %d AND remote_status = %s ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$source_profile_id,
				$after_id,
				ObjectRemoteStatus::PRESENT,
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Mark object rows stale when no longer in manifest (no remote delete).
	 *
	 * @param list<string> $relative_paths Local relative paths removed from manifest.
	 */
	public function mark_stale_by_relative_paths( int $attachment_id, array $relative_paths ): int {
		global $wpdb;
		$relative_paths = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $relative_paths )
				)
			)
		);
		if ( $relative_paths === array() ) {
			return 0;
		}

		$now    = gmdate( 'Y-m-d H:i:s' );
		$marked = 0;
		foreach ( $relative_paths as $relative ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->table()} SET remote_status = %s, updated_at = %s WHERE attachment_id = %d AND local_relative_path = %s AND remote_status NOT IN (%s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL
					ObjectRemoteStatus::STALE,
					$now,
					$attachment_id,
					$relative,
					ObjectRemoteStatus::STALE,
					ObjectRemoteStatus::DELETED
				)
			);
			if ( is_int( $updated ) && $updated > 0 ) {
				$marked += $updated;
			}
		}
		return $marked;
	}

	/**
	 * Count inventory rows for one storage profile.
	 */
	public function count_by_profile( int $storage_profile_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table()} WHERE storage_profile_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$storage_profile_id
			)
		);
	}

	/**
	 * Remove inventory rows after a full local restore (attachment serves locally again).
	 */
	public function delete_by_attachment( int $attachment_id ): int {
		global $wpdb;
		$deleted = $wpdb->delete( $this->table(), array( 'attachment_id' => $attachment_id ), array( '%d' ) );
		return is_int( $deleted ) ? $deleted : 0;
	}
}
