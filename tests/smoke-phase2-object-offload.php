<?php
/**
 * Phase 2 smoke: object-level offload against MinIO (local dev).
 *
 * Usage (from repo root):
 *   docker compose -f docker-compose.yml -f docker-compose.minio.yml --profile minio up -d minio
 *   docker compose exec -T php wp eval-file wp-content/plugins/kazcode-universal-storage/tests/smoke-phase2-object-offload.php
 *
 * @package Kazcode\WpStorage
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via WP-CLI eval-file inside WordPress.\n" );
	exit( 1 );
}

use Kazcode\WpStorage\Attachment\AttachmentOffloader;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
use Kazcode\WpStorage\Plugin;
use Kazcode\WpStorage\Services\ObjectOffloadService;

$fail = static function ( string $message ): void {
	fwrite( STDERR, "SMOKE FAIL: {$message}\n" );
	exit( 1 );
};

$ok = static function ( string $message ): void {
	echo "SMOKE OK: {$message}\n";
};

global $wpdb;

$plugin   = Plugin::instance();
$settings = $plugin->settings();

$backup = array(
	'settings'          => get_option( 's3ms_settings', array() ),
	'encrypted_secret'  => get_option( 's3ms_encrypted_secret', '' ),
	'profile_row'       => $wpdb->get_row(
		"SELECT * FROM {$wpdb->prefix}s3ms_storage_profiles WHERE is_default_upload_target = 1 LIMIT 1",
		ARRAY_A
	),
);

$restore = static function () use ( $backup ): void {
	global $wpdb;
	if ( is_array( $backup['settings'] ) ) {
		update_option( 's3ms_settings', $backup['settings'], false );
	}
	if ( $backup['encrypted_secret'] !== '' ) {
		update_option( 's3ms_encrypted_secret', $backup['encrypted_secret'], false );
	} else {
		delete_option( 's3ms_encrypted_secret' );
	}
	if ( is_array( $backup['profile_row'] ) && ! empty( $backup['profile_row']['id'] ) ) {
		$id = (int) $backup['profile_row']['id'];
		unset( $backup['profile_row']['id'] );
		$wpdb->update( $wpdb->prefix . 's3ms_storage_profiles', $backup['profile_row'], array( 'id' => $id ) );
	}
	Plugin::instance()->settings()->flush_cache();
};

register_shutdown_function( $restore );

$minio = array(
	'provider_preset'           => 'minio',
	'access_key_id'             => getenv( 'KAZUS_MINIO_ROOT_USER' ) ?: 'minioadmin',
	'region'                    => 'us-east-1',
	'bucket'                    => getenv( 'KAZUS_MINIO_BUCKET' ) ?: 's3ms-test',
	'endpoint'                  => 'http://minio:9000',
	'force_path_style'          => true,
	'object_prefix'             => 'smoke/',
	'enabled'                   => true,
	'serve_from_s3'             => true,
	'delete_local_after_upload' => false,
	'verify_before_delete'      => true,
	'keep_local_files'          => true,
);

$merged = array_merge( is_array( $backup['settings'] ) ? $backup['settings'] : array(), $minio );
update_option( 's3ms_settings', $merged, false );
$settings->flush_cache();
$settings->set_secret_access_key( getenv( 'KAZUS_MINIO_ROOT_PASSWORD' ) ?: 'minioadmin' );

if ( is_array( $backup['profile_row'] ) && ! empty( $backup['profile_row']['id'] ) ) {
	$wpdb->update(
		$wpdb->prefix . 's3ms_storage_profiles',
		array(
			'provider_type' => 'minio',
			'bucket'        => $minio['bucket'],
			'region'        => $minio['region'],
			'endpoint'      => $minio['endpoint'],
			'path_style'    => 1,
			'prefix'        => 'smoke/',
			'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
		),
		array( 'id' => (int) $backup['profile_row']['id'] )
	);
}

update_option( ObjectOffloadService::OPTION_ENABLED, true, false );

$storage = $plugin->storage();
try {
	$storage->client()->createBucket( array( 'Bucket' => $minio['bucket'] ) );
} catch ( Throwable $e ) {
	// Bucket may already exist.
}

try {
	$storage->assert_bucket_exists();
} catch ( Throwable $e ) {
	$fail( 'MinIO bucket not reachable: ' . $e->getMessage() );
}
$ok( 'MinIO bucket reachable' );

$uploads = wp_upload_dir();
$rel     = '2026/08/smoke-' . wp_generate_password( 8, false, false ) . '.png';
$abs     = $uploads['basedir'] . '/' . $rel;
wp_mkdir_p( dirname( $abs ) );
if ( ! copy( ABSPATH . 'wp-admin/images/w-logo-blue.png', $abs ) ) {
	$fail( 'Could not copy test PNG into uploads.' );
}

$attachment_id = wp_insert_attachment(
	array(
		'post_title'     => 'S3MS Phase2 Smoke',
		'post_content'   => '',
		'post_status'    => 'inherit',
		'post_mime_type' => 'image/png',
	),
	$abs
);
if ( ! is_int( $attachment_id ) || $attachment_id <= 0 ) {
	$fail( 'wp_insert_attachment failed.' );
}

require_once ABSPATH . 'wp-admin/includes/image.php';
$meta = wp_generate_attachment_metadata( $attachment_id, $abs );
wp_update_attachment_metadata( $attachment_id, $meta );

$result = $plugin->offloader()->offload( $attachment_id, true );
if ( empty( $result['success'] ) ) {
	$fail( 'Offload failed: ' . ( $result['message'] ?? 'unknown' ) );
}

$status = (string) get_post_meta( $attachment_id, '_s3ms_status', true );
if ( $status !== AttachmentOffloader::STATUS_OFFLOADED ) {
	$fail( "Expected offloaded status, got {$status}" );
}

$rows = ( new ObjectRepository() )->find_by_attachment( $attachment_id );
if ( $rows === array() ) {
	$fail( 'No s3ms_objects rows written.' );
}

foreach ( $rows as $row ) {
	if ( ( $row['remote_status'] ?? '' ) !== ObjectRemoteStatus::PRESENT ) {
		$fail( 'Object row not present: ' . wp_json_encode( $row ) );
	}
	$key  = (string) ( $row['object_key'] ?? '' );
	$head = $storage->head_key( $key );
	if ( empty( $head['exists'] ) ) {
		$fail( "Remote missing for key {$key}" );
	}
}

$bindings_before_rebuild = array();
$remote_before_rebuild   = array();
foreach ( $rows as $row ) {
	$key = (string) ( $row['object_key'] ?? '' );
	$bindings_before_rebuild[] = (string) ( $row['local_relative_path'] ?? '' ) . "\0" . $key;
	$head = $storage->client()->headObject(
		array(
			'Bucket' => $minio['bucket'],
			'Key'    => $key,
		)
	);
	$last_modified = $head['LastModified'] ?? null;
	$remote_before_rebuild[ $key ] = array(
		'etag'          => (string) ( $head['ETag'] ?? '' ),
		'content_length' => (int) ( $head['ContentLength'] ?? 0 ),
		'last_modified'  => $last_modified instanceof DateTimeInterface ? $last_modified->format( DATE_ATOM ) : '',
	);
}
sort( $bindings_before_rebuild );
ksort( $remote_before_rebuild );

$ok( sprintf( 'Offloaded attachment %d with %d object row(s)', $attachment_id, count( $rows ) ) );

// A safe cleanup is part of the real lifecycle: only after each remote HEAD
// above succeeded may the original disappear from the custom uploads root.
if ( is_readable( $abs ) ) {
	$fail( 'Expected guarded cleanup to remove the local original after verified offload.' );
}
$ok( 'Guarded cleanup removed local original only after remote verification' );

// Simulate a lost local inventory table while the exact remote objects remain.
// Rebuild must discover only those exact keys; it must never upload in this path.
$deleted_rows = ( new ObjectRepository() )->delete_by_attachment( $attachment_id );
if ( $deleted_rows !== count( $rows ) ) {
	$fail( sprintf( 'Expected to remove %d inventory row(s), removed %d.', count( $rows ), $deleted_rows ) );
}
$rebuild = $plugin->rebuild_inventory()->rebuild_attachment( $attachment_id, null, false );
if ( empty( $rebuild['success'] ) || ( $rebuild['outcome'] ?? '' ) !== 'complete' ) {
	$fail( 'Inventory rebuild failed: ' . wp_json_encode( $rebuild ) );
}
$rebuilt_rows = ( new ObjectRepository() )->find_by_attachment( $attachment_id );
$bindings_after_rebuild = array();
$remote_after_rebuild   = array();
foreach ( $rebuilt_rows as $row ) {
	$key = (string) ( $row['object_key'] ?? '' );
	$bindings_after_rebuild[] = (string) ( $row['local_relative_path'] ?? '' ) . "\0" . $key;
	$head = $storage->client()->headObject(
		array(
			'Bucket' => $minio['bucket'],
			'Key'    => $key,
		)
	);
	$last_modified = $head['LastModified'] ?? null;
	$remote_after_rebuild[ $key ] = array(
		'etag'          => (string) ( $head['ETag'] ?? '' ),
		'content_length' => (int) ( $head['ContentLength'] ?? 0 ),
		'last_modified'  => $last_modified instanceof DateTimeInterface ? $last_modified->format( DATE_ATOM ) : '',
	);
}
sort( $bindings_after_rebuild );
ksort( $remote_after_rebuild );
if ( $bindings_after_rebuild !== $bindings_before_rebuild ) {
	$fail( 'Rebuild changed the exact local-relative/object-key inventory bindings.' );
}
if ( $remote_after_rebuild !== $remote_before_rebuild ) {
	$fail( 'Rebuild altered a remote object instead of observing it.' );
}
$ok( 'Rebuild inventory adopted exact remote objects without altering remote bytes' );

// The original is cloud-only at this point, so this is a real download and
// inventory-clear path rather than merely a control-plane restore.
$restore_result = $plugin->restorer()->restore( $attachment_id );
if ( empty( $restore_result['success'] ) ) {
	$fail( 'Restore failed: ' . ( $restore_result['message'] ?? 'unknown' ) );
}
if ( metadata_exists( 'post', $attachment_id, '_s3ms_status' ) ) {
	$fail( 'Expected _s3ms_status cleared after restore, got ' . (string) get_post_meta( $attachment_id, '_s3ms_status', true ) );
}
$rows_after = ( new ObjectRepository() )->find_by_attachment( $attachment_id );
if ( $rows_after !== array() ) {
	$fail( 'Object rows still present after restore.' );
}
if ( ! is_readable( $abs ) ) {
	$fail( 'Local original missing after restore.' );
}
$ok( 'Restore cleared inventory and kept local file' );

$keys = array_map(
	static fn( array $row ): string => (string) ( $row['object_key'] ?? '' ),
	$rows
);
$storage->delete_keys( array_values( array_filter( $keys ) ) );
wp_delete_attachment( $attachment_id, true );

$ok( 'Phase 2 object offload + restore smoke complete (settings restored on exit)' );
exit( 0 );
