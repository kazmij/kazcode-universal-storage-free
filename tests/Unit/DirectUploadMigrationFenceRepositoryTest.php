<?php
declare(strict_types=1);
namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Infrastructure\ReservationCleanupRepository;
use Kazcode\WpStorage\Infrastructure\DirectUploadMigrationFenceRepository;
use PHPUnit\Framework\TestCase;

final class DirectUploadMigrationFenceRepositoryTest extends TestCase {
	public function test_authorize_rolls_back_without_a_fence_when_a_locking_cleanup_read_fails(): void {
		foreach ( array( 'first_cleanup_read' ) as $failure ) {
			$GLOBALS['wpdb'] = new AuthorizeFenceWpdbFake( $failure );
			$this->assertFalse( ( new DirectUploadMigrationFenceRepository() )->authorize( $this->plan() ), $failure );
			$this->assertSame( 0, $GLOBALS['wpdb']->fence_inserts, $failure );
			$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries, $failure );
			$this->assertNotContains( 'COMMIT', $GLOBALS['wpdb']->queries, $failure );
		}
	}

	public function test_authorize_accepts_clean_empty_locking_cleanup_reads(): void {
		$GLOBALS['wpdb'] = new AuthorizeFenceWpdbFake();
		$this->assertTrue( ( new DirectUploadMigrationFenceRepository() )->authorize( $this->plan() ) );
		$this->assertSame( 1, $GLOBALS['wpdb']->fence_inserts );
		$this->assertContains( 'COMMIT', $GLOBALS['wpdb']->queries );
	}

	public function test_authorize_locks_the_migration_fence_before_the_cleanup_table(): void {
		$GLOBALS['wpdb'] = new AuthorizeFenceWpdbFake();

		$this->assertTrue( ( new DirectUploadMigrationFenceRepository() )->authorize( $this->plan() ) );

		$fence_lock = array_search( 'fence_lock', $GLOBALS['wpdb']->locking_reads, true );
		$cleanup_lock = array_search( 'cleanup_lock', $GLOBALS['wpdb']->locking_reads, true );
		$this->assertIsInt( $fence_lock );
		$this->assertIsInt( $cleanup_lock );
		$this->assertLessThan( $cleanup_lock, $fence_lock, 'Migration authorization must take the shared fence lock before cleanup-record locks, matching ReservationCleanupRepository::record().' );
	}

	public function test_created_multipart_identity_is_written_to_the_exact_durable_destination_fence(): void {
		$GLOBALS['wpdb'] = new AuthorizeFenceWpdbFake();
		$this->assertTrue( ( new DirectUploadMigrationFenceRepository() )->record_multipart( 71, 8, 'destination/archive.zip', str_repeat( 'b', 64 ), 'provider-upload-71' ) );
		$this->assertTrue( (bool) array_filter( $GLOBALS['wpdb']->queries, static fn( string $query ): bool => str_contains( $query, 'multipart_upload_id' ) && str_contains( $query, 'provider-upload-71' ) ) );
	}

	public function test_created_multipart_identity_only_sets_an_empty_fence_or_repeats_its_existing_identity(): void {
		$wpdb = new MultipartIdentityFenceWpdbFake();
		$GLOBALS['wpdb'] = $wpdb;
		$fences = new DirectUploadMigrationFenceRepository();

		$this->assertTrue( $fences->record_multipart( 71, 8, 'destination/archive.zip', str_repeat( 'b', 64 ), 'provider-upload-first' ) );
		$this->assertSame( 'provider-upload-first', $wpdb->multipart_upload_id );
		$this->assertTrue( $fences->record_multipart( 71, 8, 'destination/archive.zip', str_repeat( 'b', 64 ), 'provider-upload-first' ) );
		$this->assertFalse( $fences->record_multipart( 71, 8, 'destination/archive.zip', str_repeat( 'b', 64 ), 'provider-upload-second' ) );
		$this->assertSame( 'provider-upload-first', $wpdb->multipart_upload_id, 'A conflicting second provider upload must not replace the original cleanup authority.' );
	}

	public function test_complete_cleanup_removes_an_exact_empty_upload_id_fence_for_a_recovered_upload(): void {
		$GLOBALS['wpdb'] = new CompleteCleanupFenceWpdbFake( 8, 'destination/archive.zip', str_repeat( 'b', 64 ), '' );
		$binding = $this->cleanup_binding( 'provider-upload-71' );

		$this->assertTrue( ( new DirectUploadMigrationFenceRepository() )->complete_cleanup( $binding ) );
		$this->assertStringContainsString( "multipart_upload_id = '' AND 'provider-upload-71' <> ''", $GLOBALS['wpdb']->queries[0] );
		$this->assertTrue( ( new DirectUploadMigrationFenceRepository() )->complete_cleanup( $binding ), 'A retry after the exact fence is removed must allow the cleanup record to finish.' );
		$this->assertStringContainsString( 'SELECT', $GLOBALS['wpdb']->queries[2] );
	}

	public function test_complete_cleanup_rejects_nonempty_mismatched_or_unrelated_fences(): void {
		$cases = array(
			'nonempty mismatch' => array( new CompleteCleanupFenceWpdbFake( 8, 'destination/archive.zip', str_repeat( 'b', 64 ), 'different-upload' ), $this->cleanup_binding( 'provider-upload-71' ) ),
			'unrelated destination' => array( new CompleteCleanupFenceWpdbFake( 8, 'other/archive.zip', str_repeat( 'b', 64 ), '' ), $this->cleanup_binding( 'provider-upload-71' ) ),
			'unrelated profile' => array( new CompleteCleanupFenceWpdbFake( 9, 'destination/archive.zip', str_repeat( 'b', 64 ), '' ), $this->cleanup_binding( 'provider-upload-71' ) ),
			'unrelated fingerprint' => array( new CompleteCleanupFenceWpdbFake( 8, 'destination/archive.zip', str_repeat( 'c', 64 ), '' ), $this->cleanup_binding( 'provider-upload-71' ) ),
		);
		foreach ( $cases as $case => $entry ) {
			$GLOBALS['wpdb'] = $entry[0];
			$binding = $entry[1];
			$this->assertFalse( ( new DirectUploadMigrationFenceRepository() )->complete_cleanup( $binding ), $case );
			$this->assertStringContainsString( 'destination_profile_id', $GLOBALS['wpdb']->queries[0], $case );
			$this->assertStringContainsString( 'destination_object_key', $GLOBALS['wpdb']->queries[0], $case );
			$this->assertStringContainsString( 'destination_fingerprint', $GLOBALS['wpdb']->queries[0], $case );
			$this->assertStringContainsString( "multipart_upload_id = 'provider-upload-71'", $GLOBALS['wpdb']->queries[0], $case );
		}
	}

	public function test_complete_cleanup_fails_closed_when_a_zero_row_delete_leaves_a_mismatched_fence(): void {
		$GLOBALS['wpdb'] = new CompleteCleanupFenceWpdbFake( 8, 'other/archive.zip', str_repeat( 'b', 64 ), '' );

		$this->assertFalse( ( new DirectUploadMigrationFenceRepository() )->complete_cleanup( $this->cleanup_binding( 'provider-upload-71' ) ) );
	}

	public function test_complete_cleanup_fails_closed_when_the_zero_row_delete_confirmation_read_fails(): void {
		$GLOBALS['wpdb'] = new CompleteCleanupFenceWpdbFake( 8, 'destination/archive.zip', str_repeat( 'b', 64 ), '', true, true );

		$this->assertFalse( ( new DirectUploadMigrationFenceRepository() )->complete_cleanup( $this->cleanup_binding( 'provider-upload-71' ) ) );
	}

	/** @return array<string,mixed> */
	private function plan(): array {
		return array( 'attachment_id' => 71, 'source_profile_id' => 7, 'source_object_key' => 'source/archive.zip', 'source_fingerprint' => str_repeat( 'a', 64 ), 'destination_profile_id' => 8, 'destination_object_key' => 'destination/archive.zip', 'destination_fingerprint' => str_repeat( 'b', 64 ) );
	}
	/** @return array<string,mixed> */
	private function cleanup_binding( string $upload_id ): array {
		return array( 'attachment_id' => 71, 'migration_cleanup_location' => array( 'profile_id' => 8, 'object_key' => 'destination/archive.zip', 'profile_fingerprint' => str_repeat( 'b', 64 ) ), 'migration_multipart_upload_id' => $upload_id );
	}
	public function test_locking_fence_read_error_rolls_back_without_committing_source_only_cleanup(): void {
		$GLOBALS['wpdb'] = new FenceReadErrorWpdbFake();
		$binding = array( 'version' => 1, 'attachment_id' => 71, 'blog_id' => 1, 'owner_uuid' => 'owner', 'path_reservation_id' => 11, 'key_reservation_id' => 12, 'relative_path' => '2026/10/archive.zip', 'profile_id' => 7, 'object_key' => 'source/archive.zip', 'profile_fingerprint' => str_repeat( 'a', 64 ) );

		$result = ( new ReservationCleanupRepository() )->record( $binding, 'attachment_deleted:71' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'reservation_cleanup_write_failed', $result->get_error_code() );
		$this->assertSame( 0, $GLOBALS['wpdb']->cleanup_inserts );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
		$this->assertNotContains( 'COMMIT', $GLOBALS['wpdb']->queries );
	}
}

