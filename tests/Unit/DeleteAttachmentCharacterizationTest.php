<?php
/**
 * Characterization: delete_attachment remote key discovery.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Attachment\AttachmentRestorer;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Infrastructure\AttachmentLock;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\ReservationRepository;
use Kazcode\WpStorage\Services\ProfileAwareObjectOperations;
use Kazcode\WpStorage\Services\RemoteDeleteSafetyGuard;
use Kazcode\WpStorage\Services\ReservationCleanupService;
use Kazcode\WpStorage\Storage\S3KeyResolver;
use Kazcode\WpStorage\Storage\S3Storage;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class DeleteAttachmentCharacterizationTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		remove_all_filters( 'kazus_direct_upload_cleanup_retire_pre_ready_session' );
		WpStubs::reset();
	}

	public function test_delete_uses_guard_locations_not_original_key_inventory(): void {
		$settings = $this->createMock(Settings::class);
		$settings->method('is_enabled')->willReturn(true);
		$settings->method('should_delete_remote')->willReturn(true);
		$settings->method('is_aws_configured')->willReturn(true);
		$settings->method('get')->willReturnCallback(
			static function (string $key, mixed $default = null): mixed {
				$map = array( 'object_prefix' => 'wordpress/' );
				return $map[ $key ] ?? $default;
			}
		);

		$keys     = new S3KeyResolver($settings);
		$storage  = $this->createMock(S3Storage::class);
		$storage->method('keys')->willReturn($keys);
		$storage->expects($this->never())->method('delete_relatives');

		WpStubs::set_meta(99, '_s3ms_status', 'offloaded');
		WpStubs::set_meta(99, '_s3ms_original_key', 'wordpress/2026/08/ONLY-ORIGINAL.jpg');
		WpStubs::set_meta(99, '_wp_attached_file', '2026/08/photo.jpg');
		WpStubs::set_meta(
			99,
			'_wp_attachment_metadata',
			array(
				'file'  => '2026/08/photo.jpg',
				'sizes' => array(
					'thumbnail' => array( 'file' => 'photo-150x150.jpg' ),
					'medium'    => array( 'file' => 'photo-300x200.jpg' ),
				),
			)
		);

		$locations = array( 'resolved-profile-location' );
		$guard     = $this->createMock( RemoteDeleteSafetyGuard::class );
		$guard->method( 'evaluate' )->with( 99 )->willReturn(
			array(
				'status'    => RemoteDeleteSafetyGuard::SAFE_TO_DELETE,
				'reason'    => 'test',
				'keys'      => array( 'profile-bound-key' ),
				'locations' => $locations,
			)
		);
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->expects( $this->once() )->method( 'delete_locations' )->with( $locations );

		$restorer = new AttachmentRestorer($settings, $storage, $guard, null, $profile_ops);
		$restorer->on_delete_attachment(99);
	}

	public function test_delete_skipped_when_no_s3_meta(): void {
		$settings = $this->createMock(Settings::class);
		$settings->method('is_enabled')->willReturn(true);
		$settings->method('should_delete_remote')->willReturn(true);
		$settings->method('is_aws_configured')->willReturn(true);

		$storage = $this->createMock(S3Storage::class);
		$storage->expects($this->never())->method('delete_relatives');
		$storage->method('keys')->willReturn(new S3KeyResolver($settings));

		$restorer = new AttachmentRestorer($settings, $storage);
		$restorer->on_delete_attachment(1);
	}

	public function test_successful_remote_delete_also_clears_object_inventory_rows(): void {
		// Regression: wp_posts has no FK/cascade into s3ms_objects. Skipping this
		// cleanup left orphaned "present" rows behind forever, which both poisoned
		// Health/stats and permanently blocked the default profile's location sync
		// (count_by_profile() never returning to 0) after any attachment was ever
		// deleted.
		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_enabled' )->willReturn( true );
		$settings->method( 'should_delete_remote' )->willReturn( true );
		$settings->method( 'is_aws_configured' )->willReturn( true );

		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		$storage->expects( $this->never() )->method( 'delete_relatives' );

		WpStubs::set_meta( 7, '_s3ms_status', 'offloaded' );
		WpStubs::set_meta( 7, '_s3ms_original_key', '2026/08/photo.jpg' );
		WpStubs::set_meta( 7, '_wp_attached_file', '2026/08/photo.jpg' );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )->method( 'delete_by_attachment' )->with( 7 );

		$locations = array( 'resolved-profile-location' );
		$guard     = $this->createMock( RemoteDeleteSafetyGuard::class );
		$guard->method( 'evaluate' )->with( 7 )->willReturn(
			array(
				'status'    => RemoteDeleteSafetyGuard::SAFE_TO_DELETE,
				'reason'    => 'test',
				'keys'      => array( 'profile-bound-key' ),
				'locations' => $locations,
			)
		);
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->expects( $this->once() )->method( 'delete_locations' )->with( $locations );

		$restorer = new AttachmentRestorer( $settings, $storage, $guard, null, $profile_ops );
		$ref      = new \ReflectionClass( AttachmentRestorer::class );
		$prop     = $ref->getProperty( 'objects' );
		$prop->setAccessible( true );
		$prop->setValue( $restorer, $objects );

		$restorer->on_delete_attachment( 7 );
	}

	public function test_successful_direct_upload_delete_releases_only_its_durably_bound_owned_reservations_after_inventory_cleanup(): void {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_enabled' )->willReturn( true );
		$settings->method( 'should_delete_remote' )->willReturn( true );
		$settings->method( 'is_aws_configured' )->willReturn( true );
		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		WpStubs::set_meta( 73, '_s3ms_status', 'offloaded' );
		WpStubs::set_meta( 73, '_s3ms_original_key', 'private/direct-upload/session-1/archive.zip' );
		WpStubs::set_meta( 73, '_wp_attached_file', '2026/10/archive.zip' );
		WpStubs::set_meta( 73, '_s3ms_direct_upload_location', wp_json_encode( array( 'version' => 2, 'profile_id' => 9, 'object_key' => 'private/direct-upload/session-1/archive.zip', 'relative_path' => '2026/10/archive.zip', 'location_fingerprint' => 'profile-fingerprint-1' ) ) );
		WpStubs::set_meta( 73, '_s3ms_direct_upload_reservation_binding', wp_json_encode( array( 'version' => 1, 'blog_id' => 1, 'owner_uuid' => 'session-1', 'path_reservation_id' => 7, 'key_reservation_id' => 8, 'relative_path' => '2026/10/archive.zip', 'profile_id' => 9, 'object_key' => 'private/direct-upload/session-1/archive.zip' ) ) );
		$guard = $this->createMock( RemoteDeleteSafetyGuard::class );
		$guard->expects( $this->never() )->method( 'evaluate' );
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->expects( $this->once() )->method( 'delete_exact_profile_key' )->with( 9, 'private/direct-upload/session-1/archive.zip', 'profile-fingerprint-1' )->willReturn( true );
		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )->method( 'delete_by_attachment' )->with( 73 );
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->expects( $this->once() )->method( 'release_owned_attachment_pair' )->with( 1, 'session-1', 7, 8, '2026/10/archive.zip', 9, 'private/direct-upload/session-1/archive.zip', 'attachment_deleted:73' )->willReturn( array( 'id' => 7 ) );

		$cleanup = new class {
			public array $ready = array();
			public function record( array $binding, string $proof ) { return array( 'id' => 1 ); }
			public function remote_deleted( int $id ): bool { return true; }
			public function ready( int $id ): bool { $this->ready[] = $id; return true; }
			public function complete( int $id ): bool { return true; }
		};
		$restorer = new AttachmentRestorer( $settings, $storage, $guard, null, $profile_ops, null, $reservations, $cleanup );
		$ref = new \ReflectionClass( AttachmentRestorer::class );
		$prop = $ref->getProperty( 'objects' );
		$prop->setAccessible( true );
		$prop->setValue( $restorer, $objects );
		$restorer->on_delete_attachment( 73 );
		$this->assertSame( array( 1 ), $cleanup->ready );
	}

	public function test_restored_direct_upload_delete_uses_its_durable_exact_location_and_releases_only_its_original_owned_pair(): void {
		$settings = $this->delete_settings();
		$storage = $this->delete_storage( $settings );
		// Finalization persists the exact delivery location and original reservation
		// binding. A successful local restore intentionally removes these ordinary
		// offload markers and inventory rows, while retaining both immutable records.
		$this->direct_upload_meta( 78, 'session-6', 57, 58 );
		delete_post_meta( 78, '_s3ms_status' );
		delete_post_meta( 78, '_s3ms_original_key' );
		$guard = $this->createMock( RemoteDeleteSafetyGuard::class );
		$guard->expects( $this->never() )->method( 'evaluate' );
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->expects( $this->once() )->method( 'delete_exact_profile_key' )->with( 9, 'private/direct-upload/session-6/archive.zip', 'profile-fingerprint-session-6' )->willReturn( true );
		$profile_ops->expects( $this->never() )->method( 'delete_locations' );
		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )->method( 'delete_by_attachment' )->with( 78 );
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->expects( $this->once() )->method( 'release_owned_attachment_pair' )->with( 1, 'session-6', 57, 58, '2026/10/archive.zip', 9, 'private/direct-upload/session-6/archive.zip', 'attachment_deleted:78' )->willReturn( array( 'id' => 57 ) );
		$cleanup = new class {
			public array $recorded = array();
			public array $remote_deleted = array();
			public array $ready = array();
			public function record( array $binding, string $proof ) { $this->recorded = array( $binding, $proof ); return array( 'id' => 10 ); }
			public function remote_deleted( int $id ): bool { $this->remote_deleted[] = $id; return true; }
			public function ready( int $id ): bool { $this->ready[] = $id; return true; }
			public function complete( int $id ): bool { return true; }
		};

		$this->restorer_with( $settings, $storage, $guard, $profile_ops, $objects, $reservations, $cleanup )->on_delete_attachment( 78 );

		$this->assertSame( 9, $cleanup->recorded[0]['cleanup_profile_id'] );
		$this->assertSame( 'private/direct-upload/session-6/archive.zip', $cleanup->recorded[0]['cleanup_object_key'] );
		$this->assertSame( array( 10 ), $cleanup->remote_deleted );
		$this->assertSame( array( 10 ), $cleanup->ready );
	}

	public function test_synchronous_pre_ready_delete_releases_the_exact_pinned_pair_only_after_verified_remote_and_inventory_cleanup(): void {
		$settings = $this->delete_settings();
		$storage = $this->delete_storage( $settings );
		$this->direct_upload_meta( 80, 'session-15', 131, 132 );
		$guard = $this->createMock( RemoteDeleteSafetyGuard::class );
		$guard->expects( $this->never() )->method( 'evaluate' );
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->expects( $this->once() )->method( 'delete_exact_profile_key' )->with( 9, 'private/direct-upload/session-15/archive.zip', 'profile-fingerprint-session-15' )->willReturn( true );
		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )->method( 'delete_by_attachment' )->with( 80 );
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->expects( $this->once() )->method( 'release_owned_attachment_pair' )
			->with( 1, 'session-15', 131, 132, '2026/10/archive.zip', 9, 'private/direct-upload/session-15/archive.zip', 'attachment_deleted:80' )
			->willReturn( new \WP_Error( 'reservation_attachment_release_lost', 'Pair remains pinned before READY.' ) );
		$reservations->expects( $this->once() )->method( 'inspect_pre_ready_pinned_attachment_pair' )->willReturn( array( 'status' => 'pinned' ) );
		$reservations->expects( $this->once() )->method( 'release_pre_ready_pinned_attachment_pair' )
			->with( 1, 'session-15', 131, 132, '2026/10/archive.zip', 9, 'private/direct-upload/session-15/archive.zip', 'attachment_deleted:80' )
			->willReturn( array( 'path_reservation_id' => 131, 'key_reservation_id' => 132 ) );
		$cleanup = new class {
			public array $remote_deleted = array();
			public array $ready = array();
			public array $completed = array();
			public array $required = array();
			public function record( array $binding, string $proof ) { return array( 'id' => 15 ); }
			public function remote_deleted( int $id ): bool { $this->remote_deleted[] = $id; return true; }
			public function ready( int $id ): bool { $this->ready[] = $id; return true; }
			public function require_pre_ready_retirement( int $id, array $binding, string $proof ): bool { $this->required[] = array( $id, $binding, $proof ); return true; }
			public function complete( int $id ): bool { $this->completed[] = $id; return true; }
		};
		add_filter( 'kazus_direct_upload_cleanup_retire_pre_ready_session', static fn( bool $retired, array $binding, string $proof ): bool => $binding['requires_pre_ready_session_retirement'] === true && $proof === 'attachment_deleted:80', 10, 3 );

		$this->restorer_with( $settings, $storage, $guard, $profile_ops, $objects, $reservations, $cleanup )->on_delete_attachment( 80 );

		$this->assertSame( array( 15 ), $cleanup->remote_deleted );
		$this->assertSame( array( 15 ), $cleanup->ready );
		$this->assertSame( array( 15, false, 'attachment_deleted:80' ), array( $cleanup->required[0][0], $cleanup->required[0][1]['requires_pre_ready_session_retirement'] ?? false, $cleanup->required[0][2] ) );
		$this->assertSame( array( 15 ), $cleanup->completed );
	}

	public function test_synchronous_pre_ready_delete_keeps_the_durable_cleanup_when_pro_is_unavailable(): void {
		$settings = $this->delete_settings();
		$storage = $this->delete_storage( $settings );
		$this->direct_upload_meta( 82, 'session-17', 151, 152 );
		$guard = $this->createMock( RemoteDeleteSafetyGuard::class );
		$guard->expects( $this->never() )->method( 'evaluate' );
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->expects( $this->once() )->method( 'delete_exact_profile_key' )->willReturn( true );
		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )->method( 'delete_by_attachment' )->with( 82 );
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->expects( $this->once() )->method( 'release_owned_attachment_pair' )->willReturn( new \WP_Error( 'still_pinned', 'Still pinned.' ) );
		$reservations->expects( $this->once() )->method( 'inspect_pre_ready_pinned_attachment_pair' )->willReturn( array( 'status' => 'pinned' ) );
		$reservations->expects( $this->once() )->method( 'release_pre_ready_pinned_attachment_pair' )->willReturn( array( 'path_reservation_id' => 151, 'key_reservation_id' => 152 ) );
		$cleanup = new class {
			public array $required = array(); public array $completed = array();
			public function record( array $binding, string $proof ) { return array( 'id' => 17 ); }
			public function remote_deleted( int $id ): bool { return true; }
			public function ready( int $id ): bool { return true; }
			public function require_pre_ready_retirement( int $id, array $binding, string $proof ): bool { $this->required[] = array( $id, $binding, $proof ); return true; }
			public function complete( int $id ): bool { $this->completed[] = $id; return true; }
		};

		$this->restorer_with( $settings, $storage, $guard, $profile_ops, $objects, $reservations, $cleanup )->on_delete_attachment( 82 );

		$this->assertCount( 1, $cleanup->required );
		$this->assertSame( array(), $cleanup->completed );
	}

	public function test_ordinary_local_delete_without_direct_upload_evidence_never_invokes_remote_cleanup(): void {
		$settings = $this->delete_settings();
		$storage = $this->delete_storage( $settings );
		WpStubs::set_meta( 79, '_wp_attached_file', '2026/10/local.txt' );
		$guard = $this->createMock( RemoteDeleteSafetyGuard::class );
		$guard->expects( $this->never() )->method( 'evaluate' );
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->expects( $this->never() )->method( 'delete_exact_profile_key' );
		$profile_ops->expects( $this->never() )->method( 'delete_locations' );
		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->never() )->method( 'delete_by_attachment' );
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->expects( $this->never() )->method( 'release_owned_attachment_pair' );
		$cleanup = new class { public function record( array $binding, string $proof ) { throw new \RuntimeException( 'must not persist cleanup' ); } };

		$this->restorer_with( $settings, $storage, $guard, $profile_ops, $objects, $reservations, $cleanup )->on_delete_attachment( 79 );
	}

	public function test_direct_upload_delete_fails_closed_when_cleanup_intent_cannot_be_persisted(): void {
		$settings = $this->delete_settings();
		$storage = $this->delete_storage( $settings );
		$this->direct_upload_meta( 75, 'session-3', 27, 28 );
		$guard = $this->safe_delete_guard( 75 );
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->expects( $this->never() )->method( 'delete_locations' );
		$profile_ops->expects( $this->once() )->method( 'delete_exact_profile_key' )->with( 9, 'private/direct-upload/session-3/archive.zip', 'profile-fingerprint-session-3' )->willReturn( true );
		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )->method( 'delete_by_attachment' )->with( 75 );
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->expects( $this->once() )->method( 'release_owned_attachment_pair' )->willReturn( array( 'state' => 'released' ) );
		$cleanup = new class {
			public int $attempts = 0;
			public function record( array $binding, string $proof ) {
				++$this->attempts;
				return $this->attempts === 1 ? new \WP_Error( 'write_failed', 'failed' ) : array( 'id' => 75 );
			}
			public function remote_deleted( int $id ): bool { return true; }
			public function ready( int $id ): bool { return true; }
			public function complete( int $id ): bool { return true; }
		};

		$restorer = $this->restorer_with( $settings, $storage, $guard, $profile_ops, $objects, $reservations, $cleanup );
		try {
			$restorer->on_delete_attachment( 75 );
			$this->fail( 'A failed durable cleanup write must abort attachment deletion.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'cleanup persistence failed', $error->getMessage() );
		}
		$this->assertNotSame( '', get_post_meta( 75, '_s3ms_direct_upload_reservation_binding', true ) );
		$this->assertNotSame( '', get_post_meta( 75, '_s3ms_direct_upload_location', true ) );

		$restorer->on_delete_attachment( 75 );
		$this->assertSame( 2, $cleanup->attempts, 'A later WordPress deletion retry must be able to establish cleanup authority.' );
	}

	public function test_direct_upload_remote_failure_retains_intent_without_ready_transition_or_release(): void {
		$settings = $this->delete_settings();
		$storage = $this->delete_storage( $settings );
		$this->direct_upload_meta( 76, 'session-4', 37, 38 );
		$guard = $this->safe_delete_guard( 76 );
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->expects( $this->once() )->method( 'delete_exact_profile_key' )->with( 9, 'private/direct-upload/session-4/archive.zip', 'profile-fingerprint-session-4' )->willReturn( false );
		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->never() )->method( 'delete_by_attachment' );
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->expects( $this->never() )->method( 'release_owned_attachment_pair' );
		$cleanup = new class { public array $recorded = array(); public array $ready = array(); public function record( array $binding, string $proof ) { $this->recorded = array( $binding, $proof ); return array( 'id' => 8 ); } public function ready( int $id ): bool { $this->ready[] = $id; return true; } };

		$this->restorer_with( $settings, $storage, $guard, $profile_ops, $objects, $reservations, $cleanup )->on_delete_attachment( 76 );
		$this->assertSame( 37, $cleanup->recorded[0]['path_reservation_id'] );
		$this->assertSame( array(), $cleanup->ready );
	}

	public function test_failed_ready_transition_after_remote_and_inventory_cleanup_replays_safely_on_the_scheduler(): void {
		$settings = $this->delete_settings();
		$storage = $this->delete_storage( $settings );
		$this->direct_upload_meta( 77, 'session-5', 47, 48 );
		$guard = $this->safe_delete_guard( 77 );
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->expects( $this->once() )->method( 'delete_exact_profile_key' )->with( 9, 'private/direct-upload/session-5/archive.zip', 'profile-fingerprint-session-5' )->willReturn( true );
		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->exactly( 2 ) )->method( 'delete_by_attachment' )->with( 77 );
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->expects( $this->once() )->method( 'release_owned_attachment_pair' )->with( 1, 'session-5', 47, 48, '2026/10/archive.zip', 9, 'private/direct-upload/session-5/archive.zip', 'attachment_deleted:77' )->willReturn( array( 'path_reservation_id' => 47 ) );
		$cleanup = new class {
			public array $binding = array();
			public array $remote_deleted = array();
			public array $ready = array();
			public array $completed = array();
			public function record( array $binding, string $proof ) { $this->binding = $binding; return array( 'id' => 9 ); }
			public function remote_deleted( int $id ): bool { $this->remote_deleted[] = $id; return true; }
			public function ready( int $id ): bool { $this->ready[] = $id; return count( $this->ready ) > 1; }
			public function pending( int $limit ): array { return array( array( 'id' => 9, 'state' => 'remote_deleted', 'binding_json' => wp_json_encode( $this->binding ), 'settlement_proof' => 'attachment_deleted:77' ) ); }
			public function complete( int $id ): bool { $this->completed[] = $id; return true; }
		};

		$this->restorer_with( $settings, $storage, $guard, $profile_ops, $objects, $reservations, $cleanup )->on_delete_attachment( 77 );
		$result = ( new ReservationCleanupService( $cleanup, $reservations, $objects ) )->run();

		$this->assertSame( array( 9 ), $cleanup->remote_deleted );
		$this->assertSame( array( 9, 9 ), $cleanup->ready );
		$this->assertSame( array( 9 ), $cleanup->completed );
		$this->assertSame( array( 'scanned' => 1, 'released' => 1, 'deferred' => 0 ), $result );
	}

	public function test_direct_upload_delete_durably_records_exact_cleanup_before_a_failed_reservation_release(): void {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_enabled' )->willReturn( true );
		$settings->method( 'should_delete_remote' )->willReturn( true );
		$settings->method( 'is_aws_configured' )->willReturn( true );
		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		WpStubs::set_meta( 74, '_s3ms_status', 'offloaded' );
		WpStubs::set_meta( 74, '_s3ms_original_key', 'private/direct-upload/session-2/archive.zip' );
		WpStubs::set_meta( 74, '_wp_attached_file', '2026/10/archive.zip' );
		WpStubs::set_meta( 74, '_s3ms_direct_upload_location', wp_json_encode( array( 'version' => 2, 'profile_id' => 9, 'object_key' => 'private/direct-upload/session-2/archive.zip', 'relative_path' => '2026/10/archive.zip', 'location_fingerprint' => 'profile-fingerprint-2' ) ) );
		WpStubs::set_meta( 74, '_s3ms_direct_upload_reservation_binding', wp_json_encode( array( 'version' => 1, 'blog_id' => 1, 'owner_uuid' => 'session-2', 'path_reservation_id' => 17, 'key_reservation_id' => 18, 'relative_path' => '2026/10/archive.zip', 'profile_id' => 9, 'object_key' => 'private/direct-upload/session-2/archive.zip', 'direct_upload_session_uuid' => 'session-2', 'direct_upload_attempt_uuid' => 'attempt-session-2', 'direct_upload_attempt_generation' => 1, 'direct_upload_profile_fingerprint' => 'profile-fingerprint-2' ) ) );
		$guard = $this->createMock( RemoteDeleteSafetyGuard::class );
		$guard->method( 'evaluate' )->willReturn( array( 'status' => RemoteDeleteSafetyGuard::SAFE_TO_DELETE, 'locations' => array( 'location' ) ) );
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->expects( $this->once() )->method( 'delete_exact_profile_key' )->with( 9, 'private/direct-upload/session-2/archive.zip', 'profile-fingerprint-2' )->willReturn( true );
		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )->method( 'delete_by_attachment' )->with( 74 );
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->expects( $this->once() )->method( 'release_owned_attachment_pair' )->willReturn( new \WP_Error( 'release_failed', 'failed' ) );
		$reservations->expects( $this->once() )->method( 'inspect_pre_ready_pinned_attachment_pair' )->willReturn( array( 'status' => 'not_pinned' ) );
		$reservations->expects( $this->never() )->method( 'release_pre_ready_pinned_attachment_pair' );
		$cleanup = new class {
			public array $recorded = array();
			public function record( array $binding, string $proof ) { $this->recorded = array( $binding, $proof ); return array( 'id' => 1 ); }
			public function remote_deleted( int $id ): bool { return true; }
			public function ready( int $id ): bool { return true; }
			public function require_pre_ready_retirement( int $id, array $binding, string $proof ): bool { return true; }
			public function complete( int $id ): bool { return true; }
		};

		$restorer = new AttachmentRestorer( $settings, $storage, $guard, null, $profile_ops, null, $reservations, $cleanup );
		$ref = new \ReflectionClass( AttachmentRestorer::class );
		$prop = $ref->getProperty( 'objects' );
		$prop->setAccessible( true );
		$prop->setValue( $restorer, $objects );
		$restorer->on_delete_attachment( 74 );

		$this->assertSame( 17, $cleanup->recorded[0]['path_reservation_id'] );
		$this->assertSame( 'attachment_deleted:74', $cleanup->recorded[1] );
	}

	public function test_remote_delete_is_skipped_when_attachment_lock_is_busy(): void {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_enabled' )->willReturn( true );
		$settings->method( 'should_delete_remote' )->willReturn( true );
		$settings->method( 'is_aws_configured' )->willReturn( true );

		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		$storage->expects( $this->never() )->method( 'delete_relatives' );

		WpStubs::set_meta( 17, '_s3ms_status', 'offloaded' );
		WpStubs::set_meta( 17, '_s3ms_original_key', '2026/08/photo.jpg' );
		WpStubs::set_meta( 17, '_wp_attached_file', '2026/08/photo.jpg' );

		$this->assertNotNull( ( new AttachmentLock() )->acquire_lease( 17, 'restore' ) );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->never() )->method( 'delete_by_attachment' );

		$restorer = new AttachmentRestorer( $settings, $storage );
		$ref      = new \ReflectionClass( AttachmentRestorer::class );
		$prop     = $ref->getProperty( 'objects' );
		$prop->setValue( $restorer, $objects );

		$restorer->on_delete_attachment( 17 );
	}

	public function test_object_rows_kept_when_remote_delete_disabled(): void {
		// If the admin chose to keep remote objects on attachment delete, the
		// s3ms_objects rows still describe real remote objects — do not delete them.
		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_enabled' )->willReturn( true );
		$settings->method( 'should_delete_remote' )->willReturn( false );

		$storage = $this->createMock( S3Storage::class );
		$storage->expects( $this->never() )->method( 'delete_relatives' );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->never() )->method( 'delete_by_attachment' );

		$restorer = new AttachmentRestorer( $settings, $storage );
		$ref      = new \ReflectionClass( AttachmentRestorer::class );
		$prop     = $ref->getProperty( 'objects' );
		$prop->setAccessible( true );
		$prop->setValue( $restorer, $objects );

		$restorer->on_delete_attachment( 8 );
	}

	private function delete_settings(): Settings {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_enabled' )->willReturn( true );
		$settings->method( 'should_delete_remote' )->willReturn( true );
		$settings->method( 'is_aws_configured' )->willReturn( true );
		return $settings;
	}

	private function delete_storage( Settings $settings ): S3Storage {
		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		return $storage;
	}

	private function direct_upload_meta( int $attachment_id, string $owner, int $path_id, int $key_id ): void {
		WpStubs::set_meta( $attachment_id, '_s3ms_status', 'offloaded' );
		WpStubs::set_meta( $attachment_id, '_s3ms_original_key', 'private/direct-upload/' . $owner . '/archive.zip' );
		WpStubs::set_meta( $attachment_id, '_wp_attached_file', '2026/10/archive.zip' );
		WpStubs::set_meta( $attachment_id, '_s3ms_direct_upload_location', wp_json_encode( array( 'version' => 2, 'profile_id' => 9, 'object_key' => 'private/direct-upload/' . $owner . '/archive.zip', 'relative_path' => '2026/10/archive.zip', 'location_fingerprint' => 'profile-fingerprint-' . $owner ) ) );
		WpStubs::set_meta( $attachment_id, '_s3ms_direct_upload_reservation_binding', wp_json_encode( array( 'version' => 1, 'blog_id' => 1, 'owner_uuid' => $owner, 'path_reservation_id' => $path_id, 'key_reservation_id' => $key_id, 'relative_path' => '2026/10/archive.zip', 'profile_id' => 9, 'object_key' => 'private/direct-upload/' . $owner . '/archive.zip', 'direct_upload_session_uuid' => $owner, 'direct_upload_attempt_uuid' => 'attempt-' . $owner, 'direct_upload_attempt_generation' => 1, 'direct_upload_profile_fingerprint' => 'profile-fingerprint-' . $owner ) ) );
	}

	private function safe_delete_guard( int $attachment_id ): RemoteDeleteSafetyGuard {
		$guard = $this->createMock( RemoteDeleteSafetyGuard::class );
		$guard->method( 'evaluate' )->with( $attachment_id )->willReturn( array( 'status' => RemoteDeleteSafetyGuard::SAFE_TO_DELETE, 'locations' => array( 'location' ) ) );
		return $guard;
	}

	private function restorer_with( Settings $settings, S3Storage $storage, RemoteDeleteSafetyGuard $guard, ProfileAwareObjectOperations $profile_ops, ObjectRepository $objects, ReservationRepository $reservations, object $cleanup ): AttachmentRestorer {
		$restorer = new AttachmentRestorer( $settings, $storage, $guard, null, $profile_ops, null, $reservations, $cleanup );
		$reflection = new \ReflectionClass( AttachmentRestorer::class );
		$property = $reflection->getProperty( 'objects' );
		$property->setAccessible( true );
		$property->setValue( $restorer, $objects );
		return $restorer;
	}
}
