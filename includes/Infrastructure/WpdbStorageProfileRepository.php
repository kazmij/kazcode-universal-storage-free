<?php
/**
 * wpdb-backed storage profile repository.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Domain\StorageProfileRepositoryInterface;

/**
 * Per-site table {$wpdb->prefix}s3ms_storage_profiles — owned exclusively by
 * this plugin. See ObjectRepository's class docblock for why direct $wpdb
 * queries against this table are correct (no core API/table involved) and
 * why no object-cache group is used (profile CRUD is admin-only, low
 * frequency, and every read here matters immediately after a mutation —
 * e.g. is_default_upload_target must reflect the row just changed). Each
 * query is kept on one line so its phpcs:ignore annotation survives this
 * plugin's PHP-Scoper release build unambiguously (see BUILD.md).
 */
final class WpdbStorageProfileRepository implements StorageProfileRepositoryInterface {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 's3ms_storage_profiles';
	}

	/**
	 * {@inheritdoc}
	 */
	public function all(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table, see class docblock; no dynamic value to prepare (fixed listing query).
		$rows = $wpdb->get_results( "SELECT * FROM {$this->table()} ORDER BY id ASC", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = StorageProfile::from_row( $row );
		}
		return $out;
	}

	public function find( int $id ): ?StorageProfile {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table, see class docblock.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? StorageProfile::from_row( $row ) : null;
	}

	public function find_by_uuid( string $uuid ): ?StorageProfile {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table, see class docblock.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE uuid = %s", $uuid ), ARRAY_A );
		return is_array( $row ) ? StorageProfile::from_row( $row ) : null;
	}

	public function find_default_upload_target(): ?StorageProfile {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table, see class docblock; no dynamic value to prepare (fixed lookup by constant column value), and must reflect the row set_default_upload_target() just changed.
		$row = $wpdb->get_row( "SELECT * FROM {$this->table()} WHERE is_default_upload_target = 1 ORDER BY id ASC LIMIT 1", ARRAY_A );
		return is_array( $row ) ? StorageProfile::from_row( $row ) : null;
	}

	public function count(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table, see class docblock; no dynamic value to prepare (fixed count query), and callers rely on it reflecting the row just inserted/deleted.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()}" );
	}

	/**
	 * Pure data-access primitive — enforcing the one-profile Free product
	 * boundary is the caller's job (StorageProfileAdminService delegates
	 * creation of a 2nd+ profile to Pro; Pro's own service is what calls
	 * this for additional profiles), not this repository's.
	 */
	public function insert( StorageProfile $profile ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin-owned table, see class docblock; $wpdb->insert() already parameterizes values.
		$wpdb->insert( $this->table(), $profile->to_row() );
		return (int) $wpdb->insert_id;
	}

	public function update( StorageProfile $profile ): void {
		global $wpdb;
		if ( $profile->id === null ) {
			throw new \InvalidArgumentException( 'Cannot update profile without id.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned table, see class docblock; $wpdb->update() already parameterizes values.
		$wpdb->update( $this->table(), $profile->to_row(), array( 'id' => $profile->id ) );
	}

	public function set_default_upload_target( int $profile_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table, see class docblock; no dynamic value to prepare (unconditional reset of a boolean flag on all rows, always paired with the update() below that sets exactly one row).
		$wpdb->query( "UPDATE {$this->table()} SET is_default_upload_target = 0" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned table, see class docblock; $wpdb->update() already parameterizes values.
		$wpdb->update( $this->table(), array( 'is_default_upload_target' => 1, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $profile_id ) );
	}

	public function delete( int $profile_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned table, see class docblock; $wpdb->delete() already parameterizes values.
		$wpdb->delete( $this->table(), array( 'id' => $profile_id ), array( '%d' ) );
	}
}
