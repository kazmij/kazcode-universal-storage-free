<?php
/**
 * Characterization for remote-only media through WordPress REST context=edit.
 *
 * Usage:
 *   wp --exec='define("WP_DEBUG", true); define("WP_DEBUG_LOG", true); define("WP_DEBUG_DISPLAY", false);' eval-file wp-content/plugins/kazcode-universal-storage/tests/eval-file/test-remote-only-rest-context-edit.php
 *
 * @package Kazcode\WpStorage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use Kazcode\WpStorage\Attachment\AttachmentOffloader;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\LocalStoragePolicy;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Plugin;
use Kazcode\WpStorage\Services\ObjectOffloadService;

$errors   = array();
$warnings = array();
$notes    = array();

$fail = static function ( string $message ) use ( &$errors ): void {
	$errors[] = $message;
	echo "[FAIL] {$message}\n";
};

$ok = static function ( string $message ): void {
	echo "[OK] {$message}\n";
};

$note = static function ( string $message ) use ( &$notes ): void {
	$notes[] = $message;
	echo "[NOTE] {$message}\n";
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( empty( $admin[0] ) ) {
	fwrite( STDERR, "NO_ADMIN\n" );
	exit( 1 );
}
wp_set_current_user( (int) $admin[0]->ID );

if ( ! extension_loaded( 'gd' ) ) {
	fwrite( STDERR, "GD_UNAVAILABLE\n" );
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

$plugin            = Plugin::instance();
$settings_service  = $plugin->settings();
$previous_settings = get_option( Settings::OPTION_KEY, false );
$previous_object_offload_enabled = get_option( ObjectOffloadService::OPTION_ENABLED, false );
$upload            = wp_upload_dir();
$keep_fixture      = getenv( 'KAZUS_REST_KEEP_FIXTURE' ) === '1';
global $wpdb;
$profiles_table = $wpdb->prefix . 's3ms_storage_profiles';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only backup of plugin-owned table row.
$profile_backup = $wpdb->get_row( "SELECT * FROM {$profiles_table} WHERE is_default_upload_target = 1 ORDER BY id ASC LIMIT 1", ARRAY_A );
if ( ! empty( $upload['error'] ) ) {
	fwrite( STDERR, 'UPLOAD_DIR_ERROR=' . $upload['error'] . "\n" );
	exit( 1 );
}

$attachment_id = 0;
$created_paths = array();

$cleanup = static function () use ( &$attachment_id, &$created_paths, $previous_settings, $previous_object_offload_enabled, $profile_backup, $profiles_table, $settings_service, $wpdb, $keep_fixture ): void {
	if ( $keep_fixture ) {
		return;
	}
	foreach ( array_unique( $created_paths ) as $path ) {
		if ( is_string( $path ) && $path !== '' && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}
	if ( $attachment_id > 0 ) {
		wp_delete_attachment( $attachment_id, true );
	}
	if ( $previous_settings === false ) {
		delete_option( Settings::OPTION_KEY );
	} else {
		update_option( Settings::OPTION_KEY, $previous_settings, false );
	}
	if ( $previous_object_offload_enabled === false ) {
		delete_option( ObjectOffloadService::OPTION_ENABLED );
	} else {
		update_option( ObjectOffloadService::OPTION_ENABLED, $previous_object_offload_enabled, false );
	}
	if ( is_array( $profile_backup ) && ! empty( $profile_backup['id'] ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- test-only restore of plugin-owned table row.
		$wpdb->update( $profiles_table, $profile_backup, array( 'id' => (int) $profile_backup['id'] ) );
	}
	$settings_service->flush_cache();
};
register_shutdown_function( $cleanup );
update_option( ObjectOffloadService::OPTION_ENABLED, false, false );
update_option( Settings::OPTION_KEY, array_merge( Settings::defaults(), array( 'enabled' => false ) ), false );
$settings_service->flush_cache();

$filename = 'kazus remote-only rest large (' . gmdate( 'YmdHis' ) . ').jpg';
$path     = trailingslashit( $upload['path'] ) . $filename;

$image = imagecreatetruecolor( 3200, 2400 );
if ( false === $image ) {
	fwrite( STDERR, "IMAGE_CREATE_FAILED\n" );
	exit( 1 );
}
$bg = imagecolorallocate( $image, 230, 236, 242 );
$fg = imagecolorallocate( $image, 35, 68, 112 );
imagefilledrectangle( $image, 0, 0, 3199, 2399, $bg );
imagefilledrectangle( $image, 320, 300, 2880, 2100, $fg );
if ( ! imagejpeg( $image, $path, 90 ) ) {
	imagedestroy( $image );
	fwrite( STDERR, "IMAGE_WRITE_FAILED\n" );
	exit( 1 );
}
imagedestroy( $image );
$created_paths[] = $path;

$filetype = wp_check_filetype( $path );
$attachment_id = wp_insert_attachment(
	array(
		'post_mime_type' => $filetype['type'] ?: 'image/jpeg',
		'post_title'     => 'KAZUS remote-only REST large image',
		'post_content'   => '',
		'post_status'    => 'inherit',
	),
	$path
);
if ( is_wp_error( $attachment_id ) || $attachment_id <= 0 ) {
	fwrite( STDERR, "ATTACHMENT_CREATE_FAILED\n" );
	exit( 1 );
}

$meta = wp_generate_attachment_metadata( $attachment_id, $path );
wp_update_attachment_metadata( $attachment_id, $meta );
$attached = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
$meta     = wp_get_attachment_metadata( $attachment_id );

if ( empty( $meta['original_image'] ) ) {
	$fail( 'original_image metadata was not generated; big-image path not exercised' );
} else {
	$ok( 'original_image metadata generated: ' . (string) $meta['original_image'] );
}

$minio = array(
	'provider_preset'           => 'minio',
	'access_key_id'             => getenv( 'KAZUS_MINIO_ROOT_USER' ) ?: 'minioadmin',
	'region'                    => 'us-east-1',
	'bucket'                    => getenv( 'KAZUS_MINIO_BUCKET' ) ?: 's3ms-test',
	'endpoint'                  => 'http://minio:9000',
	'force_path_style'          => true,
	'object_prefix'             => 'remote-only-rest/',
	'enabled'                   => true,
	'serve_from_s3'             => true,
	'delete_local_after_upload' => false,
	'verify_before_delete'      => true,
	'keep_local_files'          => true,
	'local_storage_policy'      => LocalStoragePolicy::KEEP_ALL,
);

$settings = array_merge(
	Settings::defaults(),
	is_array( $previous_settings ) ? $previous_settings : array(),
	$minio
);
update_option( Settings::OPTION_KEY, $settings, false );
$settings_service->flush_cache();
$settings_service->set_secret_access_key( getenv( 'KAZUS_MINIO_ROOT_PASSWORD' ) ?: 'minioadmin' );
update_option( ObjectOffloadService::OPTION_ENABLED, true, false );

if ( is_array( $profile_backup ) && ! empty( $profile_backup['id'] ) ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- test-only setup of plugin-owned table row.
	$wpdb->update(
		$profiles_table,
		array(
			'provider_type' => 'minio',
			'bucket'        => $minio['bucket'],
			'region'        => $minio['region'],
			'endpoint'      => $minio['endpoint'],
			'path_style'    => 1,
			'prefix'        => 'remote-only-rest/',
			'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
		),
		array( 'id' => (int) $profile_backup['id'] )
	);
}

$storage = $plugin->storage();
try {
	$storage->client()->createBucket( array( 'Bucket' => $minio['bucket'] ) );
} catch ( Throwable $e ) {
	// Bucket may already exist in the disposable MinIO container.
}
try {
	$storage->assert_bucket_exists();
	$ok( 'MinIO bucket reachable' );
} catch ( Throwable $e ) {
	$fail( 'MinIO bucket not reachable for remote-only REST test' );
}

$offload_result = $plugin->offloader()->offload( $attachment_id, false, $meta );
if ( empty( $offload_result['success'] ) ) {
	$fail( 'Offload to MinIO failed: ' . (string) ( $offload_result['message'] ?? 'unknown' ) );
}
if ( (string) get_post_meta( $attachment_id, '_s3ms_status', true ) !== AttachmentOffloader::STATUS_OFFLOADED ) {
	$fail( 'Attachment not marked offloaded after MinIO upload' );
}
$rows = ( new ObjectRepository() )->find_by_attachment( $attachment_id );
if ( $rows === array() ) {
	$fail( 'No s3ms_objects rows after MinIO offload' );
}
foreach ( $rows as $row ) {
	if ( (string) ( $row['remote_status'] ?? '' ) !== ObjectRemoteStatus::PRESENT ) {
		$fail( 'Object row not PRESENT after offload: ' . (string) ( $row['local_relative_path'] ?? '' ) );
	}
}
$ok( 'remote objects and inventory created: ' . count( $rows ) );

$settings['local_storage_policy']      = LocalStoragePolicy::REMOTE_ONLY;
$settings['delete_local_after_upload'] = true;
$settings['keep_local_files']          = false;
update_option( Settings::OPTION_KEY, $settings, false );
$settings_service->flush_cache();

$relative_paths = array( $attached );
if ( is_array( $meta ) && ! empty( $meta['original_image'] ) ) {
	$relative_paths[] = trailingslashit( dirname( $attached ) ) . (string) $meta['original_image'];
}
if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
	foreach ( $meta['sizes'] as $size ) {
		if ( is_array( $size ) && ! empty( $size['file'] ) ) {
			$relative_paths[] = trailingslashit( dirname( $attached ) ) . (string) $size['file'];
		}
	}
}

foreach ( array_unique( $relative_paths ) as $relative ) {
	$absolute = trailingslashit( $upload['basedir'] ) . ltrim( $relative, '/' );
	$created_paths[] = $absolute;
	if ( is_file( $absolute ) ) {
		wp_delete_file( $absolute );
	}
	if ( is_file( $absolute ) ) {
		$fail( 'remote-only setup failed; local file still exists: ' . $relative );
	}
}
$ok( 'local files removed for remote-only state' );

$log_file     = WP_CONTENT_DIR . '/debug.log';
$log_position = is_file( $log_file ) ? (int) filesize( $log_file ) : 0;

$run_rest = static function ( string $label, string $route, array $params, int $user_id ) use ( $log_file, &$log_position ): array {
	$scenario_warnings = array();
	$handler = static function ( int $errno, string $errstr, string $errfile, int $errline ) use ( &$scenario_warnings ): bool {
		if ( preg_match( '/exif_imagetype|file_get_contents|getimagesize|wp_getimagesize|failed to open stream|No such file|Permission denied|Undefined|Deprecated|Warning|Fatal/i', $errstr ) ) {
			$scenario_warnings[] = "{$errstr} @ {$errfile}:{$errline}";
		}
		return false;
	};

	wp_set_current_user( $user_id );
	set_error_handler( $handler );
	$request = new WP_REST_Request( 'GET', $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( (string) $key, $value );
	}
	$response = rest_do_request( $request );
	restore_error_handler();

	if ( is_file( $log_file ) ) {
		$fh = fopen( $log_file, 'rb' );
		if ( is_resource( $fh ) ) {
			fseek( $fh, $log_position );
			$tail = stream_get_contents( $fh );
			$size = filesize( $log_file );
			$log_position = is_int( $size ) ? $size : $log_position;
			fclose( $fh );
			if ( is_string( $tail ) && preg_match_all( '/.*(?:exif_imagetype|file_get_contents|getimagesize|wp_getimagesize|failed to open stream|No such file|Permission denied|Undefined|Deprecated|Warning|Fatal).*/i', $tail, $matches ) ) {
				foreach ( $matches[0] as $line ) {
					$scenario_warnings[] = 'debug.log: ' . $line;
				}
			}
		}
	}

	return array(
		'label'    => $label,
		'status'   => (int) $response->get_status(),
		'data'     => $response->get_data(),
		'warnings' => $scenario_warnings,
	);
};

