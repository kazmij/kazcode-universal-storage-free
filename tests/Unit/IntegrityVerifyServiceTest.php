<?php
/**
 * Integrity Verify (#28): observation-only dry run, narrow apply.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Infrastructure\AttachmentLeaseHandle;
use Kazcode\WpStorage\Infrastructure\AttachmentLock;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Services\AuditLog;
use Kazcode\WpStorage\Services\IntegrityVerifyService;
use Kazcode\WpStorage\Services\ProfileAwareObjectOperations;
use Kazcode\WpStorage\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class IntegrityVerifyServiceTest extends TestCase {

	private string $uploads;

	protected function setUp(): void {
		WpStubs::reset();
		$this->uploads = sys_get_temp_dir() . '/s3ms-verify-' . uniqid( '', true );
		wp_mkdir_p( $this->uploads . '/2026/08' );
		WpStubs::$uploads_basedir = $this->uploads;
	}

	protected function tearDown(): void {
		foreach ( glob( $this->uploads . '/2026/08/*' ) ?: array() as $file ) {
			unlink( $file );
		}
		@rmdir( $this->uploads . '/2026/08' );
		@rmdir( $this->uploads . '/2026' );
		@rmdir( $this->uploads );
		WpStubs::reset();
	}

	public function test_local_size_match_verifies_and_proposes_present_without_writing(): void {
		file_put_contents( $this->uploads . '/2026/08/a.jpg', str_repeat( 'x', 10 ) );
		$objects = $this->repo( array( $this->row( 1, '2026/08/a.jpg', ObjectRemoteStatus::FAILED ) ) );
		$objects->expects( $this->never() )->method( 'upsert' );

		$report = $this->service( $objects, $this->heads( array( 1 => array( 'exists' => true, 'content_length' => 10 ) ) ) )->observe_page();

		$this->assertSame( IntegrityVerifyService::VERIFIED, $report['items'][0]['outcome'] );
		$this->assertSame( 'local', $report['items'][0]['size_source'] );
		$this->assertSame( IntegrityVerifyService::CHANGE_MARK_PRESENT, $report['items'][0]['change'] );
		$this->assertSame( 1, $report['counts'][ IntegrityVerifyService::VERIFIED ] );
	}

	public function test_offloaded_only_uses_inventory_size_and_reports_conflict(): void {
		$objects = $this->repo( array( $this->row( 2, '2026/08/gone.jpg', ObjectRemoteStatus::PRESENT, 100 ) ) );

		$item = $this->service( $objects, $this->heads( array( 2 => array( 'exists' => true, 'content_length' => 90 ) ) ) )->observe_page()['items'][0];

		$this->assertSame( IntegrityVerifyService::SIZE_CONFLICT, $item['outcome'] );
		$this->assertSame( 'inventory', $item['size_source'] );
		$this->assertNull( $item['change'] );
	}

	public function test_missing_inventory_size_is_existence_only_never_size_verified(): void {
		$objects = $this->repo( array( $this->row( 3, '2026/08/gone.jpg', ObjectRemoteStatus::MISSING, 0 ) ) );

		$item = $this->service( $objects, $this->heads( array( 3 => array( 'exists' => true, 'content_length' => 5 ) ) ) )->observe_page()['items'][0];

		$this->assertSame( IntegrityVerifyService::EXISTS_ONLY, $item['outcome'] );
		$this->assertSame( 'none', $item['size_source'] );
		$this->assertNull( $item['change'], 'existence-only must not mark a row present' );
	}

	public function test_transient_and_auth_errors_never_propose_missing(): void {
		$objects = $this->repo(
			array(
				$this->row( 4, '2026/08/a.jpg', ObjectRemoteStatus::PRESENT, 10 ),
				$this->row( 5, '2026/08/b.jpg', ObjectRemoteStatus::PRESENT, 10 ),
			)
		);
		$heads = $this->heads(
			array(
				4 => array( 'exists' => false, 'confirmed_missing' => false, 'error' => 'Connection timed out' ),
				5 => array( 'exists' => false, 'confirmed_missing' => false, 'error' => 'AccessDenied', 'error_code' => 'AccessDenied' ),
			)
		);

		$items = $this->service( $objects, $heads )->observe_page()['items'];

		$this->assertSame( array( IntegrityVerifyService::UNKNOWN, IntegrityVerifyService::UNKNOWN ), array_column( $items, 'outcome' ) );
		$this->assertSame( array( 'timeout', 'auth' ), array_column( $items, 'error_class' ) );
		$this->assertSame( array( null, null ), array_column( $items, 'change' ) );
	}

	public function test_unresolved_profile_is_reported_not_changed(): void {
		$objects = $this->repo( array( $this->row( 6, '2026/08/a.jpg', ObjectRemoteStatus::PRESENT, 10 ) ) );

		$item = $this->service( $objects, $this->heads( array( 6 => array( 'exists' => false, 'location_status' => 'profile_not_found' ) ) ) )->observe_page()['items'][0];

		$this->assertSame( IntegrityVerifyService::UNRESOLVED, $item['outcome'] );
		$this->assertNull( $item['change'] );
	}

	public function test_superseded_and_in_flight_rows_are_not_verified(): void {
		$objects = $this->repo(
			array(
				$this->row( 7, '2026/08/a.jpg', ObjectRemoteStatus::STALE ),
				$this->row( 8, '2026/08/b.jpg', ObjectRemoteStatus::DELETED ),
				$this->row( 9, '2026/08/c.jpg', ObjectRemoteStatus::UPLOADING ),
			)
		);
		$locations = $this->createMock( ProfileAwareObjectOperations::class );
		$locations->expects( $this->never() )->method( 'head_object_row' );

		$report = $this->service( $objects, $locations )->observe_page();

		$this->assertSame( array(), $report['items'] );
		$this->assertSame( 3, $report['scanned'] );
		$this->assertSame( 9, $report['next_after_id'] );
	}

	public function test_apply_marks_confirmed_missing_without_touching_size_bytes(): void {
		$row     = $this->row( 10, '2026/08/gone.jpg', ObjectRemoteStatus::PRESENT, 100 );
		$objects = $this->repo( array( $row ) );
		$written = array();
		$objects->method( 'upsert' )->willReturnCallback(
			static function ( array $data ) use ( &$written ): int {
				$written[] = $data;
				return 10;
			}
		);
		$audit = $this->createMock( AuditLog::class );
		$audit->expects( $this->once() )->method( 'record' )->with(
			'integrity_verify_apply',
			$this->callback( static fn( array $c ): bool => $c['applied'] === 1 && ! str_contains( (string) json_encode( $c ), 'uploads/' ) )
		);

		$result = $this->service( $objects, $this->heads( array( 10 => array( 'exists' => false, 'confirmed_missing' => true ) ) ), $audit )->apply( array( 10 ) );

		$this->assertSame( 1, $result['applied'] );
		$this->assertCount( 1, $written );
		$this->assertSame( ObjectRemoteStatus::MISSING, $written[0]['remote_status'] );
		$this->assertArrayNotHasKey( 'size_bytes', $written[0] );
	}

	public function test_apply_rechecks_and_skips_rows_whose_result_no_longer_proposes_a_change(): void {
		$objects = $this->repo( array( $this->row( 11, '2026/08/gone.jpg', ObjectRemoteStatus::PRESENT, 100 ) ) );
		$objects->expects( $this->never() )->method( 'upsert' );

		// The dry run saw a 404, but by apply time the HEAD only times out.
		$heads = $this->heads( array( 11 => array( 'exists' => false, 'confirmed_missing' => false, 'error' => 'timeout' ) ) );
		$result = $this->service( $objects, $heads )->apply( array( 11 ) );

		$this->assertSame( 0, $result['applied'] );
		$this->assertSame( 1, $result['unchanged'] );
	}

	public function test_apply_never_marks_present_on_size_conflict(): void {
		$objects = $this->repo( array( $this->row( 12, '2026/08/gone.jpg', ObjectRemoteStatus::MISSING, 100 ) ) );
		$objects->expects( $this->never() )->method( 'upsert' );

		$result = $this->service( $objects, $this->heads( array( 12 => array( 'exists' => true, 'content_length' => 7 ) ) ) )->apply( array( 12 ) );

		$this->assertSame( 1, $result['unchanged'] );
	}

	public function test_apply_skips_locked_attachments(): void {
		$objects = $this->repo( array( $this->row( 13, '2026/08/gone.jpg', ObjectRemoteStatus::PRESENT, 100 ) ) );
		$objects->expects( $this->never() )->method( 'upsert' );
		$lock = $this->createMock( AttachmentLock::class );
		$lock->method( 'acquire_lease' )->willReturn( null );

		$service = new IntegrityVerifyService( $objects, $this->heads( array( 13 => array( 'exists' => false, 'confirmed_missing' => true ) ) ), $lock, $this->createMock( AuditLog::class ) );
		$result  = $service->apply( array( 13 ) );

		$this->assertSame( 1, $result['skipped'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row( int $id, string $relative, string $status, ?int $size = null ): array {
		return array(
			'id'                  => $id,
			'attachment_id'       => 500 + $id,
			'storage_profile_id'  => 1,
			'local_relative_path' => $relative,
			'object_key'          => 'uploads/' . $relative,
			'variant_type'        => 'original',
			'remote_status'       => $status,
			'size_bytes'          => $size,
			'verified_at'         => null,
		);
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return ObjectRepository&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function repo( array $rows ): ObjectRepository {
		$by_id   = array_column( $rows, null, 'id' );
		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'scan_page' )->willReturn( $rows );
		$objects->method( 'find_by_id' )->willReturnCallback( static fn( int $id ): ?array => $by_id[ $id ] ?? null );
		$objects->method( 'find_by_attachment' )->willReturn( array() );
		return $objects;
	}

	/**
	 * @param array<int, array<string, mixed>> $by_row_id HEAD result per object id.
	 */
	private function heads( array $by_row_id ): ProfileAwareObjectOperations {
		$locations = $this->createMock( ProfileAwareObjectOperations::class );
		$locations->method( 'head_object_row' )->willReturnCallback(
			static function ( array $row ) use ( $by_row_id ): array {
				$head = $by_row_id[ (int) $row['id'] ];
				if ( ! isset( $head['location_status'] ) ) {
					$head['location_status']    = 'found';
					$head['storage_profile_id'] = 1;
				}
				return $head;
			}
		);
		return $locations;
	}

	private function service( ObjectRepository $objects, ProfileAwareObjectOperations $locations, ?AuditLog $audit = null ): IntegrityVerifyService {
		$lock = $this->createMock( AttachmentLock::class );
		$lock->method( 'acquire_lease' )->willReturnCallback(
			static fn( int $attachment_id ): AttachmentLeaseHandle => new AttachmentLeaseHandle( $attachment_id, 'verify-token', 1, 'verify', time() + 300 )
		);
		$lock->method( 'renew' )->willReturn( true );
		$lock->method( 'release_lease' )->willReturn( true );
		return new IntegrityVerifyService( $objects, $locations, $lock, $audit ?? $this->createMock( AuditLog::class ) );
	}
}
