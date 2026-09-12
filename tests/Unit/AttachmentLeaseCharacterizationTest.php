<?php
/**
 * Attachment lease fencing regressions from the public 1.0.2 baseline.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Attachment\AttachmentFileResolver;
use Kazcode\WpStorage\Attachment\AttachmentOffloader;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\LocalStoragePolicy;
use Kazcode\WpStorage\Infrastructure\AttachmentLeaseHandle;
use Kazcode\WpStorage\Infrastructure\AttachmentLock;
use Kazcode\WpStorage\Storage\S3KeyResolver;
use Kazcode\WpStorage\Storage\S3Storage;
use Kazcode\WpStorage\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class AttachmentLeaseCharacterizationTest extends TestCase {

	private string $uploads;

	protected function setUp(): void {
		WpStubs::reset();
		WpStubs::$options['s3ms_object_offload_enabled'] = false;
		$this->uploads = sys_get_temp_dir() . '/s3ms-lease-' . uniqid( '', true );
		wp_mkdir_p( $this->uploads . '/2026/08' );
		WpStubs::$uploads_basedir = $this->uploads;
	}

	protected function tearDown(): void {
		$this->rmTree( $this->uploads );
		WpStubs::reset();
	}

	public function test_old_owner_release_must_not_delete_new_owner_lock(): void {
		$old_owner = new AttachmentLock();
		$new_owner = new AttachmentLock();

		$this->assertTrue( $old_owner->acquire( 502, 'migrate' ) );
		$this->expire_lock( 502 );
		$this->assertTrue( $new_owner->acquire( 502, 'delete' ) );

		$old_owner->release( 502 );

		$this->assertTrue(
			$new_owner->is_locked( 502 ),
			'An expired worker must not be able to release a newer worker lock.'
		);
	}

	public function test_stale_offload_worker_cannot_commit_after_newer_owner_committed_state(): void {
		$attachment_id = 503;
		$relative      = '2026/08/photo.jpg';
		$absolute      = $this->touch_file( $relative );

		WpStubs::set_meta( $attachment_id, '_wp_attached_file', $relative );

		$settings = $this->createMock( Settings::class );
		$settings->method( 'should_delete_local' )->willReturn( false );
		$settings->method( 'local_storage_policy' )->willReturn( LocalStoragePolicy::KEEP_ALL );

		$keys    = new S3KeyResolver( $this->settings_map( array( 'object_prefix' => 'uploads/' ) ) );
		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( $keys );
		$storage->method( 'head_relative' )->willReturn( array( 'exists' => true, 'content_length' => filesize( $absolute ) ) );
		$storage->method( 'upload_file' )->willReturnCallback(
			function () use ( $attachment_id, $relative ): string {
				$this->expire_lock( $attachment_id );
				$this->assertTrue(
					( new AttachmentLock() )->acquire( $attachment_id, 'delete' ),
					'The newer conflicting owner should be able to acquire after TTL expiry.'
				);
				WpStubs::set_meta( $attachment_id, '_s3ms_status', 'deleted_by_new_owner' );
				return 'uploads/' . $relative;
			}
		);

		$file_resolver = $this->getMockBuilder( AttachmentFileResolver::class )
			->setConstructorArgs( array( $keys ) )
			->onlyMethods( array( 'existing_local_files', 'relative_paths' ) )
			->getMock();
		$file_resolver->method( 'existing_local_files' )->willReturn( array( $relative => $absolute ) );
		$file_resolver->method( 'relative_paths' )->willReturn( array( $relative ) );

		$offloader = new AttachmentOffloader( $settings, $storage );
		$this->inject( $offloader, 'files', $file_resolver );

		$result = $offloader->offload( $attachment_id, false );

		$this->assertSame(
			'deleted_by_new_owner',
			WpStubs::$post_meta[ $attachment_id ]['_s3ms_status'] ?? null,
			'A stale worker must not overwrite a newer owner authoritative attachment state.'
		);
		$this->assertFalse(
			$result['success'],
			'A stale worker that lost ownership must not report success after a newer conflicting owner committed state.'
		);
	}

	public function test_current_owner_can_renew_without_incrementing_generation(): void {
		$lock  = new AttachmentLock();
		$lease = $lock->acquire_lease( 504, 'migrate' );

		$this->assertNotNull( $lease );
		$this->assertTrue( $lock->renew( $lease ) );
		$this->assertSame( 1, $lease->generation );
		$this->assertTrue( $lock->is_current( $lease ) );
	}

	public function test_stale_owner_cannot_renew_or_release_new_generation(): void {
		$lock = new AttachmentLock();
		$old  = $lock->acquire_lease( 505, 'migrate' );
		$this->assertNotNull( $old );
		$this->expire_lock( 505 );

		$new = $lock->acquire_lease( 505, 'delete' );
		$this->assertNotNull( $new );
		$this->assertSame( 2, $new->generation );

		$this->assertFalse( $lock->renew( $old ) );
		$this->assertFalse( $lock->release_lease( $old ) );
		$this->assertTrue( $lock->is_current( $new ) );
	}

	public function test_active_legacy_lock_is_respected_and_expired_legacy_lock_converts_to_fenced_generation(): void {
		$lock = new AttachmentLock();

		WpStubs::$options['s3ms_lock_508'] = array(
			'operation' => 'migrate',
			'at'        => time(),
			'expires'   => time() + 300,
		);
		$this->assertNull( $lock->acquire_lease( 508, 'delete' ) );

		$this->expire_lock( 508 );
		$lease = $lock->acquire_lease( 508, 'delete' );
		$this->assertNotNull( $lease );
		$this->assertSame( 1, $lease->generation );
	}

	public function test_corrupt_lock_record_fails_closed(): void {
		WpStubs::$options['s3ms_lock_509'] = array( 'unexpected' => 'payload' );

		$this->assertNull( ( new AttachmentLock() )->acquire_lease( 509, 'delete' ) );
	}

	private function expire_lock( int $attachment_id ): void {
		$key      = 's3ms_lock_' . $attachment_id;
		$existing = WpStubs::$options[ $key ] ?? array();
		if ( is_string( $existing ) ) {
			$existing = json_decode( $existing, true );
		}
		$this->assertIsArray( $existing );
		$existing['expires']      = time() - 1;
		WpStubs::$options[ $key ] = json_encode( $existing );
	}

	/**
	 * @param array<string, mixed> $map
	 */
	private function settings_map( array $map ): Settings {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'get' )->willReturnCallback(
			static function ( string $key, mixed $default = null ) use ( $map ): mixed {
				return $map[ $key ] ?? $default;
			}
		);
		return $settings;
	}

	private function inject( object $target, string $property, mixed $value ): void {
		$ref  = new \ReflectionClass( $target );
		$prop = $ref->getProperty( $property );
		$prop->setValue( $target, $value );
	}

	private function touch_file( string $relative ): string {
		$absolute = $this->uploads . '/' . $relative;
		$dir      = dirname( $absolute );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		file_put_contents( $absolute, 'x' );
		return $absolute;
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
