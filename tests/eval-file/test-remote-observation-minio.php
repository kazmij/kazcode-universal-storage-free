<?php
/**
 * Real MinIO smoke for remote observation semantics.
 *
 * @package Kazcode\WpStorage
 */

use Kazcode\WpStorage\Domain\RemoteObservation;
use Kazcode\WpStorage\Plugin;

defined( 'ABSPATH' ) || exit;

$plugin   = Plugin::instance();
$settings = $plugin->settings();
$backup   = array(
	'settings'         => get_option( 's3ms_settings', array() ),
	'encrypted_secret' => get_option( 's3ms_encrypted_secret', '' ),
);

$restore = static function () use ( $settings, $backup ): void {
	update_option( 's3ms_settings', $backup['settings'], false );
	if ( $backup['encrypted_secret'] !== '' ) {
		update_option( 's3ms_encrypted_secret', $backup['encrypted_secret'], false );
	} else {
		delete_option( 's3ms_encrypted_secret' );
	}
	$settings->flush_cache();
};

register_shutdown_function( $restore );

$minio = array(
	'provider_preset'           => 'minio',
	'access_key_id'             => getenv( 'KAZUS_MINIO_ROOT_USER' ) ?: 'minioadmin',
	'region'                    => 'us-east-1',
	'bucket'                    => getenv( 'KAZUS_MINIO_BUCKET' ) ?: 's3ms-test',
	'endpoint'                  => 'http://minio:9000',
	'force_path_style'          => true,
	'object_prefix'             => 'observation-smoke/',
	'enabled'                   => true,
	'serve_from_s3'             => true,
	'delete_local_after_upload' => false,
	'verify_before_delete'      => true,
);

update_option( 's3ms_settings', array_merge( is_array( $backup['settings'] ) ? $backup['settings'] : array(), $minio ), false );
$settings->flush_cache();
$settings->set_secret_access_key( getenv( 'KAZUS_MINIO_ROOT_PASSWORD' ) ?: 'minioadmin' );

$storage = $plugin->storage();
try {
	$storage->client()->createBucket( array( 'Bucket' => $minio['bucket'] ) );
} catch ( Throwable $e ) {
	// Bucket may already exist.
}

$local = wp_tempnam( 'kazus-observation-' );
if ( ! is_string( $local ) ) {
	throw new RuntimeException( 'Could not create temp file.' );
}
file_put_contents( $local, str_repeat( 'm', 1000 ) );

$present_key = 'observation-smoke/present-' . wp_generate_uuid4() . '.bin';
$missing_key = 'observation-smoke/missing-' . wp_generate_uuid4() . '.bin';

$storage->upload_file_to_key( $local, $present_key, basename( $present_key ) );

$present = RemoteObservation::from_head_result( $storage->head_key( $present_key ), 1000 );
$mismatch = RemoteObservation::from_head_result( $storage->head_key( $present_key ), 900 );
$missing = RemoteObservation::from_head_result( $storage->head_key( $missing_key ) );

$storage->delete_key( $present_key );
@unlink( $local );

printf( "PRESENT=%s\n", $present->status );
printf( "PRESENT_LEVEL=%s\n", $present->verification_level );
printf( "MISMATCH_LEVEL=%s\n", $mismatch->verification_level );
printf( "MISSING=%s\n", $missing->status );
printf( "MISSING_CONFIRMED=%s\n", $missing->is_confirmed_missing() ? 'YES' : 'NO' );

if ( $present->status !== RemoteObservation::REMOTE_PRESENT || $present->verification_level !== RemoteObservation::SIZE_VERIFIED ) {
	throw new RuntimeException( 'MinIO present object was not size verified.' );
}
if ( $mismatch->verification_level !== RemoteObservation::SIZE_MISMATCH ) {
	throw new RuntimeException( 'MinIO size mismatch was not detected.' );
}
if ( ! $missing->is_confirmed_missing() ) {
	throw new RuntimeException( 'MinIO missing key was not confirmed missing.' );
}

echo "REMOTE_OBSERVATION_MINIO=PASS\n";
