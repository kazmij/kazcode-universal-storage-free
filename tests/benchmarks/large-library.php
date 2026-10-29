<?php
/**
 * Deterministic, local-only large media-library characterization runner.
 *
 * Run only through the disposable benchmark wrapper:
 * wp eval-file tests/benchmarks/large-library.php -- --scale 10000
 *
 * @package Kazcode\WpStorage
 */

/**
 * @return list<int>
 */
function kazus_large_library_supported_scales(): array {
	return array( 10000, 50000, 100000 );
}

/**
 * @return array{scale:int,first_attachment_id:int,last_attachment_id:int,expected_attachment_count:int,expected_inventory_attachment_count:int,expected_legacy_attachment_count:int,post_name_prefix:string}
 */
function kazus_large_library_fixture_descriptor( int $scale ): array {
	if ( ! in_array( $scale, kazus_large_library_supported_scales(), true ) ) {
		throw new \InvalidArgumentException( 'Unsupported benchmark scale: ' . $scale );
	}

	$first_attachment_id = 1000000;
	return array(
		'scale'                     => $scale,
		'first_attachment_id'       => $first_attachment_id,
		'last_attachment_id'        => $first_attachment_id + $scale - 1,
		'expected_attachment_count' => $scale,
		// Even IDs have an inventory row; odd IDs model legacy attachments that
		// adoption must discover. This permits both cursor paths to be measured
		// against the same disposable, large library.
		'expected_inventory_attachment_count' => intdiv( $scale, 2 ),
		'expected_legacy_attachment_count'    => $scale - intdiv( $scale, 2 ),
		'post_name_prefix'          => 'kazus-benchmark-attachment-',
	);
}

/**
 * @param list<list<int>> $pages
 * @return array{status:string,attachment_count:int,last_attachment_id:int,findings:list<string>}
 */
function kazus_large_library_cursor_result( array $pages, int $expected_count ): array {
	$findings = array();
	$last     = 0;
	$count    = 0;

	foreach ( $pages as $page ) {
		foreach ( $page as $attachment_id ) {
			if ( $attachment_id <= $last ) {
				$findings[] = 'non_monotonic_or_duplicate_cursor';
				break 2;
			}
			$last = $attachment_id;
			++$count;
		}
	}

	if ( $count !== $expected_count ) {
		$findings[] = 'cursor_count_mismatch';
	}

	return array(
		'status'               => $findings === array() ? 'PASS' : 'FAIL',
		'attachment_count'     => $count,
		'last_attachment_id'   => $last,
		'findings'             => array_values( array_unique( $findings ) ),
	);
}

/**
 * @param callable():array<string,mixed> $operation
 * @return array<string,mixed>
 */
function kazus_large_library_measure_operation( string $name, callable $operation ): array {
	global $wpdb;

	$before_queries = is_object( $wpdb ?? null ) && isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : 0;
	$started        = microtime( true );
	$before_memory  = memory_get_peak_usage( true );

	try {
		$details = $operation();
		if ( ! is_array( $details ) ) {
			$details = array();
		}
		$status        = 'PASS';
		$error_class   = '';
		$error_message = '';
	} catch ( \Throwable $error ) {
		$details       = array();
		$status        = 'FAIL';
		$error_class   = get_class( $error );
		$error_message = $error->getMessage();
	}

	$after_queries = is_object( $wpdb ?? null ) && isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : $before_queries;
	return array_merge(
		array(
			'name'                => $name,
			'status'              => $status,
			'wall_time_ms'        => round( ( microtime( true ) - $started ) * 1000, 3 ),
			'peak_memory_bytes'   => max( $before_memory, memory_get_peak_usage( true ) ),
			'sql_statement_count' => max( 0, $after_queries - $before_queries ),
			'error_class'         => $error_class,
			'error_message'       => $error_message,
		),
		$details
	);
}

/**
 * @param list<array<string,mixed>> $rows
 * @return array{estimated_rows:int,access_types:list<string>,keys:list<string>}
 */
