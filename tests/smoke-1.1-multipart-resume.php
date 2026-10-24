<?php
/**
 * Real MinIO multipart crash/resume smoke.
 *
 * Usage (from the WordPress Compose stack):
 *   docker compose exec -T php wp eval-file wp-content/plugins/kazcode-universal-storage/tests/smoke-1.1-multipart-resume.php
 *
 * @package Kazcode\WpStorage
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via WP-CLI eval-file inside WordPress.\n" );
	exit( 1 );
}

use Kazcode\WpStorage\Core\Module\ModuleRegistry;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Domain\TransferSessionStatus;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\TransferSessionRepository;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
use Kazcode\WpStorage\Plugin;
use Kazcode\WpStorage\Pro\Services\MigrateObjectService;
use Kazcode\WpStorage\Services\LegacyProfileMigrator;
use Kazcode\WpStorage\Services\ResumableTransferService;
use Kazcode\WpStorage\Storage\ObjectKeyService;
use Kazcode\WpStorage\Storage\ProfileStorageGateway;
use Kazcode\WpStorage\Storage\StorageGatewayInterface;

$fail = static function ( string $message ): void {
	fwrite( STDERR, "SMOKE FAIL: {$message}\n" );
	exit( 1 );
};

$ok = static function ( string $message ): void {
	echo "SMOKE OK: {$message}\n";
};

/**
 * Records concrete gateway calls while delegating each S3 operation to MinIO.
 */
final class KazusMultipartResumeSmokeGateway implements StorageGatewayInterface {
	/** @var array<string,mixed> */
	private array $calls;

	/** @param array<string,mixed> $calls */
	public function __construct( private StorageGatewayInterface $inner, array &$calls ) {
		$this->calls =& $calls;
	}

	public function profile_id(): int {
		return $this->inner->profile_id();
	}

	public function bucket(): string {
		return $this->inner->bucket();
	}

	public function head_key( string $key ): array {
		$this->calls['head'][] = $key;
		return $this->inner->head_key( $key );
	}

	public function put_object_from_file( string $key, string $local_path, string $content_type, string $cache_control ): void {
		$this->calls['put'][] = $key;
		$this->inner->put_object_from_file( $key, $local_path, $content_type, $cache_control );
	}

	public function create_multipart_upload( string $key, string $content_type, string $cache_control ): string {
		$this->calls['create'][] = $key;
		return $this->inner->create_multipart_upload( $key, $content_type, $cache_control );
	}

	public function upload_part( string $key, string $upload_id, int $part_number, $body, int $content_length ): string {
		$this->calls['upload_part'][] = $part_number;
		return $this->inner->upload_part( $key, $upload_id, $part_number, $body, $content_length );
	}

	public function list_parts( string $key, string $upload_id ): array {
		$this->calls['list_parts'][] = array( $key, $upload_id );
		return $this->inner->list_parts( $key, $upload_id );
	}

	public function complete_multipart_upload( string $key, string $upload_id, array $parts ): void {
		$this->calls['complete'][] = $key;
		$this->inner->complete_multipart_upload( $key, $upload_id, $parts );
	}

	public function abort_multipart_upload( string $key, string $upload_id ): void {
		$this->calls['abort'][] = $key;
		$this->inner->abort_multipart_upload( $key, $upload_id );
	}

	public function get_range( string $key, int $start, int $end ) {
		$this->calls['get_range'][] = array( $key, $start, $end );
		return $this->inner->get_range( $key, $start, $end );
	}
}

global $wpdb;

$plugin    = Plugin::instance();
$settings  = $plugin->settings();
$profiles  = new WpdbStorageProfileRepository();
$sessions  = new TransferSessionRepository();
$objects   = new ObjectRepository();
$run_id    = wp_generate_uuid4();
$bucket_a  = getenv( 'KAZUS_MINIO_BUCKET' ) ?: 's3ms-test';
$bucket_b  = $bucket_a . '-multipart-resume';
$prefix    = 'smoke-1.1-multipart/' . $run_id . '/';
$upload_rel = '2026/09/multipart-resume-' . $run_id . '.bin';
$upload_abs = trailingslashit( wp_upload_dir()['basedir'] ) . $upload_rel;

$backup = array(
	'settings'         => get_option( 's3ms_settings', array() ),
	'encrypted_secret' => get_option( 's3ms_encrypted_secret', '' ),
	'profile_row'      => $wpdb->get_row(
		"SELECT * FROM {$wpdb->prefix}s3ms_storage_profiles WHERE is_default_upload_target = 1 LIMIT 1",
		ARRAY_A
	),
);
$created_profile_ids = array();
$created_object_ids  = array();
$remote_keys         = array();
$session_uuids       = array();

