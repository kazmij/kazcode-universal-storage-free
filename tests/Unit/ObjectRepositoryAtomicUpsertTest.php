<?php
/**
 * ObjectRepository object-key integrity and atomic upsert regressions.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\SchemaMigrator;
use PHPUnit\Framework\TestCase;

final class ObjectRepositoryAtomicUpsertTest extends TestCase {

	private mixed $previous_wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
	}

	protected function tearDown(): void {
		if ( $this->previous_wpdb === null ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		}
		parent::tearDown();
	}

	public function test_schema_uses_full_object_key_hash_not_prefix_unique_index(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Infrastructure/SchemaMigrator.php' );

		$this->assertStringContainsString( 'public const VERSION    = ' . SchemaMigrator::VERSION . ';', $source );
		$this->assertStringContainsString( 'object_key_hash char(64) NOT NULL', $source );
		$this->assertStringContainsString( 'UNIQUE KEY profile_object_key_hash (storage_profile_id, object_key_hash)', $source );
		$this->assertStringContainsString( 'DROP INDEX', $source );
	}

	public function test_schema_indexes_profile_relative_status_for_delivery_reverse_lookup(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Infrastructure/SchemaMigrator.php' );

		$this->assertGreaterThanOrEqual( 12, SchemaMigrator::VERSION );
		$this->assertStringContainsString(
			'KEY profile_relative_status (storage_profile_id, local_relative_path(191), remote_status)',
			$source
		);
	}

	public function test_upsert_inserts_atomically_without_preselect(): void {
		$wpdb = new AtomicUpsertWpdbFake();
		$GLOBALS['wpdb'] = $wpdb;

		$id = ( new ObjectRepository() )->upsert( $this->row( 'same-prefix/a.jpg' ) );

		$this->assertSame( 123, $id );
		$this->assertSame( 0, $wpdb->get_var_calls );
		$this->assertCount( 2, $wpdb->queries );
		$this->assertStringContainsString( 'INSERT INTO wp_s3ms_objects', $wpdb->queries[0] );
		$this->assertStringContainsString( 'object_key_hash', $wpdb->queries[0] );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $wpdb->queries[0] );
		$this->assertStringContainsString( 'id = LAST_INSERT_ID(id)', $wpdb->queries[0] );
		$this->assertStringContainsString( hash( 'sha256', 'same-prefix/a.jpg' ), $wpdb->queries[0] );
	}

	public function test_upsert_returns_existing_id_after_duplicate_update(): void {
		$wpdb = new AtomicUpsertWpdbFake();
		$wpdb->insert_id_after_query = 456;
		$GLOBALS['wpdb'] = $wpdb;

		$id = ( new ObjectRepository() )->upsert(
			array_merge(
				$this->row( 'same-prefix/a.jpg' ),
				array(
					'remote_status' => 'present',
					'size_bytes'    => 999,
				)
			)
		);

		$this->assertSame( 456, $id );
		$this->assertStringContainsString( 'remote_status = VALUES(remote_status)', $wpdb->queries[0] );
		$this->assertStringContainsString( 'size_bytes = VALUES(size_bytes)', $wpdb->queries[0] );
		$this->assertStringNotContainsString( 'created_at = VALUES(created_at)', $wpdb->queries[0] );
	}

	public function test_insert_owned_or_conflict_emits_null_literals_for_cleared_verification(): void {
		$wpdb = new AtomicUpsertWpdbFake();
		$wpdb->insert_id_after_query = 55;
		$wpdb->returned_row          = array(
			'id'                  => 55,
			'attachment_id'       => 99,
			'storage_profile_id'  => 10,
			'local_relative_path' => '2026/09/a.jpg',
			'object_key'          => 'same-prefix/a.jpg',
			'remote_status'       => 'present',
			'size_bytes'          => null,
			'verified_at'         => null,
		);
		$GLOBALS['wpdb'] = $wpdb;

		$row               = $this->row( 'same-prefix/a.jpg' );
		$row['size_bytes'] = null;
		$row['verified_at'] = null;
		$result            = ( new ObjectRepository() )->insert_owned_or_conflict( $row );

		$this->assertTrue( $result['ok'] );
		$sql = $this->owned_insert_sql( $wpdb );
		$this->assertMatchesRegularExpression( '/size_bytes[,)].*NULL/s', $sql );
		$this->assertMatchesRegularExpression( '/verified_at[,)].*NULL/s', $sql );
		$this->assertStringContainsString( 'VALUES (', $sql );
	}

	public function test_insert_owned_or_conflict_uses_owner_preserving_odku_not_blind_upsert(): void {
		$wpdb = new AtomicUpsertWpdbFake();
		$wpdb->insert_id_after_query = 321;
		$wpdb->returned_row          = array(
			'id'                  => 321,
			'attachment_id'       => 99,
			'storage_profile_id'  => 10,
			'local_relative_path' => '2026/09/a.jpg',
			'object_key'          => 'same-prefix/a.jpg',
			'remote_status'       => 'present',
		);
		$GLOBALS['wpdb'] = $wpdb;

		$result = ( new ObjectRepository() )->insert_owned_or_conflict( $this->row( 'same-prefix/a.jpg' ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 321, $result['id'] );
		$sql = $this->owned_insert_sql( $wpdb );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $sql );
		$this->assertStringContainsString( "remote_status IN ('stale','deleted')", $sql );
		$this->assertStringContainsString( 'attachment_id = VALUES(attachment_id) AND BINARY local_relative_path = BINARY VALUES(local_relative_path)', $sql );
		$this->assertStringContainsString( 'VALUES(attachment_id), attachment_id)', $sql );
		$this->assertTrue( $this->queries_contain( $wpdb, 'GET_LOCK' ) );
		$this->assertTrue( $this->queries_contain( $wpdb, 'START TRANSACTION' ) );
		$this->assertTrue( $this->queries_contain( $wpdb, 'FOR UPDATE' ) );
		$this->assertTrue( $this->queries_contain( $wpdb, 'COMMIT' ) );
		$this->assertTrue( $this->queries_contain( $wpdb, 'RELEASE_LOCK' ) );
	}

	public function test_insert_owned_or_conflict_reports_conflict_when_existing_owner_differs_after_odku(): void {
		$wpdb = new AtomicUpsertWpdbFake();
		$wpdb->insert_id_after_query = 321;
		$wpdb->returned_row          = array(
			'id'                  => 321,
			'attachment_id'       => 50,
			'storage_profile_id'  => 10,
			'local_relative_path' => 'other.jpg',
			'object_key'          => 'same-prefix/a.jpg',
			'remote_status'       => 'missing',
		);
		$GLOBALS['wpdb'] = $wpdb;

		$result = ( new ObjectRepository() )->insert_owned_or_conflict( $this->row( 'same-prefix/a.jpg' ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'key_owned_by_other_attachment', $result['reason'] );
		$this->assertSame( 50, (int) $result['existing']['attachment_id'] );
		$this->assertTrue( $this->queries_contain( $wpdb, 'ROLLBACK' ) );
		$this->assertTrue( $this->queries_contain( $wpdb, 'RELEASE_LOCK' ) );
	}

	public function test_insert_owned_or_conflict_refuses_relative_key_conflict_inside_transaction(): void {
		$wpdb = new AtomicUpsertWpdbFake();
		$wpdb->locked_relative_rows = array(
			array(
				'id'                  => 11,
				'attachment_id'       => 99,
				'storage_profile_id'  => 10,
				'local_relative_path' => '2026/09/a.jpg',
				'object_key'          => 'other-prefix/a.jpg',
				'remote_status'       => 'present',
			),
		);
		$GLOBALS['wpdb'] = $wpdb;

		$result = ( new ObjectRepository() )->insert_owned_or_conflict( $this->row( 'same-prefix/a.jpg' ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'relative_key_conflict', $result['reason'] );
		$this->assertTrue( $this->queries_contain( $wpdb, 'GET_LOCK' ) );
		$this->assertTrue( $this->queries_contain( $wpdb, 'START TRANSACTION' ) );
		$this->assertTrue( $this->queries_contain( $wpdb, 'FOR UPDATE' ) );
		$this->assertTrue( $this->queries_contain( $wpdb, 'ROLLBACK' ) );
		$this->assertTrue( $this->queries_contain( $wpdb, 'RELEASE_LOCK' ) );
	}

	/**
	 * @return string
	 */
	private function owned_insert_sql( AtomicUpsertWpdbFake $wpdb ): string {
		foreach ( $wpdb->queries as $query ) {
			if ( str_contains( $query, 'INSERT INTO' ) ) {
				return $query;
			}
		}
		$this->fail( 'Expected owner-preserving INSERT query.' );
	}

	private function queries_contain( AtomicUpsertWpdbFake $wpdb, string $needle ): bool {
		foreach ( $wpdb->queries as $query ) {
			if ( str_contains( $query, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	public function test_upsert_treats_zero_affected_rows_as_success_when_database_returns_id(): void {
		$wpdb = new AtomicUpsertWpdbFake();
		$wpdb->query_result = 0;
		$wpdb->insert_id_after_query = 789;
		$GLOBALS['wpdb'] = $wpdb;

		$id = ( new ObjectRepository() )->upsert( $this->row( 'same-prefix/a.jpg' ) );

		$this->assertSame( 789, $id );
		$this->assertCount( 2, $wpdb->queries );
	}

	public function test_upsert_raises_when_database_query_fails(): void {
		$wpdb = new AtomicUpsertWpdbFake();
		$wpdb->query_result = false;
		$wpdb->last_error = 'duplicate key write failed';
		$GLOBALS['wpdb'] = $wpdb;

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Object upsert failed' );

		( new ObjectRepository() )->upsert( $this->row( 'same-prefix/a.jpg' ) );
	}

	public function test_upsert_fails_closed_when_returned_row_has_different_full_object_key(): void {
		$wpdb = new AtomicUpsertWpdbFake();
		$wpdb->returned_row = array(
			'id'                 => 123,
			'storage_profile_id' => 10,
			'object_key'         => 'same-prefix/a.jpg',
		);
		$GLOBALS['wpdb'] = $wpdb;

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Object upsert returned a mismatched object key.' );

		( new ObjectRepository() )->upsert( $this->row( 'same-prefix/b.jpg' ) );
	}

	public function test_find_by_profile_and_key_uses_object_key_hash_not_collated_text(): void {
		$wpdb = new AtomicUpsertWpdbFake();
		$wpdb->returned_row = array(
			'id'                 => 55,
			'storage_profile_id' => 10,
			'object_key'         => 'Case/Key.jpg',
			'object_key_hash'    => hash( 'sha256', 'Case/Key.jpg' ),
		);
		$GLOBALS['wpdb'] = $wpdb;

		$row = ( new ObjectRepository() )->find_by_profile_and_key( 10, 'Case/Key.jpg' );

		$this->assertNotNull( $row );
		$this->assertStringContainsString( 'object_key_hash', $wpdb->queries[0] );
		$this->assertStringContainsString( hash( 'sha256', 'Case/Key.jpg' ), $wpdb->queries[0] );
		$this->assertStringNotContainsString( 'object_key = ', $wpdb->queries[0] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row( string $object_key, int $profile_id = 10 ): array {
		return array(
			'attachment_id'       => 99,
			'storage_profile_id'  => $profile_id,
			'local_relative_path' => '2026/09/a.jpg',
			'object_key'          => $object_key,
			'variant_type'        => 'original',
			'mime_type'           => 'image/jpeg',
			'size_bytes'          => 123,
			'remote_status'       => 'pending',
			'local_status'        => 'present',
		);
	}
}

final class AtomicUpsertWpdbFake {
	public string $prefix = 'wp_';
	public int $insert_id = 0;
	public string $last_error = '';
	public int $get_var_calls = 0;
	public int|false $query_result = 1;
	public int $insert_id_after_query = 123;
	public ?array $returned_row = null;

	/** @var list<array<string, mixed>> */
	public array $locked_relative_rows = array();

	/** @var list<string> */
	public array $queries = array();

	/** @var list<mixed> */
	public array $last_prepare_args = array();

	public function prepare( string $query, mixed ...$args ): string {
		$this->last_prepare_args = $args;
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$query       = preg_replace( '/%[ds]/', $replacement, $query, 1 ) ?? $query;
		}
		return $query;
	}

	public function get_var( string $query ): mixed {
		$this->queries[] = $query;
		if ( str_contains( $query, 'GET_LOCK' ) || str_contains( $query, 'RELEASE_LOCK' ) ) {
			return 1;
		}
		++$this->get_var_calls;
		throw new \RuntimeException( 'upsert must not SELECT before INSERT.' );
	}

	public function query( string $query ): int|false {
		$this->queries[] = $query;
		if ( str_starts_with( $query, 'START TRANSACTION' ) || str_starts_with( $query, 'COMMIT' ) || str_starts_with( $query, 'ROLLBACK' ) ) {
			return 1;
		}
		if ( $this->query_result !== false ) {
			$this->insert_id = $this->insert_id_after_query;
		}
		return $this->query_result;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function get_results( string $query, mixed $output = null ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$this->queries[] = $query;
		return $this->locked_relative_rows;
	}

	public function get_row( string $query, mixed $output = null ): ?array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$this->queries[] = $query;
		return $this->returned_row ?? array(
			'id'                 => $this->insert_id_after_query,
			'storage_profile_id' => 10,
			'object_key'         => (string) ( $this->last_prepare_args[3] ?? 'same-prefix/a.jpg' ),
		);
	}
}
