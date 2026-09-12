<?php
/**
 * Durable transfer/import state repository tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

use Kazcode\WpStorage\Domain\ImportRunStatus;
use Kazcode\WpStorage\Domain\TransferSessionStatus;
use Kazcode\WpStorage\Infrastructure\ImportRunRepository;
use Kazcode\WpStorage\Infrastructure\TransferSessionRepository;
use PHPUnit\Framework\TestCase;

final class TransferSessionRepositoryTest extends TestCase {

	private TransferSessionRepository $transfers;
	private ImportRunRepository $imports;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wpdb'] = new DurableStateWpdbStub();
		$this->transfers  = new TransferSessionRepository();
		$this->imports    = new ImportRunRepository();
	}

	public function test_transfer_create_find_update_and_completed_parts_round_trip(): void {
		$uuid = '11111111-1111-4111-8111-111111111111';
		$id   = $this->transfers->create(
			array(
				'uuid'                   => $uuid,
				'kind'                   => 'local_upload',
				'destination_profile_id' => 12,
				'source_ref'             => '2026/09/photo.jpg',
				'destination_key'        => 'media/2026/09/photo.jpg',
				'size_bytes'             => 104857600,
				'source_fingerprint'     => 'local:abc',
				'part_size_bytes'        => 67108864,
				'completed_parts'        => array( '1' => array( 'etag' => 'etag-1', 'size' => 67108864 ) ),
				'status'                 => TransferSessionStatus::UPLOADING,
			)
		);

		$this->transfers->update( $id, array( 'completed_parts' => array( '1' => array( 'etag' => 'etag-1', 'size' => 67108864 ), '2' => array( 'etag' => 'etag-2', 'size' => 37748736 ) ) ) );
		$session = $this->transfers->find_by_uuid( $uuid );

		$this->assertSame( $id, $session['id'] );
		$this->assertSame( TransferSessionStatus::UPLOADING, $session['status'] );
		$this->assertSame( array( '1' => array( 'etag' => 'etag-1', 'size' => 67108864 ), '2' => array( 'etag' => 'etag-2', 'size' => 37748736 ) ), $session['completed_parts'] );
	}

	public function test_rejects_absolute_local_source_reference(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'source_ref must be a relative path or remote object key.' );

		$this->transfers->create(
			array(
				'uuid'                   => '12121212-1212-4121-8121-121212121212',
				'kind'                   => 'local_upload',
				'destination_profile_id' => 12,
				'source_ref'             => '/var/www/html/wp-content/uploads/2026/09/photo.jpg',
				'destination_key'        => 'media/2026/09/photo.jpg',
				'size_bytes'             => 1,
				'source_fingerprint'     => 'local:abc',
				'part_size_bytes'        => 67108864,
				'status'                 => TransferSessionStatus::PENDING,
			)
		);
	}

	public function test_finds_only_resumable_transfer_for_exact_source_and_destination(): void {
		$matching = $this->create_transfer( '22222222-2222-4222-8222-222222222222', TransferSessionStatus::PENDING );
		$this->create_transfer( '33333333-3333-4333-8333-333333333333', TransferSessionStatus::COMPLETED );
		$this->create_transfer( '44444444-4444-4444-8444-444444444444', TransferSessionStatus::ABORTED );
		$this->create_transfer( '55555555-5555-4555-8555-555555555555', TransferSessionStatus::UPLOADING, 'other-fingerprint' );

		$session = $this->transfers->find_resumable( 'local_upload', 12, 'media/2026/09/photo.jpg', 'local:abc' );

		$this->assertSame( $matching, $session['id'] );
		$this->assertSame( TransferSessionStatus::PENDING, $session['status'] );
	}

	public function test_failed_transfer_is_not_resumable_for_exact_source_and_destination(): void {
		// Catches: reusing a multipart upload id from a failed/source-changed session when the local fingerprint later matches again.
		$this->create_transfer( '51515151-5151-4515-8515-515151515151', TransferSessionStatus::FAILED );

		$session = $this->transfers->find_resumable( 'local_upload', 12, 'media/2026/09/photo.jpg', 'local:abc' );

		$this->assertNull( $session );
	}

	public function test_lists_stale_incomplete_transfers_after_cursor(): void {
		$old_id = $this->create_transfer( '66666666-6666-4666-8666-666666666666', TransferSessionStatus::FAILED );
		$this->create_transfer( '77777777-7777-4777-8777-777777777777', TransferSessionStatus::COMPLETED );
		$new_id = $this->create_transfer( '88888888-8888-4888-8888-888888888888', TransferSessionStatus::UPLOADING );
		$GLOBALS['wpdb']->rows['wp_s3ms_transfer_sessions'][ $old_id ]['updated_at'] = '2026-08-01 00:00:00';
		$GLOBALS['wpdb']->rows['wp_s3ms_transfer_sessions'][ $new_id ]['updated_at'] = '2026-08-01 00:00:00';

		$stale = $this->transfers->list_stale_incomplete( strtotime( '2026-08-15 00:00:00 UTC' ), $old_id );

		$this->assertSame( array( $new_id ), array_column( $stale, 'id' ) );
	}

	public function test_finds_latest_successful_dry_run_and_updates_import_run(): void {
		$first = $this->imports->create( $this->import_data( '99999999-9999-4999-8999-999999999999' ) );
		$this->imports->update( $first, array( 'status' => ImportRunStatus::COMPLETED, 'finished_at' => '2026-08-01 00:00:00' ) );
		$second = $this->imports->create( $this->import_data( 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' ) );
		$this->imports->update( $second, array( 'status' => ImportRunStatus::COMPLETED, 'finished_at' => '2026-08-02 00:00:00' ) );
		$this->imports->create( array_merge( $this->import_data( 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb' ), array( 'mode' => 'commit', 'status' => ImportRunStatus::COMPLETED ) ) );

		$run = $this->imports->find_latest_successful_dry_run( 'wp_offload_media', 12, 'source:abc' );

		$this->assertSame( $second, $run['id'] );
		$this->assertSame( ImportRunStatus::COMPLETED, $run['status'] );
	}

	public function test_import_run_create_if_no_active_uses_database_lock_and_rechecks_before_insert(): void {
		$GLOBALS['wpdb']->inject_active_import_after_lock = $this->import_data( 'cccccccc-cccc-4ccc-8ccc-cccccccccccc' );

		$id = $this->imports->create_if_no_active( $this->import_data( 'dddddddd-dddd-4ddd-8ddd-dddddddddddd' ) );

		$this->assertSame( 0, $id );
		$this->assertCount( 1, $GLOBALS['wpdb']->rows['wp_s3ms_import_runs'] );
		$this->assertSame( array( 'GET_LOCK', 'RELEASE_LOCK' ), $GLOBALS['wpdb']->import_lock_calls );
	}

	private function create_transfer( string $uuid, string $status, string $fingerprint = 'local:abc' ): int {
		return $this->transfers->create(
			array(
				'uuid'                   => $uuid,
				'kind'                   => 'local_upload',
				'destination_profile_id' => 12,
				'source_ref'             => '2026/09/photo.jpg',
				'destination_key'        => 'media/2026/09/photo.jpg',
				'size_bytes'             => 1,
				'source_fingerprint'     => $fingerprint,
				'part_size_bytes'        => 67108864,
				'completed_parts'        => array(),
				'status'                 => $status,
			)
		);
	}

	/** @return array<string, mixed> */
	private function import_data( string $uuid ): array {
		return array(
			'uuid'               => $uuid,
			'source_type'        => 'wp_offload_media',
			'profile_id'         => 12,
			'mode'               => 'dry_run',
			'status'             => ImportRunStatus::RUNNING,
			'source_fingerprint' => 'source:abc',
		);
	}
}

