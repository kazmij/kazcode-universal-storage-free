<?php
/**
 * Policy reconciliation (#62): dry run vs local storage policy, narrow repairs.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\LocalStoragePolicy;
use Kazcode\WpStorage\Domain\ManifestBuilder;
use Kazcode\WpStorage\Domain\MediaManifest;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Infrastructure\AttachmentLeaseHandle;
use Kazcode\WpStorage\Infrastructure\AttachmentLock;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Services\AuditLog;
use Kazcode\WpStorage\Services\PolicyReconciliationService;
use Kazcode\WpStorage\Services\ProfileAwareObjectOperations;
use Kazcode\WpStorage\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class PolicyReconciliationServiceTest extends TestCase {

	private const ORIGINAL = '2026/08/photo.jpg';
	private const THUMB    = '2026/08/photo-150x150.jpg';

	/** @var list<string> */
	private array $local = array();
	/** @var list<int> */
	private array $cleanups = array();

	protected function setUp(): void {
		WpStubs::reset();
		WpStubs::$uploads_basedir = sys_get_temp_dir() . '/s3ms-policy';
		$this->local    = array();
		$this->cleanups = array();
	}

	public function test_keep_all_reports_missing_local_file_with_verified_remote_as_repairable(): void {
		$this->local = array( self::ORIGINAL );
		$findings    = $this->service( LocalStoragePolicy::KEEP_ALL, $this->rows() )->analyze( 42 );

		$this->assertCount( 1, $findings );
		$this->assertSame( PolicyReconciliationService::LOCAL_MISSING, $findings[0]['finding'] );
		$this->assertSame( self::THUMB, $findings[0]['relative'] );
		$this->assertTrue( $findings[0]['repairable'] );
		$this->assertSame( 2, $findings[0]['storage_profile_id'], 'recorded profile ownership' );
	}

	public function test_missing_local_file_without_verified_remote_is_report_only(): void {
		$rows        = $this->rows();
		$rows[1]['remote_status'] = ObjectRemoteStatus::FAILED;
		$findings    = $this->service( LocalStoragePolicy::KEEP_ALL, $rows )->analyze( 42 );

		$this->assertFalse( $findings[1]['repairable'] ?? $findings[0]['repairable'] );
		$this->assertStringContainsString( 'Verify', implode( ' ', array_column( $findings, 'next_action' ) ) );
	}

	public function test_keep_originals_expects_only_the_original_locally(): void {
		$this->local = array( self::ORIGINAL, self::THUMB );
		$findings    = $this->service( LocalStoragePolicy::KEEP_ORIGINALS, $this->rows() )->analyze( 42 );

		$this->assertSame( array( PolicyReconciliationService::LOCAL_RETAINED ), array_column( $findings, 'finding' ) );
		$this->assertSame( self::THUMB, $findings[0]['relative'] );
	}

	public function test_remote_only_with_no_local_files_is_clean(): void {
		$this->assertSame( array(), $this->service( LocalStoragePolicy::REMOTE_ONLY, $this->rows() )->analyze( 42 ) );
	}

	public function test_superseded_rows_never_make_a_current_file_verified(): void {
		$rows = array(
			$this->row( self::ORIGINAL, ObjectRemoteStatus::PRESENT ),
			$this->row( self::THUMB, ObjectRemoteStatus::STALE ),
		);
		$findings = $this->service( LocalStoragePolicy::KEEP_ALL, $rows )->analyze( 42 );

		$this->assertSame( PolicyReconciliationService::LOCAL_MISSING, $findings[1]['finding'] );
		$this->assertFalse( $findings[1]['repairable'] );
	}

	public function test_interrupted_transfer_older_than_a_day_is_reported(): void {
		$this->local = array( self::ORIGINAL, self::THUMB );
		$rows        = $this->rows();
		$rows[]      = array_merge( $this->row( '2026/08/photo-300x200.jpg', ObjectRemoteStatus::UPLOADING ), array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * 86400 ) ) );
		$rows[]      = array_merge( $this->row( '2026/08/photo-600x400.jpg', ObjectRemoteStatus::UPLOADING ), array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );

		$findings = $this->service( LocalStoragePolicy::KEEP_ALL, $rows )->analyze( 42 );

		$this->assertSame( array( PolicyReconciliationService::INTERRUPTED_TRANSFER ), array_column( $findings, 'finding' ) );
		$this->assertFalse( $findings[0]['repairable'] );
	}

	public function test_site_findings_flag_undeliverable_cleanup_policy_and_stale_sessions(): void {
		$service = $this->service( LocalStoragePolicy::REMOTE_ONLY, array(), serve: false, stale: 3 );

		$this->assertSame(
			array( PolicyReconciliationService::CLEANUP_UNDELIVERABLE, PolicyReconciliationService::STALE_SESSIONS ),
			array_column( $service->site_findings(), 'finding' )
		);
		$this->assertSame( array(), $this->service( LocalStoragePolicy::KEEP_ALL, array() )->site_findings() );
	}

	public function test_dry_run_never_downloads_cleans_or_writes(): void {
		$objects = $this->repo( $this->rows() );
		$objects->expects( $this->never() )->method( 'upsert' );
		$objects->method( 'attachment_ids_page' )->willReturn( array( 42 ) );
		$locations = $this->createMock( ProfileAwareObjectOperations::class );
		$locations->expects( $this->never() )->method( 'download_resolved_to_local' );

		$report = $this->service( LocalStoragePolicy::KEEP_ALL, $this->rows(), objects: $objects, locations: $locations )->scan_page();

		$this->assertSame( 1, $report['scanned'] );
		$this->assertCount( 2, $report['findings'] );
		$this->assertSame( array(), $this->cleanups );
	}

	public function test_apply_downloads_the_exact_verified_object_with_size_check(): void {
		$locations = $this->createMock( ProfileAwareObjectOperations::class );
		$locations->expects( $this->once() )->method( 'download_resolved_to_local' )
			->with( 42, self::THUMB, $this->stringEndsWith( '/' . self::THUMB ), 2, 'p2/' . self::THUMB, false, 1234 )
			->willReturn( array( 'success' => true ) );
		$audit = $this->createMock( AuditLog::class );
		$audit->expects( $this->once() )->method( 'record' )->with( 'integrity_policy_apply', $this->callback( static fn( array $c ): bool => $c['applied'] === 1 ) );

		$this->local = array( self::ORIGINAL );
		$service     = $this->service( LocalStoragePolicy::KEEP_ALL, $this->rows(), locations: $locations, audit: $audit );
		$result      = $service->apply( $service->analyze( 42 ) );

		$this->assertSame( 1, $result['applied'] );
	}

	public function test_apply_runs_guarded_cleanup_once_per_attachment(): void {
		$this->local = array( self::ORIGINAL, self::THUMB );
		$service     = $this->service( LocalStoragePolicy::REMOTE_ONLY, $this->rows() );
		$result      = $service->apply( $service->analyze( 42 ) );

		$this->assertSame( array( 42 ), $this->cleanups );
		$this->assertSame( 1, $result['applied'] );
	}

	public function test_apply_skips_findings_that_no_longer_apply_or_are_not_repairable(): void {
		$this->local = array( self::ORIGINAL, self::THUMB );
		$service     = $this->service( LocalStoragePolicy::REMOTE_ONLY, $this->rows() );
		$findings    = $service->analyze( 42 );
		$this->local = array(); // Cleaned up elsewhere between dry run and apply.

		$result = $service->apply( $findings );
		$this->assertSame( array(), $this->cleanups );
		$this->assertSame( 1, $result['skipped'] );

		$forged = array( array( 'attachment_id' => 42, 'relative' => self::THUMB, 'finding' => PolicyReconciliationService::INTERRUPTED_TRANSFER, 'repairable' => true ) );
		$this->assertSame( array(), $service->apply( $forged )['results'] );
	}

	public function test_apply_skips_locked_attachments(): void {
		$this->local = array( self::ORIGINAL, self::THUMB );
		$lock        = $this->createMock( AttachmentLock::class );
		$lock->method( 'acquire_lease' )->willReturn( null );
		$service = $this->service( LocalStoragePolicy::REMOTE_ONLY, $this->rows(), lock: $lock );

		$result = $service->apply( $service->analyze( 42 ) );

		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( array(), $this->cleanups );
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 */
	private function service(
		string $policy,
		array $rows,
		bool $serve = true,
		int $stale = 0,
		?ObjectRepository $objects = null,
		?ProfileAwareObjectOperations $locations = null,
		?AuditLog $audit = null,
		?AttachmentLock $lock = null,
	): PolicyReconciliationService {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'local_storage_policy' )->willReturn( $policy );
		$settings->method( 'is_serve_enabled' )->willReturn( $serve );
		$settings->method( 'has_public_url_config' )->willReturn( true );

		$manifests = $this->createMock( ManifestBuilder::class );
		$manifests->method( 'build' )->willReturnCallback(
			static fn( int $id ): MediaManifest => new MediaManifest(
				$id,
				array(
					array( 'relative' => self::ORIGINAL, 'variant_type' => 'original' ),
					array( 'relative' => self::THUMB, 'variant_type' => 'size' ),
				)
			)
		);

		if ( $lock === null ) {
			$lock = $this->createMock( AttachmentLock::class );
			$lock->method( 'acquire_lease' )->willReturnCallback( static fn( int $id ): AttachmentLeaseHandle => new AttachmentLeaseHandle( $id, 't', 1, 'policy_reconcile', time() + 300 ) );
			$lock->method( 'renew' )->willReturn( true );
		}

		return new PolicyReconciliationService(
			$settings,
			$objects ?? $this->repo( $rows ),
			$manifests,
			$locations ?? $this->createMock( ProfileAwareObjectOperations::class ),
			$lock,
			$audit ?? $this->createMock( AuditLog::class ),
			fn( string $relative ): bool => in_array( $relative, $this->local, true ),
			function ( int $id ): array {
				$this->cleanups[] = $id;
				return array( 'deleted' => 1 );
			},
			static fn(): int => $stale
		);
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @return ObjectRepository&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function repo( array $rows ): ObjectRepository {
		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->willReturn( $rows );
		return $objects;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function rows(): array {
		return array( $this->row( self::ORIGINAL, ObjectRemoteStatus::PRESENT ), $this->row( self::THUMB, ObjectRemoteStatus::PRESENT ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function row( string $relative, string $status ): array {
		return array(
			'attachment_id'       => 42,
			'storage_profile_id'  => 2,
			'local_relative_path' => $relative,
			'object_key'          => 'p2/' . $relative,
			'remote_status'       => $status,
			'size_bytes'          => 1234,
			'verified_at'         => $status === ObjectRemoteStatus::PRESENT ? '2026-10-01 00:00:00' : null,
			'updated_at'          => gmdate( 'Y-m-d H:i:s' ),
		);
	}
}
