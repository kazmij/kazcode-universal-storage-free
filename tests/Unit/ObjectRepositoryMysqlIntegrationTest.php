<?php
/**
 * Real MySQL/MariaDB coverage for long object keys and v1->v2 migration.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace {
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}

	if ( ! function_exists( 'dbDelta' ) ) {
		function dbDelta( string $sql ): array {
			global $wpdb;
			$GLOBALS['s3ms_schema_sql'][] = $sql;
			if ( ! $wpdb instanceof \Kazcode\WpStorage\Tests\MysqliWpdbFake ) {
				$wpdb->query( $sql );
				return array();
			}
			$table_sql = preg_replace( '/\\ACREATE TABLE\\s+/i', 'CREATE TABLE IF NOT EXISTS ', $sql, 1 ) ?? $sql;
			$wpdb->query( $table_sql );
			if (
				str_contains( $sql, 'rejected bigint' )
				&& preg_match( '/\\ACREATE TABLE\\s+([^\\s(]+)/i', $sql, $matches ) === 1
			) {
				$table    = $matches[1];
				$rejected = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'rejected'" );
				if ( ! $rejected ) {
					$wpdb->query( "ALTER TABLE {$table} ADD rejected bigint(20) unsigned NOT NULL DEFAULT 0" );
				}
			}
			return array();
		}
	}
}

namespace Kazcode\WpStorage\Tests {

	use Kazcode\WpStorage\Infrastructure\ObjectRepository;
	use Kazcode\WpStorage\Infrastructure\ReservationRepository;
	use Kazcode\WpStorage\Infrastructure\ReservationCleanupRepository;
	use Kazcode\WpStorage\Infrastructure\DirectUploadFinalizationIntentRepository;
	use Kazcode\WpStorage\Infrastructure\SchemaMigrator;
	use Kazcode\WpStorage\Tests\Support\WpStubs;
	use PHPUnit\Framework\TestCase;

	final class ObjectRepositoryMysqlIntegrationTest extends TestCase {

		private ?\mysqli $db = null;
		private string $prefix = '';
		private mixed $previous_wpdb;

		protected function setUp(): void {
			parent::setUp();
			WpStubs::reset();
			$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
			try {
				$this->db = @new \mysqli( 'db', 'root', 'root', 'wp_71_php84' );
			} catch ( \mysqli_sql_exception $e ) {
				$this->markTestSkipped( 'MariaDB test database is not available: ' . $e->getMessage() );
			}
			if ( $this->db->connect_errno ) {
				$this->markTestSkipped( 'MariaDB test database is not available: ' . $this->db->connect_error );
			}
			$this->db->set_charset( 'utf8mb4' );
			$this->prefix         = 's3ms_it_' . bin2hex( random_bytes( 4 ) ) . '_';
			$GLOBALS['wpdb']      = new MysqliWpdbFake( $this->db, $this->prefix );
			WpStubs::$options     = array();
		}

		protected function tearDown(): void {
			if ( $this->db instanceof \mysqli ) {
				foreach ( array( 's3ms_reservation_cleanup', 's3ms_du_finalization_intents', 's3ms_direct_upload_migration_fences' ) as $suffix ) {
					$this->db->query( "DROP TABLE IF EXISTS `{$this->prefix}{$suffix}`" );
				}
				$table = $this->prefix . 's3ms_import_runs';
				$this->db->query( "DROP TABLE IF EXISTS `{$table}`" );
				$table = $this->prefix . 's3ms_transfer_sessions';
				$this->db->query( "DROP TABLE IF EXISTS `{$table}`" );
			$table = $this->prefix . 's3ms_objects';
			$this->db->query( "DROP TABLE IF EXISTS `{$table}`" );
			$table = $this->prefix . 's3ms_storage_reservations';
			$this->db->query( "DROP TABLE IF EXISTS `{$table}`" );
				$table = $this->prefix . 's3ms_storage_profiles';
				$this->db->query( "DROP TABLE IF EXISTS `{$table}`" );
				$this->db->close();
			}
			if ( $this->previous_wpdb === null ) {
				unset( $GLOBALS['wpdb'] );
			} else {
				$GLOBALS['wpdb'] = $this->previous_wpdb;
			}
			WpStubs::reset();
			parent::tearDown();
		}

		public function test_fresh_schema_allows_same_profile_keys_that_differ_after_191_characters(): void {
			( new SchemaMigrator() )->maybe_upgrade();
			$repo   = new ObjectRepository();
			$prefix = str_repeat( 'p', 220 );

			$id_a = $repo->upsert( $this->row( $prefix . '/a.jpg', 10, 101 ) );
			$id_b = $repo->upsert( $this->row( $prefix . '/b.jpg', 10, 102 ) );

			$this->assertGreaterThan( 0, $id_a );
			$this->assertGreaterThan( 0, $id_b );
			$this->assertNotSame( $id_a, $id_b );
			$this->assertSame( 2, $repo->total_count() );
		}

		public function test_v12_upgrade_adds_delivery_reverse_lookup_index_to_existing_objects_table(): void {
			$this->create_public_v2_objects_table();
			WpStubs::$options[ SchemaMigrator::OPTION_KEY ] = 11;

			( new SchemaMigrator() )->maybe_upgrade();

			$this->assertTrue( $this->index_exists( 'profile_relative_status' ) );
			$this->assertSame( SchemaMigrator::VERSION, (int) WpStubs::$options[ SchemaMigrator::OPTION_KEY ] );
		}

		public function test_finalization_intent_and_cleanup_settle_atomically_without_touching_an_unrelated_marker(): void {
			( new SchemaMigrator() )->maybe_upgrade();
			$binding = array( 'version' => 1, 'attachment_id' => 999, 'blog_id' => 1, 'owner_uuid' => 'session-999', 'path_reservation_id' => 991, 'key_reservation_id' => 992, 'relative_path' => '2026/10/archive.zip', 'profile_id' => 9, 'object_key' => 'private/direct-upload/session-999/archive.zip', 'profile_fingerprint' => 'fingerprint-999' );
			$intent_binding = array_merge( $binding, array( 'direct_upload_session_uuid' => 'session-999', 'direct_upload_attempt_uuid' => 'attempt-999', 'direct_upload_attempt_generation' => 1, 'direct_upload_profile_fingerprint' => 'fingerprint-999' ) );
			unset( $intent_binding['attachment_id'], $intent_binding['profile_fingerprint'] );
			$location = array( 'version' => 2, 'profile_id' => 9, 'object_key' => 'private/direct-upload/session-999/archive.zip', 'relative_path' => '2026/10/archive.zip', 'location_fingerprint' => 'fingerprint-999' );
			$intents = new DirectUploadFinalizationIntentRepository();
			$intent_binding_json = wp_json_encode( $intent_binding );
			$intent_location_json = wp_json_encode( $location );
			foreach ( array( 'marker-999', 'unrelated-marker' ) as $marker ) {
				$this->assertNotFalse( $this->db->query( "INSERT INTO `{$this->prefix}s3ms_du_finalization_intents` (recovery_marker, binding_json, location_json, created_at, updated_at) VALUES ('{$marker}', '" . $this->db->real_escape_string( (string) $intent_binding_json ) . "', '" . $this->db->real_escape_string( (string) $intent_location_json ) . "', UTC_TIMESTAMP(), UTC_TIMESTAMP())" ) );
			}
			$binding = array_merge( $intent_binding, array( 'attachment_id' => 999, 'profile_fingerprint' => 'fingerprint-999', 'direct_upload_finalization_marker' => 'marker-999', 'direct_upload_finalization_intent_binding' => $intent_binding_json, 'direct_upload_finalization_intent_location' => $intent_location_json ) );
			$cleanup = ( new ReservationCleanupRepository() )->record( $binding, 'attachment_deleted:999' );
			$this->assertIsArray( $cleanup );
			$this->assertTrue( ( new ReservationCleanupRepository() )->complete_with_finalization_intent( (int) $cleanup['id'], $binding, 'attachment_deleted:999' ) );
			$this->assertNull( $intents->find( 'marker-999' ) );
			$this->assertNotNull( $intents->find( 'unrelated-marker' ) );
			$this->assertSame( 0, (int) $this->db->query( "SELECT COUNT(*) FROM `{$this->prefix}s3ms_reservation_cleanup`" )->fetch_row()[0] );
		}

		public function test_strict_mysql_reports_an_overlong_reservation_path_as_a_write_failure_not_a_collision(): void {
			$this->db->query( "SET SESSION sql_mode = 'STRICT_ALL_TABLES'" );
			( new SchemaMigrator() )->maybe_upgrade();

			$result = ( new ReservationRepository() )->acquire(
				array(
					'blog_id'       => 1,
					'owner_uuid'    => '22222222-2222-4222-8222-222222222222',
					'relative_path' => str_repeat( 'x', 513 ),
				)
			);

			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'reservation_write_failed', $result->get_error_code() );
		}

		public function test_keys_that_differ_before_191_characters_still_coexist(): void {
			( new SchemaMigrator() )->maybe_upgrade();
			$repo = new ObjectRepository();

			$repo->upsert( $this->row( 'a/' . str_repeat( 'x', 220 ) . '.jpg', 10, 101 ) );
			$repo->upsert( $this->row( 'b/' . str_repeat( 'x', 220 ) . '.jpg', 10, 102 ) );

			$this->assertSame( 2, $repo->total_count() );
		}

		public function test_same_profile_and_same_key_updates_one_logical_row(): void {
			( new SchemaMigrator() )->maybe_upgrade();
			$repo = new ObjectRepository();
			$key  = str_repeat( 'same/', 50 ) . 'image.jpg';

			$id_a = $repo->upsert( $this->row( $key, 10, 101, 100 ) );
			$id_b = $repo->upsert( $this->row( $key, 10, 202, 999 ) );

			$this->assertSame( $id_a, $id_b );
			$this->assertSame( 1, $repo->total_count() );
			$row = $repo->find_by_id( $id_a );
			$this->assertSame( 202, (int) $row['attachment_id'] );
			$this->assertSame( 999, (int) $row['size_bytes'] );
		}

		public function test_same_key_on_different_profiles_creates_distinct_rows(): void {
			( new SchemaMigrator() )->maybe_upgrade();
			$repo = new ObjectRepository();
			$key  = str_repeat( 'shared/', 40 ) . 'image.jpg';

			$id_a = $repo->upsert( $this->row( $key, 10, 101 ) );
			$id_b = $repo->upsert( $this->row( $key, 20, 102 ) );

			$this->assertNotSame( $id_a, $id_b );
			$this->assertSame( 2, $repo->total_count() );
		}

		public function test_php_and_sql_hash_same_raw_object_key_bytes(): void {
			( new SchemaMigrator() )->maybe_upgrade();
			$repo = new ObjectRepository();
			$keys = array(
				'ascii/path.jpg',
				'space path/file name.jpg',
				'encoded/%25-plus+literal.jpg',
				'polskie/zażółć-gęślą-jaźń.jpg',
				'emoji/📦-media.jpg',
				str_repeat( 'long/', 120 ) . 'tail.jpg',
			);

			foreach ( $keys as $i => $key ) {
				$repo->upsert( $this->row( $key, 30 + $i, 500 + $i ) );
				$sql_hash = (string) $GLOBALS['wpdb']->get_var(
					$GLOBALS['wpdb']->prepare(
						"SELECT SHA2(object_key, 256) FROM {$this->prefix}s3ms_objects WHERE storage_profile_id = %d AND object_key = %s",
						30 + $i,
						$key
					)
				);
				$row_hash = (string) $GLOBALS['wpdb']->get_var(
					$GLOBALS['wpdb']->prepare(
						"SELECT object_key_hash FROM {$this->prefix}s3ms_objects WHERE storage_profile_id = %d AND object_key = %s",
						30 + $i,
						$key
					)
				);

				$this->assertSame( hash( 'sha256', $key ), $sql_hash );
				$this->assertSame( hash( 'sha256', $key ), $row_hash );
			}
		}

		public function test_upgrade_from_101_backfills_hash_and_replaces_prefix_unique_index(): void {
			$this->create_v1_objects_table();
			WpStubs::$options[ SchemaMigrator::OPTION_KEY ] = 1;

			$prefix = str_repeat( 'p', 220 );
			$this->db->query(
				"INSERT INTO `{$this->prefix}s3ms_objects` (attachment_id, storage_profile_id, local_relative_path, object_key, variant_type, created_at, updated_at) VALUES " .
				"(1, 10, 'a.jpg', '{$prefix}/a.jpg', 'original', '2026-09-11 00:00:00', '2026-09-11 00:00:00')"
			);

			( new SchemaMigrator() )->maybe_upgrade();
			( new SchemaMigrator() )->maybe_upgrade();

			$this->assertSame( SchemaMigrator::VERSION, (int) WpStubs::$options[ SchemaMigrator::OPTION_KEY ] );
			$this->assertTrue( $this->index_exists( 'profile_object_key_hash' ) );
			$this->assertFalse( $this->index_exists( 'profile_object_key' ) );
			$this->assertSame( hash( 'sha256', $prefix . '/a.jpg' ), (string) $GLOBALS['wpdb']->get_var( "SELECT object_key_hash FROM {$this->prefix}s3ms_objects LIMIT 1" ) );

			$repo = new ObjectRepository();
			$repo->upsert( $this->row( $prefix . '/b.jpg', 10, 2 ) );
			$this->assertSame( 2, $repo->total_count() );
		}

		public function test_upgrade_recovers_when_hash_column_exists_without_backfill(): void {
			$this->create_v1_objects_table();
			$this->db->query( "ALTER TABLE `{$this->prefix}s3ms_objects` ADD object_key_hash char(64) NOT NULL DEFAULT '' AFTER object_key" );
			WpStubs::$options[ SchemaMigrator::OPTION_KEY ] = 1;

			$this->insert_raw_object( str_repeat( 'partial-a-', 25 ) . '/a.jpg' );

			$this->run_upgrade_and_assert_v2();
		}

		public function test_upgrade_recovers_when_hash_column_is_partially_backfilled(): void {
			$this->create_v1_objects_table();
			$this->db->query( "ALTER TABLE `{$this->prefix}s3ms_objects` ADD object_key_hash char(64) NOT NULL DEFAULT '' AFTER object_key" );
			WpStubs::$options[ SchemaMigrator::OPTION_KEY ] = 1;

			$key = str_repeat( 'partial-b-', 25 ) . '/a.jpg';
			$this->insert_raw_object( $key, hash( 'sha256', $key ) );
			$this->insert_raw_object( str_repeat( 'partial-b-', 25 ) . '/b.jpg', '', 20 );

			$this->run_upgrade_and_assert_v2();
		}

		public function test_upgrade_recovers_when_hash_is_backfilled_but_old_index_remains(): void {
			$this->create_v1_objects_table();
			$this->db->query( "ALTER TABLE `{$this->prefix}s3ms_objects` ADD object_key_hash char(64) NOT NULL DEFAULT '' AFTER object_key" );
			WpStubs::$options[ SchemaMigrator::OPTION_KEY ] = 1;

			$key = str_repeat( 'partial-c-', 25 ) . '/a.jpg';
			$this->insert_raw_object( $key, hash( 'sha256', $key ) );

			$this->run_upgrade_and_assert_v2();
		}

		public function test_upgrade_recovers_when_old_index_was_dropped_before_new_index(): void {
			$this->create_v1_objects_table();
			$this->db->query( "ALTER TABLE `{$this->prefix}s3ms_objects` ADD object_key_hash char(64) NOT NULL DEFAULT '' AFTER object_key" );
			$this->db->query( "ALTER TABLE `{$this->prefix}s3ms_objects` DROP INDEX profile_object_key" );
			WpStubs::$options[ SchemaMigrator::OPTION_KEY ] = 1;

			$key = str_repeat( 'partial-d-', 25 ) . '/a.jpg';
			$this->insert_raw_object( $key, hash( 'sha256', $key ) );

			$this->run_upgrade_and_assert_v2();
		}

		public function test_upgrade_recovers_when_new_index_exists_but_schema_option_is_still_v1(): void {
			$this->create_v1_objects_table();
			$this->db->query( "ALTER TABLE `{$this->prefix}s3ms_objects` ADD object_key_hash char(64) NOT NULL DEFAULT '' AFTER object_key" );
			$this->db->query( "ALTER TABLE `{$this->prefix}s3ms_objects` DROP INDEX profile_object_key" );
			WpStubs::$options[ SchemaMigrator::OPTION_KEY ] = 1;

			$key = str_repeat( 'partial-e-', 25 ) . '/a.jpg';
			$this->insert_raw_object( $key, hash( 'sha256', $key ) );
			$this->db->query( "ALTER TABLE `{$this->prefix}s3ms_objects` ADD UNIQUE KEY profile_object_key_hash (storage_profile_id, object_key_hash)" );

			$this->run_upgrade_and_assert_v2();
		}

		public function test_public_102_schema_v2_upgrades_to_v3_without_rewriting_object_identity(): void {
			$this->create_public_v2_objects_table();
			WpStubs::$options[ SchemaMigrator::OPTION_KEY ] = 2;

			$key = str_repeat( 'public-v2/', 80 ) . 'photo.jpg';
			$this->insert_raw_object( $key, hash( 'sha256', $key ), 44 );

			( new SchemaMigrator() )->maybe_upgrade();

			$this->assertSame( SchemaMigrator::VERSION, (int) WpStubs::$options[ SchemaMigrator::OPTION_KEY ] );
			$this->assertTrue( $this->table_exists( 's3ms_transfer_sessions' ) );
			$this->assertTrue( $this->table_exists( 's3ms_import_runs' ) );
			$this->assertTrue( $this->index_exists( 'profile_object_key_hash' ) );
			$this->assertFalse( $this->index_exists( 'profile_object_key' ) );
			$this->assertSame( $key, (string) $GLOBALS['wpdb']->get_var( "SELECT object_key FROM {$this->prefix}s3ms_objects LIMIT 1" ) );
			$this->assertSame( hash( 'sha256', $key ), (string) $GLOBALS['wpdb']->get_var( "SELECT object_key_hash FROM {$this->prefix}s3ms_objects LIMIT 1" ) );
		}

		public function test_ambiguous_old_development_schema_v2_without_public_hash_fails_closed(): void {
			$this->create_v1_objects_table();
			WpStubs::$options[ SchemaMigrator::OPTION_KEY ] = 2;

			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( 'Stored schema version 2 does not match the public object-key hash schema.' );

			( new SchemaMigrator() )->maybe_upgrade();
		}

		public function test_concurrent_upsert_same_profile_and_key_uses_one_logical_row(): void {
			if ( ! function_exists( 'proc_open' ) ) {
				$this->markTestSkipped( 'proc_open is required for the real concurrent upsert regression.' );
			}

			( new SchemaMigrator() )->maybe_upgrade();

			$result_dir = sys_get_temp_dir() . '/kazus-upsert-race-' . bin2hex( random_bytes( 4 ) );
			mkdir( $result_dir );
			$worker_script = "{$result_dir}/worker.php";
			file_put_contents( $worker_script, $this->concurrent_worker_script() );

			try {
				for ( $iteration = 0; $iteration < 100; ++$iteration ) {
					$key   = 'race/' . str_repeat( 'shared-', 30 ) . $iteration . '.jpg';
					$start = "{$result_dir}/start-{$iteration}";
					$procs = array();

					for ( $worker = 0; $worker < 2; ++$worker ) {
						$procs[] = $this->start_concurrent_worker( $worker_script, $iteration, $worker, $key, $start, $result_dir );
					}

					touch( $start );
					foreach ( $procs as $proc ) {
						$stdout = stream_get_contents( $proc['pipes'][1] );
						$stderr = stream_get_contents( $proc['pipes'][2] );
						fclose( $proc['pipes'][1] );
						fclose( $proc['pipes'][2] );
						$exit = proc_close( $proc['process'] );
						$this->assertSame( 0, $exit, "Concurrent worker exited unsuccessfully.\nSTDOUT: {$stdout}\nSTDERR: {$stderr}" );
					}

					$ids = array();
					for ( $worker = 0; $worker < 2; ++$worker ) {
						$result_path = "{$result_dir}/result-{$iteration}-{$worker}.json";
						$this->assertFileExists( $result_path );
						$result = json_decode( (string) file_get_contents( $result_path ), true );
						$this->assertIsArray( $result );
						$this->assertSame( 'ok', $result['status'] ?? null );
						$ids[] = (int) ( $result['id'] ?? 0 );
					}

					$this->assertGreaterThan( 0, $ids[0] );
					$this->assertSame( $ids[0], $ids[1] );
					$count = (int) $GLOBALS['wpdb']->get_var(
						$GLOBALS['wpdb']->prepare(
							"SELECT COUNT(*) FROM {$this->prefix}s3ms_objects WHERE storage_profile_id = %d AND object_key = %s",
							77,
							$key
						)
					);
					$this->assertSame( 1, $count );
				}
			} finally {
				foreach ( glob( "{$result_dir}/*" ) ?: array() as $file ) {
					@unlink( $file );
				}
				@rmdir( $result_dir );
			}
		}

		/**
		 * @return array<string, mixed>
		 */
		private function row( string $object_key, int $profile_id, int $attachment_id, int $size = 123 ): array {
			return array(
				'attachment_id'       => $attachment_id,
				'storage_profile_id'  => $profile_id,
				'local_relative_path' => $attachment_id . '.jpg',
				'object_key'          => $object_key,
				'variant_type'        => 'original',
				'mime_type'           => 'image/jpeg',
				'size_bytes'          => $size,
				'remote_status'       => 'present',
				'local_status'        => 'present',
			);
		}

		private function create_v1_objects_table(): void {
			$this->db->query(
				"CREATE TABLE `{$this->prefix}s3ms_objects` (
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
					PRIMARY KEY (id),
					UNIQUE KEY profile_object_key (storage_profile_id, object_key(191))
				) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
			);
		}

		private function create_public_v2_objects_table(): void {
			$this->create_v1_objects_table();
			$this->db->query( "ALTER TABLE `{$this->prefix}s3ms_objects` ADD object_key_hash char(64) NOT NULL DEFAULT '' AFTER object_key" );
			$this->db->query( "ALTER TABLE `{$this->prefix}s3ms_objects` DROP INDEX profile_object_key" );
			$this->db->query( "ALTER TABLE `{$this->prefix}s3ms_objects` ADD UNIQUE KEY profile_object_key_hash (storage_profile_id, object_key_hash)" );
		}

		private function insert_raw_object( string $key, string $hash = '', int $profile_id = 10 ): void {
			$table   = $this->prefix . 's3ms_objects';
			$columns = 'attachment_id, storage_profile_id, local_relative_path, object_key, variant_type, created_at, updated_at';
			$values  = "1, {$profile_id}, 'a.jpg', '" . $this->db->real_escape_string( $key ) . "', 'original', '2026-09-11 00:00:00', '2026-09-11 00:00:00'";
			if ( $this->column_exists( 'object_key_hash' ) ) {
				$columns .= ', object_key_hash';
				$values  .= ", '" . $this->db->real_escape_string( $hash ) . "'";
			}
			$this->db->query( "INSERT INTO `{$table}` ({$columns}) VALUES ({$values})" );
		}

		private function run_upgrade_and_assert_v2(): void {
			( new SchemaMigrator() )->maybe_upgrade();
			( new SchemaMigrator() )->maybe_upgrade();

			$this->assertSame( SchemaMigrator::VERSION, (int) WpStubs::$options[ SchemaMigrator::OPTION_KEY ] );
			$this->assertTrue( $this->column_exists( 'object_key_hash' ) );
			$this->assertTrue( $this->index_exists( 'profile_object_key_hash' ) );
			$this->assertFalse( $this->index_exists( 'profile_object_key' ) );
			$this->assertSame( '0', (string) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$this->prefix}s3ms_objects WHERE object_key_hash = '' OR object_key_hash IS NULL" ) );
		}

		private function index_exists( string $name ): bool {
			$name = $this->db->real_escape_string( $name );
			$res  = $this->db->query( "SHOW INDEX FROM `{$this->prefix}s3ms_objects` WHERE Key_name = '{$name}'" );
			return $res instanceof \mysqli_result && $res->num_rows > 0;
		}

		private function column_exists( string $name ): bool {
			$name = $this->db->real_escape_string( $name );
			$res  = $this->db->query( "SHOW COLUMNS FROM `{$this->prefix}s3ms_objects` LIKE '{$name}'" );
			return $res instanceof \mysqli_result && $res->num_rows > 0;
		}

		private function table_exists( string $suffix ): bool {
			$name = $this->db->real_escape_string( $this->prefix . $suffix );
			$res  = $this->db->query( "SHOW TABLES LIKE '{$name}'" );
			return $res instanceof \mysqli_result && $res->num_rows > 0;
		}

		/**
		 * @return array{process: resource, pipes: array<int, resource>}
		 */
		private function start_concurrent_worker( string $worker_script, int $iteration, int $worker, string $key, string $start, string $result_dir ): array {
			$command = implode(
				' ',
				array_map(
					'escapeshellarg',
					array(
						PHP_BINARY,
						$worker_script,
						dirname( __DIR__, 2 ),
						$this->prefix,
						(string) $iteration,
						(string) $worker,
						$key,
						$start,
						$result_dir,
					)
				)
			);
			$pipes = array();
			$proc  = proc_open(
				$command,
				array(
					1 => array( 'pipe', 'w' ),
					2 => array( 'pipe', 'w' ),
				),
				$pipes
			);
			if ( ! is_resource( $proc ) ) {
				$this->fail( 'Could not start concurrent upsert worker process.' );
			}
			return array(
				'process' => $proc,
				'pipes'   => $pipes,
			);
		}

		private function concurrent_worker_script(): string {
			return <<<'PHP'
<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

require $argv[1] . '/vendor/autoload.php';

final class KazusRaceWpdb {
	public int $insert_id = 0;
	public string $last_error = '';

	public function __construct(
		private mysqli $db,
		public string $prefix
	) {}

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . $this->db->real_escape_string( (string) $arg ) . "'";
			$query       = preg_replace( '/%[ds]/', $replacement, $query, 1 ) ?? $query;
		}
		return $query;
	}

	public function query( string $query ): int|false {
		$result = $this->db->query( $query );
		if ( false === $result ) {
			$this->last_error = $this->db->error;
			return false;
		}
		$this->insert_id = (int) $this->db->insert_id;
		return true === $result ? $this->db->affected_rows : 0;
	}

	public function get_row( string $query, mixed $output = null ): ?array {
		$result = $this->db->query( $query );
		if ( ! $result instanceof mysqli_result ) {
			$this->last_error = $this->db->error;
			return null;
		}
		$row = $result->fetch_assoc();
		return is_array( $row ) ? $row : null;
	}
}