$restore = static function () use ( $backup, &$created_profile_ids, &$created_object_ids, &$remote_keys, &$session_uuids, $settings, $prefix, $upload_abs ): void {
	global $wpdb;

	// Remove remote artifacts while the temporary profiles still point at MinIO.
	try {
		foreach ( $remote_keys as $entry ) {
			$profile = ( new WpdbStorageProfileRepository() )->find( (int) $entry['profile_id'] );
			if ( $profile !== null ) {
				( new ProfileStorageGateway( $profile, $settings ) )->delete_key( (string) $entry['key'] );
			}
		}
	} catch ( Throwable $e ) {
		fwrite( STDERR, "SMOKE cleanup warning: {$e->getMessage()}\n" );
	}

	foreach ( $created_object_ids as $object_id ) {
		$wpdb->delete( $wpdb->prefix . 's3ms_objects', array( 'id' => $object_id ) );
	}
	foreach ( $created_profile_ids as $profile_id ) {
		$wpdb->delete( $wpdb->prefix . 's3ms_objects', array( 'storage_profile_id' => $profile_id ) );
		$wpdb->delete( $wpdb->prefix . 's3ms_storage_profiles', array( 'id' => $profile_id ) );
	}
	foreach ( $session_uuids as $uuid ) {
		$wpdb->delete( $wpdb->prefix . 's3ms_transfer_sessions', array( 'uuid' => $uuid ) );
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}s3ms_transfer_sessions WHERE destination_key LIKE %s", $prefix . '%' ) );

	if ( is_array( $backup['profile_row'] ) && ! empty( $backup['profile_row']['id'] ) ) {
		$id = (int) $backup['profile_row']['id'];
		$row = $backup['profile_row'];
		unset( $row['id'] );
		$wpdb->update( $wpdb->prefix . 's3ms_storage_profiles', $row, array( 'id' => $id ) );
	}
	if ( is_array( $backup['settings'] ) ) {
		update_option( 's3ms_settings', $backup['settings'], false );
	}
	if ( $backup['encrypted_secret'] !== '' ) {
		update_option( 's3ms_encrypted_secret', $backup['encrypted_secret'], false );
	} else {
		delete_option( 's3ms_encrypted_secret' );
	}
	$settings->flush_cache();
	if ( is_file( $upload_abs ) ) {
		wp_delete_file( $upload_abs );
	}
};
register_shutdown_function( $restore );

$minio_settings = array_merge(
	is_array( $backup['settings'] ) ? $backup['settings'] : array(),
	array(
		'provider_preset' => 'minio',
		'access_key_id'    => getenv( 'KAZUS_MINIO_ROOT_USER' ) ?: 'minioadmin',
		'region'            => 'us-east-1',
		'bucket'            => $bucket_a,
		'endpoint'          => 'http://minio:9000',
		'force_path_style'  => true,
		'enabled'           => true,
	)
);
update_option( 's3ms_settings', $minio_settings, false );
$settings->flush_cache();
$settings->set_secret_access_key( getenv( 'KAZUS_MINIO_ROOT_PASSWORD' ) ?: 'minioadmin' );

if ( ! is_array( $backup['profile_row'] ) || empty( $backup['profile_row']['id'] ) ) {
	$fail( 'Default storage profile is unavailable.' );
}

$source_profile_id = (int) $backup['profile_row']['id'];
$wpdb->update(
	$wpdb->prefix . 's3ms_storage_profiles',
	array(
		'provider_type'   => 'minio',
		'bucket'          => $bucket_a,
		'region'          => 'us-east-1',
		'endpoint'        => 'http://minio:9000',
		'path_style'      => 1,
		'prefix'          => $prefix . 'source/',
		'credential_mode' => 'keys',
		'credentials_ref' => LegacyProfileMigrator::CREDENTIALS_REF,
		'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
	),
	array( 'id' => $source_profile_id )
);

$source_profile = $profiles->find( $source_profile_id );
if ( $source_profile === null ) {
	$fail( 'Could not configure source MinIO profile.' );
}