$admin_id = get_current_user_id();
$primary  = $run_rest( 'edit_context', '/wp/v2/media/' . $attachment_id, array( 'context' => 'edit' ), $admin_id );
$warnings = array_merge( $warnings, $primary['warnings'] );

$status = (int) $primary['status'];
$data   = $primary['data'];
if ( $status >= 200 && $status < 300 && is_array( $data ) && ! empty( $data['id'] ) ) {
	$ok( 'REST context=edit returned HTTP ' . $status );
} else {
	$fail( 'REST context=edit failed with HTTP ' . $status );
}

if ( is_array( $data ) && ! empty( $data['media_details'] ) && array_key_exists( 'missing_image_sizes', $data ) ) {
	$ok( 'REST edit payload included media_details and missing_image_sizes' );
} else {
	$fail( 'REST edit payload missing expected media fields' );
}

if ( ! is_file( $log_file ) ) {
	$note( 'debug.log absent after request' );
}

foreach ( $warnings as $warning ) {
	$fail( 'PHP warning during REST edit: ' . $warning );
}
if ( empty( $warnings ) ) {
	$ok( 'REST edit emitted no matching PHP warnings' );
}

$persisted_after = array();
foreach ( array_unique( $relative_paths ) as $relative ) {
	$absolute = trailingslashit( $upload['basedir'] ) . ltrim( $relative, '/' );
	if ( is_file( $absolute ) ) {
		$persisted_after[] = $relative;
	}
}
if ( empty( $persisted_after ) ) {
	$ok( 'REST edit left no local files behind in remote-only policy' );
} else {
	$fail( 'REST edit left local files behind: ' . implode( ', ', $persisted_after ) );
}