$prefix     = $argv[2];
$iteration  = (int) $argv[3];
$worker     = (int) $argv[4];
$key        = $argv[5];
$start      = $argv[6];
$result_dir = $argv[7];
$result     = "{$result_dir}/result-{$iteration}-{$worker}.json";

$db = @new mysqli( 'db', 'root', 'root', 'wp_71_php84' );
if ( $db->connect_errno ) {
	file_put_contents( $result, (string) json_encode( array( 'status' => 'error', 'error' => $db->connect_error ) ) );
	exit( 1 );
}
$db->set_charset( 'utf8mb4' );
$GLOBALS['wpdb'] = new KazusRaceWpdb( $db, $prefix );

while ( ! file_exists( $start ) ) {
	usleep( 1000 );
}

try {
	$id = ( new Kazcode\WpStorage\Infrastructure\ObjectRepository() )->upsert(
		array(
			'attachment_id'       => 700000 + ( $iteration * 2 ) + $worker,
			'storage_profile_id'  => 77,
			'local_relative_path' => "race-{$iteration}-{$worker}.jpg",
			'object_key'          => $key,
			'variant_type'        => 'original',
			'mime_type'           => 'image/jpeg',
			'size_bytes'          => 1000 + $worker,
			'remote_status'       => 'present',
			'local_status'        => 'present',
		)
	);
	file_put_contents( $result, (string) json_encode( array( 'status' => 'ok', 'id' => $id ) ) );
} catch ( Throwable $e ) {
	file_put_contents( $result, (string) json_encode( array( 'status' => 'error', 'error' => $e->getMessage() ) ) );
	exit( 1 );
} finally {
	$db->close();
}
PHP;
		}
	}

	final class MysqliWpdbFake {
		public int $insert_id = 0;
		public string $last_error = '';
		public int $last_errno = 0;

		public function __construct(
			private \mysqli $db,
			public string $prefix
		) {}

		public function get_charset_collate(): string {
			return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
		}

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$replacement = is_int( $arg ) ? (string) $arg : "'" . $this->db->real_escape_string( (string) $arg ) . "'";
				$query       = preg_replace( '/%[ds]/', $replacement, $query, 1 ) ?? $query;
			}
			return $query;
		}

		public function query( string $query ): int|false {
			$result = $this->db->query( $query );
			if ( $result === false ) {
				$this->last_error = $this->db->error;
				$this->last_errno = $this->db->errno;
				return false;
			}
			$this->insert_id = (int) $this->db->insert_id;
			return $result === true ? $this->db->affected_rows : 0;
		}

		public function insert( string $table, array $data ): bool {
			$columns = array_map( static fn( string $column ): string => "`{$column}`", array_keys( $data ) );
			$values = array_map( fn( $value ): string => $value === null ? 'NULL' : "'" . $this->db->real_escape_string( (string) $value ) . "'", array_values( $data ) );
			try {
				$result = $this->db->query( 'INSERT INTO ' . $table . ' (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $values ) . ')' );
			} catch ( \mysqli_sql_exception $error ) {
				$this->last_error = $error->getMessage();
				$this->last_errno = $error->getCode();
				return false;
			}
			if ( $result === false ) {
				$this->last_error = $this->db->error;
				$this->last_errno = $this->db->errno;
				return false;
			}
			$this->insert_id = (int) $this->db->insert_id;
			return true;
		}

		public function delete( string $table, array $where, array $format = array() ): int|false { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			$clauses = array();
			foreach ( $where as $column => $value ) $clauses[] = "`{$column}` = " . (int) $value;
			return $this->query( "DELETE FROM {$table} WHERE " . implode( ' AND ', $clauses ) );
		}

		public function get_var( string $query ): mixed {
			$result = $this->db->query( $query );
			if ( ! $result instanceof \mysqli_result ) {
				$this->last_error = $this->db->error;
				return null;
			}
			$row = $result->fetch_row();
			return is_array( $row ) ? $row[0] : null;
		}

		public function get_results( string $query, mixed $output = null ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			$result = $this->db->query( $query );
			if ( ! $result instanceof \mysqli_result ) {
				$this->last_error = $this->db->error;
				return array();
			}
			$rows = array();
			while ( $row = $result->fetch_assoc() ) {
				$rows[] = $row;
			}
			return $rows;
		}

		public function get_row( string $query, mixed $output = null ): ?array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			$result = $this->db->query( $query );
			if ( ! $result instanceof \mysqli_result ) {
				$this->last_error = $this->db->error;
				return null;
			}
			$row = $result->fetch_assoc();
			return is_array( $row ) ? $row : null;
		}
	}
}