function kazus_large_library_cursor_explain_summary( array $rows ): array {
	$estimated_rows = 0;
	$access_types   = array();
	$keys           = array();
	foreach ( $rows as $row ) {
		$estimated_rows += max( 0, (int) ( $row['rows'] ?? 0 ) );
		if ( isset( $row['type'] ) && $row['type'] !== null && $row['type'] !== '' ) {
			$access_types[] = (string) $row['type'];
		}
		if ( isset( $row['key'] ) && $row['key'] !== null && $row['key'] !== '' ) {
			$keys[] = (string) $row['key'];
		}
	}

	return array(
		'estimated_rows' => $estimated_rows,
		'access_types'   => array_values( array_unique( $access_types ) ),
		'keys'           => array_values( array_unique( $keys ) ),
	);
}

/**
 * @return array{estimated_rows:int,access_types:list<string>,keys:list<string>}
 */
function kazus_large_library_cursor_explain(): array {
	global $wpdb;
	if ( ! is_object( $wpdb ?? null ) || ! isset( $wpdb->posts ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_results' ) ) {
		throw new \RuntimeException( 'WordPress database access is required for cursor EXPLAIN.' );
	}

	$sql = $wpdb->prepare(
		"EXPLAIN SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND ID > %d ORDER BY ID ASC LIMIT %d",
		'attachment',
		'inherit',
		0,
		250
	);
	return kazus_large_library_cursor_explain_summary( (array) $wpdb->get_results( $sql, ARRAY_A ) );
}

function kazus_large_library_core_plugin_version(): string {
	$plugin_file = defined( 'WP_PLUGIN_DIR' )
		? WP_PLUGIN_DIR . '/kazcode-universal-storage/kazcode-universal-storage.php'
		: dirname( __DIR__, 2 ) . '/kazcode-universal-storage.php';
	$source      = is_readable( $plugin_file ) ? (string) file_get_contents( $plugin_file ) : '';
	if ( preg_match( '/^ \* Version:\s*(.+)$/m', $source, $match ) !== 1 ) {
		return 'unavailable';
	}
	return trim( $match[1] );
}

/**
 * @param array{scale:int,first_attachment_id:int,last_attachment_id:int,expected_attachment_count:int,expected_inventory_attachment_count:int,expected_legacy_attachment_count:int,post_name_prefix:string} $fixture
 * @param list<array<string,mixed>> $operations
 * @return array{environment:array<string,mixed>,fixture:array<string,mixed>,operations:list<array<string,mixed>>,status:string,findings:list<string>}
 */
function kazus_large_library_report( array $fixture, array $operations ): array {
	$findings = array();
	foreach ( $operations as $operation ) {
		if ( ( $operation['status'] ?? 'FAIL' ) !== 'PASS' ) {
			$findings[] = 'operation_failed:' . (string) ( $operation['name'] ?? 'unknown' );
		}
		foreach ( (array) ( $operation['findings'] ?? array() ) as $finding ) {
			$findings[] = (string) $finding;
		}
	}

	global $wpdb;
	$wordpress_version = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : 'unit-test';
	$database_version  = is_object( $wpdb ?? null ) && method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : 'unavailable';

	return array(
		'environment' => array(
			'evidence_scope'    => 'LOCAL_SYNTHETIC',
			'php_version'       => PHP_VERSION,
			'wordpress_version' => $wordpress_version,
			'mariadb_version'   => $database_version,
			'core_plugin_version' => kazus_large_library_core_plugin_version(),
		),
		'fixture'     => $fixture,
		'operations'  => $operations,
		'status'      => $findings === array() ? 'PASS' : 'FAIL',
		'findings'    => array_values( array_unique( $findings ) ),
	);
}

/**
 * Seed only an empty benchmark namespace in the disposable WordPress database.
 *
 * @param array{scale:int,first_attachment_id:int,last_attachment_id:int,expected_attachment_count:int,expected_inventory_attachment_count:int,expected_legacy_attachment_count:int,post_name_prefix:string} $fixture
 * @return array{inserted:int}
 */
function kazus_large_library_seed_fixture( array $fixture ): array {
	global $wpdb;
	if ( ! is_object( $wpdb ?? null ) || ! isset( $wpdb->posts ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'query' ) ) {
		throw new \RuntimeException( 'WordPress database access is required for fixture seeding.' );
	}

	$prefix = $fixture['post_name_prefix'];
	$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_name LIKE %s", $prefix . '%' ) );
	if ( $existing !== 0 ) {
		throw new \RuntimeException( 'Benchmark fixture namespace is not empty.' );
	}

	$rows       = array();
	$meta_rows  = array();
	$object_rows = array();
	$inserted   = 0;
	$meta_inserted = 0;
	$object_inserted = 0;
	$batch_size = 250;
	for ( $id = $fixture['first_attachment_id']; $id <= $fixture['last_attachment_id']; ++$id ) {
		$slug   = $prefix . $id;
		$rows[] = $wpdb->prepare(
			'(%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %s, %d)',
			$id,
			'2000-01-01 00:00:00',
			'2000-01-01 00:00:00',
			'',
			$slug,
			'inherit',
			'open',
			'closed',
			$slug,
			'2000-01-01 00:00:00',
			'2000-01-01 00:00:00',
			0,
			'attachment',
			0
		);
		$meta_rows[] = $wpdb->prepare( '(%d, %s, %s)', $id, '_s3ms_status', 'offloaded' );
		if ( $id % 2 === 0 ) {
			$relative = 'kazus-benchmark/' . $id . '.bin';
			$object   = 'benchmark/' . $id . '.bin';
			$object_rows[] = $wpdb->prepare(
				'(%d, %d, %s, %s, %s, %s, %s, %d, %s, %s, %d, %s, %s)',
				$id,
				1,
				$relative,
				$object,
				hash( 'sha256', $object ),
				'original',
				'application/octet-stream',
				1,
				'deleted',
				'present',
				0,
				'2000-01-01 00:00:00',
				'2000-01-01 00:00:00'
			);
		}

		if ( count( $rows ) === $batch_size || $id === $fixture['last_attachment_id'] ) {
			$sql = "INSERT INTO {$wpdb->posts} (ID, post_date, post_date_gmt, post_content, post_title, post_status, comment_status, ping_status, post_name, post_modified, post_modified_gmt, post_parent, post_type, menu_order) VALUES " . implode( ', ', $rows );
			if ( $wpdb->query( $sql ) === false ) {
				throw new \RuntimeException( 'Fixture insert failed: ' . (string) ( $wpdb->last_error ?? 'unknown database error' ) );
			}
			$inserted += count( $rows );
			$meta_sql = "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ', ', $meta_rows );
			if ( $wpdb->query( $meta_sql ) === false ) {
				throw new \RuntimeException( 'Fixture status-meta insert failed: ' . (string) ( $wpdb->last_error ?? 'unknown database error' ) );
			}
			$meta_inserted += count( $meta_rows );
			if ( $object_rows !== array() ) {
				$objects_table = $wpdb->prefix . 's3ms_objects';
				$object_sql = "INSERT INTO {$objects_table} (attachment_id, storage_profile_id, local_relative_path, object_key, object_key_hash, variant_type, mime_type, size_bytes, local_status, remote_status, attempt_count, created_at, updated_at) VALUES " . implode( ', ', $object_rows );
				if ( $wpdb->query( $object_sql ) === false ) {
					throw new \RuntimeException( 'Fixture inventory insert failed: ' . (string) ( $wpdb->last_error ?? 'unknown database error' ) );
				}
				$object_inserted += count( $object_rows );
			}
			$rows = array();
			$meta_rows = array();
			$object_rows = array();
		}
	}

	if ( $inserted !== $fixture['expected_attachment_count'] || $meta_inserted !== $fixture['expected_attachment_count'] || $object_inserted !== $fixture['expected_inventory_attachment_count'] ) {
		throw new \RuntimeException( 'Fixture insertion count did not match the requested scale.' );
	}
	return array( 'inserted' => $inserted, 'status_meta_inserted' => $meta_inserted, 'inventory_rows_inserted' => $object_inserted );
}

/**
 * @param array{scale:int,first_attachment_id:int,last_attachment_id:int,expected_attachment_count:int,post_name_prefix:string} $fixture
 * @return list<list<int>>
 */
function kazus_large_library_cursor_pages( array $fixture, int $page_size = 250 ): array {
	if ( ! class_exists( 'WP_Query' ) ) {
		throw new \RuntimeException( 'WP_Query is required for cursor characterization.' );
	}

	$pages = array();
	$after = 0;
	while ( true ) {
		$where = null;
		if ( $after > 0 ) {
			$where = static function ( string $sql ) use ( $after ): string {
				global $wpdb;
				return $sql . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after );
			};
			add_filter( 'posts_where', $where, 10, 1 );
		}
		try {
			$query = new \WP_Query(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => $page_size,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'no_found_rows'  => true,
				)
			);
		} finally {
			if ( $where !== null ) {
				remove_filter( 'posts_where', $where, 10 );
			}
		}
		$ids = array_map( 'intval', (array) $query->posts );
		if ( $ids === array() ) {
			break;
		}
		$pages[] = $ids;
		$after   = max( $ids );
	}

	return $pages;
}

