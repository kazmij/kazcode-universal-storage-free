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
	public const VERSION    = 1;

	/**
	 * Run pending schema upgrades.
	 */
	public function maybe_upgrade(): void {
		$current = (int) get_option( self::OPTION_KEY, 0 );
		if ( $current >= self::VERSION ) {
			return;
		}

		$this->install_v1();
		update_option( self::OPTION_KEY, self::VERSION, false );
	}

	/**
	 * Create profiles + objects tables (dbDelta).
	 */
	private function install_v1(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

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
			UNIQUE KEY profile_object_key (storage_profile_id, object_key(191)),
			KEY attachment_id (attachment_id),
			KEY remote_status (remote_status),
			KEY updated_at (updated_at),
			KEY attachment_remote (attachment_id, remote_status),
			KEY profile_attachment (storage_profile_id, attachment_id)
		) {$charset};";

		dbDelta( $sql_profiles );
		dbDelta( $sql_objects );
	}
}
