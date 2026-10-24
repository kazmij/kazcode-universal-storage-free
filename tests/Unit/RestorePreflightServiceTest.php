<?php
/**
 * Restore Preflight (§6.4) characterization.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Attachment\AttachmentRestorer;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Domain\RestorePreflightResult;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Import\ImportDetection;
use Kazcode\WpStorage\Import\ImportedObjectDescriptor;
use Kazcode\WpStorage\Import\ImporterInterface;
use Kazcode\WpStorage\Import\ImportRegistry;
use Kazcode\WpStorage\Import\ImportScanPage;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
use Kazcode\WpStorage\Services\ProfileAwareObjectOperations;
use Kazcode\WpStorage\Services\RestorePreflightService;
use Kazcode\WpStorage\Storage\ProfileStorageGateway;
use Kazcode\WpStorage\Storage\S3KeyResolver;
use Kazcode\WpStorage\Storage\S3Storage;
use Kazcode\WpStorage\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class RestorePreflightServiceTest extends TestCase {

	private string $uploads;

	protected function setUp(): void {
		WpStubs::reset();
		$this->uploads = sys_get_temp_dir() . '/kazus-restore-preflight-' . uniqid( '', true );
		mkdir( $this->uploads, 0777, true );
		WpStubs::$uploads_basedir = $this->uploads;
	}

	protected function tearDown(): void {
		$this->rmTree( $this->uploads );
		WpStubs::reset();
	}

	public function test_safe_when_capacity_ok_writable_and_present(): void {
		$this->seed_attachment( 10, '2026/09/a.jpg', null );
		$row = $this->present_row( 10, 3, '2026/09/a.jpg', 'uploads/2026/09/a.jpg', 100 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->once() )
			->method( 'head_key' )
			->with( 'uploads/2026/09/a.jpg' )
			->willReturn( array( 'exists' => true, 'content_length' => 100 ) );

		$result = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects( array( $row ) ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
		)->preflight( array( 10 ) );

		$this->assertSame( RestorePreflightResult::OUTCOME_SAFE, $result['outcome'] );
		$this->assertSame( 1, $result['attachment_count'] );
		$this->assertSame( 1, $result['required_download_count'] );
		$this->assertSame( 1, $result['restorable_count'] );
		$this->assertSame( 100, $result['required_bytes'] );
		$this->assertGreaterThanOrEqual( 64 * 1024 * 1024, $result['reserve_bytes'] );
		$this->assertSame( 'PRESENT', $result['plan'][0]['class'] );
	}

	public function test_blocked_insufficient_disk(): void {
		$this->seed_attachment( 11, '2026/09/b.jpg', null );
		$row = $this->present_row( 11, 3, '2026/09/b.jpg', 'uploads/2026/09/b.jpg', 200 * 1024 * 1024 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 200 * 1024 * 1024 ) );

		$result = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects( array( $row ) ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => 100 * 1024 * 1024,
		)->preflight( array( 11 ) );

		$this->assertSame( RestorePreflightResult::OUTCOME_BLOCKED, $result['outcome'] );
		$this->assertContains( 'insufficient_disk', $result['reasons'] );
	}

	public function test_blocked_unknown_free_space_when_restorable_bytes(): void {
		$this->seed_attachment( 12, '2026/09/c.jpg', null );
		$row = $this->present_row( 12, 3, '2026/09/c.jpg', 'uploads/2026/09/c.jpg', 50 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 50 ) );

		$result = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects( array( $row ) ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => false,
		)->preflight( array( 12 ) );

		$this->assertSame( RestorePreflightResult::OUTCOME_BLOCKED, $result['outcome'] );
		$this->assertContains( 'unknown_free_space', $result['reasons'] );
	}

	public function test_blocked_when_destination_not_writable(): void {
		$this->seed_attachment( 13, '2026/09/d.jpg', null );
		$row = $this->present_row( 13, 3, '2026/09/d.jpg', 'uploads/2026/09/d.jpg', 50 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 50 ) );

		$result = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects( array( $row ) ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
			is_writable: static fn (): bool => false,
		)->preflight( array( 13 ) );

		$this->assertSame( RestorePreflightResult::OUTCOME_BLOCKED, $result['outcome'] );
		$this->assertContains( 'destination_not_writable', $result['reasons'] );
	}

	public function test_reserve_threshold_uses_max_of_ten_percent_and_64mib(): void {
		$this->seed_attachment( 14, '2026/09/e.jpg', null );
		// 100 MiB required → 10% = 10 MiB, floor 64 MiB wins.
		$row = $this->present_row( 14, 3, '2026/09/e.jpg', 'uploads/2026/09/e.jpg', 100 * 1024 * 1024 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 100 * 1024 * 1024 ) );

		$free_ok = ( 100 * 1024 * 1024 ) + ( 64 * 1024 * 1024 );
		$result  = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects( array( $row ) ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => $free_ok,
		)->preflight( array( 14 ) );

		$this->assertSame( 64 * 1024 * 1024, $result['reserve_bytes'] );
		$this->assertSame( RestorePreflightResult::OUTCOME_SAFE, $result['outcome'] );

		$result_tight = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects( array( $row ) ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => $free_ok - 1,
		)->preflight( array( 14 ) );
		$this->assertSame( RestorePreflightResult::OUTCOME_BLOCKED, $result_tight['outcome'] );
	}

	public function test_all_confirmed_missing_is_blocked(): void {
		$this->seed_attachment( 15, '2026/09/f.jpg', null );
		$row = $this->present_row( 15, 3, '2026/09/f.jpg', 'uploads/2026/09/f.jpg', 50 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn(
			array(
				'exists'            => false,
				'confirmed_missing' => true,
			)
		);

		$result = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects( array( $row ) ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
		)->preflight( array( 15 ) );

		$this->assertSame( RestorePreflightResult::OUTCOME_BLOCKED, $result['outcome'] );
		$this->assertContains( 'nothing_restorable', $result['reasons'] );
		$this->assertSame( 0, $result['required_bytes'] );
	}

	public function test_partial_confirmed_missing_is_warning(): void {
		$this->seed_attachment_with_sizes(
			16,
			'2026/09/g.jpg',
			array(
				'2026/09/g.jpg'         => null,
				'2026/09/g-150x150.jpg' => null,
			)
		);
		$rows = array(
			$this->present_row( 16, 3, '2026/09/g.jpg', 'uploads/2026/09/g.jpg', 100 ),
			$this->present_row( 16, 3, '2026/09/g-150x150.jpg', 'uploads/2026/09/g-150x150.jpg', 20, 2 ),
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturnCallback(
			static function ( string $key ): array {
				if ( str_contains( $key, '150x150' ) ) {
					return array( 'exists' => false, 'confirmed_missing' => true );
				}
				return array( 'exists' => true, 'content_length' => 100 );
			}
		);

		$result = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects( $rows ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
		)->preflight( array( 16 ) );

		$this->assertSame( RestorePreflightResult::OUTCOME_WARNING, $result['outcome'] );
		$this->assertSame( 100, $result['required_bytes'] );
		$this->assertSame( 1, $result['counts']['CONFIRMED_MISSING'] );
		$this->assertSame( 1, $result['restorable_count'] );
	}

	public function test_transient_is_warning_auth_and_invalid_request_blocked(): void {
		foreach (
			array(
				array(
					'head'     => array( 'exists' => false, 'error' => 'timeout', 'error_code' => 'RequestTimeout', 'status_code' => 408 ),
					'outcome'  => RestorePreflightResult::OUTCOME_WARNING,
					'reason'   => 'transient',
					'class'    => 'TRANSIENT',
					'id'       => 17,
				),
				array(
					'head'     => array( 'exists' => false, 'error' => 'Access Denied', 'error_code' => 'AccessDenied', 'status_code' => 403 ),
					'outcome'  => RestorePreflightResult::OUTCOME_BLOCKED,
					'reason'   => 'auth',
					'class'    => 'AUTH',
					'id'       => 18,
				),
				array(
					'head'     => array( 'exists' => false, 'error' => 'NoSuchBucket', 'error_code' => 'NoSuchBucket', 'status_code' => 400 ),
					'outcome'  => RestorePreflightResult::OUTCOME_BLOCKED,
					'reason'   => 'invalid_request',
					'class'    => 'INVALID_REQUEST',
					'id'       => 19,
				),
			) as $case
		) {
			$this->seed_attachment( $case['id'], '2026/09/x' . $case['id'] . '.jpg', null );
			$row     = $this->present_row( $case['id'], 3, '2026/09/x' . $case['id'] . '.jpg', 'uploads/2026/09/x' . $case['id'] . '.jpg', 50 );
			$gateway = $this->createMock( ProfileStorageGateway::class );
			$gateway->method( 'head_key' )->willReturn( $case['head'] );

			$result = $this->service(
				$gateway,
				new RestorePreflightInMemoryObjects( array( $row ) ),
				$this->profiles( 3 ),
				disk_free: static fn (): int|false => 500 * 1024 * 1024,
			)->preflight( array( $case['id'] ) );

			$this->assertSame( $case['outcome'], $result['outcome'], $case['class'] );
			$this->assertContains( $case['reason'], $result['reasons'], $case['class'] );
			$this->assertSame( $case['class'], $result['plan'][0]['class'] );
		}
	}

	public function test_size_conflict_is_blocked(): void {
		$this->seed_attachment( 20, '2026/09/sc.jpg', null );
		$row = $this->present_row( 20, 3, '2026/09/sc.jpg', 'uploads/2026/09/sc.jpg', 100 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 50 ) );

		$result = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects( array( $row ) ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
		)->preflight( array( 20 ) );

		$this->assertSame( RestorePreflightResult::OUTCOME_BLOCKED, $result['outcome'] );
		$this->assertContains( 'size_conflict', $result['reasons'] );
		$this->assertSame( 'SIZE_CONFLICT', $result['plan'][0]['class'] );
	}

	public function test_empty_manifest_blocked(): void {
		WpStubs::$posts[ 21 ] = (object) array( 'ID' => 21, 'post_type' => 'attachment' );
		// No _wp_attached_file → empty manifest.

		$result = $this->service(
			$this->createMock( ProfileStorageGateway::class ),
			new RestorePreflightInMemoryObjects(),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
		)->preflight( array( 21 ) );

		$this->assertSame( RestorePreflightResult::OUTCOME_BLOCKED, $result['outcome'] );
		$this->assertContains( 'invalid_or_empty_manifest', $result['reasons'] );
	}

	public function test_pathguard_rejection_among_siblings_blocked(): void {
		$outside = sys_get_temp_dir() . '/kazus-restore-escape-' . uniqid( '', true );
		mkdir( $outside, 0777, true );
		$link = $this->uploads . '/escape-link';
		symlink( $outside, $link );

		try {
			$this->seed_attachment_with_sizes(
				22,
				'2026/09/ok.jpg',
				array(
					'2026/09/ok.jpg'           => null,
					'escape-link/evil.jpg'     => null,
				)
			);
			// Force both paths into metadata (basename-only sizes stay jailed; use full relative).
			WpStubs::set_meta(
				22,
				'_wp_attachment_metadata',
				array(
					'file'  => '2026/09/ok.jpg',
					'sizes' => array(
						'evil' => array( 'file' => 'escape-link/evil.jpg' ),
					),
				)
			);
			$row = $this->present_row( 22, 3, '2026/09/ok.jpg', 'uploads/2026/09/ok.jpg', 50 );

			$gateway = $this->createMock( ProfileStorageGateway::class );
			$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 50 ) );

			$result = $this->service(
				$gateway,
				new RestorePreflightInMemoryObjects( array( $row ) ),
				$this->profiles( 3 ),
				disk_free: static fn (): int|false => 500 * 1024 * 1024,
			)->preflight( array( 22 ) );

			$this->assertSame( RestorePreflightResult::OUTCOME_BLOCKED, $result['outcome'] );
			$this->assertContains( 'invalid_restore_path', $result['reasons'] );
		} finally {
			if ( is_link( $link ) ) {
				unlink( $link );
			}
			$this->rmTree( $outside );
		}
	}

	public function test_object_not_present_reobserves_via_stored_key(): void {
		$this->seed_attachment( 23, '2026/09/nop.jpg', null );
		$row               = $this->present_row( 23, 3, '2026/09/nop.jpg', 'custom/exact-nop.jpg', 77 );
		$row['remote_status'] = ObjectRemoteStatus::MISSING;

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->once() )
			->method( 'head_key' )
			->with( 'custom/exact-nop.jpg' )
			->willReturn( array( 'exists' => true, 'content_length' => 77 ) );

		$result = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects( array( $row ) ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
		)->preflight( array( 23 ) );

		$this->assertSame( RestorePreflightResult::OUTCOME_SAFE, $result['outcome'] );
		$this->assertSame( 'custom/exact-nop.jpg', $result['plan'][0]['object_key'] );
		$this->assertFalse( $result['plan'][0]['legacy_fallback'] );
	}

	public function test_partial_gap_without_exact_key_blocked_ambiguous_key(): void {
		$this->seed_attachment_with_sizes(
			24,
			'2026/09/pg.jpg',
			array(
				'2026/09/pg.jpg'         => null,
				'2026/09/pg-150x150.jpg' => null,
			)
		);
		// Only original in inventory — thumb is partial gap.
		$row = $this->present_row( 24, 3, '2026/09/pg.jpg', 'uploads/2026/09/pg.jpg', 100 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 100 ) );

		$result = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects( array( $row ) ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
		)->preflight( array( 24 ) );

		$this->assertSame( RestorePreflightResult::OUTCOME_BLOCKED, $result['outcome'] );
		$this->assertContains( 'ambiguous_key', $result['reasons'] );
	}

	public function test_importer_exact_key_retained_legacy_forbidden(): void {
		$this->seed_attachment( 25, '2026/09/imp.jpg', null );
		$importer = new RestorePreflightExactKeyFakeImporter(
			array(
				new ImportedObjectDescriptor(
					25,
					'2026/09/imp.jpg',
					'original',
					'custom/importer-key.jpg',
					88,
					null,
					'test-fingerprint'
				),
			)
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->once() )
			->method( 'head_key' )
			->with( 'custom/importer-key.jpg' )
			->willReturn( array( 'exists' => true, 'content_length' => 88 ) );

		$legacy = $this->createMock( S3Storage::class );
		$legacy->expects( $this->never() )->method( 'head_relative' );

		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_aws_configured' )->willReturn( true );

		$result = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects(),
			$this->profiles( 3 ),
			imports: new ImportRegistry( array( $importer ) ),
			legacy: $legacy,
			settings: $settings,
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
		)->preflight( array( 25 ) );

		$this->assertSame( RestorePreflightResult::OUTCOME_SAFE, $result['outcome'] );
		$this->assertSame( 'custom/importer-key.jpg', $result['plan'][0]['object_key'] );
		$this->assertFalse( $result['plan'][0]['legacy_fallback'] );
	}

	public function test_wholly_untracked_legacy_allowed(): void {
		$this->seed_attachment( 26, '2026/09/leg.jpg', null );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );

		$legacy = $this->createMock( S3Storage::class );
		$legacy->expects( $this->once() )
			->method( 'head_relative' )
			->with( '2026/09/leg.jpg' )
			->willReturn( array( 'exists' => true, 'content_length' => 40 ) );

		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_aws_configured' )->willReturn( true );

		$result = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects(),
			$this->profiles( 3 ),
			legacy: $legacy,
			settings: $settings,
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
		)->preflight( array( 26 ) );

		$this->assertSame( RestorePreflightResult::OUTCOME_SAFE, $result['outcome'] );
		$this->assertTrue( $result['plan'][0]['legacy_fallback'] );
	}

	public function test_required_download_excludes_trustworthy_local_includes_truncated(): void {
		$this->seed_attachment( 27, '2026/09/loc.jpg', 100 ); // trustworthy local
		$row = $this->present_row( 27, 3, '2026/09/loc.jpg', 'uploads/2026/09/loc.jpg', 100 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );

		$result = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects( array( $row ) ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
		)->preflight( array( 27 ) );

		$this->assertSame( RestorePreflightResult::OUTCOME_SAFE, $result['outcome'] );
		$this->assertSame( 0, $result['required_download_count'] );
		$this->assertSame( array(), $result['plan'] );

		// Truncated local must be included.
		$this->seed_attachment( 28, '2026/09/trunc.jpg', 10 );
		$row2 = $this->present_row( 28, 3, '2026/09/trunc.jpg', 'uploads/2026/09/trunc.jpg', 100 );
		$gateway2 = $this->createMock( ProfileStorageGateway::class );
		$gateway2->expects( $this->once() )
			->method( 'head_key' )
			->willReturn( array( 'exists' => true, 'content_length' => 100 ) );

		$result2 = $this->service(
			$gateway2,
			new RestorePreflightInMemoryObjects( array( $row2 ) ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
		)->preflight( array( 28 ) );

		$this->assertSame( 1, $result2['required_download_count'] );
		$this->assertSame( RestorePreflightResult::OUTCOME_SAFE, $result2['outcome'] );
	}

	public function test_capacity_ignores_confirmed_missing_sizes(): void {
		$this->seed_attachment_with_sizes(
			29,
			'2026/09/cap.jpg',
			array(
				'2026/09/cap.jpg'         => null,
				'2026/09/cap-150x150.jpg' => null,
			)
		);
		$rows = array(
			$this->present_row( 29, 3, '2026/09/cap.jpg', 'uploads/2026/09/cap.jpg', 50 ),
			$this->present_row( 29, 3, '2026/09/cap-150x150.jpg', 'uploads/2026/09/cap-150x150.jpg', 999999, 2 ),
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturnCallback(
			static function ( string $key ): array {
				if ( str_contains( $key, '150x150' ) ) {
					return array( 'exists' => false, 'confirmed_missing' => true );
				}
				return array( 'exists' => true, 'content_length' => 50 );
			}
		);

		$result = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects( $rows ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
		)->preflight( array( 29 ) );

		$this->assertSame( 50, $result['required_bytes'] );
	}

	public function test_disk_free_uses_ancestor_when_basedir_missing(): void {
		$missing_basedir = $this->uploads . '/missing-uploads-root';
		WpStubs::$uploads_basedir = $missing_basedir;
		$this->assertDirectoryDoesNotExist( $missing_basedir );

		$this->seed_attachment( 30, '2026/09/anc.jpg', null );
		$row = $this->present_row( 30, 3, '2026/09/anc.jpg', 'uploads/2026/09/anc.jpg', 40 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 40 ) );

		$probed = array();
		$result = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects( array( $row ) ),
			$this->profiles( 3 ),
			disk_free: static function ( string $path ) use ( &$probed ): int|false {
				$probed[] = $path;
				return 500 * 1024 * 1024;
			},
			uploads_basedir: $missing_basedir,
		)->preflight( array( 30 ) );

		$this->assertSame( RestorePreflightResult::OUTCOME_SAFE, $result['outcome'] );
		$this->assertNotEmpty( $probed );
		$this->assertTrue( is_dir( $probed[0] ) );
		$this->assertDirectoryDoesNotExist( $missing_basedir );
	}

	public function test_preflight_creates_no_directories(): void {
		$missing = $this->uploads . '/never-create-me';
		WpStubs::$uploads_basedir = $missing;

		$this->seed_attachment( 31, '2026/09/nodir.jpg', null );
		$row = $this->present_row( 31, 3, '2026/09/nodir.jpg', 'uploads/2026/09/nodir.jpg', 40 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 40 ) );

		$this->service(
			$gateway,
			new RestorePreflightInMemoryObjects( array( $row ) ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
			uploads_basedir: $missing,
		)->preflight( array( 31 ) );

		$this->assertDirectoryDoesNotExist( $missing );
	}

	public function test_gate_restore_refuses_blocked_and_warning_without_ack(): void {
		$this->seed_attachment( 32, '2026/09/gate.jpg', null );
		$row = $this->present_row( 32, 3, '2026/09/gate.jpg', 'uploads/2026/09/gate.jpg', 50 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn(
			array(
				'exists'     => false,
				'error'      => 'Access Denied',
				'error_code' => 'AccessDenied',
				'status_code'=> 403,
			)
		);

		$preflight = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects( array( $row ) ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
		);

		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_aws_configured' )->willReturn( true );
		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->expects( $this->never() )->method( 'download_resolved_to_local' );

		$restorer = new AttachmentRestorer( $settings, $storage, null, null, $profile_ops, $preflight );
		$blocked  = $restorer->restore( 32 );
		$this->assertFalse( $blocked['success'] );
		$this->assertSame( 'blocked', $blocked['status'] ?? '' );

		// WARNING without acknowledge.
		$gateway_w = $this->createMock( ProfileStorageGateway::class );
		$gateway_w->method( 'head_key' )->willReturn(
			array( 'exists' => false, 'error' => 'timeout', 'error_code' => 'RequestTimeout', 'status_code' => 408 )
		);
		$this->seed_attachment( 33, '2026/09/warn.jpg', null );
		$row_w     = $this->present_row( 33, 3, '2026/09/warn.jpg', 'uploads/2026/09/warn.jpg', 50 );
		$preflight_w = $this->service(
			$gateway_w,
			new RestorePreflightInMemoryObjects( array( $row_w ) ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
		);
		$restorer_w = new AttachmentRestorer( $settings, $storage, null, null, $profile_ops, $preflight_w );
		$warn       = $restorer_w->restore( 33 );
		$this->assertFalse( $warn['success'] );
		$this->assertSame( 'warning_requires_acknowledge', $warn['status'] ?? '' );
	}

	public function test_gate_acknowledge_downloads_only_restorable_plan_with_resolved_key(): void {
		$this->seed_attachment_with_sizes(
			34,
			'2026/09/ack.jpg',
			array(
				'2026/09/ack.jpg'         => null,
				'2026/09/ack-150x150.jpg' => null,
			)
		);
		$rows = array(
			$this->present_row( 34, 3, '2026/09/ack.jpg', 'custom/exact-ack.jpg', 100 ),
			$this->present_row( 34, 3, '2026/09/ack-150x150.jpg', 'custom/exact-thumb.jpg', 20, 2 ),
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturnCallback(
			static function ( string $key ): array {
				if ( $key === 'custom/exact-thumb.jpg' ) {
					return array( 'exists' => false, 'confirmed_missing' => true );
				}
				return array( 'exists' => true, 'content_length' => 100 );
			}
		);

		$preflight = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects( $rows ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
		);

		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_aws_configured' )->willReturn( true );
		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );

		$downloaded = array();
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->expects( $this->once() )
			->method( 'download_resolved_to_local' )
			->willReturnCallback(
				static function (
					int $attachment_id,
					string $relative,
					string $absolute,
					?int $profile_id,
					string $object_key,
					bool $legacy_fallback,
					?int $expected_size = null
				) use ( &$downloaded ): array {
					unset( $attachment_id, $legacy_fallback, $expected_size );
					$downloaded[] = array( $relative, $object_key, $profile_id );
					$dir = dirname( $absolute );
					if ( ! is_dir( $dir ) ) {
						wp_mkdir_p( $dir );
					}
					file_put_contents( $absolute, str_repeat( 'x', 100 ) );
					return array( 'success' => true, 'location_status' => 'found', 'legacy_fallback' => false );
				}
			);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->willReturn( $rows );
		$objects->expects( $this->never() )->method( 'delete_by_attachment' );

		$restorer = new AttachmentRestorer( $settings, $storage, null, null, $profile_ops, $preflight );
		$ref      = new \ReflectionClass( AttachmentRestorer::class );
		$prop     = $ref->getProperty( 'objects' );
		$prop->setValue( $restorer, $objects );

		$result = $restorer->restore( 34, array( 'acknowledge_warning' => true ) );

		$this->assertFalse( $result['success'] ); // partial — missing thumb
		$this->assertSame( 'partial', $result['status'] ?? '' );
		$this->assertCount( 1, $downloaded );
		$this->assertSame( '2026/09/ack.jpg', $downloaded[0][0] );
		$this->assertSame( 'custom/exact-ack.jpg', $downloaded[0][1] );
		$this->assertSame( 3, $downloaded[0][2] );
	}

	public function test_capacity_lock_rechecks_free_space_under_lock(): void {
		$this->seed_attachment( 35, '2026/09/lock.jpg', null );
		$row = $this->present_row( 35, 3, '2026/09/lock.jpg', 'uploads/2026/09/lock.jpg', 50 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 50 ) );

		$free_calls = 0;
		$preflight  = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects( array( $row ) ),
			$this->profiles( 3 ),
			disk_free: static function () use ( &$free_calls ): int|false {
				++$free_calls;
				// First call (preflight) OK; second under lock → insufficient.
				return $free_calls === 1 ? 500 * 1024 * 1024 : 10;
			},
		);

		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_aws_configured' )->willReturn( true );
		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->expects( $this->never() )->method( 'download_resolved_to_local' );

		$restorer = new AttachmentRestorer( $settings, $storage, null, null, $profile_ops, $preflight );
		$result   = $restorer->restore( 35 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'blocked', $result['status'] ?? '' );
		$this->assertGreaterThanOrEqual( 2, $free_calls );
		$this->assertArrayNotHasKey( RestorePreflightService::CAPACITY_LOCK_OPTION, WpStubs::$options );
	}

	public function test_capacity_lock_released_when_attachment_lease_busy(): void {
		$this->seed_attachment( 37, '2026/09/busy.jpg', null );
		$row = $this->present_row( 37, 3, '2026/09/busy.jpg', 'uploads/2026/09/busy.jpg', 50 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 50 ) );

		$preflight = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects( array( $row ) ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
		);

		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_aws_configured' )->willReturn( true );
		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->expects( $this->never() )->method( 'download_resolved_to_local' );

		$restorer = new AttachmentRestorer( $settings, $storage, null, null, $profile_ops, $preflight );

		// Hold an attachment lease so restore cannot acquire it.
		$lock  = new \Kazcode\WpStorage\Infrastructure\AttachmentLock();
		$lease = $lock->acquire_lease( 37, 'other' );
		$this->assertInstanceOf( \Kazcode\WpStorage\Infrastructure\AttachmentLeaseHandle::class, $lease );

		try {
			$result = $restorer->restore( 37 );
			$this->assertFalse( $result['success'] );
			$this->assertStringContainsString( 'locked', strtolower( (string) ( $result['message'] ?? '' ) ) );
			$this->assertArrayNotHasKey( RestorePreflightService::CAPACITY_LOCK_OPTION, WpStubs::$options );
		} finally {
			$lock->release_lease( $lease );
		}
	}

	public function test_unknown_size_among_restorable_is_warning(): void {
		$this->seed_attachment( 36, '2026/09/unk.jpg', null );
		$row               = $this->present_row( 36, 3, '2026/09/unk.jpg', 'uploads/2026/09/unk.jpg', 0 );
		$row['size_bytes'] = null;

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true ) ); // no content_length

		$result = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects( array( $row ) ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
		)->preflight( array( 36 ) );

		$this->assertSame( RestorePreflightResult::OUTCOME_WARNING, $result['outcome'] );
		$this->assertContains( 'unknown_size', $result['reasons'] );
		$this->assertSame( 0, $result['required_bytes'] );
	}

	public function test_capacity_lock_second_acquire_fails_atomically(): void {
		$preflight = $this->service(
			$this->createMock( ProfileStorageGateway::class ),
			new RestorePreflightInMemoryObjects( array() ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
		);
		$first = $preflight->acquire_capacity_lock( '/tmp/uploads' );
		$this->assertIsString( $first );
		$second = $preflight->acquire_capacity_lock( '/tmp/uploads' );
		$this->assertNull( $second );
		$preflight->release_capacity_lock( $first );
		$third = $preflight->acquire_capacity_lock( '/tmp/uploads' );
		$this->assertIsString( $third );
		$preflight->release_capacity_lock( $third );
	}

	public function test_existing_local_without_inventory_size_conflicts_when_remote_differs(): void {
		$this->seed_attachment( 38, '2026/09/local-ok.jpg', 100 );
		$row               = $this->present_row( 38, 3, '2026/09/local-ok.jpg', 'uploads/2026/09/local-ok.jpg', 0 );
		$row['size_bytes'] = null;

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 50 ) );

		$result = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects( array( $row ) ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
		)->preflight( array( 38 ) );

		$this->assertSame( RestorePreflightResult::OUTCOME_BLOCKED, $result['outcome'] );
		$this->assertSame( 1, $result['counts'][ RestorePreflightResult::CLASS_SIZE_CONFLICT ] ?? 0 );
	}

	public function test_existing_local_matching_remote_size_excluded_without_inventory_size(): void {
		$this->seed_attachment( 39, '2026/09/local-match.jpg', 80 );
		$row               = $this->present_row( 39, 3, '2026/09/local-match.jpg', 'uploads/2026/09/local-match.jpg', 0 );
		$row['size_bytes'] = null;

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 80 ) );

		$result = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects( array( $row ) ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
		)->preflight( array( 39 ) );

		$this->assertSame( RestorePreflightResult::OUTCOME_SAFE, $result['outcome'] );
		$this->assertSame( 0, $result['required_download_count'] );
	}

	public function test_importer_fingerprint_blocks_when_no_compatible_profile(): void {
		$this->seed_attachment( 40, '2026/09/fp.jpg', null );
		$importer = new RestorePreflightExactKeyFakeImporter(
			array(
				new ImportedObjectDescriptor(
					40,
					'2026/09/fp.jpg',
					'original',
					'foreign/key.jpg',
					100,
					'image/jpeg',
					'wp-offload-media:aws:other-bucket:us-east-1:https://s3.example'
				),
			)
		);
		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );

		$result = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects( array() ),
			$this->profiles( 3 ),
			imports: new ImportRegistry( array( $importer ) ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
		)->preflight( array( 40 ) );

		$this->assertSame( RestorePreflightResult::OUTCOME_BLOCKED, $result['outcome'] );
		$this->assertContains( 'profile_missing', $result['reasons'] );
	}

	public function test_child_directory_not_writable_blocks(): void {
		$this->seed_attachment( 41, '2026/09/child.jpg', null );
		$row = $this->present_row( 41, 3, '2026/09/child.jpg', 'uploads/2026/09/child.jpg', 50 );
		$child = $this->uploads . '/2026/09';
		wp_mkdir_p( $child );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 50 ) );

		$result = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects( array( $row ) ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
			is_writable: static function ( string $path ) use ( $child ): bool {
				return rtrim( $path, '/' ) !== rtrim( $child, '/' );
			},
		)->preflight( array( 41 ) );

		$this->assertSame( RestorePreflightResult::OUTCOME_BLOCKED, $result['outcome'] );
		$this->assertContains( 'destination_not_writable', $result['reasons'] );
	}

	public function test_importer_source_size_used_when_inventory_size_absent(): void {
		$this->seed_attachment( 42, '2026/09/isz.jpg', null );
		$importer = new RestorePreflightExactKeyFakeImporter(
			array(
				new ImportedObjectDescriptor(
					42,
					'2026/09/isz.jpg',
					'original',
					'custom/isz.jpg',
					100,
					null,
					'test-fingerprint'
				),
			)
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->once() )
			->method( 'head_key' )
			->with( 'custom/isz.jpg' )
			->willReturn( array( 'exists' => true, 'content_length' => 50 ) );

		$result = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects(),
			$this->profiles( 3 ),
			imports: new ImportRegistry( array( $importer ) ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
		)->preflight( array( 42 ) );

		$this->assertSame( RestorePreflightResult::OUTCOME_BLOCKED, $result['outcome'] );
		$this->assertSame( 1, $result['counts'][ RestorePreflightResult::CLASS_SIZE_CONFLICT ] ?? 0 );
		$this->assertContains( 'size_conflict', $result['reasons'] );
	}

	public function test_importer_same_key_conflicting_fingerprints_blocked(): void {
		$this->seed_attachment( 43, '2026/09/dup.jpg', null );
		$importer = new RestorePreflightExactKeyFakeImporter(
			array(
				new ImportedObjectDescriptor(
					43,
					'2026/09/dup.jpg',
					'original',
					'shared/key.jpg',
					100,
					null,
					'wp-offload-media:aws:bucket-a:us-east-1'
				),
				new ImportedObjectDescriptor(
					43,
					'2026/09/dup.jpg',
					'original',
					'shared/key.jpg',
					100,
					null,
					'wp-offload-media:aws:bucket-b:us-west-2'
				),
			)
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );

		$result = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects(),
			$this->profiles( 3 ),
			imports: new ImportRegistry( array( $importer ) ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
		)->preflight( array( 43 ) );

		$this->assertSame( RestorePreflightResult::OUTCOME_BLOCKED, $result['outcome'] );
		$this->assertContains( 'ambiguous_profile', $result['reasons'] );
	}

	public function test_capacity_lock_renew_refuses_when_ownership_replaced(): void {
		$preflight = $this->service(
			$this->createMock( ProfileStorageGateway::class ),
			new RestorePreflightInMemoryObjects( array() ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
		);
		$token = $preflight->acquire_capacity_lock( '/tmp/uploads' );
		$this->assertIsString( $token );

		// Simulate another restore taking over after expiry/steal.
		update_option(
			RestorePreflightService::CAPACITY_LOCK_OPTION,
			array(
				'owner'      => 'other-owner',
				'basedir'    => '/tmp/uploads',
				'expires_at' => time() + 1800,
			),
			false
		);

		$this->assertFalse( $preflight->renew_capacity_lock( $token ) );
		$stored = get_option( RestorePreflightService::CAPACITY_LOCK_OPTION, null );
		$this->assertIsArray( $stored );
		$this->assertSame( 'other-owner', $stored['owner'] );
	}

	public function test_preflight_heartbeat_invoked_per_manifest_item(): void {
		$this->seed_attachment( 44, '2026/09/hb.jpg', null );
		$row = $this->present_row( 44, 3, '2026/09/hb.jpg', 'uploads/2026/09/hb.jpg', 40 );
		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 40 ) );

		$beats = 0;
		$result = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects( array( $row ) ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
		)->preflight(
			array( 44 ),
			static function () use ( &$beats ): void {
				++$beats;
			}
		);

		$this->assertSame( RestorePreflightResult::OUTCOME_SAFE, $result['outcome'] );
		$this->assertGreaterThanOrEqual( 2, $beats ); // attachment + at least one item
	}

	public function test_expected_size_zero_not_overwritten_by_head_length(): void {
		$this->seed_attachment( 45, '2026/09/empty.bin', null );
		$row = $this->present_row( 45, 3, '2026/09/empty.bin', 'uploads/2026/09/empty.bin', 0 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 12 ) );

		$result = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects( array( $row ) ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
		)->preflight( array( 45 ) );

		$this->assertSame( RestorePreflightResult::OUTCOME_BLOCKED, $result['outcome'] );
		$this->assertSame( 1, $result['counts'][ RestorePreflightResult::CLASS_SIZE_CONFLICT ] ?? 0 );
		$this->assertSame( 0, $result['plan'][0]['expected_size'] );
	}

	public function test_invalid_size_variant_with_traversal_blocks_even_when_original_ok(): void {
		$this->seed_attachment( 46, '2026/09/ok.jpg', null );
		WpStubs::set_meta(
			46,
			'_wp_attachment_metadata',
			array(
				'file'  => '2026/09/ok.jpg',
				'sizes' => array(
					'evil' => array( 'file' => '../evil.jpg' ),
				),
			)
		);
		$row = $this->present_row( 46, 3, '2026/09/ok.jpg', 'uploads/2026/09/ok.jpg', 50 );
		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );

		$result = $this->service(
			$gateway,
			new RestorePreflightInMemoryObjects( array( $row ) ),
			$this->profiles( 3 ),
			disk_free: static fn (): int|false => 500 * 1024 * 1024,
		)->preflight( array( 46 ) );

		$this->assertSame( RestorePreflightResult::OUTCOME_BLOCKED, $result['outcome'] );
		$this->assertContains( 'invalid_restore_path', $result['reasons'] );
	}

	public function test_ttl_for_large_object_is_not_capped_below_transfer_time(): void {
		// ~200 MiB at 25 KiB/s + 600s buffer exceeds the former 7200s cap.
		$bytes = 200 * 1024 * 1024;
		$ttl   = RestorePreflightService::ttl_for_expected_bytes( $bytes );
		$this->assertGreaterThan( 7200, $ttl );
		$this->assertSame( (int) ceil( $bytes / 25000 ) + 600, $ttl );
	}

	public function test_ttl_for_unknown_size_keeps_day_long_lease(): void {
		$ttl = RestorePreflightService::ttl_for_expected_bytes( null );
		$this->assertGreaterThanOrEqual( 24 * 3600, $ttl );
	}

	public function test_invalid_filter_contribution_blocks_even_when_original_ok(): void {
		$this->seed_attachment( 47, '2026/09/ok.jpg', null );
		$row = $this->present_row( 47, 3, '2026/09/ok.jpg', 'uploads/2026/09/ok.jpg', 50 );
		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );

		$filter = static function ( array $extra, int $attachment_id ): array {
			if ( $attachment_id !== 47 ) {
				return $extra;
			}
			$extra[] = array(
				'relative'     => '../sidecar.webp',
				'variant_type' => 'other',
			);
			return $extra;
		};
		add_filter( 'kazus_manifest_files', $filter, 10, 2 );
		try {
			$result = $this->service(
				$gateway,
				new RestorePreflightInMemoryObjects( array( $row ) ),
				$this->profiles( 3 ),
				disk_free: static fn (): int|false => 500 * 1024 * 1024,
			)->preflight( array( 47 ) );
		} finally {
			remove_all_filters( 'kazus_manifest_files' );
		}

		$this->assertSame( RestorePreflightResult::OUTCOME_BLOCKED, $result['outcome'] );
		$this->assertContains( 'invalid_restore_path', $result['reasons'] );
	}

	/**
	 * @param callable(string): (int|false)|null $disk_free
	 * @param callable(string): bool|null        $is_writable
	 */
	private function service(
		$gateway,
		ObjectRepository $objects,
		WpdbStorageProfileRepository $profiles,
		?ImportRegistry $imports = null,
		?S3Storage $legacy = null,
		?Settings $settings = null,
		$disk_free = null,
		$is_writable = null,
		?string $uploads_basedir = null,
	): RestorePreflightService {
		$settings = $settings ?? $this->createMock( Settings::class );
		return new RestorePreflightService(
			$settings,
			$objects,
			$profiles,
			static fn (): ProfileStorageGateway => $gateway,
			$imports ?? new ImportRegistry( array() ),
			$legacy,
			$disk_free,
			$is_writable,
			$uploads_basedir
		);
	}

	private function profiles( int $default_id ): WpdbStorageProfileRepository {
		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->willReturnCallback(
			fn ( int $id ): StorageProfile => $this->profile( $id )
		);
		$profiles->method( 'find_default_upload_target' )->willReturn( $this->profile( $default_id ) );
		$profiles->method( 'all' )->willReturn( array( $this->profile( $default_id ) ) );
		return $profiles;
	}

	private function profile( int $id, string $prefix = 'uploads/' ): StorageProfile {
		$now = gmdate( 'Y-m-d H:i:s' );
		return new StorageProfile(
			$id,
			'uuid-' . $id,
			'P' . $id,
			'aws',
			'bucket',
			'us-east-1',
			'',
			false,
			$prefix,
			'storage',
			'',
			false,
			'keys',
			'',
			true,
			false,
			false,
			$now,
			$now,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function present_row(
		int $attachment_id,
		int $profile_id,
		string $relative,
		string $key,
		?int $size,
		int $id = 1
	): array {
		return array(
			'id'                  => $id,
			'attachment_id'       => $attachment_id,
			'storage_profile_id'  => $profile_id,
			'local_relative_path' => $relative,
			'object_key'          => $key,
			'remote_status'       => ObjectRemoteStatus::PRESENT,
			'size_bytes'          => $size,
			'variant_type'        => 'original',
		);
	}

	private function seed_attachment( int $id, string $relative, ?int $local_size ): void {
		WpStubs::$posts[ $id ] = (object) array(
			'ID'        => $id,
			'post_type' => 'attachment',
		);
		WpStubs::set_meta( $id, '_wp_attached_file', $relative );
		WpStubs::set_meta(
			$id,
			'_wp_attachment_metadata',
			array(
				'file'  => $relative,
				'sizes' => array(),
			)
		);
		if ( $local_size === null ) {
			return;
		}
		$abs = rtrim( WpStubs::$uploads_basedir, '/' ) . '/' . $relative;
		$dir = dirname( $abs );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		file_put_contents( $abs, str_repeat( 'x', $local_size ) );
	}

	/**
	 * @param array<string, int|null> $files relative => local size (null = absent).
	 */
	private function seed_attachment_with_sizes( int $id, string $primary, array $files ): void {
		WpStubs::$posts[ $id ] = (object) array(
			'ID'        => $id,
			'post_type' => 'attachment',
		);
		WpStubs::set_meta( $id, '_wp_attached_file', $primary );
		$sizes = array();
		foreach ( $files as $relative => $size ) {
			if ( $size !== null ) {
				$abs = rtrim( WpStubs::$uploads_basedir, '/' ) . '/' . $relative;
				$dir = dirname( $abs );
				if ( ! is_dir( $dir ) ) {
					mkdir( $dir, 0777, true );
				}
				file_put_contents( $abs, str_repeat( 'x', $size ) );
			}
			if ( $relative !== $primary ) {
				$sizes['thumb'] = array(
					'file'   => basename( $relative ),
					'width'  => 150,
					'height' => 150,
				);
			}
		}
		WpStubs::set_meta(
			$id,
			'_wp_attachment_metadata',
			array(
				'file'  => $primary,
				'sizes' => $sizes,
			)
		);
	}

	private function rmTree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		if ( $items === false ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( $item === '.' || $item === '..' ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->rmTree( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}
}

/**
 * In-memory ObjectRepository for restore preflight tests.
 */
final class RestorePreflightInMemoryObjects extends ObjectRepository {

	/** @var list<array<string, mixed>> */
	private array $rows;

	/** @param list<array<string, mixed>> $rows Seed. */
	public function __construct( array $rows = array() ) {
		$this->rows = $rows;
	}

	public function find_by_attachment( int $attachment_id ): array {
		return array_values(
			array_filter(
				$this->rows,
				static fn( array $row ): bool => (int) ( $row['attachment_id'] ?? 0 ) === $attachment_id
			)
		);
	}
}

/**
 * Importer stub with exact keys for restore preflight.
 */
final class RestorePreflightExactKeyFakeImporter implements ImporterInterface {

	/** @param list<ImportedObjectDescriptor> $descriptors */
	public function __construct( private array $descriptors ) {
	}

	public function id(): string {
		return 'restore-preflight-exact-key-fake';
	}

	public function label(): string {
		return 'Restore Preflight Exact Key Fake';
	}

	public function detect(): ImportDetection {
		return new ImportDetection(
			$this->id(),
			$this->label(),
			'1.0.0',
			's3',
			'bucket',
			'us-east-1',
			'',
			'uploads/',
			'exact'
		);
	}

	public function scan_page( int $limit, int $after_attachment_id = 0 ): ImportScanPage {
		return new ImportScanPage( $limit, $after_attachment_id, array() );
	}

	public function descriptors_for_attachment( int $attachment_id ): array {
		return array_values(
			array_filter(
				$this->descriptors,
				static fn( ImportedObjectDescriptor $d ): bool => $d->attachment_id() === $attachment_id
			)
		);
	}
}