$destination_profile_id = $profiles->insert(
	new StorageProfile(
		null,
		wp_generate_uuid4(),
		'Multipart resume smoke destination',
		'minio',
		$bucket_b,
		'us-east-1',
		'http://minio:9000',
		true,
		$prefix . 'destination/',
		'storage',
		'',
		false,
		'keys',
		LegacyProfileMigrator::CREDENTIALS_REF,
		false,
		false,
		false,
		gmdate( 'Y-m-d H:i:s' ),
		gmdate( 'Y-m-d H:i:s' )
	)
);
$created_profile_ids[] = $destination_profile_id;
$destination_profile   = $profiles->find( $destination_profile_id );
if ( $destination_profile === null ) {
	$fail( 'Could not create destination MinIO profile.' );
}

$source_gateway      = new ProfileStorageGateway( $source_profile, $settings );
$destination_gateway = new ProfileStorageGateway( $destination_profile, $settings );
foreach ( array( $bucket_a, $bucket_b ) as $bucket ) {
	try {
		$source_gateway->client()->createBucket( array( 'Bucket' => $bucket ) );
	} catch ( Throwable $e ) {
		// The smoke is rerunnable; existing buckets are valid.
	}
}
try {
	$source_gateway->client()->headBucket( array( 'Bucket' => $bucket_a ) );
	$destination_gateway->client()->headBucket( array( 'Bucket' => $bucket_b ) );
} catch ( Throwable $e ) {
	$fail( 'MinIO buckets are not reachable: ' . $e->getMessage() );
}
$ok( 'MinIO source and destination buckets reachable' );

wp_mkdir_p( dirname( $upload_abs ) );
$body = fopen( $upload_abs, 'wb' );
if ( $body === false ) {
	$fail( 'Could not create deterministic local source.' );
}
$chunk = hash( 'sha256', 'kazus-1.1-multipart-resume-' . $run_id, true );
for ( $written = 0; $written < ( 10 * 1024 * 1024 ) + 123; $written += strlen( $chunk ) ) {
	fwrite( $body, $chunk );
}
fclose( $body );
$size = (int) filesize( $upload_abs );
if ( $size !== ( 10 * 1024 * 1024 ) + 128 ) {
	$fail( 'Deterministic source size was not the expected three-part payload.' );
}

add_filter( 'kazus_multipart_threshold_bytes', static fn(): int => 1 );
add_filter( 'kazus_multipart_part_size_bytes', static fn(): int => 5 * 1024 * 1024 );
add_filter( 'kazus_multipart_concurrency', static fn(): int => 1 );
add_filter( 'kazus_multipart_max_parts_per_execution', static fn(): int => 1 );

$new_service = static function ( array &$calls ) use ( $profiles, $settings ): ResumableTransferService {
	return new ResumableTransferService(
		new TransferSessionRepository(),
		static function ( int $profile_id ) use ( $profiles, $settings, &$calls ): StorageGatewayInterface {
			$profile = $profiles->find( $profile_id );
			if ( $profile === null ) {
				throw new RuntimeException( 'Smoke profile is unavailable.' );
			}
			return new KazusMultipartResumeSmokeGateway( new ProfileStorageGateway( $profile, $settings ), $calls );
		}
	);
};

$local_key = $prefix . 'local-resume.bin';
$remote_keys[] = array( 'profile_id' => $source_profile_id, 'key' => $local_key );
$first_calls = array( 'create' => array(), 'upload_part' => array(), 'list_parts' => array(), 'complete' => array() );
$first = $new_service( $first_calls )->upload_local( 0, $source_profile_id, $upload_rel, $local_key, 'application/octet-stream', '' );
$session_uuids[] = (string) $first['session_uuid'];
if ( ! empty( $first['verified_complete'] ) ) {
	$fail( 'First execution completed instead of yielding after its configured one-part budget.' );
}
if ( $first_calls['upload_part'] !== array( 1 ) || $first_calls['complete'] !== array() ) {
	$fail( 'First execution did not stop after exactly the first multipart part.' );
}
$local_session = $sessions->find_by_uuid( (string) $first['session_uuid'] );
if ( ! is_array( $local_session ) || (string) $local_session['status'] !== TransferSessionStatus::UPLOADING ) {
	$fail( 'First execution did not leave an uploading durable local transfer session.' );
}
$provider_parts = $source_gateway->list_parts( $local_key, (string) $local_session['multipart_upload_id'] );
if ( array_keys( $provider_parts ) !== array( 1 ) ) {
	$fail( 'MinIO did not retain exactly the first completed multipart part.' );
}
$ok( 'First local request yielded with durable session and real MinIO multipart part' );