final class AuthorizeFenceWpdbFake {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public array $queries = array();
	public int $fence_inserts = 0;
	/** @var list<string> */
	public array $locking_reads = array();
	private int $cleanup_reads = 0;
	public function __construct( private string $failure = '' ) {}
	public function prepare( string $query, mixed ...$args ): string { foreach ( $args as $arg ) $query = preg_replace( '/%[ds]/', is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query; return $query; }
	public function query( string $query ): int|false { $this->queries[] = $query; if ( str_contains( $query, 'INSERT INTO' ) ) ++$this->fence_inserts; return 1; }
	public function insert( string $table, array $data ): int|false { unset( $table, $data ); ++$this->fence_inserts; return 1; }
	public function get_results( string $query, mixed $output = null ): array|false { unset( $output ); $this->locking_reads[] = str_contains( $query, 's3ms_reservation_cleanup' ) ? 'cleanup_lock' : 'other_lock'; ++$this->cleanup_reads; if ( $this->failure === ( $this->cleanup_reads === 1 ? 'first_cleanup_read' : 'second_cleanup_read' ) ) { $this->last_error = 'Injected locking cleanup SELECT failure'; return false; } return array(); }
	public function get_row( string $query, mixed $output = null ): ?array { unset( $output ); $this->locking_reads[] = str_contains( $query, 's3ms_direct_upload_migration_fences' ) ? 'fence_lock' : 'other_lock'; return null; }
}

final class FenceReadErrorWpdbFake {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public array $queries = array();
	public int $cleanup_inserts = 0;
	public function prepare( string $query, mixed ...$args ): string { foreach ( $args as $arg ) $query = preg_replace( '/%[ds]/', is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query; return $query; }
	public function query( string $query ): int|false { $this->queries[] = $query; if ( str_contains( $query, 'INSERT INTO' ) ) ++$this->cleanup_inserts; return 1; }
	public function get_row( string $query, mixed $output = null ): ?array { unset( $output ); $this->last_error = 'Injected locking SELECT failure'; return null; }
}

final class MultipartIdentityFenceWpdbFake {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public string $multipart_upload_id = '';
	/** @var list<string> */
	public array $queries = array();
	public function prepare( string $query, mixed ...$args ): string { foreach ( $args as $arg ) $query = preg_replace( '/%[ds]/', is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query; return $query; }
	public function query( string $query ): int|false {
		$this->queries[] = $query;
		preg_match( "/SET multipart_upload_id = '([^']+)'/", $query, $matches );
		$requested = $matches[1] ?? '';
		if ( ! str_contains( $query, "AND (multipart_upload_id = '' OR multipart_upload_id =" ) ) {
			$this->multipart_upload_id = $requested;
			return 1;
		}
		if ( $this->multipart_upload_id === '' || $this->multipart_upload_id === $requested ) {
			$this->multipart_upload_id = $requested;
			return $this->multipart_upload_id === $requested && str_contains( $query, "multipart_upload_id = ''" ) ? 1 : 0;
		}
		return 0;
	}
	public function get_var( string $query ): string { $this->queries[] = $query; return $this->multipart_upload_id; }
}

final class CompleteCleanupFenceWpdbFake {
	public string $prefix = 'wp_';
	public string $last_error = '';
	/** @var list<string> */
	public array $queries = array();
	public function __construct( private int $profile_id, private string $object_key, private string $fingerprint, private string $upload_id, private bool $force_zero_delete = false, private bool $fail_confirmation_read = false ) {}
	public function prepare( string $query, mixed ...$args ): string { foreach ( $args as $arg ) $query = preg_replace( '/%[ds]/', is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query; return $query; }
	public function query( string $query ): int|false {
		$this->queries[] = $query;
		if ( $this->force_zero_delete ) return 0;
		if ( $this->profile_id < 1 || ! str_contains( $query, 'destination_profile_id = ' . $this->profile_id ) || ! str_contains( $query, "destination_object_key = '{$this->object_key}'" ) || ! str_contains( $query, "destination_fingerprint = '{$this->fingerprint}'" ) ) return 0;
		if ( $this->upload_id === '' && str_contains( $query, "multipart_upload_id = '' AND 'provider-upload-71' <> ''" ) ) { $this->profile_id = 0; return 1; }
		return $this->upload_id !== '' && str_contains( $query, "multipart_upload_id = '{$this->upload_id}'" ) ? 1 : 0;
	}
	public function get_row( string $query, mixed $output = null ): ?array {
		unset( $output );
		$this->queries[] = $query;
		if ( $this->fail_confirmation_read ) { $this->last_error = 'Injected fence confirmation SELECT failure'; return null; }
		if ( $this->profile_id < 1 ) return null;
		return array( 'destination_profile_id' => $this->profile_id, 'destination_object_key' => $this->object_key, 'destination_fingerprint' => $this->fingerprint, 'multipart_upload_id' => $this->upload_id );
	}
}