final class DurableStateWpdbStub {
	public string $prefix = 'wp_';
	public int $insert_id = 0;
	/** @var array<string, mixed>|null */
	public ?array $inject_active_import_after_lock = null;
	/** @var list<string> */
	public array $import_lock_calls = array();
	/** @var array<string, array<int, array<string, mixed>>> */
	public array $rows = array();

	public function insert( string $table, array $data ): void {
		$this->insert_id++;
		$data['id'] = $this->insert_id;
		$this->rows[ $table ][ $this->insert_id ] = $data;
	}

	public function update( string $table, array $data, array $where ): void {
		$id = (int) $where['id'];
		$this->rows[ $table ][ $id ] = array_merge( $this->rows[ $table ][ $id ], $data );
	}

	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "\\'", (string) $arg ) . "'";
			$query       = preg_replace( '/%[ds]/', $replacement, $query, 1 ) ?? $query;
		}
		return $query;
	}

	public function get_var( string $query ): int {
		if ( str_contains( $query, 'GET_LOCK' ) ) {
			$this->import_lock_calls[] = 'GET_LOCK';
			if ( $this->inject_active_import_after_lock !== null ) {
				$this->insert( 'wp_s3ms_import_runs', $this->inject_active_import_after_lock );
				$this->inject_active_import_after_lock = null;
			}
			return 1;
		}
		if ( str_contains( $query, 'RELEASE_LOCK' ) ) {
			$this->import_lock_calls[] = 'RELEASE_LOCK';
			return 1;
		}
		return 0;
	}

	/** @return array<string, mixed>|null */
	public function get_row( string $query, string $output ): ?array {
		$rows = $this->matching_rows( $query );
		return $rows === array() ? null : $rows[0];
	}

	/** @return list<array<string, mixed>> */
	public function get_results( string $query, string $output ): array {
		return $this->matching_rows( $query );
	}

	/** @return list<array<string, mixed>> */
	private function matching_rows( string $query ): array {
		preg_match( '/FROM (wp_s3ms_[a-z_]+)/', $query, $table_match );
		$table = $table_match[1] ?? '';
		$rows  = array_values( $this->rows[ $table ] ?? array() );
		if ( preg_match( "/uuid = '([^']+)'/", $query, $match ) ) {
			return array_values( array_filter( $rows, static fn( array $row ): bool => $row['uuid'] === $match[1] ) );
		}
		if ( $table === 'wp_s3ms_transfer_sessions' && str_contains( $query, 'destination_profile_id' ) ) {
			preg_match_all( "/'(.*?)'/", $query, $strings );
			preg_match( '/destination_profile_id = (\\d+)/', $query, $profile );
			$active_statuses = array( 'pending', 'uploading', 'completing' );
			if ( str_contains( $query, 'source_ref' ) ) {
				return array_values( array_filter( $rows, static fn( array $row ): bool => $row['kind'] === $strings[1][0] && (int) $row['destination_profile_id'] === (int) $profile[1] && $row['destination_key'] === $strings[1][1] && $row['source_ref'] === $strings[1][2] && in_array( $row['status'], $active_statuses, true ) ) );
			}
			return array_values( array_filter( $rows, static fn( array $row ): bool => $row['kind'] === $strings[1][0] && (int) $row['destination_profile_id'] === (int) $profile[1] && $row['destination_key'] === $strings[1][1] && $row['source_fingerprint'] === $strings[1][2] && in_array( $row['status'], $active_statuses, true ) ) );
		}
		if ( $table === 'wp_s3ms_transfer_sessions' && str_contains( $query, 'updated_at <') ) {
			preg_match( '/id > (\\d+)/', $query, $after );
			preg_match( "/updated_at < '([^']+)'/", $query, $before );
			return array_values( array_filter( $rows, static fn( array $row ): bool => (int) $row['id'] > (int) $after[1] && $row['updated_at'] < $before[1] && ! in_array( $row['status'], array( 'completed', 'aborted' ), true ) ) );
		}
		if ( $table === 'wp_s3ms_import_runs' ) {
			if ( str_contains( $query, 'status IN' ) ) {
				$rows = array_values(
					array_filter(
						$rows,
						static fn( array $row ): bool => in_array( $row['status'], array( ImportRunStatus::RUNNING, ImportRunStatus::PAUSED ), true )
					)
				);
				usort( $rows, static fn( array $left, array $right ): int => $right['id'] <=> $left['id'] );
				return $rows;
			}
			preg_match_all( "/'(.*?)'/", $query, $strings );
			preg_match( '/profile_id = (\\d+)/', $query, $profile );
			$rows = array_values( array_filter( $rows, static fn( array $row ): bool => $row['source_type'] === $strings[1][0] && (int) $row['profile_id'] === (int) $profile[1] && $row['source_fingerprint'] === $strings[1][1] && $row['mode'] === 'dry_run' && $row['status'] === 'completed' ) );
			usort( $rows, static fn( array $left, array $right ): int => $right['id'] <=> $left['id'] );
			return $rows;
		}
		return $rows;
	}
}