remove_all_filters( 'kazus_multipart_max_parts_per_execution' );
$second_calls = array( 'create' => array(), 'upload_part' => array(), 'list_parts' => array(), 'complete' => array() );
$second = $new_service( $second_calls )->upload_local( 0, $source_profile_id, $upload_rel, $local_key, 'application/octet-stream', '' );
if ( empty( $second['verified_complete'] ) || empty( $second['resumed'] ) ) {
	$fail( 'Fresh local service graph did not resume and verify the transfer.' );
}
if ( $second_calls['list_parts'] === array() || in_array( 1, $second_calls['upload_part'], true ) ) {
	$fail( 'Local resume did not reconcile MinIO ListParts before skipping completed part 1.' );
}
$local_head = $source_gateway->head_key( $local_key );
$local_session = $sessions->find_by_uuid( (string) $first['session_uuid'] );
if ( empty( $local_head['exists'] ) || (int) $local_head['content_length'] !== $size || ! is_array( $local_session ) || (string) $local_session['status'] !== TransferSessionStatus::COMPLETED ) {
	$fail( 'Local resumed multipart transfer did not complete with exact final HEAD/session state.' );
}
$ok( 'Fresh local service graph reconciled ListParts, skipped part 1, and completed exact-size HEAD verification' );

// Direct Upload migrations force multipart beneath the normal threshold so the
// durable session gives cleanup an upload ID it can recover and abort. Exercise
// that mode against MinIO with a one-part execution budget, then a fresh service
// graph, to catch zero-sized ranges and zero persisted part sizes.
$forced_source_key      = $prefix . 'forced-small-source.bin';
$forced_destination_key = $prefix . 'forced-small-destination.bin';
$remote_keys[] = array( 'profile_id' => $source_profile_id, 'key' => $forced_source_key );
$remote_keys[] = array( 'profile_id' => $destination_profile_id, 'key' => $forced_destination_key );
$source_gateway->put_object_from_file( $forced_source_key, $upload_abs, 'application/octet-stream', '' );
remove_all_filters( 'kazus_multipart_threshold_bytes' );
add_filter( 'kazus_multipart_threshold_bytes', static fn() => $size + 1 );
add_filter( 'kazus_multipart_max_parts_per_execution', static fn(): int => 1 );
$forced_first_calls = array( 'create' => array(), 'upload_part' => array(), 'list_parts' => array(), 'complete' => array(), 'get_range' => array() );
$forced_first = $new_service( $forced_first_calls )->copy_remote( 0, $source_profile_id, $forced_source_key, $destination_profile_id, $forced_destination_key, 'application/octet-stream', '', $size, null, true );
$session_uuids[] = (string) $forced_first['session_uuid'];
if ( ! empty( $forced_first['success'] ) || $forced_first_calls['upload_part'] !== array( 1 ) || $forced_first_calls['get_range'] !== array( array( $forced_source_key, 0, ( 5 * 1024 * 1024 ) - 1 ) ) ) {
	$fail( 'Forced below-threshold MinIO copy did not upload a valid first multipart range before yielding.' );
}
$forced_session = $sessions->find_by_uuid( (string) $forced_first['session_uuid'] );
if ( ! is_array( $forced_session ) || (int) $forced_session['part_size_bytes'] <= 0 || (string) $forced_session['status'] !== TransferSessionStatus::UPLOADING ) {
	$fail( 'Forced below-threshold MinIO copy did not persist a positive resumable part size.' );
}

remove_all_filters( 'kazus_multipart_max_parts_per_execution' );
$forced_second_calls = array( 'create' => array(), 'upload_part' => array(), 'list_parts' => array(), 'complete' => array(), 'get_range' => array() );
$forced_second = $new_service( $forced_second_calls )->copy_remote( 0, $source_profile_id, $forced_source_key, $destination_profile_id, $forced_destination_key, 'application/octet-stream', '', $size, null, true );
if ( empty( $forced_second['success'] ) || empty( $forced_second['resumed'] ) || $forced_second_calls['list_parts'] === array() || in_array( 1, $forced_second_calls['upload_part'], true ) ) {
	$fail( 'Forced below-threshold MinIO resume did not reconcile and skip completed part 1.' );
}
$forced_head = $destination_gateway->head_key( $forced_destination_key );
if ( empty( $forced_head['exists'] ) || (int) $forced_head['content_length'] !== $size ) {
	$fail( 'Forced below-threshold MinIO copy did not finish with an exact destination HEAD.' );
}
$ok( 'Forced below-threshold MinIO copy resumed with positive part size and no duplicate part 1' );

