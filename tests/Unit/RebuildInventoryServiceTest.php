<?php
/**
 * Rebuild Storage Inventory (§6.3) characterization.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Attachment\AttachmentOffloader;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\AttachmentSyncDeriver;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Import\ImportRegistry;
use Kazcode\WpStorage\Import\ImportedObjectDescriptor;
use Kazcode\WpStorage\Import\ImporterInterface;
use Kazcode\WpStorage\Import\ImportDetection;
use Kazcode\WpStorage\Import\ImportScanPage;
use Kazcode\WpStorage\Infrastructure\AttachmentLock;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
use Kazcode\WpStorage\Services\ObjectStatsAggregator;
use Kazcode\WpStorage\Services\RebuildInventoryService;
use Kazcode\WpStorage\Storage\ProfileStorageGateway;
use Kazcode\WpStorage\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class RebuildInventoryServiceTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
		WpStubs::$uploads_basedir = sys_get_temp_dir() . '/kazus-rebuild-uploads';
		if ( ! is_dir( WpStubs::$uploads_basedir ) ) {
			mkdir( WpStubs::$uploads_basedir, 0777, true );
		}
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_dry_run_present_does_not_upsert(): void {
		$this->seed_attachment( 10, '2026/09/a.jpg', 100 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->once() )
			->method( 'head_key' )
			->with( 'uploads/2026/09/a.jpg' )
			->willReturn( array( 'exists' => true, 'content_length' => 100 ) );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->willReturn( array() );
		$objects->method( 'find_by_profile_and_key' )->willReturn( null );
		$objects->expects( $this->never() )->method( 'upsert' );
		$objects->expects( $this->never() )->method( 'insert_owned_or_conflict' );

		$result = $this->service( $gateway, $objects, $this->profiles( 3 ) )->rebuild_attachment( 10, null, true );

		$this->assertTrue( $result['success'] );
		$this->assertSame( RebuildInventoryService::OUTCOME_COMPLETE, $result['outcome'] );
		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( 1, $result['counts']['present'] );
		$this->assertSame( 0, $result['counts']['present_adopted'] );
		$this->assertSame( 'PRESENT', $result['results'][0]['class'] );
	}

	public function test_apply_adopts_present_with_inventory_size_match_when_local_absent(): void {
		$this->seed_attachment( 11, '2026/09/b.jpg', null ); // no local file

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->once() )
			->method( 'head_key' )
			->with( 'custom/exact-b.jpg' )
			->willReturn( array( 'exists' => true, 'content_length' => 555 ) );
		$gateway->expects( $this->never() )->method( 'put_object_from_file' );

		$row = array(
			'id'                  => 90,
			'attachment_id'       => 11,
			'storage_profile_id'  => 3,
			'local_relative_path' => '2026/09/b.jpg',
			'object_key'          => 'custom/exact-b.jpg',
			'remote_status'       => ObjectRemoteStatus::MISSING,
			'size_bytes'          => 555,
			'variant_type'        => 'original',
		);

		$objects = new RebuildInMemoryObjectRepository( array( $row ) );

		$result = $this->service( $gateway, $objects, $this->profiles( 3 ) )->rebuild_attachment( 11, null, false );

		$this->assertTrue( $result['success'] );
		$this->assertSame( RebuildInventoryService::OUTCOME_COMPLETE, $result['outcome'] );
		$this->assertSame( 1, $result['counts']['present_adopted'] );
		$this->assertSame( 'PRESENT', $result['results'][0]['class'] );
		$updated = $objects->find_by_profile_and_key( 3, 'custom/exact-b.jpg' );
		$this->assertNotNull( $updated );
		$this->assertSame( ObjectRemoteStatus::PRESENT, (string) $updated['remote_status'] );
		$this->assertSame( 555, (int) $updated['size_bytes'] );
		$this->assertSame( 'missing', (string) $updated['local_status'] );
		$this->assertSame( AttachmentOffloader::STATUS_OFFLOADED, (string) get_post_meta( 11, '_s3ms_status', true ) );
		$this->assertSame( 'custom/exact-b.jpg', (string) get_post_meta( 11, '_s3ms_original_key', true ) );
	}

	public function test_apply_sets_local_status_present_when_local_file_exists(): void {
		$this->seed_attachment( 33, '2026/09/local-present.jpg', 40 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 40 ) );

		$objects = new RebuildInMemoryObjectRepository();
		$result  = $this->service( $gateway, $objects, $this->profiles( 3 ) )->rebuild_attachment( 33, 3, false );

		$this->assertSame( RebuildInventoryService::OUTCOME_COMPLETE, $result['outcome'] );
		$row = $objects->find_by_profile_and_key( 3, 'uploads/2026/09/local-present.jpg' );
		$this->assertNotNull( $row );
		$this->assertSame( 'present', (string) $row['local_status'] );
	}

	public function test_sync_original_key_ignores_stale_present_original_for_other_path(): void {
		$this->seed_attachment( 34, '2026/09/current.jpg', 40 );

		$objects = new RebuildInMemoryObjectRepository(
			array(
				array(
					'id'                  => 1,
					'attachment_id'       => 34,
					'storage_profile_id'  => 3,
					'local_relative_path' => '2026/09/old-original.jpg',
					'object_key'          => 'uploads/2026/09/old-original.jpg',
					'remote_status'       => ObjectRemoteStatus::PRESENT,
					'variant_type'        => 'original',
					'size_bytes'          => 40,
				),
			)
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 40 ) );

		$result = $this->service( $gateway, $objects, $this->profiles( 3 ) )->rebuild_attachment( 34, 3, false );

		$this->assertSame( RebuildInventoryService::OUTCOME_COMPLETE, $result['outcome'] );
		$this->assertSame( 'uploads/2026/09/current.jpg', (string) get_post_meta( 34, '_s3ms_original_key', true ) );
		$this->assertNotSame( 'uploads/2026/09/old-original.jpg', (string) get_post_meta( 34, '_s3ms_original_key', true ) );
	}

	public function test_apply_auth_does_not_downgrade_offloaded_status(): void {
		$this->seed_attachment( 35, '2026/09/serve.jpg', null );
		WpStubs::set_meta( 35, '_s3ms_status', AttachmentOffloader::STATUS_OFFLOADED );

		$objects = new RebuildInMemoryObjectRepository(
			array(
				array(
					'id'                  => 1,
					'attachment_id'       => 35,
					'storage_profile_id'  => 3,
					'local_relative_path' => '2026/09/serve.jpg',
					'object_key'          => 'uploads/2026/09/serve.jpg',
					'remote_status'       => ObjectRemoteStatus::PRESENT,
					'variant_type'        => 'original',
					'size_bytes'          => 40,
					'verified_at'         => '2026-09-01 00:00:00',
				),
			)
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn(
			array(
				'exists'     => false,
				'error'      => 'Access Denied',
				'error_code' => 'AccessDenied',
				'status_code'=> 403,
			)
		);

		$result = $this->service( $gateway, $objects, $this->profiles( 3 ) )->rebuild_attachment( 35, 3, false );

		$this->assertSame( RebuildInventoryService::OUTCOME_FAILED, $result['outcome'] );
		$this->assertSame( AttachmentOffloader::STATUS_OFFLOADED, (string) get_post_meta( 35, '_s3ms_status', true ) );
		// Uncertain Apply must not rewrite verification timestamps.
		$this->assertSame( '', (string) get_post_meta( 35, '_s3ms_verified_at', true ) );
	}

	public function test_uncertain_head_does_not_promote_incomplete_inventory_to_offloaded(): void {
		$this->seed_attachment_with_sizes(
			38,
			'2026/09/partial-serve.jpg',
			array(
				'2026/09/partial-serve.jpg'         => 40,
				'2026/09/partial-serve-150x150.jpg' => 10,
			)
		);
		WpStubs::set_meta( 38, '_s3ms_status', AttachmentOffloader::STATUS_PARTIAL );

		$objects = new RebuildInMemoryObjectRepository(
			array(
				array(
					'id'                  => 1,
					'attachment_id'       => 38,
					'storage_profile_id'  => 3,
					'local_relative_path' => '2026/09/partial-serve.jpg',
					'object_key'          => 'uploads/2026/09/partial-serve.jpg',
					'remote_status'       => ObjectRemoteStatus::PRESENT,
					'variant_type'        => 'original',
					'size_bytes'          => 40,
				),
			)
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturnCallback(
			static function ( string $key ): array {
				if ( str_contains( $key, '150x150' ) ) {
					return array(
						'exists'     => false,
						'error'      => 'Access Denied',
						'error_code' => 'AccessDenied',
						'status_code'=> 403,
					);
				}
				return array( 'exists' => true, 'content_length' => 40 );
			}
		);

		$result = $this->service( $gateway, $objects, $this->profiles( 3 ) )->rebuild_attachment( 38, 3, false );

		$this->assertSame( RebuildInventoryService::OUTCOME_PARTIAL, $result['outcome'] );
		$this->assertNotSame( AttachmentOffloader::STATUS_OFFLOADED, (string) get_post_meta( 38, '_s3ms_status', true ) );
	}

	public function test_mixed_confirmed_missing_and_auth_does_not_preserve_offloaded(): void {
		$this->seed_attachment_with_sizes(
			39,
			'2026/09/mixed-fail.jpg',
			array(
				'2026/09/mixed-fail.jpg'         => 40,
				'2026/09/mixed-fail-150x150.jpg' => 10,
			)
		);
		WpStubs::set_meta( 39, '_s3ms_status', AttachmentOffloader::STATUS_OFFLOADED );

		$objects = new RebuildInMemoryObjectRepository(
			array(
				array(
					'id'                  => 1,
					'attachment_id'       => 39,
					'storage_profile_id'  => 3,
					'local_relative_path' => '2026/09/mixed-fail.jpg',
					'object_key'          => 'uploads/2026/09/mixed-fail.jpg',
					'remote_status'       => ObjectRemoteStatus::PRESENT,
					'variant_type'        => 'original',
					'size_bytes'          => 40,
				),
				array(
					'id'                  => 2,
					'attachment_id'       => 39,
					'storage_profile_id'  => 3,
					'local_relative_path' => '2026/09/mixed-fail-150x150.jpg',
					'object_key'          => 'uploads/2026/09/mixed-fail-150x150.jpg',
					'remote_status'       => ObjectRemoteStatus::PRESENT,
					'variant_type'        => 'size',
					'size_bytes'          => 10,
				),
			)
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturnCallback(
			static function ( string $key ): array {
				if ( str_contains( $key, '150x150' ) ) {
					return array(
						'exists'            => false,
						'confirmed_missing' => true,
					);
				}
				return array(
					'exists'     => false,
					'error'      => 'Access Denied',
					'error_code' => 'AccessDenied',
					'status_code'=> 403,
				);
			}
		);

		$result = $this->service( $gateway, $objects, $this->profiles( 3 ) )->rebuild_attachment( 39, 3, false );

		$this->assertSame( RebuildInventoryService::OUTCOME_PARTIAL, $result['outcome'] );
		$this->assertNotSame( AttachmentOffloader::STATUS_OFFLOADED, (string) get_post_meta( 39, '_s3ms_status', true ) );
	}

	public function test_uncertain_head_for_new_manifest_path_does_not_preserve_offloaded(): void {
		$this->seed_attachment_with_sizes(
			40,
			'2026/09/grew.jpg',
			array(
				'2026/09/grew.jpg'         => 40,
				'2026/09/grew-150x150.jpg' => 10,
			)
		);
		WpStubs::set_meta( 40, '_s3ms_status', AttachmentOffloader::STATUS_OFFLOADED );

		// Inventory only covers the original — thumbnail is a new manifest path.
		$objects = new RebuildInMemoryObjectRepository(
			array(
				array(
					'id'                  => 1,
					'attachment_id'       => 40,
					'storage_profile_id'  => 3,
					'local_relative_path' => '2026/09/grew.jpg',
					'object_key'          => 'uploads/2026/09/grew.jpg',
					'remote_status'       => ObjectRemoteStatus::PRESENT,
					'variant_type'        => 'original',
					'size_bytes'          => 40,
				),
			)
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturnCallback(
			static function ( string $key ): array {
				if ( str_contains( $key, '150x150' ) ) {
					return array(
						'exists'     => false,
						'error'      => 'Access Denied',
						'error_code' => 'AccessDenied',
						'status_code'=> 403,
					);
				}
				return array( 'exists' => true, 'content_length' => 40 );
			}
		);

		$result = $this->service( $gateway, $objects, $this->profiles( 3 ) )->rebuild_attachment( 40, 3, false );

		$this->assertSame( RebuildInventoryService::OUTCOME_PARTIAL, $result['outcome'] );
		$this->assertNotSame( AttachmentOffloader::STATUS_OFFLOADED, (string) get_post_meta( 40, '_s3ms_status', true ) );
	}

	public function test_skipped_stale_count_reports_superseded_rows(): void {
		$this->seed_attachment( 41, '2026/09/stale-count.jpg', 20 );
		$objects = new RebuildInMemoryObjectRepository(
			array(
				array(
					'id'                  => 1,
					'attachment_id'       => 41,
					'storage_profile_id'  => 3,
					'local_relative_path' => '2026/09/old.jpg',
					'object_key'          => 'uploads/2026/09/old.jpg',
					'remote_status'       => ObjectRemoteStatus::STALE,
					'variant_type'        => 'original',
				),
			)
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 20 ) );

		$result = $this->service( $gateway, $objects, $this->profiles( 3 ) )->rebuild_attachment( 41, 3, true );

		$this->assertSame( 1, $result['counts']['skipped_stale'] );
	}

	public function test_skipped_stale_count_includes_deleted_superseded_rows(): void {
		$this->seed_attachment( 42, '2026/09/deleted-count.jpg', 20 );
		$objects = new RebuildInMemoryObjectRepository(
			array(
				array(
					'id'                  => 1,
					'attachment_id'       => 42,
					'storage_profile_id'  => 3,
					'local_relative_path' => '2026/09/gone.jpg',
					'object_key'          => 'uploads/2026/09/gone.jpg',
					'remote_status'       => ObjectRemoteStatus::DELETED,
					'variant_type'        => 'original',
				),
				array(
					'id'                  => 2,
					'attachment_id'       => 42,
					'storage_profile_id'  => 3,
					'local_relative_path' => '2026/09/old.jpg',
					'object_key'          => 'uploads/2026/09/old.jpg',
					'remote_status'       => ObjectRemoteStatus::STALE,
					'variant_type'        => 'size',
				),
			)
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 20 ) );

		$result = $this->service( $gateway, $objects, $this->profiles( 3 ) )->rebuild_attachment( 42, 3, true );

		$this->assertSame( 2, $result['counts']['skipped_stale'] );
	}

	public function test_exists_only_adopt_does_not_increment_present_adopted(): void {
		$this->seed_attachment( 36, '2026/09/exists-adopt.jpg', null );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 9 ) );

		$result = $this->service( $gateway, new RebuildInMemoryObjectRepository(), $this->profiles( 3 ) )
			->rebuild_attachment( 36, 3, false );

		$this->assertSame( RebuildInventoryService::OUTCOME_COMPLETE, $result['outcome'] );
		$this->assertSame( 1, $result['counts']['exists_only'] );
		$this->assertSame( 0, $result['counts']['present_adopted'] );
		$this->assertSame( 1, $result['adopted'] );
	}

	public function test_no_such_bucket_head_is_invalid_request(): void {
		$this->seed_attachment( 37, '2026/09/bad-bucket.jpg', 10 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn(
			array(
				'exists'            => false,
				'confirmed_missing' => false,
				'error'             => 'The specified bucket does not exist',
				'error_code'        => 'NoSuchBucket',
				'status_code'       => 404,
			)
		);

		$result = $this->service( $gateway, new RebuildInMemoryObjectRepository(), $this->profiles( 3 ) )
			->rebuild_attachment( 37, 3, true );

		$this->assertSame( RebuildInventoryService::CLASS_INVALID_REQUEST, $result['results'][0]['class'] );
		$this->assertSame( 1, $result['counts']['invalid_request'] );
	}

	public function test_inventory_size_mismatch_is_size_conflict_without_overwrite_on_apply(): void {
		$this->seed_attachment( 12, '2026/09/c.jpg', null );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 1 ) );

		$row = array(
			'id'                  => 91,
			'attachment_id'       => 12,
			'storage_profile_id'  => 3,
			'local_relative_path' => '2026/09/c.jpg',
			'object_key'          => 'uploads/2026/09/c.jpg',
			'remote_status'       => ObjectRemoteStatus::PRESENT,
			'size_bytes'          => 999,
			'verified_at'         => '2026-01-01 00:00:00',
			'variant_type'        => 'original',
		);
		$objects = new RebuildInMemoryObjectRepository( array( $row ) );

		$result = $this->service( $gateway, $objects, $this->profiles( 3 ) )->rebuild_attachment( 12, null, false );

		$this->assertFalse( $result['success'] );
		$this->assertSame( RebuildInventoryService::OUTCOME_FAILED, $result['outcome'] );
		$this->assertSame( 1, $result['counts']['conflicts'] );
		$this->assertSame( 'SIZE_CONFLICT', $result['results'][0]['class'] );
		$updated = $objects->find_by_profile_and_key( 3, 'uploads/2026/09/c.jpg' );
		$this->assertSame( 999, (int) $updated['size_bytes'] );
		$this->assertSame( ObjectRemoteStatus::FAILED, (string) $updated['remote_status'] );
		$this->assertSame( 'size_conflict', (string) $updated['last_error_code'] );
		$this->assertEmpty( $updated['verified_at'] ?? null );
		$this->assertSame( AttachmentOffloader::STATUS_FAILED, (string) get_post_meta( 12, '_s3ms_status', true ) );
		// Reconciler-style rollup must not promote back to offloaded from the failed row.
		$this->assertSame( AttachmentOffloader::STATUS_FAILED, AttachmentSyncDeriver::derive_status( array( $updated ) ) );
	}

	public function test_surviving_profile_used_not_site_default(): void {
		$this->seed_attachment( 13, '2026/09/d.jpg', 40 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->once() )
			->method( 'head_key' )
			->with( 'other/2026/09/d.jpg' )
			->willReturn( array( 'exists' => true, 'content_length' => 40 ) );

		$row = array(
			'id'                  => 92,
			'attachment_id'       => 13,
			'storage_profile_id'  => 9,
			'local_relative_path' => '2026/09/d.jpg',
			'object_key'          => 'other/2026/09/d.jpg',
			'remote_status'       => ObjectRemoteStatus::PRESENT,
			'size_bytes'          => 40,
			'variant_type'        => 'original',
		);
		$objects = new RebuildInMemoryObjectRepository( array( $row ) );

		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->willReturnCallback(
			function ( int $id ): ?StorageProfile {
				return $this->profile( $id, $id === 9 ? 'other/' : 'uploads/' );
			}
		);
		$profiles->expects( $this->never() )->method( 'find_default_upload_target' );

		$result = $this->service( $gateway, $objects, $profiles )->rebuild_attachment( 13, null, true );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 9, $result['profile_id'] );
	}

	public function test_only_superseded_bindings_require_explicit_profile(): void {
		$this->seed_attachment( 130, '2026/09/superseded-only.jpg', 40 );

		$objects = new RebuildInMemoryObjectRepository(
			array(
				array(
					'id'                  => 501,
					'attachment_id'       => 130,
					'storage_profile_id'  => 9,
					'local_relative_path' => '2026/09/superseded-only.jpg',
					'object_key'          => 'other/2026/09/superseded-only.jpg',
					'remote_status'       => ObjectRemoteStatus::STALE,
					'size_bytes'          => 40,
					'variant_type'        => 'original',
				),
			)
		);

		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->willReturnCallback(
			fn ( int $id ): StorageProfile => $this->profile( $id, $id === 9 ? 'other/' : 'uploads/' )
		);
		$profiles->expects( $this->never() )->method( 'find_default_upload_target' );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );

		$ambiguous = $this->service( $gateway, $objects, $profiles )->rebuild_attachment( 130, null, true );
		$this->assertFalse( $ambiguous['success'] );
		$this->assertSame( 'ambiguous_profile', $ambiguous['reason'] );
		$this->assertSame( 1, $ambiguous['counts']['skipped_stale'] );
		$this->assertStringContainsString( 'superseded', strtolower( (string) $ambiguous['message'] ) );

		$gateway2 = $this->createMock( ProfileStorageGateway::class );
		$gateway2->expects( $this->once() )
			->method( 'head_key' )
			->with( 'other/2026/09/superseded-only.jpg' )
			->willReturn( array( 'exists' => true, 'content_length' => 40 ) );

		$explicit = $this->service( $gateway2, $objects, $profiles )->rebuild_attachment( 130, 9, true );
		$this->assertTrue( $explicit['success'] );
		$this->assertSame( 9, $explicit['profile_id'] );
	}

	public function test_ambiguous_surviving_profiles_excluded(): void {
		$this->seed_attachment( 14, '2026/09/e.jpg', 10 );

		$objects = new RebuildInMemoryObjectRepository(
			array(
				array(
					'id'                  => 1,
					'attachment_id'       => 14,
					'storage_profile_id'  => 3,
					'local_relative_path' => '2026/09/e.jpg',
					'object_key'          => 'a',
					'remote_status'       => ObjectRemoteStatus::PRESENT,
					'variant_type'        => 'original',
				),
				array(
					'id'                  => 2,
					'attachment_id'       => 14,
					'storage_profile_id'  => 9,
					'local_relative_path' => '2026/09/thumb.jpg',
					'object_key'          => 'b',
					'remote_status'       => ObjectRemoteStatus::PRESENT,
					'variant_type'        => 'size',
				),
			)
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );

		$result = $this->service( $gateway, $objects, $this->profiles( 3 ) )->rebuild_attachment( 14, null, true );

		$this->assertFalse( $result['success'] );
		$this->assertSame( RebuildInventoryService::OUTCOME_FAILED, $result['outcome'] );
		$this->assertSame( 'ambiguous_profile', $result['reason'] );
		$this->assertSame( 1, $result['counts']['ambiguous_profile'] );
	}

	public function test_confirmed_missing_reported_not_persisted_on_apply_by_default(): void {
		$this->seed_attachment( 15, '2026/09/f.jpg', 20 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn(
			array(
				'exists'            => false,
				'confirmed_missing' => true,
			)
		);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->willReturn( array() );
		$objects->method( 'find_by_profile_and_key' )->willReturn( null );
		$objects->expects( $this->never() )->method( 'upsert' );
		$objects->expects( $this->never() )->method( 'insert_owned_or_conflict' );

		$result = $this->service( $gateway, $objects, $this->profiles( 3 ) )->rebuild_attachment( 15, 3, false );

		$this->assertTrue( $result['success'] );
		$this->assertSame( RebuildInventoryService::OUTCOME_COMPLETE, $result['outcome'] );
		$this->assertSame( 1, $result['counts']['confirmed_missing'] );
		$this->assertSame( 'CONFIRMED_MISSING', $result['results'][0]['class'] );
	}

	public function test_confirmed_missing_updates_existing_present_inventory_on_apply(): void {
		$this->seed_attachment( 150, '2026/09/was-present.jpg', null );
		WpStubs::set_meta( 150, '_s3ms_status', AttachmentOffloader::STATUS_OFFLOADED );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn(
			array(
				'exists'            => false,
				'confirmed_missing' => true,
			)
		);

		$objects = new RebuildInMemoryObjectRepository(
			array(
				array(
					'id'                  => 77,
					'attachment_id'       => 150,
					'storage_profile_id'  => 3,
					'local_relative_path' => '2026/09/was-present.jpg',
					'object_key'          => 'uploads/2026/09/was-present.jpg',
					'remote_status'       => ObjectRemoteStatus::PRESENT,
					'size_bytes'          => 88,
					'verified_at'         => '2026-01-01 00:00:00',
					'variant_type'        => 'original',
				),
			)
		);

		$stats = $this->createMock( ObjectStatsAggregator::class );
		$stats->expects( $this->once() )->method( 'invalidate' );

		$result = $this->service( $gateway, $objects, $this->profiles( 3 ), null, null, $stats )
			->rebuild_attachment( 150, 3, false );

		$this->assertTrue( $result['success'] );
		$this->assertSame( RebuildInventoryService::OUTCOME_COMPLETE, $result['outcome'] );
		$this->assertSame( 1, $result['counts']['confirmed_missing'] );
		$this->assertTrue( ! empty( $result['results'][0]['inventory_updated'] ) );

		$updated = $objects->find_by_profile_and_key( 3, 'uploads/2026/09/was-present.jpg' );
		$this->assertSame( ObjectRemoteStatus::MISSING, (string) $updated['remote_status'] );
		$this->assertSame( 88, (int) $updated['size_bytes'] );
		$this->assertSame( 'confirmed_missing', (string) $updated['last_error_code'] );
		$this->assertEmpty( $updated['verified_at'] ?? null );
		$this->assertSame( AttachmentOffloader::STATUS_FAILED, (string) get_post_meta( 150, '_s3ms_status', true ) );
		$this->assertSame( AttachmentOffloader::STATUS_FAILED, AttachmentSyncDeriver::derive_status( array( $updated ) ) );
	}

	public function test_lease_loss_after_definitive_negative_still_syncs_non_offloaded_meta(): void {
		$this->seed_attachment( 151, '2026/09/lease-after-neg.jpg', null );
		WpStubs::set_meta( 151, '_s3ms_status', AttachmentOffloader::STATUS_OFFLOADED );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn(
			array(
				'exists'            => false,
				'confirmed_missing' => true,
			)
		);

		$objects = new RebuildInMemoryObjectRepository(
			array(
				array(
					'id'                  => 78,
					'attachment_id'       => 151,
					'storage_profile_id'  => 3,
					'local_relative_path' => '2026/09/lease-after-neg.jpg',
					'object_key'          => 'uploads/2026/09/lease-after-neg.jpg',
					'remote_status'       => ObjectRemoteStatus::PRESENT,
					'size_bytes'          => 40,
					'verified_at'         => '2026-01-01 00:00:00',
					'variant_type'        => 'original',
				),
			)
		);

		$lease = new \Kazcode\WpStorage\Infrastructure\AttachmentLeaseHandle( 151, 'tok', 1, 'rebuild', time() + 300 );
		$lock  = $this->createMock( AttachmentLock::class );
		$lock->method( 'acquire_lease' )->willReturn( $lease );
		// Pre-write renew succeeds; post-loop renew (before meta sync) fails.
		$lock->method( 'renew' )->willReturnOnConsecutiveCalls( true, false );
		$lock->expects( $this->once() )->method( 'release_lease' )->with( $lease );

		$stats = $this->createMock( ObjectStatsAggregator::class );
		$stats->expects( $this->once() )->method( 'invalidate' );

		$result = $this->service( $gateway, $objects, $this->profiles( 3 ), $lock, null, $stats )
			->rebuild_attachment( 151, 3, false );

		$this->assertFalse( $result['success'] );
		$this->assertSame( RebuildInventoryService::OUTCOME_FAILED, $result['outcome'] );
		$this->assertSame( RebuildInventoryService::CLASS_LOCK_CONFLICT, $result['reason'] );
		$this->assertSame( ObjectRemoteStatus::MISSING, (string) $objects->find_by_profile_and_key( 3, 'uploads/2026/09/lease-after-neg.jpg' )['remote_status'] );
		$status = (string) get_post_meta( 151, '_s3ms_status', true );
		$this->assertNotSame( AttachmentOffloader::STATUS_OFFLOADED, $status );
		$this->assertContains( $status, array( AttachmentOffloader::STATUS_FAILED, AttachmentOffloader::STATUS_PARTIAL ) );
	}

	public function test_cross_attachment_missing_row_blocks_apply(): void {
		$this->seed_attachment( 16, '2026/09/g.jpg', 30 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 30 ) );

		$objects = new RebuildInMemoryObjectRepository(
			array(
				array(
					'id'                  => 50,
					'attachment_id'       => 999,
					'storage_profile_id'  => 3,
					'local_relative_path' => 'other.jpg',
					'object_key'          => 'uploads/2026/09/g.jpg',
					'remote_status'       => ObjectRemoteStatus::MISSING,
					'size_bytes'          => 30,
					'variant_type'        => 'original',
				),
			)
		);

		$result = $this->service( $gateway, $objects, $this->profiles( 3 ) )->rebuild_attachment( 16, 3, false );

		$this->assertFalse( $result['success'] );
		$this->assertSame( RebuildInventoryService::OUTCOME_FAILED, $result['outcome'] );
		$this->assertSame( 1, $result['counts']['key_owned_by_other_attachment'] );
		$this->assertSame( 'key_owned_by_other_attachment', $result['results'][0]['class'] );
		$this->assertSame( 999, (int) $objects->find_by_profile_and_key( 3, 'uploads/2026/09/g.jpg' )['attachment_id'] );
	}

	public function test_retained_importer_exact_key_used_when_inventory_row_lost(): void {
		$this->seed_attachment( 20, '2026/09/import.jpg', 50 );
		$importer = new RebuildExactKeyFakeImporter(
			array(
				new ImportedObjectDescriptor(
					20,
					'2026/09/import.jpg',
					'original',
					'custom-prefix/2026/09/1693560000/import.jpg',
					50,
					'image/jpeg',
					'advmo:bucket:import'
				),
			)
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->once() )
			->method( 'head_key' )
			->with( 'custom-prefix/2026/09/1693560000/import.jpg' )
			->willReturn( array( 'exists' => true, 'content_length' => 50 ) );

		$objects = new RebuildInMemoryObjectRepository();
		$result  = $this->service(
			$gateway,
			$objects,
			$this->profiles( 3 ),
			null,
			new ImportRegistry( array( $importer ) )
		)->rebuild_attachment( 20, 3, true );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'PRESENT', $result['results'][0]['class'] );
		$this->assertSame( 'custom-prefix/2026/09/1693560000/import.jpg', $result['results'][0]['key'] );
	}

	public function test_direct_upload_recovery_evidence_restores_a_lost_inventory_binding_by_its_exact_key(): void {
		$this->seed_attachment( 202, '2026/10/archive.zip', 50 );
		update_post_meta( 202, '_s3ms_direct_upload_location', wp_json_encode( array(
			'version'       => 2,
			'profile_id'    => 3,
			'object_key'    => 'private/direct-upload/session/attempt/archive.zip',
			'relative_path' => '2026/10/archive.zip',
			'size_bytes'    => 50,
			'mime_type'     => 'application/zip',
			'location_fingerprint' => $this->direct_upload_location_fingerprint( $this->profile( 3 ) ),
		) ) );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->once() )
			->method( 'head_key' )
			->with( 'private/direct-upload/session/attempt/archive.zip' )
			->willReturn( array( 'exists' => true, 'content_length' => 50 ) );

		$objects = new RebuildInMemoryObjectRepository();
		$result = $this->service( $gateway, $objects, $this->profiles( 3 ) )
			->rebuild_attachment( 202, null, false );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 3, $result['profile_id'] );
		$this->assertSame( 'private/direct-upload/session/attempt/archive.zip', $result['results'][0]['key'] );
		$this->assertSame( 'application/zip', (string) $objects->find_by_profile_and_key( 3, 'private/direct-upload/session/attempt/archive.zip' )['mime_type'] );
	}

	public function test_single_surviving_inventory_profile_that_disagrees_with_direct_upload_profile_fails_closed(): void {
		$this->seed_attachment( 205, '2026/10/archive.zip', 50 );
		update_post_meta( 205, '_s3ms_direct_upload_location', wp_json_encode( array(
			'version'              => 2,
			'profile_id'           => 9,
			'object_key'           => 'private/direct-upload/session/attempt/archive.zip',
			'relative_path'        => '2026/10/archive.zip',
			'size_bytes'           => 50,
			'mime_type'            => 'application/zip',
			'location_fingerprint' => $this->direct_upload_location_fingerprint( $this->profile( 9, 'other/' ) ),
		) ) );
		$objects = new RebuildInMemoryObjectRepository( array(
			array(
				'id'                  => 205,
				'attachment_id'       => 205,
				'storage_profile_id'  => 3,
				'local_relative_path' => '2026/10/archive.zip',
				'object_key'          => 'uploads/2026/10/archive.zip',
				'remote_status'       => ObjectRemoteStatus::PRESENT,
				'size_bytes'          => 50,
				'variant_type'        => 'original',
			),
		) );
		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );

		$result = $this->service( $gateway, $objects, $this->profiles( 3 ) )
			->rebuild_attachment( 205, null, true );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'direct_upload_location_mismatch', $result['reason'] );
		$this->assertSame( 1, $result['counts']['direct_upload_location_mismatch'] );
	}

	public function test_single_surviving_inventory_profile_with_changed_direct_upload_fingerprint_fails_closed(): void {
		$this->seed_attachment( 206, '2026/10/archive.zip', 50 );
		$original = $this->profile( 3 );
		update_post_meta( 206, '_s3ms_direct_upload_location', wp_json_encode( array(
			'version'              => 2,
			'profile_id'           => 3,
			'object_key'           => 'private/direct-upload/session/attempt/archive.zip',
			'relative_path'        => '2026/10/archive.zip',
			'size_bytes'           => 50,
			'mime_type'            => 'application/zip',
			'location_fingerprint' => $this->direct_upload_location_fingerprint( $original ),
		) ) );
		$objects = new RebuildInMemoryObjectRepository( array(
			array(
				'id'                  => 206,
				'attachment_id'       => 206,
				'storage_profile_id'  => 3,
				'local_relative_path' => '2026/10/archive.zip',
				'object_key'          => 'uploads/2026/10/archive.zip',
				'remote_status'       => ObjectRemoteStatus::PRESENT,
				'size_bytes'          => 50,
				'variant_type'        => 'original',
			),
		) );
		$changed = $original->with( array( 'bucket' => 'replacement-bucket' ) );
		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->with( 3 )->willReturn( $changed );
		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );

		$result = $this->service( $gateway, $objects, $profiles )
			->rebuild_attachment( 206, null, true );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'direct_upload_location_mismatch', $result['reason'] );
		$this->assertSame( 1, $result['counts']['direct_upload_location_mismatch'] );
	}

	public function test_direct_upload_recovery_evidence_rejects_a_profile_whose_location_changed(): void {
		$this->seed_attachment( 203, '2026/10/archive.zip', null );
		$original = $this->profile( 3 );
		update_post_meta( 203, '_s3ms_direct_upload_location', wp_json_encode( array(
			'version'              => 2,
			'profile_id'           => 3,
			'object_key'           => 'private/direct-upload/session/attempt/archive.zip',
			'relative_path'        => '2026/10/archive.zip',
			'size_bytes'           => 50,
			'mime_type'            => 'application/zip',
			'location_fingerprint' => $this->direct_upload_location_fingerprint( $original ),
		) ) );

		$changed = $original->with( array( 'bucket' => 'replacement-bucket' ) );
		$this->assertSame( 'replacement-bucket', $changed->bucket );
		$this->assertNotSame( $this->direct_upload_location_fingerprint( $original ), $this->direct_upload_location_fingerprint( $changed ) );
		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->willReturn( $changed );
		$profiles->method( 'find_default_upload_target' )->willReturn( $changed );
		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );

		$result = $this->service( $gateway, new RebuildInMemoryObjectRepository(), $profiles )
			->rebuild_attachment( 203, 3, true );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'direct_upload_location_mismatch', $result['reason'] );
	}

	public function test_direct_upload_recovery_evidence_size_mismatch_is_a_conflict_when_the_inventory_row_is_lost(): void {
		$this->seed_attachment( 204, '2026/10/archive.zip', null );
		update_post_meta( 204, '_s3ms_direct_upload_location', wp_json_encode( array(
			'version'              => 2,
			'profile_id'           => 3,
			'object_key'           => 'private/direct-upload/session/attempt/archive.zip',
			'relative_path'        => '2026/10/archive.zip',
			'size_bytes'           => 50,
			'mime_type'            => 'application/zip',
			'location_fingerprint' => $this->direct_upload_location_fingerprint( $this->profile( 3 ) ),
		) ) );
		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->once() )
			->method( 'head_key' )
			->with( 'private/direct-upload/session/attempt/archive.zip' )
			->willReturn( array( 'exists' => true, 'content_length' => 51 ) );

		$result = $this->service( $gateway, new RebuildInMemoryObjectRepository(), $this->profiles( 3 ) )
			->rebuild_attachment( 204, 3, true );

		$this->assertFalse( $result['success'] );
		$this->assertSame( RebuildInventoryService::CLASS_SIZE_CONFLICT, $result['results'][0]['class'] );
		$this->assertSame( 50, $result['results'][0]['expected_size'] );
	}

	public function test_incomplete_importer_evidence_is_ambiguous_key_not_synthesized(): void {
		$this->seed_attachment( 21, '2026/09/only-original.jpg', 40 );
		// Importer proves custom keys exist, but not for this relative (thumb only).
		$importer = new RebuildExactKeyFakeImporter(
			array(
				new ImportedObjectDescriptor(
					21,
					'2026/09/other-size.jpg',
					'thumbnail',
					'custom-prefix/other-size.jpg',
					40,
					'image/jpeg',
					'advmo:bucket:partial'
				),
			)
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );

		$objects = new RebuildInMemoryObjectRepository();
		$result  = $this->service(
			$gateway,
			$objects,
			$this->profiles( 3 ),
			null,
			new ImportRegistry( array( $importer ) )
		)->rebuild_attachment( 21, 3, true );

		$this->assertFalse( $result['success'] );
		$this->assertSame( RebuildInventoryService::OUTCOME_FAILED, $result['outcome'] );
		$this->assertSame( 1, $result['counts']['ambiguous_key'] );
		$this->assertSame( 'ambiguous_key', $result['results'][0]['class'] );
	}

	public function test_apply_lock_contention_skips_mutation(): void {
		$this->seed_attachment( 22, '2026/09/locked.jpg', 10 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->never() )->method( 'insert_owned_or_conflict' );
		$objects->expects( $this->never() )->method( 'upsert' );

		$lock = $this->createMock( AttachmentLock::class );
		$lock->method( 'acquire_lease' )->willReturn( null );
		$lock->expects( $this->never() )->method( 'release_lease' );

		$result = $this->service( $gateway, $objects, $this->profiles( 3 ), $lock )->rebuild_attachment( 22, 3, false );

		$this->assertFalse( $result['success'] );
		$this->assertSame( RebuildInventoryService::OUTCOME_FAILED, $result['outcome'] );
		$this->assertSame( 1, $result['counts']['lock_conflict'] );
		$this->assertSame( 'lock_conflict', $result['results'][0]['class'] );
	}

	public function test_apply_refreshes_failed_attachment_cache_after_complete_rebuild(): void {
		$this->seed_attachment( 23, '2026/09/cache.jpg', 77 );
		WpStubs::set_meta( 23, '_s3ms_status', AttachmentOffloader::STATUS_FAILED );
		WpStubs::set_meta( 23, '_s3ms_original_key', '' );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 77 ) );

		$objects = new RebuildInMemoryObjectRepository();
		$result  = $this->service( $gateway, $objects, $this->profiles( 3 ) )->rebuild_attachment( 23, 3, false );

		$this->assertTrue( $result['success'] );
		$this->assertSame( AttachmentOffloader::STATUS_OFFLOADED, (string) get_post_meta( 23, '_s3ms_status', true ) );
		$this->assertSame( 'uploads/2026/09/cache.jpg', (string) get_post_meta( 23, '_s3ms_original_key', true ) );
	}

	public function test_partial_rebuild_does_not_mark_attachment_offloaded(): void {
		$this->seed_attachment_with_sizes(
			26,
			'2026/09/partial.jpg',
			array(
				'2026/09/partial.jpg'         => 20,
				'2026/09/partial-150x150.jpg' => 5,
			)
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturnCallback(
			static function ( string $key ): array {
				if ( str_contains( $key, '150x150' ) ) {
					return array(
						'exists'            => false,
						'confirmed_missing' => true,
					);
				}
				return array( 'exists' => true, 'content_length' => 20 );
			}
		);

		$objects = new RebuildInMemoryObjectRepository();
		$result  = $this->service( $gateway, $objects, $this->profiles( 3 ) )->rebuild_attachment( 26, 3, false );

		$this->assertSame( RebuildInventoryService::OUTCOME_COMPLETE, $result['outcome'] );
		$this->assertSame( AttachmentOffloader::STATUS_PARTIAL, (string) get_post_meta( 26, '_s3ms_status', true ) );
		$this->assertNotSame( AttachmentOffloader::STATUS_OFFLOADED, (string) get_post_meta( 26, '_s3ms_status', true ) );
	}

	public function test_importer_keys_from_other_bucket_are_ignored_for_selected_profile(): void {
		$this->seed_attachment( 27, '2026/09/moved.jpg', 30 );
		$importer = new RebuildExactKeyFakeImporter(
			array(
				new ImportedObjectDescriptor(
					27,
					'2026/09/moved.jpg',
					'original',
					'old-bucket-prefix/moved.jpg',
					30,
					'image/jpeg',
					'advmo:old:old-bucket'
				),
			),
			'old-bucket'
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );

		$objects = new RebuildInMemoryObjectRepository();
		$result  = $this->service(
			$gateway,
			$objects,
			$this->profiles( 3 ),
			null,
			new ImportRegistry( array( $importer ) )
		)->rebuild_attachment( 27, 3, true );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'ambiguous_key', $result['results'][0]['class'] );
	}

	public function test_apply_renews_lease_before_write_and_fails_closed_when_lost(): void {
		$this->seed_attachment( 28, '2026/09/renew.jpg', 10 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 10 ) );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->willReturn( array() );
		$objects->method( 'find_by_profile_and_key' )->willReturn( null );
		$objects->expects( $this->never() )->method( 'insert_owned_or_conflict' );

		$lease = new \Kazcode\WpStorage\Infrastructure\AttachmentLeaseHandle( 28, 'tok', 1, 'rebuild', time() + 300 );
		$lock  = $this->createMock( AttachmentLock::class );
		$lock->method( 'acquire_lease' )->willReturn( $lease );
		$lock->method( 'renew' )->willReturn( false );
		$lock->expects( $this->once() )->method( 'release_lease' )->with( $lease );

		$result = $this->service( $gateway, $objects, $this->profiles( 3 ), $lock )->rebuild_attachment( 28, 3, false );

		$this->assertFalse( $result['success'] );
		$this->assertSame( RebuildInventoryService::OUTCOME_FAILED, $result['outcome'] );
		$this->assertGreaterThan( 0, $result['counts']['lock_conflict'] );
	}

	public function test_size_conflict_on_existing_present_row_does_not_refresh_offloaded_meta(): void {
		$this->seed_attachment( 29, '2026/09/conflict.jpg', null );
		WpStubs::set_meta( 29, '_s3ms_status', AttachmentOffloader::STATUS_FAILED );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 1 ) );

		$objects = new RebuildInMemoryObjectRepository(
			array(
				array(
					'id'                  => 1,
					'attachment_id'       => 29,
					'storage_profile_id'  => 3,
					'local_relative_path' => '2026/09/conflict.jpg',
					'object_key'          => 'uploads/2026/09/conflict.jpg',
					'remote_status'       => ObjectRemoteStatus::PRESENT,
					'size_bytes'          => 999,
					'variant_type'        => 'original',
				),
			)
		);

		$result = $this->service( $gateway, $objects, $this->profiles( 3 ) )->rebuild_attachment( 29, 3, false );

		$this->assertSame( 'SIZE_CONFLICT', $result['results'][0]['class'] );
		$this->assertSame( AttachmentOffloader::STATUS_FAILED, (string) get_post_meta( 29, '_s3ms_status', true ) );
		$this->assertNotSame( AttachmentOffloader::STATUS_OFFLOADED, (string) get_post_meta( 29, '_s3ms_status', true ) );
	}

	public function test_missing_attachment_is_failed_not_complete(): void {
		$gateway = $this->createMock( ProfileStorageGateway::class );
		$objects = new RebuildInMemoryObjectRepository();
		$result  = $this->service( $gateway, $objects, $this->profiles( 3 ) )->rebuild_attachment( 404404, 3, true );

		$this->assertFalse( $result['success'] );
		$this->assertSame( RebuildInventoryService::OUTCOME_FAILED, $result['outcome'] );
		$this->assertSame( 'Attachment not found.', $result['message'] );
	}

	public function test_idempotent_apply_counts_already_tracked_not_adopted(): void {
		$this->seed_attachment( 30, '2026/09/tracked.jpg', 40 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 40 ) );

		$objects = new RebuildInMemoryObjectRepository(
			array(
				array(
					'id'                  => 7,
					'attachment_id'       => 30,
					'storage_profile_id'  => 3,
					'local_relative_path' => '2026/09/tracked.jpg',
					'object_key'          => 'uploads/2026/09/tracked.jpg',
					'remote_status'       => ObjectRemoteStatus::PRESENT,
					'size_bytes'          => 40,
					'variant_type'        => 'original',
				),
			)
		);

		$result = $this->service( $gateway, $objects, $this->profiles( 3 ) )->rebuild_attachment( 30, 3, false );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['counts']['already_tracked'] );
		$this->assertSame( 0, $result['counts']['present_adopted'] );
		$this->assertSame( 0, $result['adopted'] );
	}

	public function test_importer_same_bucket_different_endpoint_is_ignored(): void {
		$this->seed_attachment( 31, '2026/09/ep.jpg', 12 );
		$importer = new RebuildExactKeyFakeImporter(
			array(
				new ImportedObjectDescriptor(
					31,
					'2026/09/ep.jpg',
					'original',
					'custom/ep.jpg',
					12,
					'image/jpeg',
					'wpom:bucket:ep'
				),
			),
			'bucket',
			'https://old.minio.example'
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );

		$result = $this->service(
			$gateway,
			new RebuildInMemoryObjectRepository(),
			$this->profiles( 3 ),
			null,
			new ImportRegistry( array( $importer ) )
		)->rebuild_attachment( 31, 3, true );

		$this->assertSame( 'ambiguous_key', $result['results'][0]['class'] );
	}



	public function test_endpointless_fingerprint_rejected_for_empty_endpoint_profile(): void {
		$this->seed_attachment( 45, '2026/09/classic-aws.jpg', 40 );
		$importer = new RebuildExactKeyFakeImporter(
			array(
				new ImportedObjectDescriptor(
					45,
					'2026/09/classic-aws.jpg',
					'original',
					'custom/from-old-minio.jpg',
					40,
					'image/jpeg',
					'wp-offload-media:aws:bucket:us-east-1'
				),
			),
			'bucket'
			// detect endpoint empty — matches classic AWS profile, but fingerprint lacks historical endpoint
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );

		$result = $this->service(
			$gateway,
			new RebuildInMemoryObjectRepository(),
			$this->profiles( 3 ),
			null,
			new ImportRegistry( array( $importer ) )
		)->rebuild_attachment( 45, 3, true );

		$this->assertSame( 'ambiguous_key', $result['results'][0]['class'] );
	}

	public function test_endpoint_less_fingerprint_rejected_when_profile_has_custom_endpoint(): void {
		$this->seed_attachment( 41, '2026/09/endpoint-migrate.jpg', 40 );
		$importer = new RebuildExactKeyFakeImporter(
			array(
				new ImportedObjectDescriptor(
					41,
					'2026/09/endpoint-migrate.jpg',
					'original',
					'custom/old-endpoint.jpg',
					40,
					'image/jpeg',
					'wp-offload-media:aws:bucket:us-east-1'
				),
			),
			'bucket',
			'http://minio-b.example:9000' // current detect matches profile B
		);

		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->willReturnCallback(
			fn ( int $id ): StorageProfile => $this->profile( $id, 'uploads/', 'http://minio-b.example:9000' )
		);
		$profiles->method( 'find_default_upload_target' )->willReturn(
			$this->profile( 3, 'uploads/', 'http://minio-b.example:9000' )
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );

		$result = $this->service(
			$gateway,
			new RebuildInMemoryObjectRepository(),
			$profiles,
			null,
			new ImportRegistry( array( $importer ) )
		)->rebuild_attachment( 41, 3, true );

		$this->assertSame( 'ambiguous_key', $result['results'][0]['class'] );
	}


	public function test_fingerprint_endpoint_mismatch_is_rejected(): void {
		$this->seed_attachment( 44, '2026/09/endpoint-mismatch.jpg', 40 );
		$importer = new RebuildExactKeyFakeImporter(
			array(
				new ImportedObjectDescriptor(
					44,
					'2026/09/endpoint-mismatch.jpg',
					'original',
					'custom/endpoint-a.jpg',
					40,
					'image/jpeg',
					'wp-offload-media:aws:bucket:us-east-1:http://minio-a.example:9000'
				),
			),
			'bucket',
			'http://minio-b.example:9000'
		);

		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->willReturnCallback(
			fn ( int $id ): StorageProfile => $this->profile( $id, 'uploads/', 'http://minio-b.example:9000' )
		);
		$profiles->method( 'find_default_upload_target' )->willReturn(
			$this->profile( 3, 'uploads/', 'http://minio-b.example:9000' )
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );

		$result = $this->service(
			$gateway,
			new RebuildInMemoryObjectRepository(),
			$profiles,
			null,
			new ImportRegistry( array( $importer ) )
		)->rebuild_attachment( 44, 3, true );

		$this->assertSame( 'ambiguous_key', $result['results'][0]['class'] );
	}

	public function test_fingerprint_with_matching_endpoint_is_accepted_for_custom_endpoint_profile(): void {
		$this->seed_attachment( 42, '2026/09/endpoint-ok.jpg', 40 );
		$importer = new RebuildExactKeyFakeImporter(
			array(
				new ImportedObjectDescriptor(
					42,
					'2026/09/endpoint-ok.jpg',
					'original',
					'custom/proven-endpoint.jpg',
					40,
					'image/jpeg',
					'wp-offload-media:aws:bucket:us-east-1:http://minio-b.example:9000'
				),
			),
			'bucket',
			'http://minio-b.example:9000'
		);

		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->willReturnCallback(
			fn ( int $id ): StorageProfile => $this->profile( $id, 'uploads/', 'http://minio-b.example:9000' )
		);
		$profiles->method( 'find_default_upload_target' )->willReturn(
			$this->profile( 3, 'uploads/', 'http://minio-b.example:9000' )
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->once() )
			->method( 'head_key' )
			->with( 'custom/proven-endpoint.jpg' )
			->willReturn( array( 'exists' => true, 'content_length' => 40 ) );

		$result = $this->service(
			$gateway,
			new RebuildInMemoryObjectRepository(),
			$profiles,
			null,
			new ImportRegistry( array( $importer ) )
		)->rebuild_attachment( 42, 3, true );

		$this->assertSame( RebuildInventoryService::CLASS_PRESENT, $result['results'][0]['class'] );
	}

	public function test_retained_importer_source_size_size_verifies_remote_only_rebuild(): void {
		// No local file size and no inventory size_bytes — only importer source_size.
		$this->seed_attachment( 48, '2026/09/retained-size.jpg', null );
		$importer = new RebuildExactKeyFakeImporter(
			array(
				new ImportedObjectDescriptor(
					48,
					'2026/09/retained-size.jpg',
					'original',
					'custom/retained-size.jpg',
					77,
					'image/jpeg',
					'wp-offload-media:aws:bucket:us-east-1:-'
				),
			),
			'bucket',
			''
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->once() )
			->method( 'head_key' )
			->with( 'custom/retained-size.jpg' )
			->willReturn( array( 'exists' => true, 'content_length' => 77 ) );

		$result = $this->service(
			$gateway,
			new RebuildInMemoryObjectRepository(),
			$this->profiles( 3 ),
			null,
			new ImportRegistry( array( $importer ) )
		)->rebuild_attachment( 48, 3, false );

		$this->assertSame( RebuildInventoryService::OUTCOME_COMPLETE, $result['outcome'] );
		$this->assertSame( 1, $result['counts']['present'] );
		$this->assertSame( 0, $result['counts']['exists_only'] );
		$this->assertSame( RebuildInventoryService::CLASS_PRESENT, $result['results'][0]['class'] ?? null );
		$this->assertSame( 77, $result['results'][0]['expected_size'] ?? null );
	}

	public function test_mid_scan_positive_write_does_not_downgrade_offloaded_meta(): void {
		$this->seed_attachment_with_sizes(
			43,
			'2026/09/multi.jpg',
			array(
				'2026/09/multi.jpg'         => 20,
				'2026/09/multi-150x150.jpg' => 5,
			)
		);
		WpStubs::set_meta( 43, '_s3ms_status', AttachmentOffloader::STATUS_OFFLOADED );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturnCallback(
			static function ( string $key ): array {
				if ( str_contains( $key, '150x150' ) ) {
					return array( 'exists' => true, 'content_length' => 5 );
				}
				return array( 'exists' => true, 'content_length' => 20 );
			}
		);

		$objects = new RebuildInMemoryObjectRepository(
			array(
				array(
					'id'                  => 1,
					'attachment_id'       => 43,
					'storage_profile_id'  => 3,
					'local_relative_path' => '2026/09/multi.jpg',
					'object_key'          => 'uploads/2026/09/multi.jpg',
					'remote_status'       => ObjectRemoteStatus::PRESENT,
					'size_bytes'          => 20,
					'verified_at'         => '2026-01-01 00:00:00',
					'variant_type'        => 'original',
				),
				array(
					'id'                  => 2,
					'attachment_id'       => 43,
					'storage_profile_id'  => 3,
					'local_relative_path' => '2026/09/multi-150x150.jpg',
					'object_key'          => 'uploads/2026/09/multi-150x150.jpg',
					'remote_status'       => ObjectRemoteStatus::PRESENT,
					'size_bytes'          => 5,
					'verified_at'         => '2026-01-01 00:00:00',
					'variant_type'        => 'size',
				),
			)
		);

		$lease = new \Kazcode\WpStorage\Infrastructure\AttachmentLeaseHandle( 43, 'tok', 1, 'rebuild', time() + 300 );
		$lock  = $this->createMock( AttachmentLock::class );
		$lock->method( 'acquire_lease' )->willReturn( $lease );
		// Renew succeeds for first variant write, fails before second — simulating mid-scan lease loss.
		$lock->method( 'renew' )->willReturnOnConsecutiveCalls( true, false );
		$lock->expects( $this->once() )->method( 'release_lease' )->with( $lease );

		$result = $this->service( $gateway, $objects, $this->profiles( 3 ), $lock )->rebuild_attachment( 43, 3, false );

		$this->assertFalse( $result['success'] );
		$this->assertSame( RebuildInventoryService::OUTCOME_FAILED, $result['outcome'] );
		$this->assertSame( AttachmentOffloader::STATUS_OFFLOADED, (string) get_post_meta( 43, '_s3ms_status', true ) );
	}

	public function test_all_auth_heads_are_failed_outcome(): void {
		$this->seed_attachment( 24, '2026/09/auth.jpg', 10 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn(
			array(
				'exists'     => false,
				'error'      => 'Access Denied',
				'error_code' => 'AccessDenied',
				'status_code'=> 403,
			)
		);

		$objects = new RebuildInMemoryObjectRepository();
		$result  = $this->service( $gateway, $objects, $this->profiles( 3 ) )->rebuild_attachment( 24, 3, true );

		$this->assertFalse( $result['success'] );
		$this->assertSame( RebuildInventoryService::OUTCOME_FAILED, $result['outcome'] );
		$this->assertSame( 1, $result['counts']['auth'] );
		$this->assertSame( 'Rebuild dry-run failed.', $result['message'] );
		$this->assertStringNotContainsString( 'complete', strtolower( (string) $result['message'] ) );
	}

	public function test_exists_only_stays_out_of_present_totals(): void {
		// No local file and no inventory size → EXISTS_ONLY, not size-verified PRESENT.
		$this->seed_attachment( 26, '2026/09/exists-only.jpg', null );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn(
			array( 'exists' => true, 'content_length' => 42 )
		);

		$objects = new RebuildInMemoryObjectRepository();
		$result  = $this->service( $gateway, $objects, $this->profiles( 3 ) )->rebuild_attachment( 26, 3, true );

		$this->assertSame( RebuildInventoryService::OUTCOME_COMPLETE, $result['outcome'] );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['counts']['present'] );
		$this->assertSame( 1, $result['counts']['exists_only'] );
		$this->assertSame( RebuildInventoryService::CLASS_EXISTS_ONLY, $result['results'][0]['class'] );
	}

	public function test_persisted_zero_byte_inventory_size_is_trustworthy(): void {
		// Local file gone; inventory still has size_bytes=0 from a prior verified adopt.
		$this->seed_attachment( 28, '2026/09/zero.jpg', null );

		$objects = new RebuildInMemoryObjectRepository(
			array(
				array(
					'id'                  => 1,
					'attachment_id'       => 28,
					'storage_profile_id'  => 3,
					'local_relative_path' => '2026/09/zero.jpg',
					'object_key'          => 'uploads/2026/09/zero.jpg',
					'remote_status'       => ObjectRemoteStatus::PRESENT,
					'variant_type'        => 'original',
					'size_bytes'          => 0,
					'verified_at'         => '2026-09-01 00:00:00',
				),
			)
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn(
			array( 'exists' => true, 'content_length' => 0 )
		);

		$result = $this->service( $gateway, $objects, $this->profiles( 3 ) )->rebuild_attachment( 28, 3, true );

		$this->assertSame( RebuildInventoryService::OUTCOME_COMPLETE, $result['outcome'] );
		$this->assertSame( 1, $result['counts']['present'] );
		$this->assertSame( 0, $result['counts']['exists_only'] );
		$this->assertSame( RebuildInventoryService::CLASS_PRESENT, $result['results'][0]['class'] );
	}

	public function test_stale_importer_fingerprint_location_is_not_accepted_for_new_profile(): void {
		$this->seed_attachment( 29, '2026/09/moved.jpg', 40 );
		// Global detect matches profile bucket, but retained fingerprint names the old bucket.
		$importer = new RebuildExactKeyFakeImporter(
			array(
				new ImportedObjectDescriptor(
					29,
					'2026/09/moved.jpg',
					'original',
					'old-bucket-prefix/2026/09/moved.jpg',
					40,
					'image/jpeg',
					'wp-offload-media:aws:old-bucket:us-east-1'
				),
			),
			'bucket' // detect() bucket matches profile
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );

		$result = $this->service(
			$gateway,
			new RebuildInMemoryObjectRepository(),
			$this->profiles( 3 ),
			null,
			new ImportRegistry( array( $importer ) )
		)->rebuild_attachment( 29, 3, true );

		$this->assertSame( 'ambiguous_key', $result['results'][0]['class'] );
		$this->assertSame( RebuildInventoryService::OUTCOME_FAILED, $result['outcome'] );
	}

	public function test_fingerprint_match_without_detect_endpoint_proof_is_ambiguous(): void {
		$this->seed_attachment( 31, '2026/09/minio.jpg', 40 );
		$importer = new RebuildExactKeyFakeImporter(
			array(
				new ImportedObjectDescriptor(
					31,
					'2026/09/minio.jpg',
					'original',
					'custom/minio.jpg',
					40,
					'image/jpeg',
					'wp-offload-media:aws:bucket:us-east-1'
				),
			),
			'bucket',
			'http://minio.local:9000' // detect endpoint differs from profile (empty)
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );

		$result = $this->service(
			$gateway,
			new RebuildInMemoryObjectRepository(),
			$this->profiles( 3 ),
			null,
			new ImportRegistry( array( $importer ) )
		)->rebuild_attachment( 31, 3, true );

		$this->assertSame( 'ambiguous_key', $result['results'][0]['class'] );
	}

	public function test_advanced_media_provider_slug_fingerprint_is_compatible(): void {
		$this->seed_attachment( 32, '2026/09/advmo.jpg', 40 );
		$importer = new RebuildExactKeyFakeImporter(
			array(
				new ImportedObjectDescriptor(
					32,
					'2026/09/advmo.jpg',
					'original',
					'custom/advmo.jpg',
					40,
					'image/jpeg',
					'advanced-media-offloader:aws_s3:bucket:us-east-1:-'
				),
			),
			'bucket'
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->once() )
			->method( 'head_key' )
			->with( 'custom/advmo.jpg' )
			->willReturn( array( 'exists' => true, 'content_length' => 40 ) );

		$result = $this->service(
			$gateway,
			new RebuildInMemoryObjectRepository(),
			$this->profiles( 3 ),
			null,
			new ImportRegistry( array( $importer ) )
		)->rebuild_attachment( 32, 3, true );

		$this->assertSame( RebuildInventoryService::CLASS_PRESENT, $result['results'][0]['class'] );
	}

	public function test_exists_only_apply_clears_inherited_size_on_reclaim(): void {
		$this->seed_attachment( 30, '2026/09/reclaim.jpg', null );

		$objects = new RebuildInMemoryObjectRepository(
			array(
				array(
					'id'                  => 1,
					'attachment_id'       => 999,
					'storage_profile_id'  => 3,
					'local_relative_path' => 'other/path.jpg',
					'object_key'          => 'uploads/2026/09/reclaim.jpg',
					'remote_status'       => ObjectRemoteStatus::STALE,
					'variant_type'        => 'original',
					'size_bytes'          => 777,
					'verified_at'         => '2026-01-01 00:00:00',
				),
			)
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn(
			array( 'exists' => true, 'content_length' => 12 )
		);

		$result = $this->service( $gateway, $objects, $this->profiles( 3 ) )->rebuild_attachment( 30, 3, false );

		$this->assertSame( RebuildInventoryService::OUTCOME_COMPLETE, $result['outcome'] );
		$row = $objects->find_by_profile_and_key( 3, 'uploads/2026/09/reclaim.jpg' );
		$this->assertNotNull( $row );
		$this->assertSame( 30, (int) $row['attachment_id'] );
		$this->assertNull( $row['size_bytes'] );
		$this->assertNull( $row['verified_at'] );
		$this->assertNotSame( AttachmentOffloader::STATUS_OFFLOADED, (string) get_post_meta( 30, '_s3ms_status', true ) );
	}

	public function test_incomplete_builtin_fingerprint_does_not_fall_back_to_detect(): void {
		$this->seed_attachment( 137, '2026/09/unknown-fp.jpg', 40 );
		$importer = new RebuildExactKeyFakeImporter(
			array(
				new ImportedObjectDescriptor(
					137,
					'2026/09/unknown-fp.jpg',
					'original',
					'legacy/old-bucket/unknown-fp.jpg',
					40,
					'image/jpeg',
					'wp-offload-media:unknown-provider:unknown-bucket:us-east-1'
				),
			),
			'bucket', // detect matches selected profile bucket — must not unlock incomplete FP
			null
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );

		$result = $this->service(
			$gateway,
			new RebuildInMemoryObjectRepository(),
			$this->profiles( 3 ),
			null,
			new ImportRegistry( array( $importer ) )
		)->rebuild_attachment( 137, 3, true );

		$this->assertSame( RebuildInventoryService::CLASS_AMBIGUOUS_KEY, $result['results'][0]['class'] );
		$this->assertSame( RebuildInventoryService::OUTCOME_FAILED, $result['outcome'] );
	}

	public function test_retained_synthesized_key_rejected_when_foreign_importer_evidence_exists(): void {
		$this->seed_attachment( 138, '2026/09/shared-name.jpg', 40 );
		WpStubs::set_meta( 138, '_s3ms_original_key', 'uploads/2026/09/shared-name.jpg' );

		$importer = new RebuildExactKeyFakeImporter(
			array(
				new ImportedObjectDescriptor(
					138,
					'2026/09/shared-name.jpg',
					'original',
					'uploads/2026/09/shared-name.jpg',
					40,
					'image/jpeg',
					'wp-offload-media:aws:other-bucket:us-east-1'
				),
			),
			'bucket',
			null
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );

		$result = $this->service(
			$gateway,
			new RebuildInMemoryObjectRepository(),
			$this->profiles( 3 ),
			null,
			new ImportRegistry( array( $importer ) )
		)->rebuild_attachment( 138, 3, true );

		$this->assertSame( RebuildInventoryService::CLASS_AMBIGUOUS_KEY, $result['results'][0]['class'] );
	}

	public function test_exists_only_plus_uncertain_does_not_preserve_offloaded_meta(): void {
		$this->seed_attachment( 139, '2026/09/preserve.jpg', null );
		WpStubs::set_meta(
			139,
			'_wp_attachment_metadata',
			array(
				'sizes' => array(
					'thumbnail' => array(
						'file'   => 'preserve-150x150.jpg',
						'width'  => 150,
						'height' => 150,
					),
				),
			)
		);
		$thumb = rtrim( WpStubs::$uploads_basedir, '/' ) . '/2026/09/preserve-150x150.jpg';
		file_put_contents( $thumb, str_repeat( 'x', 5 ) );
		WpStubs::set_meta( 139, '_s3ms_status', AttachmentOffloader::STATUS_OFFLOADED );

		$objects = new RebuildInMemoryObjectRepository(
			array(
				array(
					'id'                  => 1,
					'attachment_id'       => 139,
					'storage_profile_id'  => 3,
					'local_relative_path' => '2026/09/preserve.jpg',
					'object_key'          => 'uploads/2026/09/preserve.jpg',
					'remote_status'       => ObjectRemoteStatus::PRESENT,
					'variant_type'        => 'original',
					'verified_at'         => null,
				),
				array(
					'id'                  => 2,
					'attachment_id'       => 139,
					'storage_profile_id'  => 3,
					'local_relative_path' => '2026/09/preserve-150x150.jpg',
					'object_key'          => 'uploads/2026/09/preserve-150x150.jpg',
					'remote_status'       => ObjectRemoteStatus::PRESENT,
					'variant_type'        => 'size',
					'verified_at'         => '2026-01-01 00:00:00',
					'size_bytes'          => 5,
				),
			)
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturnCallback(
			static function ( string $key ): array {
				if ( str_contains( $key, '150x150' ) ) {
					return array(
						'exists'      => false,
						'error'       => 'Access Denied',
						'error_code'  => 'AccessDenied',
						'status_code' => 403,
					);
				}
				return array( 'exists' => true, 'content_length' => 99 );
			}
		);

		$result = $this->service( $gateway, $objects, $this->profiles( 3 ) )->rebuild_attachment( 139, 3, false );

		$this->assertNotSame( AttachmentOffloader::STATUS_OFFLOADED, (string) get_post_meta( 139, '_s3ms_status', true ) );
		$this->assertGreaterThan( 0, $result['counts']['auth'] + $result['counts']['exists_only'] );
	}

	public function test_apply_missing_attachment_is_audited(): void {
		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );

		$objects = new RebuildInMemoryObjectRepository();
		$result  = $this->service( $gateway, $objects, $this->profiles( 3 ) )
			->rebuild_attachment( 999001, 3, false );

		$this->assertSame( RebuildInventoryService::OUTCOME_FAILED, $result['outcome'] );
		$this->assertSame( 'Attachment not found.', $result['message'] );

		$actions = array_column( WpStubs::$options[ \Kazcode\WpStorage\Services\AuditLog::OPTION_KEY ] ?? array(), 'action' );
		$this->assertContains( 'rebuild_inventory_apply', $actions );
	}

	public function test_credential_provider_codes_count_as_auth_not_invalid_request(): void {
		$this->seed_attachment( 131, '2026/09/auth-code.jpg', 10 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn(
			array(
				'exists'            => false,
				'confirmed_missing' => false,
				'error'             => 'The AWS Access Key Id you provided does not exist.',
				'error_code'        => 'InvalidAccessKeyId',
				'status_code'       => 400,
			)
		);

		$result = $this->service( $gateway, new RebuildInMemoryObjectRepository(), $this->profiles( 3 ) )
			->rebuild_attachment( 131, 3, true );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 1, $result['counts']['scanned'] );
		$this->assertSame( 1, $result['counts']['auth'] );
		$this->assertSame( 0, $result['counts']['invalid_request'] );
		$this->assertSame( RebuildInventoryService::CLASS_AUTH, $result['results'][0]['class'] );
	}

	public function test_authorization_header_malformed_is_invalid_request_not_auth(): void {
		$this->seed_attachment( 133, '2026/09/region-mismatch.jpg', 10 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn(
			array(
				'exists'            => false,
				'confirmed_missing' => false,
				'error'             => 'The authorization header is malformed; the region is wrong.',
				'error_code'        => 'AuthorizationHeaderMalformed',
				'status_code'       => 400,
			)
		);

		$result = $this->service( $gateway, new RebuildInMemoryObjectRepository(), $this->profiles( 3 ) )
			->rebuild_attachment( 133, 3, true );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 1, $result['counts']['invalid_request'] );
		$this->assertSame( 0, $result['counts']['auth'] );
		$this->assertSame( RebuildInventoryService::CLASS_INVALID_REQUEST, $result['results'][0]['class'] );
	}

	public function test_http_504_without_timeout_text_is_transient(): void {
		$this->seed_attachment( 134, '2026/09/gateway-timeout.jpg', 10 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn(
			array(
				'exists'            => false,
				'confirmed_missing' => false,
				'error'             => 'Error',
				'error_code'        => '',
				'status_code'       => 504,
			)
		);

		$result = $this->service( $gateway, new RebuildInMemoryObjectRepository(), $this->profiles( 3 ) )
			->rebuild_attachment( 134, 3, true );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 1, $result['counts']['transient'] );
		$this->assertSame( 0, $result['counts']['unknown'] );
		$this->assertSame( RebuildInventoryService::CLASS_TRANSIENT, $result['results'][0]['class'] );
	}

	public function test_http_408_without_timeout_text_is_transient(): void {
		$this->seed_attachment( 135, '2026/09/request-timeout.jpg', 10 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn(
			array(
				'exists'            => false,
				'confirmed_missing' => false,
				'error'             => 'Error',
				'error_code'        => '',
				'status_code'       => 408,
			)
		);

		$result = $this->service( $gateway, new RebuildInMemoryObjectRepository(), $this->profiles( 3 ) )
			->rebuild_attachment( 135, 3, true );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 1, $result['counts']['transient'] );
		$this->assertSame( RebuildInventoryService::CLASS_TRANSIENT, $result['results'][0]['class'] );
	}

	public function test_duplicate_current_keys_for_same_relative_are_relative_key_conflict_before_head(): void {
		$this->seed_attachment( 136, '2026/09/dup-keys.jpg', 10 );

		$objects = new RebuildInMemoryObjectRepository(
			array(
				array(
					'id'                  => 1,
					'attachment_id'       => 136,
					'storage_profile_id'  => 3,
					'local_relative_path' => '2026/09/dup-keys.jpg',
					'object_key'          => 'uploads/2026/09/dup-keys.jpg',
					'remote_status'       => ObjectRemoteStatus::PRESENT,
					'variant_type'        => 'original',
				),
				array(
					'id'                  => 2,
					'attachment_id'       => 136,
					'storage_profile_id'  => 3,
					'local_relative_path' => '2026/09/dup-keys.jpg',
					'object_key'          => 'other/2026/09/dup-keys.jpg',
					'remote_status'       => ObjectRemoteStatus::FAILED,
					'variant_type'        => 'original',
				),
			)
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );

		$result = $this->service( $gateway, $objects, $this->profiles( 3 ) )->rebuild_attachment( 136, 3, true );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 1, $result['counts']['relative_key_conflict'] );
		$this->assertSame( RebuildInventoryService::CLASS_RELATIVE_KEY_CONFLICT, $result['results'][0]['class'] );
	}

	public function test_apply_audit_includes_scanned_variant_count(): void {
		$this->seed_attachment( 132, '2026/09/scanned-count.jpg', 12 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn(
			array( 'exists' => true, 'content_length' => 12 )
		);

		$result = $this->service( $gateway, new RebuildInMemoryObjectRepository(), $this->profiles( 3 ) )
			->rebuild_attachment( 132, 3, false );

		$this->assertSame( RebuildInventoryService::OUTCOME_COMPLETE, $result['outcome'] );
		$this->assertSame( 1, $result['counts']['scanned'] );

		$entries = WpStubs::$options[ \Kazcode\WpStorage\Services\AuditLog::OPTION_KEY ] ?? array();
		$rebuild = null;
		foreach ( array_reverse( $entries ) as $entry ) {
			if ( ( $entry['action'] ?? '' ) === 'rebuild_inventory_apply' ) {
				$rebuild = $entry;
				break;
			}
		}
		$this->assertIsArray( $rebuild );
		$this->assertSame( 1, (int) ( $rebuild['context']['counts']['scanned'] ?? 0 ) );
	}

	public function test_invalid_explicit_profile_id_is_rejected(): void {
		$this->seed_attachment( 27, '2026/09/bad-profile.jpg', 10 );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );

		$result = $this->service( $gateway, new RebuildInMemoryObjectRepository(), $this->profiles( 3 ) )
			->rebuild_attachment( 27, 0, true );

		$this->assertSame( RebuildInventoryService::OUTCOME_FAILED, $result['outcome'] );
		$this->assertSame( 1, $result['counts']['invalid_request'] );
		$this->assertStringContainsString( 'positive integer', (string) $result['message'] );
	}

	public function test_mixed_present_and_transient_is_partial_outcome(): void {
		$this->seed_attachment_with_sizes(
			25,
			'2026/09/mixed.jpg',
			array(
				'2026/09/mixed.jpg'           => 10,
				'2026/09/mixed-150x150.jpg'   => 5,
			)
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturnCallback(
			static function ( string $key ): array {
				if ( str_contains( $key, '150x150' ) ) {
					return array(
						'exists'      => false,
						'error'       => 'Request timed out',
						'error_code'  => 'RequestTimeout',
					);
				}
				return array( 'exists' => true, 'content_length' => 10 );
			}
		);

		$objects = new RebuildInMemoryObjectRepository();
		$result  = $this->service( $gateway, $objects, $this->profiles( 3 ) )->rebuild_attachment( 25, 3, true );

		$this->assertFalse( $result['success'] );
		$this->assertSame( RebuildInventoryService::OUTCOME_PARTIAL, $result['outcome'] );
		$this->assertGreaterThan( 0, $result['counts']['present'] );
		$this->assertGreaterThan( 0, $result['counts']['transient'] );
	}

	public function test_insert_owned_or_conflict_preserves_owner_on_duplicate_key_path(): void {
		$objects = new RebuildInMemoryObjectRepository();
		$first   = $objects->insert_owned_or_conflict(
			array(
				'attachment_id'       => 100,
				'storage_profile_id'  => 3,
				'local_relative_path' => 'a.jpg',
				'object_key'          => 'key/shared.jpg',
				'remote_status'       => ObjectRemoteStatus::PRESENT,
				'variant_type'        => 'original',
			)
		);
		$this->assertTrue( $first['ok'] );

		// Simulate TOCTOU loser writing different owner through the same API.
		$second = $objects->insert_owned_or_conflict(
			array(
				'attachment_id'       => 200,
				'storage_profile_id'  => 3,
				'local_relative_path' => 'b.jpg',
				'object_key'          => 'key/shared.jpg',
				'remote_status'       => ObjectRemoteStatus::PRESENT,
				'variant_type'        => 'original',
			)
		);
		$this->assertFalse( $second['ok'] );
		$this->assertSame( 'key_owned_by_other_attachment', $second['reason'] );
		$row = $objects->find_by_profile_and_key( 3, 'key/shared.jpg' );
		$this->assertSame( 100, (int) $row['attachment_id'] );
		$this->assertSame( 'a.jpg', (string) $row['local_relative_path'] );
	}

	public function test_insert_owned_or_conflict_refuses_second_current_key_for_same_relative(): void {
		$objects = new RebuildInMemoryObjectRepository();
		$first   = $objects->insert_owned_or_conflict(
			array(
				'attachment_id'       => 100,
				'storage_profile_id'  => 3,
				'local_relative_path' => 'a.jpg',
				'object_key'          => 'key/one.jpg',
				'remote_status'       => ObjectRemoteStatus::PRESENT,
				'variant_type'        => 'original',
			)
		);
		$this->assertTrue( $first['ok'] );

		$second = $objects->insert_owned_or_conflict(
			array(
				'attachment_id'       => 100,
				'storage_profile_id'  => 3,
				'local_relative_path' => 'a.jpg',
				'object_key'          => 'key/two.jpg',
				'remote_status'       => ObjectRemoteStatus::PRESENT,
				'variant_type'        => 'original',
			)
		);
		$this->assertFalse( $second['ok'] );
		$this->assertSame( 'relative_key_conflict', $second['reason'] );
		$this->assertNotNull( $objects->find_by_profile_and_key( 3, 'key/one.jpg' ) );
		$this->assertNull( $objects->find_by_profile_and_key( 3, 'key/two.jpg' ) );
	}

	/**
	 * @param ProfileStorageGateway|null        $gateway Gateway.
	 * @param ObjectRepository                   $objects Repo.
	 * @param WpdbStorageProfileRepository      $profiles Profiles.
	 * @param AttachmentLock|null               $lock Lock.
	 * @param ImportRegistry|null               $imports Imports.
	 * @param ObjectStatsAggregator|null        $stats Stats.
	 */
	private function service(
		$gateway,
		ObjectRepository $objects,
		WpdbStorageProfileRepository $profiles,
		?AttachmentLock $lock = null,
		?ImportRegistry $imports = null,
		?ObjectStatsAggregator $stats = null
	): RebuildInventoryService {
		$settings = $this->createMock( Settings::class );
		return new RebuildInventoryService(
			$settings,
			null,
			$objects,
			$profiles,
			static fn (): ProfileStorageGateway => $gateway,
			$lock,
			$imports ?? new ImportRegistry( array() ),
			null,
			$stats
		);
	}

	private function profiles( int $default_id ): WpdbStorageProfileRepository {
		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->willReturnCallback(
			fn ( int $id ): StorageProfile => $this->profile( $id )
		);
		$profiles->method( 'find_default_upload_target' )->willReturn( $this->profile( $default_id ) );
		return $profiles;
	}

	private function profile( int $id, string $prefix = 'uploads/', string $endpoint = '' ): StorageProfile {
		$now = gmdate( 'Y-m-d H:i:s' );
		return new StorageProfile(
			$id,
			'uuid-' . $id,
			'P' . $id,
			'aws',
			'bucket',
			'us-east-1',
			$endpoint,
			false,
			$prefix,
			'storage',
			'',
			false,
			'keys',
			'ref',
			$id === 3,
			false,
			false,
			$now,
			$now
		);
	}

	private function direct_upload_location_fingerprint( StorageProfile $profile ): string {
		$values = array( 'profile_id' => $profile->id, 'provider_type' => $profile->provider_type, 'bucket' => $profile->bucket, 'region' => $profile->region, 'endpoint' => $profile->endpoint, 'path_style' => $profile->path_style ? 1 : 0, 'prefix' => rtrim( $profile->prefix, '/' ) . '/', 'credentials_ref' => $profile->credentials_ref );
		return hash( 'sha256', json_encode( $values, JSON_THROW_ON_ERROR ) );
	}

	private function seed_attachment( int $id, string $relative, ?int $local_size ): void {
		WpStubs::$posts[ $id ] = (object) array(
			'ID'        => $id,
			'post_type' => 'attachment',
		);
		WpStubs::set_meta( $id, '_wp_attached_file', $relative );
		WpStubs::set_meta( $id, '_wp_attachment_metadata', array() );

		$abs = rtrim( WpStubs::$uploads_basedir, '/' ) . '/' . $relative;
		$dir = dirname( $abs );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		if ( $local_size === null ) {
			if ( is_file( $abs ) ) {
				unlink( $abs );
			}
			return;
		}
		file_put_contents( $abs, str_repeat( 'x', $local_size ) );
	}

	/**
	 * @param array<string, int> $files relative => size.
	 */
	private function seed_attachment_with_sizes( int $id, string $primary, array $files ): void {
		WpStubs::$posts[ $id ] = (object) array(
			'ID'        => $id,
			'post_type' => 'attachment',
		);
		WpStubs::set_meta( $id, '_wp_attached_file', $primary );
		$sizes = array();
		foreach ( $files as $relative => $size ) {
			$abs = rtrim( WpStubs::$uploads_basedir, '/' ) . '/' . $relative;
			$dir = dirname( $abs );
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0777, true );
			}
			file_put_contents( $abs, str_repeat( 'x', $size ) );
			if ( $relative !== $primary ) {
				$sizes['thumb'] = array(
					'file' => basename( $relative ),
					'width' => 150,
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
}

/**
 * In-memory ObjectRepository for rebuild ownership tests.
 */
final class RebuildInMemoryObjectRepository extends ObjectRepository {

	/** @var list<array<string, mixed>> */
	private array $rows = array();

	private int $next_id = 1;

	/**
	 * @param list<array<string, mixed>> $rows Seed.
	 */
	public function __construct( array $rows = array() ) {
		foreach ( $rows as $row ) {
			$id            = (int) ( $row['id'] ?? $this->next_id );
			$row['id']     = $id;
			$this->next_id = max( $this->next_id, $id + 1 );
			$this->rows[]  = $row;
		}
	}

	public function find_by_attachment( int $attachment_id ): array {
		return array_values(
			array_filter(
				$this->rows,
				static fn( array $row ): bool => (int) ( $row['attachment_id'] ?? 0 ) === $attachment_id
			)
		);
	}

	public function find_by_profile_and_key( int $storage_profile_id, string $object_key ): ?array {
		foreach ( $this->rows as $row ) {
			if ( (int) ( $row['storage_profile_id'] ?? 0 ) === $storage_profile_id && (string) ( $row['object_key'] ?? '' ) === $object_key ) {
				return $row;
			}
		}
		return null;
	}

	public function find_by_id( int $id ): ?array {
		foreach ( $this->rows as $row ) {
			if ( (int) ( $row['id'] ?? 0 ) === $id ) {
				return $row;
			}
		}
		return null;
	}

	public function upsert( array $data ): int {
		$profile_id = (int) ( $data['storage_profile_id'] ?? 0 );
		$key        = (string) ( $data['object_key'] ?? '' );
		foreach ( $this->rows as $index => $row ) {
			if ( (int) ( $row['storage_profile_id'] ?? 0 ) === $profile_id && (string) ( $row['object_key'] ?? '' ) === $key ) {
				$id                   = (int) $row['id'];
				$this->rows[ $index ] = array_merge( $row, $data, array( 'id' => $id ) );
				return $id;
			}
		}
		$id           = $this->next_id++;
		$data['id']   = $id;
		$this->rows[] = $data;
		return $id;
	}

	/**
	 * Owner-preserving insert used by rebuild Apply (models atomic ODKU semantics,
	 * including reclaim of stale/deleted superseded rows).
	 *
	 * @param array<string, mixed> $data Row.
	 * @return array{ok:bool,id?:int,reason?:string,existing?:array<string,mixed>}
	 */
	public function insert_owned_or_conflict( array $data ): array {
		$profile_id = (int) ( $data['storage_profile_id'] ?? 0 );
		$key        = (string) ( $data['object_key'] ?? '' );
		$attachment = (int) ( $data['attachment_id'] ?? 0 );
		$relative   = (string) ( $data['local_relative_path'] ?? '' );
		if ( $profile_id <= 0 || $key === '' || $attachment <= 0 ) {
			return array(
				'ok'     => false,
				'reason' => 'invalid_row',
			);
		}

		foreach ( $this->rows as $row ) {
			if ( (int) ( $row['attachment_id'] ?? 0 ) !== $attachment ) {
				continue;
			}
			if ( (int) ( $row['storage_profile_id'] ?? 0 ) !== $profile_id ) {
				continue;
			}
			if ( (string) ( $row['local_relative_path'] ?? '' ) !== $relative ) {
				continue;
			}
			$status = (string) ( $row['remote_status'] ?? '' );
			if ( in_array( $status, array( ObjectRemoteStatus::STALE, ObjectRemoteStatus::DELETED ), true ) ) {
				continue;
			}
			$existing_key = (string) ( $row['object_key'] ?? '' );
			if ( $existing_key !== '' && $existing_key !== $key ) {
				return array(
					'ok'       => false,
					'reason'   => 'relative_key_conflict',
					'existing' => $row,
				);
			}
		}

		$existing = $this->find_by_profile_and_key( $profile_id, $key );
		if ( $existing === null ) {
			return array( 'ok' => true, 'id' => $this->upsert( $data ) );
		}
		$status       = (string) ( $existing['remote_status'] ?? '' );
		$reclaimable  = in_array( $status, array( ObjectRemoteStatus::STALE, ObjectRemoteStatus::DELETED ), true );
		$same_owner   = (int) ( $existing['attachment_id'] ?? 0 ) === $attachment
			&& (string) ( $existing['local_relative_path'] ?? '' ) === $relative;
		if ( ! $same_owner && ! $reclaimable ) {
			$reason = (int) ( $existing['attachment_id'] ?? 0 ) !== $attachment
				? 'key_owned_by_other_attachment'
				: 'key_owned_by_other_relative';
			return array( 'ok' => false, 'reason' => $reason, 'existing' => $existing );
		}
		foreach ( $this->rows as $index => $row ) {
			if ( (int) ( $row['id'] ?? 0 ) !== (int) $existing['id'] ) {
				continue;
			}
			$merged = array_merge( $row, $data, array( 'id' => (int) $row['id'] ) );
			$this->rows[ $index ] = $merged;
			return array( 'ok' => true, 'id' => (int) $merged['id'] );
		}
		return array( 'ok' => false, 'reason' => 'write_failed' );
	}
}

/**
 * Importer stub returning fixed descriptors for rebuild exact-key tests.
 */
final class RebuildExactKeyFakeImporter implements ImporterInterface {

	/** @param list<ImportedObjectDescriptor> $descriptors */
	public function __construct(
		private array $descriptors,
		private string $bucket = 'bucket',
		private ?string $endpoint = null
	) {
	}

	public function id(): string {
		return 'rebuild-exact-key-fake';
	}

	public function label(): string {
		return 'Rebuild Exact Key Fake';
	}

	public function detect(): ImportDetection {
		return new ImportDetection(
			$this->id(),
			$this->label(),
			'1.0.0',
			's3',
			$this->bucket,
			'us-east-1',
			$this->endpoint,
			'custom-prefix/',
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
