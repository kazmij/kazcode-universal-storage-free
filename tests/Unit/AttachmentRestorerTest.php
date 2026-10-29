<?php
/**
 * AttachmentRestorer unit tests (v2 acceptance criterion 4).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Attachment\AttachmentRestorer;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\AttachmentLock;
use Kazcode\WpStorage\Infrastructure\ReservationRepository;
use Kazcode\WpStorage\Services\ProfileAwareObjectOperations;
use Kazcode\WpStorage\Services\ReservationCleanupService;
use Kazcode\WpStorage\Storage\S3KeyResolver;
use Kazcode\WpStorage\Storage\S3Storage;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class AttachmentRestorerTest extends TestCase {

	private string $uploads;

	protected function setUp(): void {
		WpStubs::reset();
		$this->uploads = sys_get_temp_dir() . '/s3ms-restore-' . uniqid( '', true );
		wp_mkdir_p( $this->uploads . '/2026/08' );
		WpStubs::$uploads_basedir = $this->uploads;
	}

	protected function tearDown(): void {
		$this->rmTree( $this->uploads );
		WpStubs::reset();
	}

	public function test_successful_restore_clears_offload_meta_and_object_rows(): void {
		$id = 42;
		WpStubs::$post_meta[ $id ]['_wp_attached_file']  = '2026/08/photo.jpg';
		WpStubs::$post_meta[ $id ]['_s3ms_status']       = 'offloaded';
		WpStubs::$post_meta[ $id ]['_s3ms_original_key'] = 'uploads/2026/08/photo.jpg';

		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_aws_configured' )->willReturn( true );

		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		$storage->method( 'head_relative' )->willReturn( array( 'exists' => true ) );
		$storage->method( 'download_relative' )->willReturnCallback(
			static function ( string $relative, string $absolute ): void {
				file_put_contents( $absolute, 'bytes' );
			}
		);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )->method( 'delete_by_attachment' )->with( 42 )->willReturn( 1 );

		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->method( 'head_attachment_relative' )->willReturn( array( 'exists' => true ) );
		$profile_ops->method( 'download_attachment_relative_to_local' )->willReturnCallback(
			static function ( int $attachment_id, string $relative, string $absolute ): array {
				unset( $attachment_id, $relative );
				file_put_contents( $absolute, 'bytes' );
				return array( 'success' => true, 'location_status' => 'test', 'legacy_fallback' => false );
			}
		);

		$restorer = new AttachmentRestorer( $settings, $storage, null, null, $profile_ops );
		$ref      = new \ReflectionClass( AttachmentRestorer::class );
		$prop     = $ref->getProperty( 'objects' );
		$prop->setAccessible( true );
		$prop->setValue( $restorer, $objects );

		$result = $restorer->restore( $id );

		$this->assertTrue( $result['success'] );
		$this->assertSame( '', (string) get_post_meta( $id, '_s3ms_status', true ) );
		$this->assertSame( '', (string) get_post_meta( $id, '_s3ms_original_key', true ) );
	}

	public function test_partial_restore_with_confirmed_missing_does_not_mark_attachment_local(): void {
		$id = 43;
		$this->seed_attachment_with_thumbnail( $id );

		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_aws_configured' )->willReturn( true );

		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->method( 'head_attachment_relative' )->willReturnCallback(
			static fn( int $attachment_id, string $relative ): array => $relative === '2026/08/photo.jpg'
				? array( 'exists' => true )
				: array( 'exists' => false, 'confirmed_missing' => true )
		);
		$profile_ops->method( 'download_attachment_relative_to_local' )->willReturnCallback(
			static function ( int $attachment_id, string $relative, string $absolute ): array {
				unset( $attachment_id );
				if ( $relative === '2026/08/photo.jpg' ) {
					file_put_contents( $absolute, 'bytes' );
					return array( 'success' => true, 'location_status' => 'test', 'legacy_fallback' => false );
				}
				return array( 'success' => false, 'location_status' => 'test', 'legacy_fallback' => false );
			}
		);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->never() )->method( 'delete_by_attachment' );

		$restorer = new AttachmentRestorer( $settings, $storage, null, null, $profile_ops );
		$this->inject_objects( $restorer, $objects );

		$result = $restorer->restore( $id );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'partial', $result['status'] ?? '' );
		$this->assertSame( 'offloaded', (string) get_post_meta( $id, '_s3ms_status', true ) );
		$this->assertSame( 'uploads/2026/08/photo.jpg', (string) get_post_meta( $id, '_s3ms_original_key', true ) );
	}

	public function test_partial_restore_with_unknown_head_does_not_mark_attachment_local(): void {
		$id = 44;
		$this->seed_attachment_with_thumbnail( $id );

		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_aws_configured' )->willReturn( true );

		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->method( 'head_attachment_relative' )->willReturnCallback(
			static fn( int $attachment_id, string $relative ): array => $relative === '2026/08/photo.jpg'
				? array( 'exists' => true )
				: array( 'exists' => false, 'confirmed_missing' => false, 'error' => 'timeout' )
		);
		$profile_ops->method( 'download_attachment_relative_to_local' )->willReturnCallback(
			static function ( int $attachment_id, string $relative, string $absolute ): array {
				unset( $attachment_id );
				if ( $relative === '2026/08/photo.jpg' ) {
					file_put_contents( $absolute, 'bytes' );
					return array( 'success' => true, 'location_status' => 'test', 'legacy_fallback' => false );
				}
				return array( 'success' => false, 'location_status' => 'test', 'legacy_fallback' => false );
			}
		);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->never() )->method( 'delete_by_attachment' );

		$restorer = new AttachmentRestorer( $settings, $storage, null, null, $profile_ops );
		$this->inject_objects( $restorer, $objects );

		$result = $restorer->restore( $id );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'partial', $result['status'] ?? '' );
		$this->assertSame( 'offloaded', (string) get_post_meta( $id, '_s3ms_status', true ) );
		$this->assertSame( 'uploads/2026/08/photo.jpg', (string) get_post_meta( $id, '_s3ms_original_key', true ) );
	}

	public function test_restore_proceeds_without_site_settings_when_profile_inventory_exists(): void {
		$id = 45;
		WpStubs::$post_meta[ $id ]['_wp_attached_file']  = '2026/08/photo.jpg';
		WpStubs::$post_meta[ $id ]['_s3ms_status']       = 'offloaded';
		WpStubs::$post_meta[ $id ]['_s3ms_original_key'] = 'uploads/2026/08/photo.jpg';

		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_aws_configured' )->willReturn( false );

		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		$storage->expects( $this->never() )->method( 'download_relative' );

		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->method( 'head_attachment_relative' )->willReturn( array( 'exists' => true ) );
		$profile_ops->method( 'download_attachment_relative_to_local' )->willReturnCallback(
			static function ( int $attachment_id, string $relative, string $absolute ): array {
				unset( $attachment_id, $relative );
				file_put_contents( $absolute, 'profile-restore' );
				return array( 'success' => true, 'location_status' => 'found', 'legacy_fallback' => false );
			}
		);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->with( 45 )->willReturn(
			array(
				array(
					'id'                 => 1,
					'attachment_id'      => 45,
					'storage_profile_id' => 9,
					'local_relative_path'=> '2026/08/photo.jpg',
					'object_key'         => 'p9/2026/08/photo.jpg',
				),
			)
		);
		$objects->expects( $this->once() )->method( 'delete_by_attachment' )->with( 45 )->willReturn( 1 );

		$restorer = new AttachmentRestorer( $settings, $storage, null, null, $profile_ops );
		$this->inject_objects( $restorer, $objects );

		$result = $restorer->restore( $id );

		$this->assertTrue( $result['success'] );
		$this->assertSame( '', (string) get_post_meta( $id, '_s3ms_status', true ) );
	}

	public function test_restore_still_blocks_when_site_settings_empty_and_no_profile_inventory(): void {
		$id = 46;
		WpStubs::$post_meta[ $id ]['_wp_attached_file'] = '2026/08/photo.jpg';

		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_aws_configured' )->willReturn( false );

		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );

		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->expects( $this->never() )->method( 'download_attachment_relative_to_local' );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->with( 46 )->willReturn( array() );

		$restorer = new AttachmentRestorer( $settings, $storage, null, null, $profile_ops );
		$this->inject_objects( $restorer, $objects );

		$result = $restorer->restore( $id );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'AWS is not configured.', $result['message'] );
	}

	public function test_delete_uses_bound_direct_upload_profile_when_legacy_settings_are_empty(): void {
		$id = 47;
		$this->seed_direct_upload_cleanup_evidence( $id );

		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_enabled' )->willReturn( true );
		$settings->method( 'should_delete_remote' )->willReturn( true );
		$settings->method( 'is_aws_configured' )->willReturn( false );
		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->expects( $this->once() )->method( 'delete_exact_profile_key' )->with( 7, 'profile-7/2026/08/photo.jpg', 'profile-fingerprint' )->willReturn( true );
		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )->method( 'delete_by_attachment' )->with( $id );
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->expects( $this->once() )->method( 'release_owned_attachment_pair' )->willReturn( true );
		$cleanup = new class {
			public function record( array $binding, string $proof ): array { return array( 'id' => 1 ); }
			public function remote_deleted( int $id ): bool { return true; }
			public function ready( int $id ): bool { return true; }
			public function complete( int $id ): bool { return true; }
		};

		$restorer = new AttachmentRestorer( $settings, $storage, null, null, $profile_ops, null, $reservations, $cleanup );
		$this->inject_objects( $restorer, $objects );
		$restorer->on_delete_attachment( $id );
	}

	public function test_delete_uses_bound_active_direct_upload_profile_when_legacy_settings_are_empty(): void {
		$id = 50;
		$this->seed_direct_upload_cleanup_evidence( $id );
		WpStubs::$post_meta[ $id ]['_s3ms_status'] = 'offloaded';
		WpStubs::$post_meta[ $id ]['_s3ms_original_key'] = 'profile-7/2026/08/photo.jpg';

		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_enabled' )->willReturn( true );
		$settings->method( 'should_delete_remote' )->willReturn( true );
		$settings->method( 'is_aws_configured' )->willReturn( false );
		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->expects( $this->once() )->method( 'delete_exact_profile_key' )->with( 7, 'profile-7/2026/08/photo.jpg', 'profile-fingerprint' )->willReturn( true );
		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )->method( 'delete_by_attachment' )->with( $id );
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->expects( $this->once() )->method( 'release_owned_attachment_pair' )->willReturn( true );
		$cleanup = new class {
			public function record( array $binding, string $proof ): array { return array( 'id' => 1 ); }
			public function remote_deleted( int $id ): bool { return true; }
			public function ready( int $id ): bool { return true; }
			public function complete( int $id ): bool { return true; }
		};

		$restorer = new AttachmentRestorer( $settings, $storage, null, null, $profile_ops, null, $reservations, $cleanup );
		$this->inject_objects( $restorer, $objects );
		$restorer->on_delete_attachment( $id );
	}

	public function test_synchronous_migration_cleanup_defers_release_until_exact_multipart_settlement_is_proven_then_retries(): void {
		$id = 59;
		$previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new MigrationFenceCompletionWpdbFake();
		try {
		$this->seed_direct_upload_cleanup_evidence( $id );
		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_enabled' )->willReturn( true );
		$settings->method( 'should_delete_remote' )->willReturn( true );
		$settings->method( 'is_aws_configured' )->willReturn( false );
		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->method( 'delete_exact_profile_key' )->willReturn( true );
		$profile_ops->expects( $this->exactly( 2 ) )->method( 'settle_exact_multipart_cleanup' )
			->with( 8, 'migration/2026/08/photo.jpg', str_repeat( 'b', 64 ), 'upload-59' )
			->willReturnOnConsecutiveCalls( false, true );
		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )->method( 'delete_by_attachment' )->with( $id );
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->expects( $this->once() )->method( 'release_owned_attachment_pair' )->willReturn( array( 'path_reservation_id' => 101 ) );
		$cleanup = new class {
			public int $remote_deleted = 0;
			public int $ready = 0;
			public int $complete = 0;
			public function record( array $binding, string $proof ): array {
				$binding['migration_cleanup_location'] = array( 'profile_id' => 8, 'object_key' => 'migration/2026/08/photo.jpg', 'profile_fingerprint' => str_repeat( 'b', 64 ) );
				$binding['migration_multipart_upload_id'] = 'upload-59';
				return array( 'id' => 59, 'binding_json' => wp_json_encode( $binding ) );
			}
			public function remote_deleted( int $id ): bool { ++$this->remote_deleted; return true; }
			public function ready( int $id ): bool { ++$this->ready; return true; }
			public function complete( int $id ): bool { ++$this->complete; return true; }
		};

		$restorer = new AttachmentRestorer( $settings, $storage, null, null, $profile_ops, null, $reservations, $cleanup );
		$this->inject_objects( $restorer, $objects );
		$restorer->on_delete_attachment( $id );
		$this->assertSame( 0, $cleanup->remote_deleted, 'Unknown multipart settlement must retain the durable cleanup authority.' );
		$this->assertSame( 0, $cleanup->complete, 'Unknown multipart settlement must not complete cleanup or release the profile pin.' );

		$restorer->on_delete_attachment( $id );
		$this->assertSame( 1, $cleanup->remote_deleted );
		$this->assertSame( 1, $cleanup->ready );
		$this->assertSame( 1, $cleanup->complete );
		} finally {
			if ( $previous_wpdb === null ) unset( $GLOBALS['wpdb'] ); else $GLOBALS['wpdb'] = $previous_wpdb;
		}
	}

	public function test_busy_direct_upload_delete_persists_exact_intent_before_metadata_disappears_then_replay_cleans_only_the_bound_pair(): void {
		$id = 51;
		$this->seed_direct_upload_cleanup_evidence( $id );
		WpStubs::$post_meta[ $id ]['_s3ms_status'] = 'offloaded';
		WpStubs::$post_meta[ $id ]['_s3ms_original_key'] = 'profile-7/2026/08/photo.jpg';
		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_enabled' )->willReturn( true );
		$settings->method( 'should_delete_remote' )->willReturn( true );
		$settings->method( 'is_aws_configured' )->willReturn( false );
		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		$busy_profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$busy_profile_ops->expects( $this->never() )->method( 'delete_exact_profile_key' );
		$busy_objects = $this->createMock( ObjectRepository::class );
		$busy_objects->expects( $this->never() )->method( 'delete_by_attachment' );
		$busy_reservations = $this->createMock( ReservationRepository::class );
		$busy_reservations->expects( $this->never() )->method( 'release_owned_attachment_pair' );
		$cleanup = new class {
			public array $records = array();
			public string $state = 'intent';
			public array $transitions = array();
			public function record( array $binding, string $proof ): array { $this->records[] = array( 'binding' => $binding, 'proof' => $proof ); return array( 'id' => 51 ); }
			public function pending( int $limit ): array { return array( array( 'id' => 51, 'state' => $this->state, 'binding_json' => wp_json_encode( $this->records[0]['binding'] ), 'settlement_proof' => $this->records[0]['proof'] ) ); }
			public function remote_deleted( int $id ): bool { $this->transitions[] = 'remote_deleted'; $this->state = 'remote_deleted'; return true; }
			public function ready( int $id ): bool { $this->transitions[] = 'ready'; $this->state = 'ready'; return true; }
			public function complete( int $id ): bool { $this->transitions[] = 'complete'; $this->state = 'complete'; return true; }
		};

		$held = ( new AttachmentLock() )->acquire_lease( $id, 'restore' );
		$this->assertNotNull( $held );
		$restorer = new AttachmentRestorer( $settings, $storage, null, null, $busy_profile_ops, null, $busy_reservations, $cleanup );
		$this->inject_objects( $restorer, $busy_objects );
		$restorer->on_delete_attachment( $id );

		$this->assertCount( 1, $cleanup->records );
		$this->assertSame( 'attachment_deleted:51', $cleanup->records[0]['proof'] );
		$this->assertSame( 101, $cleanup->records[0]['binding']['path_reservation_id'] );
		WpStubs::$post_meta[ $id ] = array();
		( new AttachmentLock() )->release_lease( $held );

		$replay_objects = $this->createMock( ObjectRepository::class );
		$replay_objects->expects( $this->once() )->method( 'delete_by_attachment' )->with( $id );
		$replay_remote = new class {
			public array $calls = array();
			public function delete_exact_profile_key( int $profile_id, string $object_key, string $fingerprint ): bool { $this->calls[] = array( $profile_id, $object_key, $fingerprint ); return true; }
		};
		$replay_reservations = $this->createMock( ReservationRepository::class );
		$replay_reservations->expects( $this->once() )->method( 'release_owned_attachment_pair' )->with( 1, 'direct-upload-owner', 101, 102, '2026/08/photo.jpg', 7, 'profile-7/2026/08/photo.jpg', 'attachment_deleted:51' )->willReturn( array( 'path_reservation_id' => 101, 'key_reservation_id' => 102 ) );

		$result = ( new ReservationCleanupService( $cleanup, $replay_reservations, $replay_objects, $replay_remote ) )->run();

		$this->assertSame( array( 'scanned' => 1, 'released' => 1, 'deferred' => 0 ), $result );
		$this->assertSame( array( 'remote_deleted', 'ready', 'complete' ), $cleanup->transitions );
		$this->assertSame( array( array( 7, 'profile-7/2026/08/photo.jpg', 'profile-fingerprint' ) ), $replay_remote->calls );
	}

	public function test_delete_does_not_use_profile_gateway_for_ordinary_local_attachment_when_legacy_settings_are_empty(): void {
		$id = 48;
		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_enabled' )->willReturn( true );
		$settings->method( 'should_delete_remote' )->willReturn( true );
		$settings->method( 'is_aws_configured' )->willReturn( false );
		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->expects( $this->never() )->method( 'delete_exact_profile_key' );
		$profile_ops->expects( $this->never() )->method( 'delete_locations' );
		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->never() )->method( 'delete_by_attachment' );

		$restorer = new AttachmentRestorer( $settings, $storage, null, null, $profile_ops );
		$this->inject_objects( $restorer, $objects );
		$restorer->on_delete_attachment( $id );
	}

	public function test_delete_fails_closed_for_invalid_direct_upload_evidence_when_legacy_settings_are_empty(): void {
		$id = 49;
		$this->seed_direct_upload_cleanup_evidence( $id );
		WpStubs::$post_meta[ $id ]['_s3ms_direct_upload_location'] = '{invalid';
		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_enabled' )->willReturn( true );
		$settings->method( 'should_delete_remote' )->willReturn( true );
		$settings->method( 'is_aws_configured' )->willReturn( false );
		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->expects( $this->never() )->method( 'delete_exact_profile_key' );
		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->never() )->method( 'delete_by_attachment' );

		$restorer = new AttachmentRestorer( $settings, $storage, null, null, $profile_ops );
		$this->inject_objects( $restorer, $objects );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'cleanup evidence is invalid' );
		$restorer->on_delete_attachment( $id );
	}

	public function test_delete_recovers_missing_binding_from_durable_finalization_intent_after_location_is_published(): void {
		$id = 52;
		$this->seed_finalization_intent_with_partial_cleanup_evidence( $id, 'location' );
		$profile_ops = $this->direct_upload_delete_profile_ops( 7, 'profile-7/2026/08/photo.jpg', 'profile-fingerprint' );
		$cleanup = new class {
			public int $records = 0;
			public function record( array $binding, string $proof ): array { ++$this->records; return array( 'id' => 52 ); }
			public function remote_deleted( int $id ): bool { return true; }
			public function ready( int $id ): bool { return true; }
			public function complete( int $id ): bool { return true; }
		};

		$this->restorer_for_durable_intent_delete( $profile_ops, $cleanup )->on_delete_attachment( $id );

		$this->assertSame( 1, $cleanup->records );
		$this->assertNotSame( '', get_post_meta( $id, '_s3ms_direct_upload_reservation_binding', true ) );
	}

	public function test_direct_upload_cleanup_is_not_blocked_by_legacy_offload_toggle(): void {
		$id = 57;
		$this->seed_finalization_intent_with_partial_cleanup_evidence( $id, 'location' );
		$profile_ops = $this->direct_upload_delete_profile_ops( 7, 'profile-7/2026/08/photo.jpg', 'profile-fingerprint' );
		$cleanup = new class {
			public function record( array $binding, string $proof ): array { return array( 'id' => 57 ); }
			public function remote_deleted( int $id ): bool { return true; }
			public function ready( int $id ): bool { return true; }
			public function complete( int $id ): bool { return true; }
		};

		$this->restorer_for_durable_intent_delete( $profile_ops, $cleanup, false )->on_delete_attachment( $id );
	}

	public function test_delete_recovers_missing_location_from_durable_finalization_intent_after_binding_is_published(): void {
		$id = 53;
		$this->seed_finalization_intent_with_partial_cleanup_evidence( $id, 'binding' );
		$profile_ops = $this->direct_upload_delete_profile_ops( 7, 'profile-7/2026/08/photo.jpg', 'profile-fingerprint' );
		$cleanup = new class {
			public int $records = 0;
			public function record( array $binding, string $proof ): array { ++$this->records; return array( 'id' => 53 ); }
			public function remote_deleted( int $id ): bool { return true; }
			public function ready( int $id ): bool { return true; }
			public function complete( int $id ): bool { return true; }
		};

		$this->restorer_for_durable_intent_delete( $profile_ops, $cleanup )->on_delete_attachment( $id );

		$this->assertSame( 1, $cleanup->records );
		$this->assertNotSame( '', get_post_meta( $id, '_s3ms_direct_upload_location', true ) );
	}

	public function test_delete_persists_cleanup_from_durable_intent_when_metadata_reconstruction_fails(): void {
		$id = 55;
		$this->seed_finalization_intent_with_partial_cleanup_evidence( $id, 'location' );
		WpStubs::$post_meta_add_failures[ $id ]['_s3ms_direct_upload_reservation_binding'] = true;
		$profile_ops = $this->direct_upload_delete_profile_ops( 7, 'profile-7/2026/08/photo.jpg', 'profile-fingerprint' );
		$cleanup = new class {
			public array $records = array();
			public function record( array $binding, string $proof ): array { $this->records[] = array( $binding, $proof ); return array( 'id' => 55 ); }
			public function remote_deleted( int $id ): bool { return true; }
			public function ready( int $id ): bool { return true; }
			public function complete( int $id ): bool { return true; }
		};

		$this->restorer_for_durable_intent_delete( $profile_ops, $cleanup )->on_delete_attachment( $id );

		$this->assertCount( 1, $cleanup->records );
		$this->assertSame( 'attachment_deleted:55', $cleanup->records[0][1] );
		$this->assertSame( 7, $cleanup->records[0][0]['cleanup_profile_id'] );
	}

	public function test_delete_rejects_conflicting_metadata_written_during_intent_reconstruction(): void {
		$id = 56;
		$this->seed_finalization_intent_with_partial_cleanup_evidence( $id, 'location' );
		WpStubs::$post_meta_add_conflicts[ $id ]['_s3ms_direct_upload_reservation_binding'] = '{"version":1,"owner_uuid":"conflict"}';
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->expects( $this->never() )->method( 'delete_exact_profile_key' );
		$cleanup = new class {
			public int $records = 0;
			public function record( array $binding, string $proof ): array { ++$this->records; return array( 'id' => 56 ); }
		};

		$wordpress_deleted_post_and_meta = false;
		try {
			$this->restorer_for_durable_intent_delete( $profile_ops, $cleanup )->on_delete_attachment( $id );
			// wp_delete_attachment() only reaches this point when every
			// delete_attachment callback returns normally.
			$wordpress_deleted_post_and_meta = true;
			unset( WpStubs::$posts[ $id ], WpStubs::$post_meta[ $id ] );
			$this->fail( 'A concurrent conflicting binding must veto WordPress attachment deletion.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'cleanup persistence failed', $error->getMessage() );
		}

		$this->assertFalse( $wordpress_deleted_post_and_meta );
		$this->assertSame( 0, $cleanup->records );
		$this->assertArrayHasKey( $id, WpStubs::$posts );
		$this->assertSame( '{"version":1,"owner_uuid":"conflict"}', get_post_meta( $id, '_s3ms_direct_upload_reservation_binding', true ) );
		$this->assertNotSame( '', get_post_meta( $id, '_s3ms_direct_upload_location', true ) );
	}

	public function test_delete_fails_closed_when_partial_cleanup_evidence_conflicts_with_durable_finalization_intent(): void {
		$id = 54;
		$this->seed_finalization_intent_with_partial_cleanup_evidence( $id, 'location' );
		$conflict = json_decode( (string) get_post_meta( $id, '_s3ms_direct_upload_location', true ), true, 16, JSON_THROW_ON_ERROR );
		$conflict['object_key'] = 'foreign/2026/08/photo.jpg';
		WpStubs::set_meta( $id, '_s3ms_direct_upload_location', wp_json_encode( $conflict ) );
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->expects( $this->never() )->method( 'delete_exact_profile_key' );
		$cleanup = new class {
			public int $records = 0;
			public function record( array $binding, string $proof ): array { ++$this->records; return array( 'id' => 54 ); }
		};

		try {
			$this->restorer_for_durable_intent_delete( $profile_ops, $cleanup )->on_delete_attachment( $id );
			$this->fail( 'A pre-existing conflicting partial metadata field must veto WordPress attachment deletion.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'cleanup metadata conflicts', $error->getMessage() );
		}

		$this->assertSame( 0, $cleanup->records );
		$this->assertSame( 'foreign/2026/08/photo.jpg', json_decode( (string) get_post_meta( $id, '_s3ms_direct_upload_location', true ), true, 16, JSON_THROW_ON_ERROR )['object_key'] );
		$this->assertArrayHasKey( $id, WpStubs::$posts, 'The veto preserves the remaining durable recovery evidence.' );
	}

	public function test_delete_vetoes_complete_but_malformed_direct_upload_evidence(): void {
		$id = 59;
		WpStubs::$posts[ $id ] = (object) array( 'ID' => $id, 'post_type' => 'attachment', 'post_name' => 'invalid-direct-upload-evidence' );
		WpStubs::set_meta( $id, '_s3ms_direct_upload_reservation_binding', '{not-json' );
		WpStubs::set_meta( $id, '_s3ms_direct_upload_location', '{also-not-json' );
		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_enabled' )->willReturn( true );
		$settings->method( 'should_delete_remote' )->willReturn( true );
		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );

		try {
			( new AttachmentRestorer( $settings, $storage ) )->on_delete_attachment( $id );
			$this->fail( 'Complete but malformed Direct Upload evidence must veto deletion.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'cleanup evidence is invalid', $error->getMessage() );
		}
		$this->assertArrayHasKey( $id, WpStubs::$posts );
		$this->assertSame( '{not-json', get_post_meta( $id, '_s3ms_direct_upload_reservation_binding', true ) );
		$this->assertSame( '{also-not-json', get_post_meta( $id, '_s3ms_direct_upload_location', true ) );
	}

	public function test_delete_aborts_when_finalization_intent_lookup_has_a_database_error(): void {
		$id = 57;
		WpStubs::$posts[ $id ] = (object) array( 'ID' => $id, 'post_type' => 'attachment', 'post_name' => 'lookup-failure-marker' );
		$previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$wpdb = new DirectUploadIntentLookupFailureWpdbFake();
		$GLOBALS['wpdb'] = $wpdb;
		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_enabled' )->willReturn( true );
		$settings->method( 'should_delete_remote' )->willReturn( true );
		$settings->method( 'is_aws_configured' )->willReturn( false );
		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		try {
			try {
				( new AttachmentRestorer( $settings, $storage ) )->on_delete_attachment( $id );
				$this->fail( 'A finalization-intent SQL read failure must abort deletion.' );
			} catch ( \RuntimeException $error ) {
				$this->assertStringContainsString( 'finalization intent lookup failed', $error->getMessage() );
				$this->assertSame( 'Earlier database diagnostic', $wpdb->last_error, 'The failed lookup must not leak over the surrounding wpdb diagnostic.' );
			}
		} finally {
			if ( $previous_wpdb === null ) unset( $GLOBALS['wpdb'] ); else $GLOBALS['wpdb'] = $previous_wpdb;
		}
	}

	public function test_delete_of_migrated_direct_upload_retires_only_the_original_verified_intent(): void {
		$id = 58;
		$marker = 'original-intent-' . $id;
		$binding = array( 'version' => 1, 'blog_id' => 1, 'owner_uuid' => 'durable-owner-' . $id, 'path_reservation_id' => 101, 'key_reservation_id' => 102, 'relative_path' => '2026/08/photo.jpg', 'profile_id' => 7, 'object_key' => 'source/2026/08/photo.jpg', 'direct_upload_session_uuid' => 'session-' . $id, 'direct_upload_attempt_uuid' => 'attempt-' . $id, 'direct_upload_attempt_generation' => 1, 'direct_upload_profile_fingerprint' => 'source-fingerprint' );
		$original_location = array( 'version' => 2, 'profile_id' => 7, 'object_key' => 'source/2026/08/photo.jpg', 'relative_path' => '2026/08/photo.jpg', 'location_fingerprint' => 'source-fingerprint' );
		$this->assertTrue( ( new \Kazcode\WpStorage\Infrastructure\DirectUploadFinalizationIntentRepository() )->record( $marker, $binding, $original_location ) );
		$this->assertSame( wp_json_encode( $binding ), ( new \Kazcode\WpStorage\Infrastructure\DirectUploadFinalizationIntentRepository() )->find( $marker )['binding_json'] ?? '' );
		WpStubs::$posts[ $id ] = (object) array( 'ID' => $id, 'post_type' => 'attachment', 'post_name' => $marker );
		WpStubs::set_meta( $id, '_s3ms_direct_upload_reservation_binding', wp_json_encode( $binding ) );
		WpStubs::set_meta( $id, '_s3ms_direct_upload_location', wp_json_encode( array( 'version' => 2, 'profile_id' => 8, 'object_key' => 'destination/2026/08/photo.jpg', 'relative_path' => '2026/08/photo.jpg', 'location_fingerprint' => 'destination-fingerprint' ) ) );
		$profile_ops = $this->direct_upload_delete_profile_ops( 8, 'destination/2026/08/photo.jpg', 'destination-fingerprint' );
		$cleanup = new class {
			public array $binding = array(); public string $marker = '';
			public function record( array $binding, string $proof ): array { $this->binding = $binding; return array( 'id' => 58 ); }
			public function remote_deleted( int $id ): bool { return true; }
			public function ready( int $id ): bool { return true; }
			public function complete_with_finalization_intent( int $id, array $binding, string $proof ): bool { $this->marker = (string) ( $binding['direct_upload_finalization_marker'] ?? '' ); return true; }
		};

		$this->restorer_for_durable_intent_delete( $profile_ops, $cleanup )->on_delete_attachment( $id );

		$this->assertSame( $marker, $cleanup->binding['direct_upload_finalization_marker'] ?? '' );
		$this->assertSame( wp_json_encode( $original_location ), $cleanup->binding['direct_upload_finalization_intent_location'] ?? '' );
	}

	private function seed_attachment_with_thumbnail( int $id ): void {
		WpStubs::$post_meta[ $id ]['_wp_attached_file']       = '2026/08/photo.jpg';
		WpStubs::$post_meta[ $id ]['_s3ms_status']            = 'offloaded';
		WpStubs::$post_meta[ $id ]['_s3ms_original_key']      = 'uploads/2026/08/photo.jpg';
		WpStubs::$post_meta[ $id ]['_wp_attachment_metadata'] = array(
			'file'  => '2026/08/photo.jpg',
			'sizes' => array(
				'thumbnail' => array( 'file' => 'photo-150x150.jpg' ),
			),
		);
	}

	private function seed_direct_upload_cleanup_evidence( int $id ): void {
		WpStubs::$post_meta[ $id ]['_s3ms_direct_upload_reservation_binding'] = wp_json_encode(
			array(
				'version'              => 1,
				'blog_id'              => 1,
				'owner_uuid'           => 'direct-upload-owner',
				'path_reservation_id'  => 101,
				'key_reservation_id'   => 102,
				'relative_path'        => '2026/08/photo.jpg',
				'profile_id'           => 7,
				'object_key'           => 'profile-7/2026/08/photo.jpg',
			)
		);
		WpStubs::$post_meta[ $id ]['_s3ms_direct_upload_location'] = wp_json_encode(
			array(
				'version'              => 2,
				'profile_id'           => 7,
				'object_key'           => 'profile-7/2026/08/photo.jpg',
				'relative_path'        => '2026/08/photo.jpg',
				'location_fingerprint' => 'profile-fingerprint',
			)
		);
	}

	private function seed_finalization_intent_with_partial_cleanup_evidence( int $id, string $published ): void {
		$marker = 'direct-upload-intent-' . $id;
		$binding = array( 'version' => 1, 'blog_id' => 1, 'owner_uuid' => 'durable-owner-' . $id, 'path_reservation_id' => 101, 'key_reservation_id' => 102, 'relative_path' => '2026/08/photo.jpg', 'profile_id' => 7, 'object_key' => 'profile-7/2026/08/photo.jpg', 'direct_upload_session_uuid' => 'session-' . $id, 'direct_upload_attempt_uuid' => 'attempt-' . $id, 'direct_upload_attempt_generation' => 1, 'direct_upload_profile_fingerprint' => 'profile-fingerprint' );
		$location = array( 'version' => 2, 'profile_id' => 7, 'object_key' => 'profile-7/2026/08/photo.jpg', 'relative_path' => '2026/08/photo.jpg', 'location_fingerprint' => 'profile-fingerprint' );
		$this->assertTrue( ( new \Kazcode\WpStorage\Infrastructure\DirectUploadFinalizationIntentRepository() )->record( $marker, $binding, $location ) );
		WpStubs::$posts[ $id ] = (object) array( 'ID' => $id, 'post_type' => 'attachment', 'post_name' => $marker );
		WpStubs::set_meta( $id, $published === 'location' ? '_s3ms_direct_upload_location' : '_s3ms_direct_upload_reservation_binding', wp_json_encode( $published === 'location' ? $location : $binding ) );
	}

	private function direct_upload_delete_profile_ops( int $profile_id, string $key, string $fingerprint ): ProfileAwareObjectOperations {
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->expects( $this->once() )->method( 'delete_exact_profile_key' )->with( $profile_id, $key, $fingerprint )->willReturn( true );
		return $profile_ops;
	}

	private function restorer_for_durable_intent_delete( ProfileAwareObjectOperations $profile_ops, object $cleanup, bool $enabled = true ): AttachmentRestorer {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_enabled' )->willReturn( $enabled );
		$settings->method( 'should_delete_remote' )->willReturn( true );
		$settings->method( 'is_aws_configured' )->willReturn( false );
		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->method( 'release_owned_attachment_pair' )->willReturn( array( 'state' => 'released' ) );
		return new AttachmentRestorer( $settings, $storage, null, null, $profile_ops, null, $reservations, $cleanup );
	}

	private function inject_objects( AttachmentRestorer $restorer, ObjectRepository $objects ): void {
		$ref  = new \ReflectionClass( AttachmentRestorer::class );
		$prop = $ref->getProperty( 'objects' );
		$prop->setValue( $restorer, $objects );
	}

	private function rmTree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}
}

final class MigrationFenceCompletionWpdbFake {
	public string $prefix = 'wp_';
	public function prepare( string $query, mixed ...$args ): string { return $query; }
	public function query( string $query ): int|false { return 1; }
}

final class DirectUploadIntentLookupFailureWpdbFake {
	public string $prefix = 'wp_';
	public string $last_error = 'Earlier database diagnostic';
	public function prepare( string $query, ...$args ): string { return $query; }
	public function query( string $query ): int { return 0; }
	public function get_row( string $query, $output ): ?array { $this->last_error = 'Injected finalization intent SELECT failure'; return null; }
}