$secondary_specs = array(
	array( 'default_context', '/wp/v2/media/' . $attachment_id, array(), true ),
	array( 'view_context', '/wp/v2/media/' . $attachment_id, array( 'context' => 'view' ), true ),
	array( 'collection_edit_context', '/wp/v2/media', array( 'context' => 'edit', 'include' => array( $attachment_id ), 'per_page' => 1 ), true ),
	array( 'unauthorized_edit_context', '/wp/v2/media/' . $attachment_id, array( 'context' => 'edit' ), false ),
);

foreach ( $secondary_specs as $spec ) {
	$scenario_user = $spec[3] ? $admin_id : 0;
	$result        = $run_rest( $spec[0], $spec[1], $spec[2], $scenario_user );
	$scenario_name = (string) $result['label'];
	$scenario_code = (int) $result['status'];
	if ( $spec[3] && $scenario_code >= 200 && $scenario_code < 300 ) {
		$ok( "REST {$scenario_name} returned HTTP {$scenario_code}" );
	} elseif ( ! $spec[3] && in_array( $scenario_code, array( 401, 403 ), true ) ) {
		$ok( "REST {$scenario_name} blocked with HTTP {$scenario_code}" );
	} else {
		$fail( "REST {$scenario_name} unexpected HTTP {$scenario_code}" );
	}
	foreach ( $result['warnings'] as $warning ) {
		$fail( "PHP warning during REST {$scenario_name}: {$warning}" );
	}
}

wp_set_current_user( $admin_id );

echo "----\n";
echo 'ATTACHMENT_ID=' . $attachment_id . "\n";
echo 'IMAGE_TYPE=jpeg' . "\n";
echo 'BIG_IMAGE=' . ( empty( $meta['original_image'] ) ? 'NO' : 'YES' ) . "\n";
echo 'REMOTE_ONLY=YES' . "\n";
echo 'HTTP_STATUS=' . $status . "\n";
echo 'WARNINGS=' . count( $warnings ) . "\n";
echo 'ERRORS=' . count( $errors ) . "\n";
echo 'KEEP_FIXTURE=' . ( $keep_fixture ? 'YES' : 'NO' ) . "\n";

if ( $errors ) {
	echo "REMOTE_ONLY_REST_CONTEXT_EDIT_FAILED\n";
	exit( 1 );
}

echo "REMOTE_ONLY_REST_CONTEXT_EDIT_PASSED\n";
