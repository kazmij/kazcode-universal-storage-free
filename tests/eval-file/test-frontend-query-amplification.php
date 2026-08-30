<?php
/**
 * Disposable WordPress query-count benchmark for frontend media URL rewriting.
 *
 * Run with:
 * wp eval-file tests/eval-file/test-frontend-query-amplification.php --allow-root
 *
 * @package Kazcode\WpStorage
 */

use Kazcode\WpStorage\Attachment\AttachmentOffloader;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Domain\LocalStoragePolicy;

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

global $wpdb;

$private_media = (bool) ( getenv( 'KAZUS_PERF_PRIVATE' ) ?: false );
$settings      = new Settings();
$settings->update(
	array_merge(
		$settings->all(),
		array(
			'enabled'              => true,
			'serve_from_s3'        => true,
			'public_base_url'      => 'https://legacy.example.test/media',
			'private_media'        => $private_media,
			'local_storage_policy' => LocalStoragePolicy::KEEP_ALL,
		)
	)
);

$now            = gmdate( 'Y-m-d H:i:s' );
$profiles_table = $wpdb->prefix . 's3ms_storage_profiles';
$objects_table  = $wpdb->prefix . 's3ms_objects';

/**
 * @return int
 */
$ensure_profile = static function ( string $name, string $base_url, bool $default = false ) use ( $wpdb, $profiles_table, $now ): int {
	$uuid = 'perf-' . sanitize_key( $name );
	$id   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$profiles_table} WHERE uuid = %s", $uuid ) );
	if ( $id > 0 ) {
		$wpdb->update(
			$profiles_table,
			array(
				'name'                     => $name,
				'bucket'                   => sanitize_key( $name ),
				'region'                   => 'us-east-1',
				'delivery_type'            => 'cdn',
				'delivery_base_url'        => $base_url,
				'cdn_includes_prefix'      => 0,
				'is_default_upload_target' => $default ? 1 : 0,
				'updated_at'               => $now,
			),
			array( 'id' => $id )
		);
		return $id;
	}
	$wpdb->insert(
		$profiles_table,
		array(
			'uuid'                     => $uuid,
			'name'                     => $name,
			'provider_type'            => 'aws',
			'bucket'                   => sanitize_key( $name ),
			'region'                   => 'us-east-1',
			'endpoint'                 => '',
			'path_style'               => 0,
			'prefix'                   => 'perf',
			'delivery_type'            => 'cdn',
			'delivery_base_url'        => $base_url,
			'cdn_includes_prefix'      => 0,
			'credential_mode'          => 'keys',
			'credentials_ref'          => '',
			'is_default_upload_target' => $default ? 1 : 0,
			'is_read_only'             => 0,
			'location_locked'          => 0,
			'created_at'               => $now,
			'updated_at'               => $now,
		)
	);
	return (int) $wpdb->insert_id;
};

$profile_a = $ensure_profile( 'Perf A', 'https://cdn-a.example.test', true );
$profile_b = $ensure_profile( 'Perf B', 'https://cdn-b.example.test', false );
$profile_c = $ensure_profile( 'Perf C', 'https://cdn-c.example.test', false );

/**
 * @return array{id:int,relatives:list<string>}
 */
