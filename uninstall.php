<?php
/**
 * Uninstall handler for KAZCODE Universal Storage.
 *
 * SAFETY INVARIANT (never violated, in either mode below): this file never
 * contacts a storage provider, never issues a DeleteObject/empty-bucket
 * request, never deletes a WordPress attachment post, and never deletes a
 * local file under wp-content/uploads. Uninstalling this plugin is strictly
 * a local WordPress/database cleanup of state this plugin itself created —
 * media that exists only in remote object storage is never at risk.
 *
 * Two modes, controlled by the "Delete Universal Storage data when
 * uninstalling" setting (Settings::defaults()['delete_data_on_uninstall'],
 * default OFF):
 *
 * - Default (preserve): removes only disposable/ephemeral runtime state
 *   (transients, per-attachment locks, queue/cursor state, scheduled cron/
 *   Action Scheduler jobs). Storage profiles, encrypted credentials, object
 *   inventory, attachment remote-status postmeta, schema version, and the
 *   audit log all survive, so a later reinstall can detect and recover the
 *   existing configuration/inventory rather than starting from zero.
 * - Purge (opt-in): additionally removes all of the above durable state —
 *   options, encrypted credentials, the two plugin-owned custom tables, and
 *   the `_s3ms_*` attachment postmeta. Still never touches remote storage,
 *   attachments, or local media files.
 *
 * Idempotent: safe to run against a partial/already-cleaned/pre-v2
 * installation. Every operation tolerates the thing it targets already
 * being absent (missing table, missing option, etc.) — nothing here can
 * fatal on a second run or an incomplete prior installation.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Disposable/ephemeral runtime state — always removed, regardless of the
 * "delete data on uninstall" setting. None of this is needed to recover an
 * existing configuration; all of it is either self-expiring (transients),
 * time-boxed (locks), or meaningless once the plugin is gone (in-flight
 * queue cursors, scheduled ticks).
 */
function kazus_uninstall_clear_ephemeral_state(): void {
	global $wpdb;

	delete_transient( 'kazus_activation_redirect' );
	delete_transient( 'kazus_migration_stats_cache' );

	delete_option( 's3ms_object_stats_cache' );   // ObjectStatsAggregator dashboard cache.
	delete_option( 's3ms_pending_jobs' );         // CronQueueAdapter fallback queue cursor.
	delete_option( 's3ms_adopt_run' );            // AdoptService resumable-batch cursor.
	delete_option( 's3ms_background_job' );       // BackgroundMigrator resumable-batch cursor.
	delete_option( 'kazus_background_tick_lease' ); // BackgroundMigrator tick concurrency lease.

	// Per-attachment processing locks (AttachmentLock) — dynamically named
	// options, one per attachment ever locked (s3ms_lock_123, s3ms_lock_456,
	// …), not a fixed key we can delete_option() by name. Fixed, hardcoded
	// LIKE pattern only — never built from request/user input.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned dynamically-named option cleanup; no WordPress API deletes options by pattern, and this is a one-time uninstall operation, not a request-path query.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 's3ms\\_lock\\_%'" );

	// Onboarding tour dismissal — a per-user UI preference, not recovery data.
	delete_metadata( 'user', 0, 's3ms_tour_dismissed', '', true );

	// Scheduled work owned by this plugin. wp_clear_scheduled_hook() is a
	// no-op (not an error) if nothing is currently scheduled.
	wp_clear_scheduled_hook( 'kazus_background_tick' );
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		// Removes only this plugin's own scheduled actions for this one hook —
		// never touches Action Scheduler's shared tables/other plugins' actions.
		as_unschedule_all_actions( 'kazus_queue_job' );
	}
}

/**
 * Durable, recovery-critical state — removed ONLY when the site owner
 * explicitly enabled "Delete Universal Storage data when uninstalling".
 * Still never touches remote storage, attachments, or local media files.
 */
function kazus_uninstall_purge_durable_state(): void {
	global $wpdb;

	// Encrypted credentials first, before the options/tables that reference them —
	// no storage-provider API calls, no decryption, just removing the encrypted
	// option itself.
	delete_option( 's3ms_profile_credentials' );
	delete_option( 's3ms_encrypted_secret' );

	delete_option( 's3ms_settings' );
	// s3ms_network_settings is written via update_site_option() (wp_sitemeta on
	// multisite), never a regular per-site option — delete_site_option() is the
	// correct call in both multisite and single-site (where it transparently
	// falls back to the regular options table).
	delete_site_option( 's3ms_network_settings' );
	delete_option( 's3ms_schema_version' );
	delete_option( 's3ms_legacy_profile_uuid' );
	delete_option( 's3ms_audit_log' );

	// Plugin-owned custom tables. Explicit, hardcoded names only — never
	// constructed from input, and never any table this plugin does not
	// exclusively own (Action Scheduler's own tables are untouched; Pro's
	// options, e.g. s3ms_orphan_scan_state / s3ms_storage_migration, are a
	// separate add-on's data and are not this uninstall's responsibility).
	$s3ms_objects_table  = $wpdb->prefix . 's3ms_objects';
	$s3ms_profiles_table = $wpdb->prefix . 's3ms_storage_profiles';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- explicit hardcoded plugin-owned table name (not user input); DROP TABLE cannot be parameterized via $wpdb->prepare() placeholders, and a one-time uninstall schema change is exactly what this table's removal requires.
	$wpdb->query( "DROP TABLE IF EXISTS {$s3ms_objects_table}" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- explicit hardcoded plugin-owned table name (not user input); DROP TABLE cannot be parameterized via $wpdb->prepare() placeholders, and a one-time uninstall schema change is exactly what this table's removal requires.
	$wpdb->query( "DROP TABLE IF EXISTS {$s3ms_profiles_table}" );

	// Legacy/compat per-attachment postmeta. This never deletes the
	// attachment post itself, never touches the local file, and never
	// contacts the storage provider — it only removes this plugin's own
	// bookkeeping meta rows.
	$s3ms_meta_keys = array(
		'_s3ms_status',
		'_s3ms_original_key',
		'_s3ms_offloaded_at',
		'_s3ms_last_error',
		'_s3ms_verified_at',
		'_s3ms_ignored',
	);
	foreach ( $s3ms_meta_keys as $s3ms_meta_key ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time uninstall cleanup of this plugin's own postmeta keys, not a request-path query; $wpdb->delete() parameterizes the meta_key value.
		$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $s3ms_meta_key ) );
	}
}

/**
 * Runs both cleanup phases for the CURRENT site (the one $wpdb/get_option()
 * currently point at — the caller is responsible for switch_to_blog() when
 * cleaning up a network-activated install across multiple sites).
 */
function kazus_uninstall_run_for_current_site(): void {
	$settings = get_option( 's3ms_settings', array() );
	$purge    = is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] );

	kazus_uninstall_clear_ephemeral_state();

	if ( $purge ) {
		kazus_uninstall_purge_durable_state();
	}
}

if ( is_multisite() ) {
	// Network-activated installs create the s3ms_objects/s3ms_storage_profiles
	// tables and s3ms_* options per-site (via switch_to_blog()'s table-prefix
	// switch), not once network-wide, so each site's own data must be cleaned
	// up individually — WordPress does not do this automatically for
	// uninstall.php. This only ever touches this plugin's own prefixed
	// tables/options on each site; no other site's unrelated data is read or
	// modified.
	$kazus_site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $kazus_site_ids as $kazus_site_id ) {
		switch_to_blog( (int) $kazus_site_id );
		kazus_uninstall_run_for_current_site();
		restore_current_blog();
	}
} else {
	kazus_uninstall_run_for_current_site();
}