/**
 * Fetch status-filtered pages using the exact WP_Query shape of the current
 * Verify selector. It deliberately measures selection only: execution would
 * contact the configured storage provider.
 *
 * @return list<list<int>>
 */
function kazus_large_library_verify_cursor_pages( int $expected_count, int $page_size = 100 ): array {
	if ( ! class_exists( 'WP_Query' ) ) {
		throw new \RuntimeException( 'WP_Query is required for cursor characterization.' );
	}

	$pages = array();
	$after = 0;
	while ( true ) {
		$where = null;
		if ( $after > 0 ) {
			$where = static function ( string $sql ) use ( $after ): string {
				global $wpdb;
				return $sql . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after );
			};
			add_filter( 'posts_where', $where, 10, 1 );
		}
		try {
			$query = new \WP_Query(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => $page_size,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'no_found_rows'  => true,
					'meta_query'     => array(
						array(
							'key'   => '_s3ms_status',
							'value' => 'offloaded',
						),
					),
				)
			);
		} finally {
			if ( $where !== null ) {
				remove_filter( 'posts_where', $where, 10 );
			}
		}
		$ids = array_map( 'intval', (array) $query->posts );
		if ( $ids === array() ) {
			break;
		}
		$pages[] = $ids;
		$after   = max( $ids );
	}

	$result = kazus_large_library_cursor_result( $pages, $expected_count );
	if ( $result['status'] !== 'PASS' ) {
		throw new \RuntimeException( implode( ', ', $result['findings'] ) );
	}
	return $pages;
}

