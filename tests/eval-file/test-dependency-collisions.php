<?php
/**
 * Built-ZIP dependency isolation smoke test.
 *
 * Run from a disposable WordPress with the built ZIP installed:
 * wp eval-file /tmp/test-dependency-collisions.php --allow-root
 *
 * @package Kazcode\WpStorage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\LocalStoragePolicy;
use Kazcode\WpStorage\Domain\RemoteObservation;
use Kazcode\WpStorage\Plugin;

$errors  = array();
$results = array();

$check = static function ( string $name, bool $ok, string $detail = '' ) use ( &$errors, &$results ): void {
	$results[ $name ] = array(
		'status' => $ok ? 'PASS' : 'FAIL',
		'detail' => $detail,
	);
	if ( ! $ok ) {
		$errors[] = $name . ( $detail !== '' ? ': ' . $detail : '' );
	}
};

$class_name = static function ( string $class ): string {
	if ( ! class_exists( $class ) ) {
		return 'MISSING';
	}
	return ( new ReflectionClass( $class ) )->getName();
};

$foreign_autoload = getenv( 'KAZUS_FOREIGN_VENDOR_AUTOLOAD' ) ?: '/tmp/kazus-foreign-vendor/autoload.php';
if ( is_readable( $foreign_autoload ) ) {
	require_once $foreign_autoload;
}

$check( 'foreign_guzzle_loaded', class_exists( 'GuzzleHttp\\Client' ), $class_name( 'GuzzleHttp\\Client' ) );
$check( 'foreign_aws_loaded', class_exists( 'Aws\\S3\\S3Client' ), $class_name( 'Aws\\S3\\S3Client' ) );
$check( 'scoped_guzzle_loaded', class_exists( 'Kazcode\\WpStorage\\Vendor\\GuzzleHttp\\Client' ), $class_name( 'Kazcode\\WpStorage\\Vendor\\GuzzleHttp\\Client' ) );
$check( 'scoped_aws_loaded', class_exists( 'Kazcode\\WpStorage\\Vendor\\Aws\\S3\\S3Client' ), $class_name( 'Kazcode\\WpStorage\\Vendor\\Aws\\S3\\S3Client' ) );
$check( 'scoped_psr_loaded', interface_exists( 'Kazcode\\WpStorage\\Vendor\\Psr\\Http\\Message\\RequestInterface' ), 'scoped PSR request interface' );

$settings_service = Plugin::instance()->settings();
$previous_settings = get_option( Settings::OPTION_KEY, false );
$settings = array_merge(
	Settings::defaults(),
	is_array( $previous_settings ) ? $previous_settings : array(),
	array(
		'provider_preset'           => 'minio',
		'access_key_id'             => getenv( 'KAZUS_MINIO_ROOT_USER' ) ?: 'minioadmin',
		'region'                    => 'us-east-1',
		'bucket'                    => getenv( 'KAZUS_MINIO_BUCKET' ) ?: 'kazus-manual-test',
		'endpoint'                  => 'http://minio:9000',
		'force_path_style'          => true,
		'object_prefix'             => 'dependency-collision/',
		'enabled'                   => true,
		'serve_from_s3'             => true,
		'delete_local_after_upload' => false,
		'verify_before_delete'      => true,
		'keep_local_files'          => true,
		'local_storage_policy'      => LocalStoragePolicy::KEEP_ALL,
	)
);
update_option( Settings::OPTION_KEY, $settings, false );
$settings_service->flush_cache();
$settings_service->set_secret_access_key( getenv( 'KAZUS_MINIO_ROOT_PASSWORD' ) ?: 'minioadmin' );

$storage = Plugin::instance()->storage();
$client  = $storage->client();
$client_class = ( new ReflectionClass( $client ) )->getName();
$check( 'kazcode_client_is_scoped', $client_class === 'Kazcode\\WpStorage\\Vendor\\Aws\\S3\\S3Client', $client_class );

$bucket = (string) $settings['bucket'];
$key    = 'dependency-collision/' . gmdate( 'YmdHis' ) . '-collision.txt';
$body   = 'kazus dependency collision smoke';

try {
	$client->createBucket( array( 'Bucket' => $bucket ) );
} catch ( Throwable $e ) {
	// The disposable MinIO bucket normally already exists.
}

try {
	$client->putObject(
		array(
			'Bucket' => $bucket,
			'Key'    => $key,
			'Body'   => $body,
		)
	);
	$head = $storage->head_key( $key );
	$check(
		'aws_success_path',
		! empty( $head['exists'] ) && (int) ( $head['content_length'] ?? 0 ) === strlen( $body ),
		wp_json_encode( $head )
	);
} catch ( Throwable $e ) {
	$check( 'aws_success_path', false, get_class( $e ) . ': ' . $e->getMessage() );
}

$missing = $storage->head_key( $key . '.missing' );
$check(
	'aws_missing_object_exception',
	empty( $missing['exists'] ) && ! empty( $missing['confirmed_missing'] ) && ( $missing['remote_status'] ?? '' ) === RemoteObservation::REMOTE_CONFIRMED_MISSING,
	wp_json_encode( $missing )
);

try {
	$signed = $storage->presigned_url_for_key( $key, 300 );
	$parts  = wp_parse_url( $signed );
	$check(
		'sigv4_signed_url',
		is_array( $parts ) && ! empty( $parts['host'] ) && str_contains( $signed, 'X-Amz-Signature=' ),
		is_array( $parts ) ? (string) ( $parts['host'] ?? '' ) . (string) ( $parts['path'] ?? '' ) : 'parse_failed'
	);
} catch ( Throwable $e ) {
	$check( 'sigv4_signed_url', false, get_class( $e ) . ': ' . $e->getMessage() );
}

try {
	$storage->delete_key( $key );
	$check( 'aws_delete_path', true );
} catch ( Throwable $e ) {
	$check( 'aws_delete_path', false, get_class( $e ) . ': ' . $e->getMessage() );
}

global $wpdb;
$serialized_vendor_hits  = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE '%Kazcode\\\\WpStorage\\\\Vendor\\\\%' OR option_value LIKE '%Aws\\\\S3\\\\S3Client%'"
);
$serialized_vendor_hits += (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE '%Kazcode\\\\WpStorage\\\\Vendor\\\\%' OR meta_value LIKE '%Aws\\\\S3\\\\S3Client%'"
);
$check( 'no_serialized_vendor_objects', $serialized_vendor_hits === 0, 'hits=' . $serialized_vendor_hits );

echo wp_json_encode(
	array(
		'foreign_autoload' => is_readable( $foreign_autoload ) ? 'LOADED' : 'NOT_READABLE',
		'client_class'     => $client_class,
		'results'          => $results,
		'errors'           => $errors,
	),
	JSON_PRETTY_PRINT
) . PHP_EOL;

if ( $errors !== array() ) {
	exit( 1 );
}
