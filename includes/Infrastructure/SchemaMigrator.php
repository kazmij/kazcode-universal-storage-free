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
	public const VERSION    = 11;

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
		if ( $current < 4 ) {
			$this->install_v4();
			$current = 4;
			update_option( self::OPTION_KEY, $current, false );
		}
		if ( $current < 5 ) {
			$this->install_v5();
			$current = 5;
			update_option( self::OPTION_KEY, $current, false );
		}
		if ( $current < 6 ) {
			$this->install_v6();
			$current = 6;
			update_option( self::OPTION_KEY, $current, false );
		}
		if ( $current < 7 ) {
			$this->install_v7();
			$current = 7;
			update_option( self::OPTION_KEY, $current, false );
		}
		if ( $current < 8 ) {
			$this->install_v8();
			$current = 8;
			update_option( self::OPTION_KEY, $current, false );
		}
		if ( $current < 9 ) { $this->install_v9(); $current = 9; update_option( self::OPTION_KEY, $current, false ); }
		if ( $current < 10 ) { $this->install_v10(); $current = 10; update_option( self::OPTION_KEY, $current, false ); }
		if ( $current < 11 ) { $this->install_v11(); $current = 11; update_option( self::OPTION_KEY, $current, false ); }
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
			skipped bigint(20) unsigned NOT NULL DEFAULT 0,
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

	/**
	 * Add skipped counter for profile-mismatched import descriptors (dbDelta).
	 */
	private function install_v4(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset = $wpdb->get_charset_collate();
		$imports = $wpdb->prefix . 's3ms_import_runs';

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
			skipped bigint(20) unsigned NOT NULL DEFAULT 0,
			last_error_message text DEFAULT NULL,
			source_fingerprint varchar(191) NOT NULL,
			started_at datetime DEFAULT NULL,
			updated_at datetime NOT NULL,
			finished_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY successful_dry_run (source_type, profile_id, source_fingerprint, mode, status)
		) {$charset};";

		\dbDelta( $sql_imports );

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned schema inspection.
		$skipped = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$imports} LIKE %s", 'skipped' ) );
		if ( ! $skipped ) {
			throw new \RuntimeException( 'Unable to verify s3ms_import_runs.skipped column.' );
		}
	}

	/**
	 * Add rejected counter for source attachments that do not produce importable descriptors (dbDelta).
	 */
	private function install_v5(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset = $wpdb->get_charset_collate();
		$imports = $wpdb->prefix . 's3ms_import_runs';

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
			skipped bigint(20) unsigned NOT NULL DEFAULT 0,
			rejected bigint(20) unsigned NOT NULL DEFAULT 0,
			last_error_message text DEFAULT NULL,
			source_fingerprint varchar(191) NOT NULL,
			started_at datetime DEFAULT NULL,
			updated_at datetime NOT NULL,
			finished_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY successful_dry_run (source_type, profile_id, source_fingerprint, mode, status)
		) {$charset};";

		\dbDelta( $sql_imports );

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned schema inspection.
		$rejected = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$imports} LIKE %s", 'rejected' ) );
		if ( ! $rejected ) {
			throw new \RuntimeException( 'Unable to verify s3ms_import_runs.rejected column.' );
		}
	}

	/**
	 * Create the shared, durable reservation registry.
	 *
	 * Reservation hashes make uniqueness independent of a database collation and
	 * avoid unsafe prefix indexes for long, case-sensitive object keys.
	 */
	private function install_v6(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset      = $wpdb->get_charset_collate();
		$reservations = $wpdb->prefix . 's3ms_storage_reservations';
		$sql          = "CREATE TABLE {$reservations} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uuid char(36) NOT NULL,
			blog_id bigint(20) unsigned NOT NULL,
			relative_path varchar(512) DEFAULT NULL,
			relative_path_hash char(64) DEFAULT NULL,
			profile_id bigint(20) unsigned DEFAULT NULL,
			object_key varchar(1024) DEFAULT NULL,
			object_key_hash char(64) DEFAULT NULL,
			owner_uuid char(36) NOT NULL,
			state varchar(16) NOT NULL,
			worker_token char(36) DEFAULT NULL,
			fencing_epoch bigint(20) unsigned NOT NULL DEFAULT 0,
			lease_expires_at datetime DEFAULT NULL,
			settled_at datetime DEFAULT NULL,
			settlement_proof varchar(191) DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			UNIQUE KEY blog_relative_path_hash (blog_id, relative_path_hash),
			UNIQUE KEY profile_object_key_hash (profile_id, object_key_hash),
			KEY owner_state (owner_uuid, state),
			KEY lease_expiry (lease_expires_at)
		) ENGINE=InnoDB {$charset};";

		\dbDelta( $sql );
		if ( ! $this->reservation_schema_is_present( $reservations ) ) {
			throw new \RuntimeException( 'Unable to verify s3ms storage reservations schema.' );
		}
	}

	/** Persist exact-pair releases that survive attachment metadata deletion. */
	private function install_v7(): void {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) ) require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $wpdb->prefix . 's3ms_reservation_cleanup';
		$charset = $wpdb->get_charset_collate();
		\dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			identity_hash char(64) NOT NULL,
			binding_json longtext NOT NULL,
			settlement_proof varchar(191) NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY identity_hash (identity_hash)
		) ENGINE=InnoDB {$charset};" );
		if ( ! $this->table_exists( $table ) ) throw new \RuntimeException( 'Unable to verify s3ms reservation cleanup schema.' );
	}

	/** Stage cleanup intent before destructive work; legacy rows are already safe to replay. */
	private function install_v8(): void {
		global $wpdb;
		$table = $wpdb->prefix . 's3ms_reservation_cleanup';
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned schema inspection.
		$state = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'state' ) );
		if ( ! $state ) {
			// Existing v7 rows were recorded only after remote and inventory cleanup, so they are safe ready work.
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- versioned plugin-owned schema migration.
			if ( false === $wpdb->query( "ALTER TABLE {$table} ADD state varchar(16) NOT NULL DEFAULT 'ready' AFTER settlement_proof" ) ) {
				throw new \RuntimeException( 'Unable to add s3ms reservation cleanup state.' );
			}
		}
	}

	/** Proof exists before a Direct Upload attachment can become deletable. */
	private function install_v9(): void {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) ) require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $wpdb->prefix . 's3ms_du_finalization_intents';
		$charset = $wpdb->get_charset_collate();
		\dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			recovery_marker varchar(191) NOT NULL,
			binding_json longtext NOT NULL,
			location_json longtext NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY recovery_marker (recovery_marker)
		) ENGINE=InnoDB {$charset};" );
		if ( ! $this->table_exists( $table ) ) throw new \RuntimeException( 'Unable to verify Direct Upload finalization intent schema.' );
	}

	/** Shared durable authorization boundary between Direct Upload cleanup and migration copy. */
	private function install_v10(): void {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) ) require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $wpdb->prefix . 's3ms_direct_upload_migration_fences';
		$charset = $wpdb->get_charset_collate();
		\dbDelta( "CREATE TABLE {$table} (
			attachment_id bigint(20) unsigned NOT NULL,
			source_profile_id bigint(20) unsigned NOT NULL,
			source_object_key varchar(1024) NOT NULL,
			source_fingerprint char(64) NOT NULL,
			destination_profile_id bigint(20) unsigned NOT NULL,
			destination_object_key varchar(1024) NOT NULL,
			destination_fingerprint char(64) NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (attachment_id)
		) ENGINE=InnoDB {$charset};" );
		if ( ! $this->table_exists( $table ) ) throw new \RuntimeException( 'Unable to verify s3ms Direct Upload migration fence schema.' );
	}

	/** Add the authoritative provider multipart identity to a migration fence. */
	private function install_v11(): void {
		global $wpdb;
		$table = $wpdb->prefix . 's3ms_direct_upload_migration_fences';
		$column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'multipart_upload_id' ) );
		if ( ! $column && false === $wpdb->query( "ALTER TABLE {$table} ADD multipart_upload_id varchar(1024) NOT NULL DEFAULT '' AFTER destination_fingerprint" ) ) {
			throw new \RuntimeException( 'Unable to add Direct Upload migration multipart authority.' );
		}
	}

	private function reservation_schema_is_present( string $table ): bool {
		global $wpdb;

		if ( ! $this->table_exists( $table ) ) {
			return false;
		}
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned schema inspection during versioned migration.
		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ), ARRAY_A );
		if ( ! is_array( $status ) || 0 !== strcasecmp( (string) ( $status['Engine'] ?? '' ), 'InnoDB' ) ) {
			return false;
		}
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned schema inspection; table name is fixed from $wpdb->prefix.
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
		if ( ! is_array( $columns ) ) {
			return false;
		}
		$required_columns = array(
			'id'                 => array( 'type' => 'bigint(20) unsigned', 'null' => 'NO',  'default' => null, 'extra' => 'auto_increment' ),
			'uuid'               => array( 'type' => 'char(36)',            'null' => 'NO',  'default' => null, 'extra' => '' ),
			'blog_id'            => array( 'type' => 'bigint(20) unsigned', 'null' => 'NO',  'default' => null, 'extra' => '' ),
			'relative_path'      => array( 'type' => 'varchar(512)',        'null' => 'YES', 'default' => null, 'extra' => '' ),
			'relative_path_hash' => array( 'type' => 'char(64)',            'null' => 'YES', 'default' => null, 'extra' => '' ),
			'profile_id'         => array( 'type' => 'bigint(20) unsigned', 'null' => 'YES', 'default' => null, 'extra' => '' ),
			'object_key'         => array( 'type' => 'varchar(1024)',       'null' => 'YES', 'default' => null, 'extra' => '' ),
			'object_key_hash'    => array( 'type' => 'char(64)',            'null' => 'YES', 'default' => null, 'extra' => '' ),
			'owner_uuid'         => array( 'type' => 'char(36)',            'null' => 'NO',  'default' => null, 'extra' => '' ),
			'state'              => array( 'type' => 'varchar(16)',         'null' => 'NO',  'default' => null, 'extra' => '' ),
			'worker_token'       => array( 'type' => 'char(36)',            'null' => 'YES', 'default' => null, 'extra' => '' ),
			'fencing_epoch'      => array( 'type' => 'bigint(20) unsigned', 'null' => 'NO',  'default' => '0',  'extra' => '' ),
			'lease_expires_at'   => array( 'type' => 'datetime',            'null' => 'YES', 'default' => null, 'extra' => '' ),
			'settled_at'         => array( 'type' => 'datetime',            'null' => 'YES', 'default' => null, 'extra' => '' ),
			'settlement_proof'   => array( 'type' => 'varchar(191)',        'null' => 'YES', 'default' => null, 'extra' => '' ),
			'created_at'         => array( 'type' => 'datetime',            'null' => 'NO',  'default' => null, 'extra' => '' ),
			'updated_at'         => array( 'type' => 'datetime',            'null' => 'NO',  'default' => null, 'extra' => '' ),
		);
		$found_columns = array();
		foreach ( $columns as $column ) {
			if ( ! is_array( $column ) || ! isset( $column['Field'] ) ) {
				continue;
			}
			$found_columns[ (string) $column['Field'] ] = $column;
		}
		foreach ( $required_columns as $required_column => $definition ) {
			if ( ! isset( $found_columns[ $required_column ] ) ) {
				return false;
			}
			$column = $found_columns[ $required_column ];
			if (
				0 !== strcasecmp( (string) ( $column['Type'] ?? '' ), $definition['type'] )
				|| strtoupper( (string) ( $column['Null'] ?? '' ) ) !== $definition['null']
				|| ( $column['Default'] ?? null ) !== $definition['default']
				|| strtolower( (string) ( $column['Extra'] ?? '' ) ) !== $definition['extra']
			) {
				return false;
			}
		}

		// SHOW COLUMNS.Key is a lossy summary affected by additional indexes.
		// Verify authoritative named key definitions, including PRIMARY, below.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned schema inspection; table name is fixed from $wpdb->prefix.
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
		if ( ! is_array( $indexes ) ) {
			return false;
		}
		$required = array(
			'PRIMARY'                 => array( true,  array( 'id' ) ),
			'uuid'                    => array( true,  array( 'uuid' ) ),
			'blog_relative_path_hash' => array( true,  array( 'blog_id', 'relative_path_hash' ) ),
			'profile_object_key_hash' => array( true,  array( 'profile_id', 'object_key_hash' ) ),
			'owner_state'             => array( false, array( 'owner_uuid', 'state' ) ),
			'lease_expiry'            => array( false, array( 'lease_expires_at' ) ),
		);
		$found = array();
		foreach ( $indexes as $index ) {
			if ( ! is_array( $index ) ) {
				continue;
			}
			$name = (string) ( $index['Key_name'] ?? '' );
			$seq  = (int) ( $index['Seq_in_index'] ?? 0 );
			$col  = (string) ( $index['Column_name'] ?? '' );
			if ( $name === '' || $seq < 1 || $col === '' ) {
				continue;
			}
			// Required constraints must index whole columns, not hash prefixes.
			// Unrelated additional indexes do not change this schema contract.
			if ( isset( $required[ $name ] ) && ( $index['Sub_part'] ?? null ) !== null ) {
				return false;
			}
			$found[ $name ]['unique'] = '0' === (string) ( $index['Non_unique'] ?? '1' );
			$found[ $name ]['columns'][ $seq ] = $col;
		}

		foreach ( $required as $name => $definition ) {
			list( $unique, $expected_columns ) = $definition;
			if (
				! isset( $found[ $name ]['unique'], $found[ $name ]['columns'] )
				|| $found[ $name ]['unique'] !== $unique
			) {
				return false;
			}
			ksort( $found[ $name ]['columns'] );
			if ( array_values( $found[ $name ]['columns'] ) !== $expected_columns ) {
				return false;
			}
		}

		return true;
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

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- versioned plugin-owned schema migration.
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
