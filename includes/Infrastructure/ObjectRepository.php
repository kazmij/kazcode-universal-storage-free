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
 * wpdb repository for {$wpdb->prefix}s3ms_objects — a table owned exclusively
 * by this plugin. Every query below is against that one table, never a
 * WordPress core table, so there is no wp_query()/get_posts()/meta API
 * equivalent to use instead, and no object-cache group is appropriate:
 * results are read immediately after writes that change them (offload,
 * verify, restore, migrate), so caching would risk serving stale
 * remote/local status right after it changes. Every dynamic value is passed
 * through $wpdb->prepare() with %d/%s placeholders; the only "unprepared"
 * interpolation the sniffs see is {$this->table()} (a fixed, non-user
 * -controlled table name build from $wpdb->prefix — table names can't be
 * bound via prepare() placeholders at all). Each query below is kept on one
 * line specifically so its phpcs:ignore annotation survives this plugin's
 * PHP-Scoper release build unambiguously (see BUILD.md).
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table, see class docblock.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE attachment_id = %d ORDER BY id ASC", $attachment_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Upsert by UNIQUE(storage_profile_id, object_key_hash).
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

		$now = gmdate( 'Y-m-d H:i:s' );
		$data['updated_at'] = $now;
		if ( empty( $data['created_at'] ) ) {
			$data['created_at'] = $now;
		}
		$data['object_key_hash'] = hash( 'sha256', $key );

		$columns = array(
			'attachment_id',
			'storage_profile_id',
			'local_relative_path',
			'object_key',
			'object_key_hash',
			'variant_type',
			'mime_type',
			'size_bytes',
			'etag',
			'checksum',
			'local_status',
			'remote_status',
			'attempt_count',
			'last_error_code',
			'last_error_message',
			'offloaded_at',
			'verified_at',
			'created_at',
			'updated_at',
		);
		$integer_columns = array( 'attachment_id', 'storage_profile_id', 'size_bytes', 'attempt_count' );
		$insert_columns  = array();
		$placeholders    = array();
		$values          = array();
		foreach ( $columns as $column ) {
			if ( ! array_key_exists( $column, $data ) ) {
				continue;
			}
			$insert_columns[] = $column;
			$this->append_sql_placeholder( $column, $data[ $column ], $integer_columns, $placeholders, $values );
		}

		$update_columns = array_values(
			array_diff(
				$insert_columns,
				array( 'created_at', 'storage_profile_id', 'object_key', 'object_key_hash' )
			)
		);
		$updates        = array_map(
			static fn( string $column ): string => "{$column} = VALUES({$column})",
			$update_columns
		);
		$updates[]      = 'id = LAST_INSERT_ID(id)';

		$sql = sprintf(
			'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
			$this->table(),
			implode( ', ', $insert_columns ),
			implode( ', ', $placeholders ),
			implode( ', ', $updates )
		);

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- SQL is assembled from a fixed column allowlist above; nulls are literal NULL, other values are placeholders.
		$result = $values === array()
			? $wpdb->query( $sql )
			: $wpdb->query( $wpdb->prepare( $sql, ...$values ) );
		if ( $result === false ) {
			throw new \RuntimeException( 'Object upsert failed.' );
		}

		$id = (int) $wpdb->insert_id;
		if ( $id <= 0 ) {
			throw new \RuntimeException( 'Object upsert failed: database did not return an object id.' );
		}

		$row = $this->find_by_id( $id );
		if ( ! is_array( $row ) || (int) ( $row['storage_profile_id'] ?? 0 ) !== $profile_id || (string) ( $row['object_key'] ?? '' ) !== $key ) {
			throw new \RuntimeException( 'Object upsert returned a mismatched object key.' );
		}

		return $id;
	}

	public function count_for_attachment( int $attachment_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table, see class docblock.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE attachment_id = %d", $attachment_id ) );
	}

	/**
	 * @return array<string, int> remote_status => count
	 */
	public function aggregate_remote_status(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table, see class docblock; no dynamic value to prepare (fixed aggregate query).
		$rows = $wpdb->get_results( "SELECT remote_status, COUNT(*) AS cnt FROM {$this->table()} GROUP BY remote_status", ARRAY_A );
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table, see class docblock.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_by_profile_and_key( int $storage_profile_id, string $object_key ): ?array {
		global $wpdb;
		$hash = hash( 'sha256', $object_key );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table, see class docblock; lookup by SHA-256 hash so case-insensitive MySQL collations cannot conflate distinct S3 keys.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE storage_profile_id = %d AND object_key_hash = %s",
				$storage_profile_id,
				$hash
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		// Exact binary key check — hash collision is vanishingly rare, but preserves case-sensitive S3 semantics.
		if ( (string) ( $row['object_key'] ?? '' ) !== $object_key ) {
			return null;
		}
		return $row;
	}

	/**
	 * WordPress upload filenames are global to a site's uploads directory, while
	 * object inventory is profile-scoped. A PRESENT row in any profile therefore
	 * makes the relative path unavailable for a new Media Library upload.
	 */
	public function has_present_relative_path( string $relative_path ): bool {
		if ( $relative_path === '' ) {
			return false;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed plugin-owned inventory table.
		$row = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$this->table()} WHERE BINARY local_relative_path = BINARY %s AND remote_status = %s LIMIT 1", $relative_path, ObjectRemoteStatus::PRESENT ) );
		return (bool) $row;
	}

	/**
	 * A Direct Upload path is unavailable when any existing writer owns it,
	 * whether that ownership is represented by local bytes, WordPress metadata,
	 * or an inventory row that has not reached remote PRESENT state.
	 */
	public function has_owned_relative_path( string $relative_path ): bool {
		if ( $relative_path === '' || str_starts_with( $relative_path, '/' ) || str_contains( $relative_path, "\0" ) || preg_match( '#(^|/)\.\.?(/|$)#', $relative_path ) ) {
			return true;
		}

		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir();
			$basedir = rtrim( (string) ( $uploads['basedir'] ?? '' ), '/\\' );
			if ( $basedir !== '' && is_file( $basedir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path ) ) ) {
				return true;
			}
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- exact ownership lookup across plugin inventory.
		$wpdb->last_error = '';
		$inventory = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$this->table()} WHERE BINARY local_relative_path = BINARY %s LIMIT 1", $relative_path ) );
		if ( $wpdb->last_error !== '' ) {
			// An indeterminate owner must reserve the path: accepting it could create
			// duplicate _wp_attached_file identities during Direct Upload creation.
			return true;
		}
		if ( $inventory ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- exact ownership lookup across WordPress attachment metadata.
		$wpdb->last_error = '';
		$attachment = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} AS pm INNER JOIN {$wpdb->posts} AS p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND BINARY pm.meta_value = BINARY %s AND p.post_type = %s LIMIT 1", '_wp_attached_file', $relative_path, 'attachment' ) );
		if ( $wpdb->last_error !== '' ) {
			return true;
		}
		return (bool) $attachment;
	}

	/**
	 * Atomic owner-preserving insert/update for profile+key.
	 *
	 * Never rewrites an existing row's attachment_id or local_relative_path to a
	 * different owner/path (even under concurrent duplicate-key races). Uses a
	 * single INSERT … ON DUPLICATE KEY UPDATE that only mutates non-ownership
	 * columns when the stored owner+relative already match VALUES().
	 *
	 * @param array<string, mixed> $data Row fields (must include attachment_id, storage_profile_id, object_key, local_relative_path).
	 * @return array{ok:bool,id?:int,reason?:string,existing?:array<string,mixed>}
	 */
	public function insert_owned_or_conflict( array $data ): array {
		global $wpdb;

		$profile_id = (int) ( $data['storage_profile_id'] ?? 0 );
		$key        = (string) ( $data['object_key'] ?? '' );
		$attachment = (int) ( $data['attachment_id'] ?? 0 );
		$relative   = (string) ( $data['local_relative_path'] ?? '' );
		if ( $profile_id <= 0 || $key === '' || $attachment <= 0 ) {
			return array(
				'ok'     => false,
				'reason' => 'invalid_row',
			);
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$data['updated_at'] = $now;
		if ( empty( $data['created_at'] ) ) {
			$data['created_at'] = $now;
		}
		$data['object_key_hash'] = hash( 'sha256', $key );

		$columns = array(
			'attachment_id',
			'storage_profile_id',
			'local_relative_path',
			'object_key',
			'object_key_hash',
			'variant_type',
			'mime_type',
			'size_bytes',
			'etag',
			'checksum',
			'local_status',
			'remote_status',
			'attempt_count',
			'last_error_code',
			'last_error_message',
			'offloaded_at',
			'verified_at',
			'created_at',
			'updated_at',
		);
		$integer_columns = array( 'attachment_id', 'storage_profile_id', 'size_bytes', 'attempt_count' );
		$insert_columns  = array();
		$placeholders    = array();
		$values          = array();
		foreach ( $columns as $column ) {
			if ( ! array_key_exists( $column, $data ) ) {
				continue;
			}
			$insert_columns[] = $column;
			$this->append_sql_placeholder( $column, $data[ $column ], $integer_columns, $placeholders, $values );
		}

		$owner_match = 'attachment_id = VALUES(attachment_id) AND BINARY local_relative_path = BINARY VALUES(local_relative_path)';
		$reclaimable = "remote_status IN ('stale','deleted')";
		$may_write   = "({$owner_match}) OR ({$reclaimable})";
		$mutable     = array_values(
			array_diff(
				$insert_columns,
				array(
					'created_at',
					'storage_profile_id',
					'object_key',
					'object_key_hash',
					'attachment_id',
					'local_relative_path',
				)
			)
		);
		$updates = array();
		// Ownership first — MySQL evaluates ODKU left-to-right; reclaimability must
		// read the original remote_status before mutable fields rewrite it.
		$updates[] = "attachment_id = IF({$reclaimable} OR ({$owner_match}), VALUES(attachment_id), attachment_id)";
		$updates[] = "local_relative_path = IF({$reclaimable} OR ({$owner_match}), VALUES(local_relative_path), local_relative_path)";
		foreach ( $mutable as $column ) {
			$updates[] = "{$column} = IF({$may_write}, VALUES({$column}), {$column})";
		}
		$updates[] = 'id = LAST_INSERT_ID(id)';

		$sql = sprintf(
			'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
			$this->table(),
			implode( ', ', $insert_columns ),
			implode( ', ', $placeholders ),
			implode( ', ', $updates )
		);

		// Named lock serializes relative-key inserts even under READ COMMITTED
		// (where gap locks from SELECT ... FOR UPDATE on empty sets are not kept).
		$lock_name = 's3ms_rel_' . substr( hash( 'sha256', $profile_id . ':' . $attachment . ':' . $relative ), 0, 48 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$got_lock = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 15)', $lock_name ) );
		if ( $got_lock !== 1 ) {
			return array(
				'ok'     => false,
				'reason' => 'write_failed',
			);
		}

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- owner-preserving write uses an explicit transaction.
			$wpdb->query( 'START TRANSACTION' );

			$relative_conflict = $this->locked_relative_key_conflict( $attachment, $profile_id, $relative, $key );
			if ( $relative_conflict !== null ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( 'ROLLBACK' );
				return array(
					'ok'       => false,
					'reason'   => 'relative_key_conflict',
					'existing' => $relative_conflict,
				);
			}

			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- SQL is assembled from a fixed column allowlist; nulls are literal NULL, other values are placeholders.
			$result = $values === array()
				? $wpdb->query( $sql )
				: $wpdb->query( $wpdb->prepare( $sql, ...$values ) );
			if ( $result === false ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( 'ROLLBACK' );
				return array(
					'ok'     => false,
					'reason' => 'write_failed',
				);
			}

			$id = (int) $wpdb->insert_id;
			if ( $id <= 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( 'ROLLBACK' );
				return array(
					'ok'     => false,
					'reason' => 'write_failed',
				);
			}

			$row = $this->find_by_id( $id );
			if ( ! is_array( $row ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( 'ROLLBACK' );
				return array(
					'ok'     => false,
					'reason' => 'write_failed',
				);
			}

			$same_owner = (int) ( $row['attachment_id'] ?? 0 ) === $attachment
				&& (string) ( $row['local_relative_path'] ?? '' ) === $relative;
			if ( ! $same_owner ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( 'ROLLBACK' );
				$reason = (int) ( $row['attachment_id'] ?? 0 ) !== $attachment
					? 'key_owned_by_other_attachment'
					: 'key_owned_by_other_relative';
				return array(
					'ok'       => false,
					'reason'   => $reason,
					'existing' => $row,
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'COMMIT' );

			return array(
				'ok' => true,
				'id' => $id,
			);
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * Lock current (attachment, profile, relative) rows and detect a different object_key.
	 *
	 * @return array<string, mixed>|null Conflicting row, or null when clear.
	 */
	private function locked_relative_key_conflict( int $attachment_id, int $profile_id, string $relative, string $object_key ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table; FOR UPDATE holds current relative bindings for the owner-preserving write.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE attachment_id = %d AND storage_profile_id = %d AND BINARY local_relative_path = BINARY %s AND remote_status NOT IN (%s, %s) FOR UPDATE",
				$attachment_id,
				$profile_id,
				$relative,
				ObjectRemoteStatus::STALE,
				ObjectRemoteStatus::DELETED
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return null;
		}
		foreach ( $rows as $row ) {
			$existing_key = (string) ( $row['object_key'] ?? '' );
			if ( $existing_key !== '' && $existing_key !== $object_key ) {
				return $row;
			}
		}
		return null;
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
		$params       = array_merge( array( $attachment_id ), array_values( $statuses ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table, see class docblock; {$placeholders} is a fixed run of literal '%s' tokens sized from count($statuses), never from user-controlled string content.
		$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE attachment_id = %d AND remote_status IN ({$placeholders}) ORDER BY id ASC", ...$params ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Known inventory keys for orphan scan (one profile).
	 *
	 * @return list<string>
	 */
	public function list_known_keys( int $storage_profile_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table, see class docblock.
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT object_key FROM {$this->table()} WHERE storage_profile_id = %d", $storage_profile_id ) );
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
	 * Distinct attachment ids that have inventory rows, cursor-paged by id.
	 *
	 * @return list<int>
	 */
	public function attachment_ids_page( int $limit = 50, int $after_attachment_id = 0 ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table, see class docblock; bounded cursor pagination for an admin-only diagnostic.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT attachment_id FROM {$this->table()} WHERE attachment_id > %d ORDER BY attachment_id ASC LIMIT %d", $after_attachment_id, $limit ) );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Paginated object rows for DB-first health scan.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function scan_page( int $limit = 500, int $after_id = 0 ): array {
		global $wpdb;
		$limit = max( 1, min( 2000, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table, see class docblock; bounded (LIMIT clamped 1-2000), sequential cursor pagination for an admin-only health scan.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id > %d ORDER BY id ASC LIMIT %d", $after_id, $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function total_count(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table, see class docblock; no dynamic value to prepare (fixed count query).
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()}" );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function find_by_attachment_and_profile( int $attachment_id, int $storage_profile_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table, see class docblock.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE attachment_id = %d AND storage_profile_id = %d ORDER BY id ASC", $attachment_id, $storage_profile_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function find_migratable_page( int $source_profile_id, int $limit = 100, int $after_id = 0 ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table, see class docblock; bounded (LIMIT clamped 1-500), sequential cursor pagination for an admin-triggered migration batch.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE storage_profile_id = %d AND id > %d AND remote_status = %s ORDER BY id ASC LIMIT %d", $source_profile_id, $after_id, ObjectRemoteStatus::PRESENT, $limit ), ARRAY_A );
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table, see class docblock; this write must be immediately visible to the very next iteration/caller, so caching would be actively harmful here.
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET remote_status = %s, updated_at = %s WHERE attachment_id = %d AND local_relative_path = %s AND remote_status NOT IN (%s, %s)", ObjectRemoteStatus::STALE, $now, $attachment_id, $relative, ObjectRemoteStatus::STALE, ObjectRemoteStatus::DELETED ) );
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table, see class docblock; called immediately after profile create/delete mutations, so a cached count would be stale by design.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE storage_profile_id = %d", $storage_profile_id ) );
	}

	public function has_active_reference_for_profile_key( int $storage_profile_id, string $object_key ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table, see class docblock; source-delete safety must read current DB state immediately before remote delete.
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE storage_profile_id = %d AND object_key = %s AND remote_status IN (%s, %s)", $storage_profile_id, $object_key, ObjectRemoteStatus::PRESENT, ObjectRemoteStatus::UPLOADING ) );
		return (int) $count > 0;
	}

	public function mark_deleted_by_profile_key( int $storage_profile_id, string $object_key ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table, see class docblock; called only after remote source delete succeeds.
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET remote_status = %s, updated_at = %s WHERE storage_profile_id = %d AND object_key = %s AND remote_status = %s", ObjectRemoteStatus::DELETED, gmdate( 'Y-m-d H:i:s' ), $storage_profile_id, $object_key, ObjectRemoteStatus::STALE ) );
		return is_int( $updated ) ? $updated : 0;
	}

	/**
	 * Remove inventory rows after a full local restore (attachment serves locally again).
	 */
	public function delete_by_attachment( int $attachment_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned table, see class docblock; $wpdb->delete() already parameterizes values.
		$deleted = $wpdb->delete( $this->table(), array( 'attachment_id' => $attachment_id ), array( '%d' ) );
		if ( ! is_int( $deleted ) ) {
			throw new \RuntimeException( 'Object inventory cleanup failed.' );
		}
		return $deleted;
	}

	/** Remove one exact Direct Upload inventory row without touching sibling objects. */
	public function delete_exact_attachment_profile_key( int $attachment_id, int $storage_profile_id, string $object_key ): int {
		if ( $attachment_id < 1 || $storage_profile_id < 1 || $object_key === '' ) {
			throw new \InvalidArgumentException( 'Exact inventory cleanup needs an attachment, profile, and object key.' );
		}
		global $wpdb;
		$hash = hash( 'sha256', $object_key );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table and all values are prepared; BINARY preserves case-sensitive object-key identity.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE attachment_id = %d AND storage_profile_id = %d AND object_key_hash = %s AND BINARY object_key = BINARY %s", $attachment_id, $storage_profile_id, $hash, $object_key ) );
		if ( ! is_int( $deleted ) ) {
			throw new \RuntimeException( 'Exact object inventory cleanup failed.' );
		}
		return $deleted;
	}

	/**
	 * Bind a column value for prepared INSERT SQL. Null becomes literal NULL (clears on ODKU).
	 *
	 * @param list<string> $integer_columns Integer columns.
	 * @param list<string> $placeholders    Placeholder accumulator.
	 * @param list<mixed>  $values          Bound value accumulator.
	 */
	private function append_sql_placeholder(
		string $column,
		mixed $value,
		array $integer_columns,
		array &$placeholders,
		array &$values
	): void {
		if ( $value === null ) {
			$placeholders[] = 'NULL';
			return;
		}
		$placeholders[] = in_array( $column, $integer_columns, true ) ? '%d' : '%s';
		$values[]       = $value;
	}
}