$ensure_attachment = static function ( int $index ) use ( $wpdb ): array {
	$existing = get_page_by_title( 'KAZUS Perf Image ' . $index, OBJECT, 'attachment' );
	if ( $existing instanceof WP_Post ) {
		$meta      = wp_get_attachment_metadata( (int) $existing->ID );
		$attached  = (string) get_post_meta( (int) $existing->ID, '_wp_attached_file', true );
		$relatives = array( $attached );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) ) {
				$relatives[] = trailingslashit( dirname( $attached ) ) . (string) $size['file'];
			}
		}
		return array( 'id' => (int) $existing->ID, 'relatives' => array_values( array_unique( $relatives ) ) );
	}

	$uploads = wp_upload_dir();
	$dir     = trailingslashit( $uploads['path'] );
	wp_mkdir_p( $dir );
	$file = $dir . 'kazus-perf-' . $index . '.jpg';

	$image = imagecreatetruecolor( 1600, 1200 );
	$bg    = imagecolorallocate( $image, ( $index * 17 ) % 255, ( $index * 31 ) % 255, ( $index * 47 ) % 255 );
	imagefilledrectangle( $image, 0, 0, 1600, 1200, $bg );
	imagejpeg( $image, $file, 82 );
	imagedestroy( $image );

	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => 'KAZUS Perf Image ' . $index,
			'post_status'    => 'inherit',
		),
		$file
	);
	if ( is_wp_error( $attachment_id ) ) {
		throw new RuntimeException( $attachment_id->get_error_message() );
	}
	$metadata = wp_generate_attachment_metadata( (int) $attachment_id, $file );
	wp_update_attachment_metadata( (int) $attachment_id, $metadata );

	$attached  = (string) get_post_meta( (int) $attachment_id, '_wp_attached_file', true );
	$relatives = array( $attached );
	foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
		if ( is_array( $size ) && ! empty( $size['file'] ) ) {
			$relatives[] = trailingslashit( dirname( $attached ) ) . (string) $size['file'];
		}
	}

	return array( 'id' => (int) $attachment_id, 'relatives' => array_values( array_unique( $relatives ) ) );
};

/**
 * @param list<string> $relatives
 */
$ensure_inventory = static function ( int $attachment_id, array $relatives, int $profile_id, bool $with_stale = false ) use ( $wpdb, $objects_table, $profile_a, $now ): void {
	foreach ( $relatives as $relative ) {
		$wpdb->replace(
			$objects_table,
			array(
				'attachment_id'      => $attachment_id,
				'storage_profile_id' => $profile_id,
				'local_relative_path' => $relative,
				'object_key'         => 'perf/' . $relative,
				'variant_type'       => basename( $relative ) === basename( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) ) ? 'original' : 'size',
				'mime_type'          => 'image/jpeg',
				'size_bytes'         => 1000,
				'etag'               => 'perf',
				'checksum'           => null,
				'local_status'       => 'present',
				'remote_status'      => ObjectRemoteStatus::PRESENT,
				'attempt_count'      => 1,
				'last_error_code'    => null,
				'last_error_message' => null,
				'offloaded_at'       => $now,
				'verified_at'        => $now,
				'created_at'         => $now,
				'updated_at'         => $now,
			)
		);

		if ( $with_stale ) {
			$wpdb->replace(
				$objects_table,
				array(
					'attachment_id'      => $attachment_id,
					'storage_profile_id' => $profile_a,
					'local_relative_path' => $relative,
					'object_key'         => 'old-perf/' . $relative,
					'variant_type'       => 'size',
					'mime_type'          => 'image/jpeg',
					'size_bytes'         => 1000,
					'etag'               => 'stale',
					'checksum'           => null,
					'local_status'       => 'present',
					'remote_status'      => ObjectRemoteStatus::STALE,
					'attempt_count'      => 1,
					'last_error_code'    => null,
					'last_error_message' => null,
					'offloaded_at'       => $now,
					'verified_at'        => $now,
					'created_at'         => $now,
					'updated_at'         => $now,
				)
			);
		}
	}
	update_post_meta( $attachment_id, '_s3ms_status', AttachmentOffloader::STATUS_OFFLOADED );
};

$attachment_count = (int) ( getenv( 'KAZUS_PERF_COUNT' ) ?: 100 );
$attachment_count = max( 1, min( 500, $attachment_count ) );
$scenario         = (string) ( getenv( 'KAZUS_PERF_SCENARIO' ) ?: 'all' );

$attachments = array();
for ( $i = 1; $i <= $attachment_count; ++$i ) {
	$attachment = $ensure_attachment( $i );
	$profile    = $profile_a;
	if ( $i % 3 === 2 ) {
		$profile = $profile_b;
	} elseif ( $i % 3 === 0 ) {
		$profile = $profile_c;
	}
	$ensure_inventory( $attachment['id'], $attachment['relatives'], $profile, $i <= 10 );
	$attachments[] = $attachment['id'];
}

