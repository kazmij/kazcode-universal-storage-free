<?php
/**
 * AttachmentObjectSummary tests (v2 Phase 11 ML column).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
use Kazcode\WpStorage\Services\AttachmentObjectSummary;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class AttachmentObjectSummaryTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_no_rows_returns_local(): void {
		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->willReturn( array() );

		$summary = ( new AttachmentObjectSummary( $objects ) )->summarize( 1 );

		$this->assertSame( 'local', $summary['state'] );
		$this->assertSame( 0, $summary['object_count'] );
	}

	public function test_all_present_returns_remote_with_profile(): void {
		WpStubs::$post_meta[5]['_s3ms_status'] = 'offloaded';

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->willReturn(
			array(
				array(
					'remote_status'      => ObjectRemoteStatus::PRESENT,
					'verified_at'        => '2026-08-01T12:00:00Z',
					'storage_profile_id' => 2,
				),
				array(
					'remote_status'      => ObjectRemoteStatus::PRESENT,
					'verified_at'        => '2026-08-02T12:00:00Z',
					'storage_profile_id' => 2,
				),
			)
		);

		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->willReturn(
			new StorageProfile(
				2,
				'uuid',
				'Legacy Default',
				'aws',
				'bucket',
				'us-east-1',
				'',
				false,
				'uploads/',
				'storage',
				'',
				false,
				'keys',
				'ref',
				true,
				false,
				false,
				'',
				''
			)
		);

		$summary = ( new AttachmentObjectSummary( $objects, $profiles ) )->summarize( 5 );

		$this->assertSame( 'remote', $summary['state'] );
		$this->assertSame( 'Legacy Default', $summary['profile'] );
		$this->assertSame( 2, $summary['object_count'] );
		$this->assertSame( '2026-08-02T12:00:00Z', $summary['last_verified'] );
	}

	/**
	 * Regression: a migrated attachment's stale rows on its old profile(s) were
	 * counted into the total alongside the present rows on its current profile,
	 * so `present === total` could never be true again after even one
	 * successful storage-profile migration — the Media Library S3 column
	 * showed "Partial" forever, even though every current object is genuinely
	 * present. Reproduced live on a real attachment migrated MinIO -> AWS S3 ->
	 * Cloudflare R2 (stale rows accumulate on each superseded profile).
	 */
	public function test_stale_rows_from_prior_migrations_do_not_count_against_total(): void {
		WpStubs::$post_meta[9]['_s3ms_status'] = 'offloaded';

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->willReturn(
			array(
				// Superseded rows on the profile migrated away from.
				array(
					'remote_status'      => ObjectRemoteStatus::STALE,
					'verified_at'        => '2026-08-01T12:00:00Z',
					'storage_profile_id' => 1,
				),
				array(
					'remote_status'      => ObjectRemoteStatus::STALE,
					'verified_at'        => '2026-08-01T12:00:00Z',
					'storage_profile_id' => 1,
				),
				// Current, fully-present rows on the profile migrated to.
				array(
					'remote_status'      => ObjectRemoteStatus::PRESENT,
					'verified_at'        => '2026-08-02T12:00:00Z',
					'storage_profile_id' => 2,
				),
				array(
					'remote_status'      => ObjectRemoteStatus::PRESENT,
					'verified_at'        => '2026-08-02T12:00:00Z',
					'storage_profile_id' => 2,
				),
			)
		);

		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->willReturn(
			new StorageProfile( 2, 'uuid', 'New Profile', 'aws', 'bucket', 'us-east-1', '', false, 'uploads/', 'storage', '', false, 'keys', 'ref', true, false, false, '', '' )
		);

		$summary = ( new AttachmentObjectSummary( $objects, $profiles ) )->summarize( 9 );

		$this->assertSame( 'remote', $summary['state'], 'stale rows on the old profile must not block a fully-present current profile from showing as Remote' );
		$this->assertSame( 'New Profile', $summary['profile'] );
		$this->assertSame( 2, $summary['object_count'], 'object_count should reflect only the active (non-stale) rows' );
		$this->assertSame( 2, $summary['present_count'] );
	}

	public function test_failed_row_or_legacy_meta_returns_failed(): void {
		WpStubs::$post_meta[7]['_s3ms_status'] = 'failed';

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->willReturn(
			array(
				array(
					'remote_status' => ObjectRemoteStatus::PRESENT,
					'storage_profile_id' => 1,
				),
			)
		);

		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->willReturn( null );

		$summary = ( new AttachmentObjectSummary( $objects, $profiles ) )->summarize( 7 );

		$this->assertSame( 'failed', $summary['state'] );
	}
}