// Restore the multipart controls established for the existing smoke scenarios.
// The forced-small copy temporarily raises only the threshold to exercise the
// forced-multipart path; the following Pro migration expects threshold=1.
remove_all_filters( 'kazus_multipart_threshold_bytes' );
add_filter( 'kazus_multipart_threshold_bytes', static fn(): int => 1 );

if ( class_exists( MigrateObjectService::class ) && ModuleRegistry::instance()->has_pro_module() ) {
	$source_key       = $prefix . 'pro-source.bin';
	$pro_relative     = '2026/09/pro-source.bin';
	$destination_key  = ObjectKeyService::key_for( $destination_profile->prefix, $pro_relative );
	$remote_keys[] = array( 'profile_id' => $source_profile_id, 'key' => $source_key );
	$remote_keys[] = array( 'profile_id' => $destination_profile_id, 'key' => $destination_key );
	$source_gateway->put_object_from_file( $source_key, $upload_abs, 'application/octet-stream', '' );
	$object_id = $objects->upsert(
		array(
			'attachment_id'       => 0,
			'storage_profile_id'  => $source_profile_id,
			'local_relative_path' => $pro_relative,
			'object_key'          => $source_key,
			'variant_type'        => 'full',
			'mime_type'           => 'application/octet-stream',
			'size_bytes'          => $size,
			'remote_status'       => ObjectRemoteStatus::PRESENT,
			'verified_at'         => gmdate( 'Y-m-d H:i:s' ),
		)
	);
	$created_object_ids[] = $object_id;

	add_filter( 'kazus_multipart_max_parts_per_execution', static fn(): int => 1 );
	$pro_first_calls = array( 'create' => array(), 'upload_part' => array(), 'list_parts' => array(), 'complete' => array() );
	$pro_first_transfer = $new_service( $pro_first_calls );
	$pro_first = ( new MigrateObjectService(
		$settings,
		$objects,
		$profiles,
		static fn( StorageProfile $profile ): ProfileStorageGateway => new ProfileStorageGateway( $profile, $settings ),
		$pro_first_transfer
	) )->migrate( $object_id, $destination_profile_id );
	if ( ! empty( $pro_first['success'] ) || $pro_first_calls['upload_part'] !== array( 1 ) ) {
		$fail( 'Interrupted Pro transfer did not stop after exactly one destination part.' );
	}
	$source_row = $objects->find_by_id( $object_id );
	if ( ! is_array( $source_row ) || (string) $source_row['remote_status'] !== ObjectRemoteStatus::PRESENT || empty( $source_gateway->head_key( $source_key )['exists'] ) ) {
		$fail( 'Interrupted Pro migration did not keep the source inventory/object servable and present.' );
	}
	$ok( 'Interrupted Pro cross-bucket transfer kept source present and servable' );

	remove_all_filters( 'kazus_multipart_max_parts_per_execution' );
	$pro_second_calls = array( 'create' => array(), 'upload_part' => array(), 'list_parts' => array(), 'complete' => array() );
	$pro_second = ( new MigrateObjectService(
		$settings,
		$objects,
		$profiles,
		static fn( StorageProfile $profile ): ProfileStorageGateway => new ProfileStorageGateway( $profile, $settings ),
		$new_service( $pro_second_calls )
	) )->migrate( $object_id, $destination_profile_id );
	if ( empty( $pro_second['success'] ) || $pro_second_calls['list_parts'] === array() || in_array( 1, $pro_second_calls['upload_part'], true ) ) {
		$fail( 'Pro resume did not reconcile the first MinIO part before verified destination commit.' );
	}
	$source_row = $objects->find_by_id( $object_id );
	$destination_row = $objects->find_by_profile_and_key( $destination_profile_id, $destination_key );
	if ( ! is_array( $source_row ) || (string) $source_row['remote_status'] !== ObjectRemoteStatus::STALE || ! is_array( $destination_row ) || (string) $destination_row['remote_status'] !== ObjectRemoteStatus::PRESENT ) {
		$fail( 'Verified Pro resume did not commit destination before staling source.' );
	}
	$ok( 'Pro cross-bucket resume reconciled MinIO and promoted destination only after verification' );
} else {
	echo "SMOKE SKIP: Pro two-profile path requires the Pro plugin to be active.\n";
}

if ( is_file( $upload_abs ) ) {
	wp_delete_file( $upload_abs );
}
$ok( '1.1 multipart resume smoke complete' );
exit( 0 );