$queries = array();
$capture = false;
add_filter(
	'query',
	static function ( string $query ) use ( &$queries, &$capture ): string {
		if ( $capture ) {
			$queries[] = $query;
		}
		return $query;
	}
);

/**
 * @return array<string,mixed>
 */
$measure = static function ( string $name, callable $callback ) use ( &$queries, &$capture, $wpdb ): array {
	$queries = array();
	$before_count = (int) $wpdb->num_queries;
	$start_time   = microtime( true );
	$start_memory = memory_get_usage( true );
	$capture      = true;
	$result       = $callback();
	$capture      = false;
	$elapsed      = microtime( true ) - $start_time;
	$peak_delta   = memory_get_peak_usage( true ) - $start_memory;

	$object_queries  = 0;
	$profile_queries = 0;
	$lease_queries   = 0;
	$audit_queries   = 0;
	foreach ( $queries as $query ) {
		if ( str_contains( $query, 's3ms_objects' ) ) {
			++$object_queries;
		}
		if ( str_contains( $query, 's3ms_storage_profiles' ) ) {
			++$profile_queries;
		}
		if ( str_contains( $query, 's3ms_lock_' ) ) {
			++$lease_queries;
		}
		if ( str_contains( $query, 's3ms_audit' ) ) {
			++$audit_queries;
		}
	}

	return array(
		'name'                 => $name,
		'total_queries'        => (int) $wpdb->num_queries - $before_count,
		'captured_queries'     => count( $queries ),
		's3ms_object_queries'  => $object_queries,
		's3ms_profile_queries' => $profile_queries,
		'lease_queries'        => $lease_queries,
		'audit_queries'        => $audit_queries,
		'remote_head_calls'    => 0,
		'remote_get_calls'     => 0,
		'request_time_ms'      => round( $elapsed * 1000, 3 ),
		'peak_memory_delta'    => $peak_delta,
		'result'               => $result,
	);
};

$render_attachment = static function ( int $id ): int {
	$count = 0;
	$url   = wp_get_attachment_url( $id );
	$count += is_string( $url ) && $url !== '' ? 1 : 0;
	$src = wp_get_attachment_image_src( $id, 'medium' );
	$count += is_array( $src ) ? 1 : 0;
	$downsize = image_downsize( $id, 'thumbnail' );
	$count += is_array( $downsize ) ? 1 : 0;
	$html = wp_get_attachment_image( $id, 'large' );
	$count += is_string( $html ) && $html !== '' ? 1 : 0;
	$meta = wp_get_attachment_metadata( $id );
	if ( is_array( $meta ) && is_array( $src ) ) {
		$srcset = wp_calculate_image_srcset( array( (int) $src[1], (int) $src[2] ), (string) $src[0], $meta, $id );
		$count += is_string( $srcset ) && $srcset !== '' ? 1 : 0;
	}
	return $count;
};

$single_id = $attachments[0];
$results   = array();
$scenarios = array();
$scenarios['single_attachment_repeated_20'] = static fn(): array => $measure(
	'single_attachment_repeated_20',
	static function () use ( $render_attachment, $single_id ): int {
		$count = 0;
		for ( $i = 0; $i < 20; ++$i ) {
			$count += $render_attachment( $single_id );
		}
		return $count;
	}
);

foreach ( array( 10, 100, 500 ) as $limit ) {
	$scenarios[ $limit . '_images' ] = static function () use ( $measure, $render_attachment, $attachments, $attachment_count, $limit ): array {
		if ( $limit > $attachment_count ) {
			return array(
				'name'   => $limit . '_images',
				'status' => 'NOT_RUN',
				'reason' => 'Set KAZUS_PERF_COUNT=' . $limit . ' to build enough benchmark attachments.',
			);
		}
		$ids = array_slice( $attachments, 0, $limit );
		return $measure(
			$limit . '_images',
			static function () use ( $render_attachment, $ids ): int {
				$count = 0;
				foreach ( $ids as $id ) {
					$count += $render_attachment( $id );
				}
				return $count;
			}
		);
	};
}

