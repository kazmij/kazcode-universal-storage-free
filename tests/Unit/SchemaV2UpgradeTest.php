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

		$this->assertSame( 3, (int) get_option( SchemaMigrator::OPTION_KEY ) );
		$this->assertContains( 'ALTER TABLE wp_s3ms_objects ADD object_key_hash char(64) NOT NULL DEFAULT \'\' AFTER object_key', $GLOBALS['s3ms_schema_queries'] );
		$this->assertContains( 'ALTER TABLE wp_s3ms_objects DROP INDEX profile_object_key', $GLOBALS['s3ms_schema_queries'] );
		$this->assertContains( 'ALTER TABLE wp_s3ms_objects ADD UNIQUE KEY profile_object_key_hash (storage_profile_id, object_key_hash)', $GLOBALS['s3ms_schema_queries'] );
		$this->assertTrue( $this->table_exists( 's3ms_transfer_sessions' ) );
		$this->assertTrue( $this->table_exists( 's3ms_import_runs' ) );
	}

	public function test_upgrade_from_v0_installs_v1_then_v2_sequentially(): void {
		update_option( SchemaMigrator::OPTION_KEY, 0, false );

		( new SchemaMigrator() )->maybe_upgrade();

		$this->assertSame( 3, (int) get_option( SchemaMigrator::OPTION_KEY ) );
		$this->assertSame(
			array(
				'wp_s3ms_storage_profiles',
				'wp_s3ms_objects',
				'wp_s3ms_transfer_sessions',
				'wp_s3ms_import_runs',
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
			$this->assertSame( 3, (int) get_option( SchemaMigrator::OPTION_KEY ) );
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
		public bool $skip_import_table = false;

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
			return null;
		}

	public function get_results( string $query, mixed $output = null ): array {
		unset( $query, $output );
		return array();
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
			}
			return 1;
		}
	}
}