/**
 * Fetch status-filtered pages using the exact WP_Query shape of the current
 * Restore selector. It deliberately measures selection only: execution would
 * contact the configured storage provider.
 *
 * @return list<list<int>>
 */
function kazus_large_library_restore_cursor_pages( int $expected_count, int $page_size = 100 ): array {
	if ( ! class_exists( 'WP_Query' ) ) {
		throw new \RuntimeException( 'WP_Query is required for cursor characterization.' );
	}

	$pages = array();
	$after = 0;
	while ( true ) {
		$where = null;
		if ( $after > 0 ) {
			$where = static function ( string $sql ) use ( $after ): string {
				global $wpdb;
				return $sql . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after );
			};
			add_filter( 'posts_where', $where, 10, 1 );
		}
		try {
			$query = new \WP_Query(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => $page_size,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_key'       => '_s3ms_status',
					'meta_value'     => 'offloaded',
					'no_found_rows'  => true,
				)
			);
		} finally {
			if ( $where !== null ) {
				remove_filter( 'posts_where', $where, 10 );
			}
		}
		$ids = array_map( 'intval', (array) $query->posts );
		if ( $ids === array() ) {
			break;
		}
		$pages[] = $ids;
		$after   = max( $ids );
	}

	$result = kazus_large_library_cursor_result( $pages, $expected_count );
	if ( $result['status'] !== 'PASS' ) {
		throw new \RuntimeException( implode( ', ', $result['findings'] ) );
	}
	return $pages;
}

/**
 * @param callable(int):list<int> $fetch_page
 * @return list<list<int>>
 */
