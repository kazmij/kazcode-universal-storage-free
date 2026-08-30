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
use Kazcode\WpStorage\Services\AuditLog;
use Kazcode\WpStorage\Services\ProfileAwareObjectOperations;
use Kazcode\WpStorage\Services\ProfileObjectLocation;
use Kazcode\WpStorage\Services\RemoteDeleteSafetyGuard;
use Kazcode\WpStorage\Storage\S3KeyResolver;
use Kazcode\WpStorage\Storage\S3Storage;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class DeleteAttachmentCharacterizationTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_delete_uses_guard_approved_physical_keys_not_metadata_relatives(): void {
		$settings = $this->createMock(Settings::class);
		$settings->method('is_enabled')->willReturn(true);
		$settings->method('should_delete_remote')->willReturn(true);
		$settings->method('is_aws_configured')->willReturn(true);

		$captured = array();
		$storage  = $this->createMock(S3Storage::class);
		$storage->method('keys')->willReturn(new S3KeyResolver($settings));
		$storage->expects($this->never())->method('delete_relatives');
		$storage->expects($this->never())->method('delete_keys');

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

		$guard = $this->createMock( RemoteDeleteSafetyGuard::class );
		$locations = array(
			ProfileObjectLocation::found( array(), $this->profile( 2 ), 'profile-two/2026/08/photo.jpg', '2026/08/photo.jpg' ),
			ProfileObjectLocation::found( array(), $this->profile( 2 ), 'profile-two/2026/08/photo-150x150.jpg', '2026/08/photo-150x150.jpg' ),
			ProfileObjectLocation::found( array(), $this->profile( 2 ), 'profile-two/2026/08/photo-300x200.jpg', '2026/08/photo-300x200.jpg' ),
		);
		$guard->method( 'evaluate' )->with( 99 )->willReturn(
			array(
				'status'    => RemoteDeleteSafetyGuard::SAFE_TO_DELETE,
				'reason'    => 'unshared_present_inventory',
				'keys'      => array(
					'profile-two/2026/08/photo.jpg',
					'profile-two/2026/08/photo-150x150.jpg',
					'profile-two/2026/08/photo-300x200.jpg',
				),
				'locations' => $locations,
			)
		);
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->expects( $this->once() )->method( 'delete_locations' )->with( $locations )->willReturnCallback(
			static function ( array $approved ) use (&$captured): void {
				foreach ( $approved as $location ) {
					$captured[] = $location->object_key;
				}
			}
		);

		$restorer = new AttachmentRestorer($settings, $storage, $guard, null, $profile_ops);
		$restorer->on_delete_attachment(99);

		$this->assertSame(
			array(
				'profile-two/2026/08/photo.jpg',
				'profile-two/2026/08/photo-150x150.jpg',
				'profile-two/2026/08/photo-300x200.jpg',
			),
			$captured
		);
	}

	public function test_delete_skips_remote_objects_and_records_audit_when_guard_reports_shared_reference(): void {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_enabled' )->willReturn( true );
		$settings->method( 'should_delete_remote' )->willReturn( true );
		$settings->method( 'is_aws_configured' )->willReturn( true );

		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		$storage->expects( $this->never() )->method( 'delete_relatives' );
		$storage->expects( $this->never() )->method( 'delete_keys' );

		WpStubs::set_meta( 101, '_s3ms_status', 'offloaded' );
		WpStubs::set_meta( 101, '_s3ms_original_key', 'uploads/2026/08/shared.jpg' );
		WpStubs::set_meta( 101, '_wp_attached_file', '2026/08/shared.jpg' );

		$guard = $this->createMock( RemoteDeleteSafetyGuard::class );
		$guard->method( 'evaluate' )->with( 101 )->willReturn(
			array(
				'status' => RemoteDeleteSafetyGuard::SHARED_REFERENCE,
				'reason' => 'same_attached_file',
				'keys'   => array(),
			)
		);

		$audit = $this->createMock( AuditLog::class );
		$audit->expects( $this->once() )->method( 'record' )->with(
			'remote_delete_skipped',
			$this->callback(
				static fn( array $context ): bool =>
					($context['attachment_id'] ?? null) === 101
					&& ($context['status'] ?? '') === RemoteDeleteSafetyGuard::SHARED_REFERENCE
					&& ($context['reason'] ?? '') === 'same_attached_file'
			)
		);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->never() )->method( 'delete_by_attachment' );

		$restorer = new AttachmentRestorer( $settings, $storage, $guard, $audit );
		$ref      = new \ReflectionClass( AttachmentRestorer::class );
		$prop     = $ref->getProperty( 'objects' );
		$prop->setValue( $restorer, $objects );

		$restorer->on_delete_attachment( 101 );
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
		$storage->method( 'delete_keys' );

		$guard = $this->createMock( RemoteDeleteSafetyGuard::class );
		$guard->method( 'evaluate' )->with( 7 )->willReturn(
			array(
				'status'    => RemoteDeleteSafetyGuard::SAFE_TO_DELETE,
				'reason'    => 'unshared_present_inventory',
				'keys'      => array( 'uploads/2026/08/photo.jpg' ),
				'locations' => array(
					ProfileObjectLocation::found( array(), $this->profile( 1 ), 'uploads/2026/08/photo.jpg', '2026/08/photo.jpg' ),
				),
			)
		);
		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->method( 'delete_locations' );

		WpStubs::set_meta( 7, '_s3ms_status', 'offloaded' );
		WpStubs::set_meta( 7, '_s3ms_original_key', '2026/08/photo.jpg' );
		WpStubs::set_meta( 7, '_wp_attached_file', '2026/08/photo.jpg' );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )->method( 'delete_by_attachment' )->with( 7 );

		$restorer = new AttachmentRestorer( $settings, $storage, $guard, null, $profile_ops );
		$ref      = new \ReflectionClass( AttachmentRestorer::class );
		$prop     = $ref->getProperty( 'objects' );
		$prop->setValue( $restorer, $objects );

		$restorer->on_delete_attachment( 7 );
	}

	public function test_delete_does_not_remove_remote_objects_when_attachment_lock_is_busy(): void {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_enabled' )->willReturn( true );
		$settings->method( 'should_delete_remote' )->willReturn( true );
		$settings->method( 'is_aws_configured' )->willReturn( true );

		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		$storage->expects( $this->never() )->method( 'delete_relatives' );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->never() )->method( 'delete_by_attachment' );

		WpStubs::set_meta( 17, '_s3ms_status', 'offloaded' );
		WpStubs::set_meta( 17, '_s3ms_original_key', 'uploads/2026/08/photo.jpg' );
		WpStubs::set_meta( 17, '_wp_attached_file', '2026/08/photo.jpg' );
		WpStubs::$options['s3ms_lock_17'] = array(
			'operation' => 'migrate',
			'at'        => time(),
			'expires'   => time() + 300,
		);

		$audit = $this->createMock( AuditLog::class );
		$audit->expects( $this->once() )->method( 'record' )->with(
			'remote_delete_skipped',
			$this->callback(
				static fn( array $context ): bool =>
					($context['attachment_id'] ?? null) === 17
					&& ($context['status'] ?? '') === RemoteDeleteSafetyGuard::UNKNOWN
					&& ($context['reason'] ?? '') === 'attachment_lock_busy'
			)
		);

		$restorer = new AttachmentRestorer( $settings, $storage, null, $audit );
		$ref      = new \ReflectionClass( AttachmentRestorer::class );
		$prop     = $ref->getProperty( 'objects' );
		$prop->setValue( $restorer, $objects );

		$restorer->on_delete_attachment( 17 );
	}

	public function test_delete_does_not_remove_remote_objects_when_lease_is_lost_after_guard_approval(): void {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_enabled' )->willReturn( true );
		$settings->method( 'should_delete_remote' )->willReturn( true );
		$settings->method( 'is_aws_configured' )->willReturn( true );

		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		$storage->expects( $this->never() )->method( 'delete_relatives' );

		WpStubs::set_meta( 18, '_s3ms_status', 'offloaded' );
		WpStubs::set_meta( 18, '_s3ms_original_key', 'uploads/2026/08/photo.jpg' );
		WpStubs::set_meta( 18, '_wp_attached_file', '2026/08/photo.jpg' );

		$guard = $this->createMock( RemoteDeleteSafetyGuard::class );
		$locations = array(
			ProfileObjectLocation::found( array(), $this->profile( 1 ), 'uploads/2026/08/photo.jpg', '2026/08/photo.jpg' ),
		);
		$guard->method( 'evaluate' )->with( 18 )->willReturnCallback(
			function () use ( $locations ): array {
				$this->expire_lock( 18 );
				$this->assertNotNull( ( new AttachmentLock() )->acquire_lease( 18, 'restore' ) );
				return array(
					'status'    => RemoteDeleteSafetyGuard::SAFE_TO_DELETE,
					'reason'    => 'unshared_present_inventory',
					'keys'      => array( 'uploads/2026/08/photo.jpg' ),
					'locations' => $locations,
				);
			}
		);

		$profile_ops = $this->createMock( ProfileAwareObjectOperations::class );
		$profile_ops->expects( $this->never() )->method( 'delete_locations' );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->never() )->method( 'delete_by_attachment' );

		$audit = $this->createMock( AuditLog::class );
		$audit->expects( $this->once() )->method( 'record' )->with(
			'remote_delete_skipped',
			$this->callback(
				static fn( array $context ): bool =>
					($context['attachment_id'] ?? null) === 18
					&& ($context['status'] ?? '') === RemoteDeleteSafetyGuard::UNKNOWN
					&& ($context['reason'] ?? '') === 'lease_lost'
			)
		);

		$restorer = new AttachmentRestorer( $settings, $storage, $guard, $audit, $profile_ops );
		$ref      = new \ReflectionClass( AttachmentRestorer::class );
		$prop     = $ref->getProperty( 'objects' );
		$prop->setValue( $restorer, $objects );

		$restorer->on_delete_attachment( 18 );
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
		$prop->setValue( $restorer, $objects );

		$restorer->on_delete_attachment( 8 );
	}

	private function profile( int $id ): \Kazcode\WpStorage\Domain\StorageProfile {
		return new \Kazcode\WpStorage\Domain\StorageProfile(
			$id,
			'uuid-' . $id,
			'Profile ' . $id,
			'aws',
			'bucket-' . $id,
			'us-east-1',
			'',
			false,
			'',
			'storage',
			'',
			false,
			'keys',
			'ref-' . $id,
			false,
			false,
			false,
			'',
			''
		);
	}

	private function expire_lock( int $attachment_id ): void {
		$key      = 's3ms_lock_' . $attachment_id;
		$existing = WpStubs::$options[ $key ] ?? array();
		if ( is_string( $existing ) ) {
			$existing = json_decode( $existing, true );
		}
		$this->assertIsArray( $existing );
		$existing['expires']        = time() - 1;
		WpStubs::$options[ $key ] = json_encode( $existing );
	}
}
