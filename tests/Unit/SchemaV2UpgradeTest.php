<?php
/**
 * Schema v2 upgrade tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace {

if ( ! function_exists( 'dbDelta' ) ) {
	/** @param string $sql */
	function dbDelta( string $sql ): void {
		$GLOBALS['s3ms_schema_sql'][] = $sql;
		$wpdb = $GLOBALS['wpdb'] ?? null;
			if ( is_object( $wpdb ) && method_exists( $wpdb, 'query' ) ) {
				$wpdb->query( $sql );
			}
		}
}
}

namespace Kazcode\WpStorage\Tests {

use Kazcode\WpStorage\Infrastructure\SchemaMigrator;
use PHPUnit\Framework\TestCase;

final class SchemaV2UpgradeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['s3ms_schema_sql']     = array();
		$GLOBALS['s3ms_schema_queries'] = array();
		$GLOBALS['wpdb']                = new SchemaV2WpdbStub();
		update_option( SchemaMigrator::OPTION_KEY, 1, false );
	}

	public function test_upgrade_from_v1_creates_public_v2_hash_then_v3_transfer_and_import_tables(): void {
		( new SchemaMigrator() )->maybe_upgrade();

		$this->assertSame( SchemaMigrator::VERSION, (int) get_option( SchemaMigrator::OPTION_KEY ) );
		$this->assertContains( 'ALTER TABLE wp_s3ms_objects ADD object_key_hash char(64) NOT NULL DEFAULT \'\' AFTER object_key', $GLOBALS['s3ms_schema_queries'] );
		$this->assertContains( 'ALTER TABLE wp_s3ms_objects DROP INDEX profile_object_key', $GLOBALS['s3ms_schema_queries'] );
		$this->assertContains( 'ALTER TABLE wp_s3ms_objects ADD UNIQUE KEY profile_object_key_hash (storage_profile_id, object_key_hash)', $GLOBALS['s3ms_schema_queries'] );
		$this->assertTrue( $this->table_exists( 's3ms_transfer_sessions' ) );
		$this->assertTrue( $this->table_exists( 's3ms_import_runs' ) );
		$this->assertTrue( $this->table_exists( 's3ms_import_run_items' ) );
		$this->assertTrue( $this->table_exists( 's3ms_storage_reservations' ) );
		$this->assertTrue( $this->table_exists( 's3ms_reservation_cleanup' ) );
		$this->assertTrue( $this->table_exists( 's3ms_du_finalization_intents' ) );
		$this->assertTrue( $this->table_exists( 's3ms_direct_upload_migration_fences' ) );
	}

	public function test_upgrade_from_v0_installs_v1_then_v2_sequentially(): void {
		update_option( SchemaMigrator::OPTION_KEY, 0, false );

		( new SchemaMigrator() )->maybe_upgrade();

		$this->assertSame( SchemaMigrator::VERSION, (int) get_option( SchemaMigrator::OPTION_KEY ) );
		$this->assertSame(
			array(
				'wp_s3ms_storage_profiles',
				'wp_s3ms_objects',
				'wp_s3ms_transfer_sessions',
				'wp_s3ms_import_runs',
				'wp_s3ms_import_runs',
				'wp_s3ms_import_runs',
				'wp_s3ms_storage_reservations',
				'wp_s3ms_reservation_cleanup',
				'wp_s3ms_du_finalization_intents',
				'wp_s3ms_direct_upload_migration_fences',
				'wp_s3ms_import_run_items',
			),
			array_map(
				static function ( string $sql ): string {
					preg_match( '/CREATE TABLE ([^ ]+)/', $sql, $matches );
					return $matches[1] ?? '';
				},
				$GLOBALS['s3ms_schema_sql']
			)
		);
	}

		public function test_second_upgrade_is_idempotent(): void {
			$migrator = new SchemaMigrator();
			$migrator->maybe_upgrade();
			$first_install_sql = $GLOBALS['s3ms_schema_sql'];

		$migrator->maybe_upgrade();

		$this->assertSame( $first_install_sql, $GLOBALS['s3ms_schema_sql'] );
			$this->assertSame( SchemaMigrator::VERSION, (int) get_option( SchemaMigrator::OPTION_KEY ) );
		}

		public function test_v3_upgrade_does_not_mark_complete_when_import_table_is_missing(): void {
			$GLOBALS['wpdb']->hash_column       = true;
			$GLOBALS['wpdb']->hash_index        = true;
			$GLOBALS['wpdb']->skip_import_table = true;
			update_option( SchemaMigrator::OPTION_KEY, 2, false );

			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( 'Unable to verify s3ms v3 schema.' );

			( new SchemaMigrator() )->maybe_upgrade();
		}

	public function test_v4_upgrade_adds_import_skipped_counter(): void {
		$GLOBALS['wpdb']->hash_column    = true;
		$GLOBALS['wpdb']->hash_index     = true;
		$GLOBALS['wpdb']->transfer_table = true;
		$GLOBALS['wpdb']->import_table   = true;
		update_option( SchemaMigrator::OPTION_KEY, 3, false );

		( new SchemaMigrator() )->maybe_upgrade();

		$this->assertSame( SchemaMigrator::VERSION, (int) get_option( SchemaMigrator::OPTION_KEY ) );
		$this->assertTrue( $GLOBALS['wpdb']->skipped_column );
	}

	public function test_v5_upgrade_adds_import_rejected_counter(): void {
		$GLOBALS['wpdb']->hash_column    = true;
		$GLOBALS['wpdb']->hash_index     = true;
		$GLOBALS['wpdb']->transfer_table = true;
		$GLOBALS['wpdb']->import_table   = true;
		$GLOBALS['wpdb']->skipped_column = true;
		update_option( SchemaMigrator::OPTION_KEY, 4, false );

		( new SchemaMigrator() )->maybe_upgrade();

		$this->assertSame( SchemaMigrator::VERSION, (int) get_option( SchemaMigrator::OPTION_KEY ) );
		$this->assertTrue( $GLOBALS['wpdb']->rejected_column );
	}

	public function test_v6_upgrade_does_not_mark_complete_without_innodb_and_unique_hash_indexes(): void {
		$GLOBALS['wpdb']->hash_column       = true;
		$GLOBALS['wpdb']->hash_index        = true;
		$GLOBALS['wpdb']->transfer_table    = true;
		$GLOBALS['wpdb']->import_table      = true;
		$GLOBALS['wpdb']->skipped_column    = true;
		$GLOBALS['wpdb']->rejected_column   = true;
		$GLOBALS['wpdb']->reservation_table = true;
		$GLOBALS['wpdb']->reservation_engine = 'MyISAM';
		update_option( SchemaMigrator::OPTION_KEY, 5, false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Unable to verify s3ms storage reservations schema.' );

		( new SchemaMigrator() )->maybe_upgrade();
	}

	public function test_v6_upgrade_rejects_missing_required_nonindexed_column(): void {
		$GLOBALS['wpdb']->hash_column        = true;
		$GLOBALS['wpdb']->hash_index         = true;
		$GLOBALS['wpdb']->transfer_table     = true;
		$GLOBALS['wpdb']->import_table       = true;
		$GLOBALS['wpdb']->skipped_column     = true;
		$GLOBALS['wpdb']->rejected_column    = true;
		$GLOBALS['wpdb']->reservation_table  = true;
		$GLOBALS['wpdb']->omit_reservation_column = 'owner_uuid';
		update_option( SchemaMigrator::OPTION_KEY, 5, false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Unable to verify s3ms storage reservations schema.' );

		( new SchemaMigrator() )->maybe_upgrade();
	}

	public function test_v6_upgrade_rejects_incompatible_required_column_definition(): void {
		$GLOBALS['wpdb']->hash_column        = true;
		$GLOBALS['wpdb']->hash_index         = true;
		$GLOBALS['wpdb']->transfer_table     = true;
		$GLOBALS['wpdb']->import_table       = true;
		$GLOBALS['wpdb']->skipped_column     = true;
		$GLOBALS['wpdb']->rejected_column    = true;
		$GLOBALS['wpdb']->reservation_table  = true;
		$GLOBALS['wpdb']->reservation_column_overrides['object_key_hash']['Type'] = 'char(1)';
		update_option( SchemaMigrator::OPTION_KEY, 5, false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Unable to verify s3ms storage reservations schema.' );

		( new SchemaMigrator() )->maybe_upgrade();
	}

	public function test_v6_upgrade_rejects_missing_required_primary_and_secondary_keys(): void {
		$GLOBALS['wpdb']->hash_column        = true;
		$GLOBALS['wpdb']->hash_index         = true;
		$GLOBALS['wpdb']->transfer_table     = true;
		$GLOBALS['wpdb']->import_table       = true;
		$GLOBALS['wpdb']->skipped_column     = true;
		$GLOBALS['wpdb']->rejected_column    = true;
		$GLOBALS['wpdb']->reservation_table  = true;
		$GLOBALS['wpdb']->reservation_column_overrides['id']['Key'] = 'MUL';
		$GLOBALS['wpdb']->drop_reservation_index = 'owner_state';
		update_option( SchemaMigrator::OPTION_KEY, 5, false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Unable to verify s3ms storage reservations schema.' );

		( new SchemaMigrator() )->maybe_upgrade();
	}

	public function test_v6_upgrade_rejects_named_unique_index_on_wrong_columns(): void {
		$GLOBALS['wpdb']->hash_column        = true;
		$GLOBALS['wpdb']->hash_index         = true;
		$GLOBALS['wpdb']->transfer_table     = true;
		$GLOBALS['wpdb']->import_table       = true;
		$GLOBALS['wpdb']->skipped_column     = true;
		$GLOBALS['wpdb']->rejected_column    = true;
		$GLOBALS['wpdb']->reservation_table  = true;
		$GLOBALS['wpdb']->reservation_indexes[2]['Column_name'] = 'object_key_hash';
		update_option( SchemaMigrator::OPTION_KEY, 5, false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Unable to verify s3ms storage reservations schema.' );

		( new SchemaMigrator() )->maybe_upgrade();
	}

	private function table_exists( string $table ): bool {
		foreach ( $GLOBALS['s3ms_schema_sql'] as $sql ) {
			if ( str_contains( $sql, 'CREATE TABLE wp_' . $table ) ) {
				return true;
			}
		}

		return false;
	}
}

final class SchemaV2WpdbStub {
	public string $prefix = 'wp_';
	/** @var list<string> */
	public array $queries = array();
		public bool $hash_column = false;
		public bool $old_index = true;
		public bool $hash_index = false;
		public bool $transfer_table = false;
	public bool $import_table = false;
	public bool $import_items_table = false;
		public bool $skipped_column = false;
		public bool $rejected_column = false;
	public bool $skip_import_table = false;
	public bool $reservation_table = false;
	public bool $reservation_cleanup_table = false;
	public bool $finalization_intent_table = false;
	public bool $migration_fence_table = false;
	public string $reservation_engine = 'InnoDB';
	public ?string $omit_reservation_column = null;
	/** @var list<string> */
	public array $reservation_columns = array(
		'id', 'uuid', 'blog_id', 'relative_path', 'relative_path_hash', 'profile_id',
		'object_key', 'object_key_hash', 'owner_uuid', 'state', 'worker_token',
		'fencing_epoch', 'lease_expires_at', 'settled_at', 'settlement_proof',
		'created_at', 'updated_at',
	);
	/** @var array<string,array<string,string>> */
	public array $reservation_column_overrides = array();
	public ?string $drop_reservation_index = null;
	/** @var list<array{Key_name: string, Non_unique: int, Seq_in_index: int, Column_name: string}> */
	public array $reservation_indexes = array(
		array( 'Key_name' => 'PRIMARY', 'Non_unique' => 0, 'Seq_in_index' => 1, 'Column_name' => 'id' ),
		array( 'Key_name' => 'uuid', 'Non_unique' => 0, 'Seq_in_index' => 1, 'Column_name' => 'uuid' ),
		array( 'Key_name' => 'blog_relative_path_hash', 'Non_unique' => 0, 'Seq_in_index' => 1, 'Column_name' => 'blog_id' ),
		array( 'Key_name' => 'blog_relative_path_hash', 'Non_unique' => 0, 'Seq_in_index' => 2, 'Column_name' => 'relative_path_hash' ),
		array( 'Key_name' => 'profile_object_key_hash', 'Non_unique' => 0, 'Seq_in_index' => 1, 'Column_name' => 'profile_id' ),
		array( 'Key_name' => 'profile_object_key_hash', 'Non_unique' => 0, 'Seq_in_index' => 2, 'Column_name' => 'object_key_hash' ),
		array( 'Key_name' => 'owner_state', 'Non_unique' => 1, 'Seq_in_index' => 1, 'Column_name' => 'owner_uuid' ),
		array( 'Key_name' => 'owner_state', 'Non_unique' => 1, 'Seq_in_index' => 2, 'Column_name' => 'state' ),
		array( 'Key_name' => 'lease_expiry', 'Non_unique' => 1, 'Seq_in_index' => 1, 'Column_name' => 'lease_expires_at' ),
	);

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$query       = preg_replace( '/%[ds]/', $replacement, $query, 1 ) ?? $query;
		}
		return $query;
	}

	public function get_var( string $query ): mixed {
		if ( str_contains( $query, 'SHOW COLUMNS' ) && str_contains( $query, 'object_key_hash' ) ) {
			return $this->hash_column ? 'object_key_hash' : null;
		}
		if ( str_contains( $query, 'SHOW COLUMNS' ) && str_contains( $query, "'skipped'" ) ) {
			return $this->skipped_column ? 'skipped' : null;
		}
		if ( str_contains( $query, 'SHOW COLUMNS' ) && str_contains( $query, "'rejected'" ) ) {
			return $this->rejected_column ? 'rejected' : null;
		}
		if ( str_contains( $query, 'SHOW INDEX' ) && str_contains( $query, 'profile_object_key_hash' ) ) {
			return $this->hash_index ? 'profile_object_key_hash' : null;
		}
			if ( str_contains( $query, 'SHOW INDEX' ) && str_contains( $query, 'profile_object_key' ) ) {
				return $this->old_index ? 'profile_object_key' : null;
			}
			if ( str_contains( $query, 'SHOW TABLES' ) && str_contains( $query, 's3ms_transfer_sessions' ) ) {
				return $this->transfer_table ? 'wp_s3ms_transfer_sessions' : null;
			}
			if ( str_contains( $query, 'SHOW TABLES' ) && str_contains( $query, 's3ms_import_runs' ) ) {
				return $this->import_table ? 'wp_s3ms_import_runs' : null;
			}
			if ( str_contains( $query, 'SHOW TABLES' ) && str_contains( $query, 's3ms_import_run_items' ) ) {
				return $this->import_items_table ? 'wp_s3ms_import_run_items' : null;
			}
			if ( str_contains( $query, 'SHOW TABLES' ) && str_contains( $query, 's3ms_storage_reservations' ) ) {
				return $this->reservation_table ? 'wp_s3ms_storage_reservations' : null;
			}
			if ( str_contains( $query, 'SHOW TABLES' ) && str_contains( $query, 's3ms_reservation_cleanup' ) ) {
				return $this->reservation_cleanup_table ? 'wp_s3ms_reservation_cleanup' : null;
			}
			if ( str_contains( $query, 'SHOW TABLES' ) && str_contains( $query, 's3ms_du_finalization_intents' ) ) return $this->finalization_intent_table ? 'wp_s3ms_du_finalization_intents' : null;
			if ( str_contains( $query, 'SHOW TABLES' ) && str_contains( $query, 's3ms_direct_upload_migration_fences' ) ) return $this->migration_fence_table ? 'wp_s3ms_direct_upload_migration_fences' : null;
			return null;
		}

	public function get_results( string $query, mixed $output = null ): array {
		unset( $output );
		if ( str_contains( $query, 'SHOW COLUMNS' ) && str_contains( $query, 's3ms_storage_reservations' ) ) {
			$columns = $this->reservation_columns;
			if ( $this->omit_reservation_column !== null ) {
				$columns = array_values( array_diff( $columns, array( $this->omit_reservation_column ) ) );
			}
			$definitions = array(
				'id' => array( 'Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => 'auto_increment' ),
				'uuid' => array( 'Type' => 'char(36)', 'Null' => 'NO', 'Key' => 'UNI', 'Default' => null, 'Extra' => '' ),
				'blog_id' => array( 'Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Key' => 'MUL', 'Default' => null, 'Extra' => '' ),
				'relative_path' => array( 'Type' => 'varchar(512)', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => '' ),
				'relative_path_hash' => array( 'Type' => 'char(64)', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => '' ),
				'profile_id' => array( 'Type' => 'bigint(20) unsigned', 'Null' => 'YES', 'Key' => 'MUL', 'Default' => null, 'Extra' => '' ),
				'object_key' => array( 'Type' => 'varchar(1024)', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => '' ),
				'object_key_hash' => array( 'Type' => 'char(64)', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => '' ),
				'owner_uuid' => array( 'Type' => 'char(36)', 'Null' => 'NO', 'Key' => 'MUL', 'Default' => null, 'Extra' => '' ),
				'state' => array( 'Type' => 'varchar(16)', 'Null' => 'NO', 'Key' => '', 'Default' => null, 'Extra' => '' ),
				'worker_token' => array( 'Type' => 'char(36)', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => '' ),
				'fencing_epoch' => array( 'Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Key' => '', 'Default' => '0', 'Extra' => '' ),
				'lease_expires_at' => array( 'Type' => 'datetime', 'Null' => 'YES', 'Key' => 'MUL', 'Default' => null, 'Extra' => '' ),
				'settled_at' => array( 'Type' => 'datetime', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => '' ),
				'settlement_proof' => array( 'Type' => 'varchar(191)', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => '' ),
				'created_at' => array( 'Type' => 'datetime', 'Null' => 'NO', 'Key' => '', 'Default' => null, 'Extra' => '' ),
				'updated_at' => array( 'Type' => 'datetime', 'Null' => 'NO', 'Key' => '', 'Default' => null, 'Extra' => '' ),
			);
			return array_map(
				function ( string $name ) use ( $definitions ): array {
					return array_merge( array( 'Field' => $name ), $definitions[ $name ], $this->reservation_column_overrides[ $name ] ?? array() );
				},
				$columns
			);
		}
		if ( str_contains( $query, 'SHOW INDEX' ) && str_contains( $query, 's3ms_storage_reservations' ) ) {
			if ( $this->drop_reservation_index === null ) {
				return $this->reservation_indexes;
			}
			return array_values( array_filter(
				$this->reservation_indexes,
				fn( array $index ): bool => $index['Key_name'] !== $this->drop_reservation_index
			) );
		}
		return array();
	}

	public function get_row( string $query, mixed $output = null ): ?array {
		unset( $output );
		if ( str_contains( $query, 'SHOW TABLE STATUS' ) && str_contains( $query, 's3ms_storage_reservations' ) && $this->reservation_table ) {
			return array( 'Name' => 'wp_s3ms_storage_reservations', 'Engine' => $this->reservation_engine );
		}
		return null;
	}

	public function query( string $query ): int|false {
		$GLOBALS['s3ms_schema_queries'][] = $query;
		if ( str_contains( $query, 'ADD object_key_hash' ) ) {
			$this->hash_column = true;
		}
		if ( str_contains( $query, 'DROP INDEX profile_object_key' ) ) {
			$this->old_index = false;
		}
			if ( str_contains( $query, 'ADD UNIQUE KEY profile_object_key_hash' ) ) {
				$this->hash_index = true;
			}
			if ( str_contains( $query, 'CREATE TABLE wp_s3ms_transfer_sessions' ) ) {
				$this->transfer_table = true;
			}
			if ( str_contains( $query, 'CREATE TABLE wp_s3ms_import_runs' ) ) {
				if ( $this->skip_import_table ) {
					return false;
				}
				$this->import_table = true;
				if ( str_contains( $query, 'skipped bigint' ) ) {
					$this->skipped_column = true;
				}
				if ( str_contains( $query, 'rejected bigint' ) ) {
					$this->rejected_column = true;
				}
			}
			if ( str_contains( $query, 'CREATE TABLE wp_s3ms_import_run_items' ) ) {
				$this->import_items_table = true;
			}
			if ( str_contains( $query, 'CREATE TABLE wp_s3ms_storage_reservations' ) ) {
				$this->reservation_table = true;
			}
			if ( str_contains( $query, 'CREATE TABLE wp_s3ms_reservation_cleanup' ) ) {
				$this->reservation_cleanup_table = true;
			}
			if ( str_contains( $query, 'CREATE TABLE wp_s3ms_du_finalization_intents' ) ) $this->finalization_intent_table = true;
			if ( str_contains( $query, 'CREATE TABLE wp_s3ms_direct_upload_migration_fences' ) ) $this->migration_fence_table = true;
			return 1;
		}
	}
}