function kazus_large_library_service_cursor_pages( callable $fetch_page, int $expected_count ): array {
	$pages = array();
	$after = 0;
	while ( true ) {
		$ids = array_map( 'intval', $fetch_page( $after ) );
		if ( $ids === array() ) {
			break;
		}
		$pages[] = $ids;
		$after   = max( $ids );
	}

	$result = kazus_large_library_cursor_result( $pages, $expected_count );
	if ( $result['status'] !== 'PASS' ) {
		throw new \RuntimeException( implode( ', ', $result['findings'] ) );
	}
	return $pages;
}

/**
 * Clear WordPress's object cache before a cold read measurement. Database and
 * fixture data remain untouched, so the report can distinguish a database
 * query from an in-process cache hit.
 *
 * @return array{cache_state:string}
 */
function kazus_large_library_cold_cache(): array {
	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}
	return array( 'cache_state' => 'cold' );
}

/**
 * Characterize the existing persisted background-job state machine without
 * running a tick or contacting a storage provider.
 *
 * @return array{evidence_scope:string,initial_running:bool,started_running:bool,busy_error_code:string,stopped_running:bool,restarted_running:bool}
 */
function kazus_large_library_background_characterization( \Kazcode\WpStorage\Services\BackgroundMigrator $migrator ): array {
	$initial   = $migrator->status();
	$started   = $migrator->start( 'verify' );
	if ( is_wp_error( $started ) ) {
		throw new \RuntimeException( 'Background start failed: ' . $started->get_error_code() );
	}
	$busy      = $migrator->start( 'verify' );
	$stopped   = $migrator->stop();
	$restarted = $migrator->start( 'verify' );
	if ( is_wp_error( $restarted ) ) {
		throw new \RuntimeException( 'Background restart failed: ' . $restarted->get_error_code() );
	}
	$migrator->stop();

	return array(
		'evidence_scope'    => 'LOCAL_SYNTHETIC',
		'initial_running'   => ! empty( $initial['running'] ),
		'started_running'   => ! empty( $started['running'] ),
		'busy_error_code'   => is_wp_error( $busy ) ? (string) $busy->get_error_code() : '',
		'stopped_running'   => ! empty( $stopped['running'] ),
		'restarted_running' => ! empty( $restarted['running'] ),
	);
}

/**
 * Execute local fixture, exact lookup, inventory lookup and cursor measurements.
 *
 * @return array{environment:array<string,mixed>,fixture:array<string,mixed>,operations:list<array<string,mixed>>,status:string,findings:list<string>}
 */
