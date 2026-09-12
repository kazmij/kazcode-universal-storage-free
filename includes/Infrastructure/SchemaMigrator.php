<?php
/**
 * Database schema versioning for KAZCODE Universal Storage v2 tables.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure;

defined( 'ABSPATH' ) || exit;

/**
 * Creates/upgrades custom tables. Must stay fast on activate / plugins_loaded.
 */
final class SchemaMigrator {

	public const OPTION_KEY = 's3ms_schema_version';
	public const VERSION    = 3;

	/**
	 * Run pending schema upgrades.
	 */
	public function maybe_upgrade(): void {
		$current = (int) get_option( self::OPTION_KEY, 0 );
		if ( $current < 1 ) {
			$this->install_v1();
			$current = 1;
			update_option( self::OPTION_KEY, $current, false );
		}
		if ( $current < 2 ) {
			$this->install_v2();
			$current = 2;
			update_option( self::OPTION_KEY, $current, false );
		}
		if ( $current === 2 && ! $this->public_v2_objects_schema_is_present() ) {
			throw new \RuntimeException( 'Stored schema version 2 does not match the public object-key hash schema.' );
		}
		if ( $current < 3 ) {
			$this->install_v3();
			$current = 3;
			update_option( self::OPTION_KEY, $current, false );
		}
	}

	/**
	 * Create profiles + objects tables (dbDelta).
	 */
	private function install_v1(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset = $wpdb->get_charset_collate();
		$profiles = $wpdb->prefix . 's3ms_storage_profiles';
		$objects  = $wpdb->prefix . 's3ms_objects';

		$sql_profiles = "CREATE TABLE {$profiles} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uuid char(36) NOT NULL,
			name varchar(191) NOT NULL,
			provider_type varchar(32) NOT NULL,
			bucket varchar(255) NOT NULL DEFAULT '',
			region varchar(64) NOT NULL DEFAULT '',
			endpoint varchar(255) NOT NULL DEFAULT '',
			path_style tinyint(1) NOT NULL DEFAULT 0,
			prefix varchar(255) NOT NULL DEFAULT '',
			delivery_type varchar(16) NOT NULL DEFAULT 'storage',
			delivery_base_url text NULL,
			cdn_includes_prefix tinyint(1) NOT NULL DEFAULT 0,
			credential_mode varchar(16) NOT NULL DEFAULT 'keys',
			credentials_ref varchar(64) NOT NULL DEFAULT '',
			is_default_upload_target tinyint(1) NOT NULL DEFAULT 0,
			is_read_only tinyint(1) NOT NULL DEFAULT 0,
			location_locked tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY provider_type (provider_type),
			KEY is_default_upload_target (is_default_upload_target)
		) {$charset};";

