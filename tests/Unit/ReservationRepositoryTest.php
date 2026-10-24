<?php
/**
 * Durable reservation registry tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Domain\ReservationState;
use Kazcode\WpStorage\Infrastructure\ReservationRepository;
use PHPUnit\Framework\TestCase;

final class ReservationRepositoryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wpdb'] = new ReservationWpdbFake();
	}

	public function test_unit_bootstrap_provides_wordpress_array_output_mode(): void {
		$this->assertSame( 'ARRAY_A', \ARRAY_A );
	}

	public function test_acquire_hashes_exact_path_and_returns_durable_row(): void {
		$result = ( new ReservationRepository() )->acquire(
			array(
				'uuid'          => '11111111-1111-4111-8111-111111111111',
				'blog_id'       => 1,
				'owner_uuid'    => '22222222-2222-4222-8222-222222222222',
				'relative_path' => '2026/09/file.bin',
				'state'         => ReservationState::PINNED,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( ReservationState::PINNED, $result['state'] );
		$this->assertSame( hash( 'sha256', '2026/09/file.bin' ), $GLOBALS['wpdb']->inserted['relative_path_hash'] );
		$this->assertNull( $GLOBALS['wpdb']->inserted['object_key'] );
	}

	public function test_conflict_is_not_reclaimed_when_a_worker_lease_has_expired(): void {
		$GLOBALS['wpdb']->insert_result = false;
		$GLOBALS['wpdb']->last_error = 'Duplicate entry for key profile_object_key_hash';
		$result = ( new ReservationRepository() )->acquire(
			array(
				'uuid'       => '11111111-1111-4111-8111-111111111111',
				'blog_id'    => 1,
				'owner_uuid' => '22222222-2222-4222-8222-222222222222',
				'profile_id' => 9,
				'object_key' => 'direct-upload/session/attempt/file.bin',
			)
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'reservation_conflict', $result->get_error_code() );
		$this->assertCount( 1, $GLOBALS['wpdb']->inserts );
	}

	public function test_non_duplicate_write_failure_is_not_misreported_as_a_retryable_collision(): void {
		$GLOBALS['wpdb']->insert_result = false;
		$GLOBALS['wpdb']->last_error = 'Data too long for column relative_path';
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

	public function test_claim_fences_worker_without_releasing_pinned_reservation(): void {
		$result = ( new ReservationRepository() )->claim( 7, 'owner', 'worker', 60 );

		$this->assertIsArray( $result );
		$this->assertSame( ReservationState::PINNED, $result['state'] );
		$this->assertStringContainsString( 'state IN (\'held\', \'pinned\')', $GLOBALS['wpdb']->queries[0] );
		$this->assertStringContainsString( 'fencing_epoch = fencing_epoch + 1', $GLOBALS['wpdb']->queries[0] );
	}

	public function test_retirement_requires_settlement_evidence_and_matching_fence(): void {
		$repo   = new ReservationRepository();
		$denied = $repo->retire_settled_attempt( 7, 'owner', 'worker', 3, '' );
		$this->assertTrue( is_wp_error( $denied ) );
		$this->assertSame( 'settlement_required', $denied->get_error_code() );

		$result = $repo->retire_settled_attempt( 7, 'owner', 'worker', 3, 'provider-abort-confirmed' );
		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'fencing_epoch = 3', $GLOBALS['wpdb']->queries[0] );
		$this->assertStringContainsString( 'settlement_proof', $GLOBALS['wpdb']->queries[0] );
	}

	public function test_retiring_a_settled_attempt_releases_its_exact_key_locator(): void {
		$result = ( new ReservationRepository() )->retire_settled_attempt( 7, 'owner', 'worker', 3, 'provider-abort-confirmed' );

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'profile_id = NULL', $GLOBALS['wpdb']->queries[0] );
		$this->assertStringContainsString( 'object_key = NULL', $GLOBALS['wpdb']->queries[0] );
		$this->assertStringContainsString( 'object_key_hash = NULL', $GLOBALS['wpdb']->queries[0] );
		$this->assertStringContainsString( 'settlement_proof', $GLOBALS['wpdb']->queries[0] );
	}

	public function test_attachment_release_frees_only_the_matching_owned_path_and_object_key_pair_after_delete(): void {
		$result = ( new ReservationRepository() )->release_owned_attachment_pair( 1, 'session-1', 7, 8, '2026/10/archive.zip', 9, 'private/direct-upload/session-1/archive.zip', 'attachment_deleted:73' );

		$this->assertIsArray( $result );
		$sql = implode( "\n", $GLOBALS['wpdb']->queries );
		$this->assertStringContainsString( 'START TRANSACTION', $sql );
		$this->assertStringContainsString( "owner_uuid = 'session-1'", $sql );
		$this->assertStringContainsString( "state = 'owned'", $sql );
		$this->assertStringContainsString( "BINARY relative_path = BINARY '2026/10/archive.zip'", $sql );
		$this->assertStringContainsString( "BINARY object_key = BINARY 'private/direct-upload/session-1/archive.zip'", $sql );
		$this->assertStringContainsString( "settlement_proof = 'attachment_deleted:73'", $sql );
		$this->assertStringContainsString( 'COMMIT', $sql );
	}

	public function test_pre_ready_attachment_release_frees_only_a_matching_pinned_pair_after_verified_delete(): void {
		$result = ( new ReservationRepository() )->release_pre_ready_pinned_attachment_pair( 1, 'session-1', 7, 8, '2026/10/archive.zip', 9, 'private/direct-upload/session-1/archive.zip', 'attachment_deleted:73' );

		$this->assertIsArray( $result );
		$sql = implode( "\n", $GLOBALS['wpdb']->queries );
		$this->assertStringContainsString( "state = 'pinned'", $sql );
		$this->assertStringContainsString( "owner_uuid = 'session-1'", $sql );
		$this->assertStringContainsString( "BINARY relative_path = BINARY '2026/10/archive.zip'", $sql );
		$this->assertStringContainsString( "BINARY object_key = BINARY 'private/direct-upload/session-1/archive.zip'", $sql );
		$this->assertStringContainsString( "settlement_proof = 'attachment_deleted:73'", $sql );
		$this->assertStringContainsString( 'COMMIT', $sql );
	}

	public function test_pre_ready_attachment_release_rejects_mixed_or_foreign_pinned_pairs_without_releasing_either_locator(): void {
		$GLOBALS['wpdb']->query_results = array( 1, 1 );

		$result = ( new ReservationRepository() )->release_pre_ready_pinned_attachment_pair( 1, 'session-1', 7, 8, '2026/10/archive.zip', 9, 'private/direct-upload/session-1/archive.zip', 'attachment_deleted:73' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'reservation_attachment_release_lost', $result->get_error_code() );
		$this->assertStringContainsString( 'ROLLBACK', implode( "\n", $GLOBALS['wpdb']->queries ) );
	}

	public function test_pre_ready_attachment_release_is_idempotent_only_for_the_same_exact_pair_owner_and_proof(): void {
		$GLOBALS['wpdb']->query_results = array( 1, 1 );
		$GLOBALS['wpdb']->get_var_result = 2;

		$result = ( new ReservationRepository() )->release_pre_ready_pinned_attachment_pair( 1, 'session-1', 7, 8, '2026/10/archive.zip', 9, 'private/direct-upload/session-1/archive.zip', 'attachment_deleted:73' );

		$this->assertSame( array( 'path_reservation_id' => 7, 'key_reservation_id' => 8 ), $result );
		$this->assertSame( 1, $GLOBALS['wpdb']->get_var_calls );
		$this->assertStringContainsString( "settlement_proof = 'attachment_deleted:73'", $GLOBALS['wpdb']->get_var_queries[0] );
	}

	public function test_pre_ready_attachment_release_requires_complete_exact_delete_proof(): void {
		$result = ( new ReservationRepository() )->release_pre_ready_pinned_attachment_pair( 1, 'session-1', 7, 8, '2026/10/archive.zip', 9, 'private/direct-upload/session-1/archive.zip', '' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'invalid_reservation_attachment_release', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['wpdb']->queries );
	}

	public function test_attachment_release_rejects_a_foreign_or_mismatched_reservation_pair_without_releasing_either_locator(): void {
		$GLOBALS['wpdb']->query_results = array( 1, 1 );

		$result = ( new ReservationRepository() )->release_owned_attachment_pair( 1, 'session-1', 7, 8, '2026/10/archive.zip', 9, 'private/direct-upload/session-1/archive.zip', 'attachment_deleted:73' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'reservation_attachment_release_lost', $result->get_error_code() );
		$this->assertStringContainsString( 'ROLLBACK', implode( "\n", $GLOBALS['wpdb']->queries ) );
	}

	public function test_attachment_release_accepts_only_the_same_pair_owner_and_proof_after_a_completed_retry(): void {
		$GLOBALS['wpdb']->query_results = array( 1, 1 );
		$GLOBALS['wpdb']->get_var_result = 2;

		$result = ( new ReservationRepository() )->release_owned_attachment_pair( 1, 'session-1', 7, 8, '2026/10/archive.zip', 9, 'private/direct-upload/session-1/archive.zip', 'attachment_deleted:73' );

		$this->assertSame( array( 'path_reservation_id' => 7, 'key_reservation_id' => 8 ), $result );
		$this->assertSame( 1, $GLOBALS['wpdb']->get_var_calls );
		$this->assertStringContainsString( "settlement_proof = 'attachment_deleted:73'", $GLOBALS['wpdb']->get_var_queries[0] );
	}

	public function test_attachment_release_fails_closed_when_its_transaction_cannot_start(): void {
		$GLOBALS['wpdb']->query_results = array( false );

		$result = ( new ReservationRepository() )->release_owned_attachment_pair( 1, 'session-1', 7, 8, '2026/10/archive.zip', 9, 'private/direct-upload/session-1/archive.zip', 'attachment_deleted:73' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'reservation_attachment_release_failed', $result->get_error_code() );
		$this->assertSame( array( 'START TRANSACTION' ), $GLOBALS['wpdb']->queries );
	}
}

final class ReservationWpdbFake {
	public string $prefix = 'wp_';
	public int $insert_id = 7;
	public bool $insert_result = true;
	public string $last_error = '';
	/** @var array<string, mixed> */
	public array $inserted = array();
	/** @var list<array<string, mixed>> */
	public array $inserts = array();
	/** @var list<string> */
	public array $queries = array();
	/** @var list<int|false> */
	public array $query_results = array();
	public int $get_var_result = 0;
	public int $get_var_calls = 0;
	public array $get_var_queries = array();

	public function insert( string $table, array $data ): bool {
		$this->inserted = $data;
		$this->inserts[] = $data;
		return $this->insert_result;
	}

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : ( $arg === null ? 'NULL' : "'" . str_replace( "'", "''", (string) $arg ) . "'" );
			$query = preg_replace( '/%[ds]/', $replacement, $query, 1 ) ?? $query;
		}
		return $query;
	}

	public function get_row( string $query, mixed $output = null ): array {
		unset( $output );
		$this->queries[] = $query;
		return array( 'id' => 7, 'state' => ReservationState::PINNED, 'fencing_epoch' => 3 );
	}

	public function get_var( string $query ): int {
		++$this->get_var_calls;
		$this->get_var_queries[] = $query;
		return $this->get_var_result;
	}

	public function query( string $query ): int|false {
		$this->queries[] = $query;
		if ( $this->query_results === array() && str_contains( $query, 'attachment_deleted:' ) ) {
			return 2;
		}
		return array_shift( $this->query_results ) ?? 1;
	}
}