$scenarios['multi_profile_100'] = static fn(): array => $measure(
	'multi_profile_100',
	static function () use ( $render_attachment, $attachments ): int {
		$count = 0;
		foreach ( array_slice( $attachments, 0, 100 ) as $id ) {
			$count += $render_attachment( $id );
		}
		return $count;
	}
);

$scenarios['post_migration_stale_present_10'] = static fn(): array => $measure(
	'post_migration_stale_present_10',
	static function () use ( $render_attachment, $attachments ): int {
		$count = 0;
		foreach ( array_slice( $attachments, 0, 10 ) as $id ) {
			$count += $render_attachment( $id );
		}
		return $count;
	}
);

$scenarios['media_library_prepare_js_100'] = static fn(): array => $measure(
	'media_library_prepare_js_100',
	static function () use ( $attachments ): int {
		$count = 0;
		foreach ( array_slice( $attachments, 0, 100 ) as $id ) {
			$prepared = wp_prepare_attachment_for_js( $id );
			$count   += is_array( $prepared ) && ! empty( $prepared['url'] ) ? 1 : 0;
		}
		return $count;
	}
);

$scenarios['explain_100k_rows'] = static function () use ( $wpdb, $objects_table, $profile_a, $now ): array {
	$prefix   = 'synthetic-perf/';
	$existing = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$objects_table} WHERE object_key LIKE %s", $prefix . '%' )
	);

	while ( $existing < 100000 ) {
		$values = array();
		for ( $i = 0; $i < 1000 && $existing < 100000; ++$i, ++$existing ) {
			$key      = $prefix . $existing . '.jpg';
			$values[] = $wpdb->prepare(
				'(%d,%d,%s,%s,%s,%s,%d,%s,%s,%s,%s,%d,%s,%s,%s,%s,%s,%s)',
				900000000 + $existing,
				$profile_a,
				$key,
				$key,
				'size',
				'image/jpeg',
				1,
				'etag',
				null,
				'present',
				ObjectRemoteStatus::PRESENT,
				0,
				null,
				null,
				$now,
				$now,
				$now,
				$now
			);
		}
		$wpdb->query(
			"INSERT IGNORE INTO {$objects_table} " .
			'(attachment_id,storage_profile_id,local_relative_path,object_key,variant_type,mime_type,size_bytes,etag,checksum,local_status,remote_status,attempt_count,last_error_code,last_error_message,offloaded_at,verified_at,created_at,updated_at) VALUES ' .
			implode( ',', $values )
		);
	}

	return array(
		'name'           => 'explain_100k_rows',
		'synthetic_rows' => (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$objects_table} WHERE object_key LIKE %s", $prefix . '%' )
		),
		'explain'        => $wpdb->get_results(
			"EXPLAIN SELECT * FROM {$objects_table} WHERE attachment_id = 900012345 ORDER BY id ASC",
			ARRAY_A
		),
	);
};

$legacy = $ensure_attachment( 1001 );
$wpdb->delete( $objects_table, array( 'attachment_id' => $legacy['id'] ), array( '%d' ) );
update_post_meta( $legacy['id'], '_s3ms_status', AttachmentOffloader::STATUS_OFFLOADED );
$scenarios['legacy_no_inventory_repeated_20'] = static fn(): array => $measure(
	'legacy_no_inventory_repeated_20',
	static function () use ( $render_attachment, $legacy ): int {
		$count = 0;
		for ( $i = 0; $i < 20; ++$i ) {
			$count += $render_attachment( $legacy['id'] );
		}
		return $count;
	}
);

if ( $scenario !== 'all' && isset( $scenarios[ $scenario ] ) ) {
	$results[] = $scenarios[ $scenario ]();
} else {
	foreach ( $scenarios as $run ) {
		$results[] = $run();
	}
}

echo wp_json_encode(
	array(
		'attachments' => count( $attachments ),
		'profiles'    => array( $profile_a, $profile_b, $profile_c ),
		'private'     => $private_media,
		'results'     => $results,
	),
	JSON_PRETTY_PRINT
) . PHP_EOL;