		$sql_objects = "CREATE TABLE {$objects} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL,
			storage_profile_id bigint(20) unsigned NOT NULL,
			local_relative_path varchar(512) NOT NULL,
			object_key varchar(1024) NOT NULL,
			object_key_hash char(64) NOT NULL,
			variant_type varchar(32) NOT NULL DEFAULT 'size',
			mime_type varchar(100) NOT NULL DEFAULT '',
			size_bytes bigint(20) unsigned DEFAULT NULL,
			etag varchar(128) DEFAULT NULL,
			checksum varchar(128) DEFAULT NULL,
			local_status varchar(16) NOT NULL DEFAULT 'unknown',
			remote_status varchar(16) NOT NULL DEFAULT 'pending',
			attempt_count int(10) unsigned NOT NULL DEFAULT 0,
			last_error_code varchar(64) DEFAULT NULL,
			last_error_message text,
			offloaded_at datetime DEFAULT NULL,
			verified_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY profile_object_key_hash (storage_profile_id, object_key_hash),
			KEY attachment_id (attachment_id),
			KEY remote_status (remote_status),
			KEY updated_at (updated_at),
			KEY attachment_remote (attachment_id, remote_status),
			KEY profile_attachment (storage_profile_id, attachment_id)
		) {$charset};";

		\dbDelta( $sql_profiles );
		\dbDelta( $sql_objects );
	}

	/**
	 * Replace prefix-based object-key uniqueness with public v1.0.2 full-key hash fencing.
	 */
	private function install_v2(): void {
		global $wpdb;

		$objects = $wpdb->prefix . 's3ms_objects';

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned table schema inspection; table name is fixed from $wpdb->prefix.
		$hash_column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$objects} LIKE %s", 'object_key_hash' ) );
		if ( ! $hash_column ) {
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- versioned plugin-owned schema migration.
			$result = $wpdb->query( "ALTER TABLE {$objects} ADD object_key_hash char(64) NOT NULL DEFAULT '' AFTER object_key" );
			if ( $result === false ) {
				throw new \RuntimeException( 'Unable to add s3ms_objects.object_key_hash.' );
			}
		}

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- versioned plugin-owned data backfill; SHA2 is provided by supported MySQL/MariaDB variants.
		$result = $wpdb->query( "UPDATE {$objects} SET object_key_hash = SHA2(object_key, 256) WHERE object_key_hash = '' OR object_key_hash IS NULL" );
		if ( $result === false ) {
			throw new \RuntimeException( 'Unable to backfill s3ms_objects.object_key_hash.' );
		}

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fail-safe duplicate detection before adding the authoritative unique key.
		$duplicates = $wpdb->get_results( "SELECT storage_profile_id, object_key_hash, COUNT(*) AS cnt, COUNT(DISTINCT object_key) AS distinct_keys FROM {$objects} GROUP BY storage_profile_id, object_key_hash HAVING cnt > 1", ARRAY_A );
		if ( is_array( $duplicates ) && $duplicates !== array() ) {
			throw new \RuntimeException( 'Duplicate s3ms_objects storage profile/object key hashes prevent safe schema upgrade.' );
		}

		$this->drop_index_if_exists( $objects, 'profile_object_key' );
		$this->add_unique_index_if_missing( $objects, 'profile_object_key_hash', 'storage_profile_id, object_key_hash' );
	}

	/**
	 * Create durable import and transfer session tables (dbDelta).
	 */
	private function install_v3(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset   = $wpdb->get_charset_collate();
		$transfers = $wpdb->prefix . 's3ms_transfer_sessions';
		$imports   = $wpdb->prefix . 's3ms_import_runs';

		$sql_transfers = "CREATE TABLE {$transfers} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uuid char(36) NOT NULL,
			kind varchar(32) NOT NULL,
			attachment_id bigint(20) unsigned DEFAULT NULL,
			object_id bigint(20) unsigned DEFAULT NULL,
			source_profile_id bigint(20) unsigned DEFAULT NULL,
			destination_profile_id bigint(20) unsigned NOT NULL,
			source_ref text NOT NULL,
			destination_key varchar(1024) NOT NULL,
			size_bytes bigint(20) unsigned NOT NULL,
			source_fingerprint varchar(191) NOT NULL,
			multipart_upload_id text DEFAULT NULL,
			part_size_bytes int(10) unsigned NOT NULL,
			completed_parts longtext NOT NULL,
			status varchar(16) NOT NULL,
			attempt_count int(10) unsigned NOT NULL DEFAULT 0,
			last_error_code varchar(64) DEFAULT NULL,
			last_error_message text DEFAULT NULL,
			started_at datetime DEFAULT NULL,
			updated_at datetime NOT NULL,
			completed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY resumable (kind, destination_profile_id, destination_key(191), source_fingerprint, status),
			KEY stale_incomplete (status, updated_at, id)
		) {$charset};";

		$sql_imports = "CREATE TABLE {$imports} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uuid char(36) NOT NULL,
			source_type varchar(64) NOT NULL,
			profile_id bigint(20) unsigned NOT NULL,
			mode varchar(16) NOT NULL,
			status varchar(16) NOT NULL,
			after_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			processed bigint(20) unsigned NOT NULL DEFAULT 0,
			verified bigint(20) unsigned NOT NULL DEFAULT 0,
			missing bigint(20) unsigned NOT NULL DEFAULT 0,
			transient_errors bigint(20) unsigned NOT NULL DEFAULT 0,
			conflicts bigint(20) unsigned NOT NULL DEFAULT 0,
			last_error_message text DEFAULT NULL,
			source_fingerprint varchar(191) NOT NULL,
			started_at datetime DEFAULT NULL,
			updated_at datetime NOT NULL,
			finished_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY successful_dry_run (source_type, profile_id, source_fingerprint, mode, status)
		) {$charset};";

		\dbDelta( $sql_transfers );
		\dbDelta( $sql_imports );

		if ( ! $this->table_exists( $transfers ) || ! $this->table_exists( $imports ) ) {
			throw new \RuntimeException( 'Unable to verify s3ms v3 schema.' );
		}
	}

	private function public_v2_objects_schema_is_present(): bool {
		global $wpdb;

		$objects = $wpdb->prefix . 's3ms_objects';

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned schema inspection.
		$hash_column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$objects} LIKE %s", 'object_key_hash' ) );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned schema inspection.
		$hash_index  = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$objects} WHERE Key_name = %s", 'profile_object_key_hash' ) );

		return (bool) $hash_column && (bool) $hash_index;
	}

	private function drop_index_if_exists( string $table, string $index_name ): void {
		global $wpdb;

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned schema inspection.
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $index_name ) );
		if ( ! $exists ) {
			return;
		}

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- versioned plugin-owned schema migration.
		$result = $wpdb->query( "ALTER TABLE {$table} DROP INDEX {$index_name}" );
		if ( $result === false ) {
			throw new \RuntimeException( 'Unable to drop s3ms_objects index.' );
		}
	}

	private function add_unique_index_if_missing( string $table, string $index_name, string $columns ): void {
		global $wpdb;

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned schema inspection.
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $index_name ) );
		if ( $exists ) {
			return;
		}

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- versioned plugin-owned schema migration.
		$result = $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY {$index_name} ({$columns})" );
		if ( $result === false ) {
			throw new \RuntimeException( 'Unable to add s3ms_objects unique index.' );
		}
	}

	private function table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned schema inspection during versioned migration.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return is_string( $found ) && $found === $table;
	}
}