function kazus_large_library_benchmark( int $scale ): array {
	$fixture = kazus_large_library_fixture_descriptor( $scale );
	global $wpdb;

	$operations   = array();
	$operations[] = kazus_large_library_measure_operation(
		'fixture_seed',
		static fn(): array => kazus_large_library_seed_fixture( $fixture )
	);
	$operations[] = kazus_large_library_measure_operation(
		'exact_attachment_lookup',
		static function () use ( $fixture, $wpdb ): array {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_name FROM {$wpdb->posts} WHERE ID = %d AND post_type = %s", $fixture['first_attachment_id'], 'attachment' ), ARRAY_A );
			if ( ! is_array( $row ) || (int) ( $row['ID'] ?? 0 ) !== $fixture['first_attachment_id'] ) {
				throw new \RuntimeException( 'Exact attachment lookup did not return the seeded attachment.' );
			}
			return array( 'attachment_id' => (int) $row['ID'] );
		}
	);
	$operations[] = kazus_large_library_measure_operation(
		'inventory_lookup',
		static function () use ( $wpdb, $fixture ): array {
			$table = $wpdb->prefix . 's3ms_objects';
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $found !== $table ) {
				return array( 'status_detail' => 'inventory_table_unavailable' );
			}
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$table} WHERE attachment_id = %d LIMIT 1", $fixture['first_attachment_id'] ), ARRAY_A );
			return array( 'inventory_rows' => count( (array) $rows ) );
		}
	);
	$operations[] = kazus_large_library_measure_operation(
		'cursor_scan',
		static function () use ( $fixture ): array {
			$measurement = kazus_large_library_cold_cache();
			$cursor = kazus_large_library_cursor_result( kazus_large_library_cursor_pages( $fixture ), $fixture['expected_attachment_count'] );
			if ( $cursor['status'] !== 'PASS' ) {
				throw new \RuntimeException( implode( ', ', $cursor['findings'] ) );
			}
			$cursor['explain'] = kazus_large_library_cursor_explain();
			return array_merge( $measurement, $cursor );
		}
	);
	$operations[] = kazus_large_library_measure_operation(
		'verify_selection_cursor_scan',
		static function () use ( $fixture ): array {
			$measurement = kazus_large_library_cold_cache();
			$cursor = kazus_large_library_cursor_result( kazus_large_library_verify_cursor_pages( $fixture['expected_attachment_count'] ), $fixture['expected_attachment_count'] );
			$cursor['selection_scope'] = 'local_query_only; verify execution is remote-provider E2E';
			return array_merge( $measurement, $cursor );
		}
	);
	$operations[] = kazus_large_library_measure_operation(
		'restore_selection_cursor_scan',
		static function () use ( $fixture ): array {
			$measurement = kazus_large_library_cold_cache();
			$cursor = kazus_large_library_cursor_result( kazus_large_library_restore_cursor_pages( $fixture['expected_attachment_count'] ), $fixture['expected_attachment_count'] );
			$cursor['selection_scope'] = 'local_query_only; restore execution is remote-provider E2E';
			return array_merge( $measurement, $cursor );
		}
	);
	$operations[] = kazus_large_library_measure_operation(
		'adoption_cursor_scan',
		static function () use ( $fixture ): array {
			$measurement = kazus_large_library_cold_cache();
			if ( ! class_exists( '\\Kazcode\\WpStorage\\Plugin' ) ) {
				throw new \RuntimeException( 'KAZCODE plugin is not available for adoption characterization.' );
			}
			$service = new \Kazcode\WpStorage\Services\AdoptService( \Kazcode\WpStorage\Plugin::instance()->settings() );
			$cursor = kazus_large_library_cursor_result(
				kazus_large_library_service_cursor_pages( static fn( int $after ): array => $service->query_ids( 200, $after, true ), $fixture['expected_legacy_attachment_count'] ),
				$fixture['expected_legacy_attachment_count']
			);
			$cursor['selection_scope'] = 'local_query_only; adoption execution is remote-provider HEAD E2E';
			return array_merge( $measurement, $cursor );
		}
	);
	$operations[] = kazus_large_library_measure_operation(
		'reconciliation_inventory_cursor_scan',
		static function () use ( $fixture ): array {
			$measurement = kazus_large_library_cold_cache();
			$repository = new \Kazcode\WpStorage\Infrastructure\ObjectRepository();
			$cursor = kazus_large_library_cursor_result(
				kazus_large_library_service_cursor_pages( static fn( int $after ): array => $repository->attachment_ids_page( 200, $after ), $fixture['expected_inventory_attachment_count'] ),
				$fixture['expected_inventory_attachment_count']
			);
			$cursor['selection_scope'] = 'local inventory cursor used by policy reconciliation';
			return array_merge( $measurement, $cursor );
		}
	);
	$operations[] = kazus_large_library_measure_operation(
		'background_job_lifecycle',
		static function (): array {
			if ( ! class_exists( '\\Kazcode\\WpStorage\\Plugin' ) ) {
				throw new \RuntimeException( 'KAZCODE plugin is not available for background-job characterization.' );
			}
			return kazus_large_library_background_characterization( \Kazcode\WpStorage\Plugin::instance()->background() );
		}
	);

	return kazus_large_library_report( $fixture, $operations );
}

/**
 * Execute as a WP-CLI eval-file target while remaining safely includeable in PHPUnit.
 */
if ( defined( 'WP_CLI' ) && WP_CLI && getenv( 'KAZUS_BENCHMARK_SCALE' ) !== false ) {
	$scale = (int) getenv( 'KAZUS_BENCHMARK_SCALE' );
	try {
		echo (string) wp_json_encode( kazus_large_library_benchmark( $scale ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	} catch ( \Throwable $error ) {
		echo (string) wp_json_encode( array( 'status' => 'FAIL', 'error_class' => get_class( $error ), 'error_message' => $error->getMessage() ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
		exit( 1 );
	}
}
