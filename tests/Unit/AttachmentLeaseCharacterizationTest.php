<?php
/**
 * Characterization: attachment lock expiry and stale owner safety.
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

	public function test_expired_lock_can_be_reacquired_while_original_worker_is_still_running(): void {
		$lock = new AttachmentLock();

		$this->assertTrue( $lock->acquire( 501, 'migrate' ) );
		$this->expire_lock( 501 );

		$this->assertTrue(
			$lock->acquire( 501, 'delete' ),
			'Current TTL-only lock allows a second worker to acquire the same attachment after expiry, even if the first worker is still executing.'
		);
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

	public function test_stale_offload_worker_can_commit_after_newer_owner_committed_state(): void {
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

	public function test_repeated_current_owner_renew_in_same_second_remains_current_with_wpdb_cas(): void {
		$previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$now           = time();
		$token         = str_repeat( 'a', 32 );
		$key           = 's3ms_lock_5041';

		$wpdb = new class() {
			public string $options = 'wp_options';

			/** @var array<string, string> */
			public array $rows = array();

			/** @var array<int, mixed> */
			private array $last_args = array();

			public function prepare( string $query, mixed ...$args ): string {
				$this->last_args = $args;
				return $query;
			}

			public function get_var( string $query ): ?string {
				unset( $query );
				$key = (string) ( $this->last_args[0] ?? '' );
				return $this->rows[ $key ] ?? null;
			}

			public function query( string $query ): int {
				unset( $query );
				$new      = (string) ( $this->last_args[0] ?? '' );
				$key      = (string) ( $this->last_args[2] ?? '' );
				$expected = (string) ( $this->last_args[3] ?? '' );
				if ( ( $this->rows[ $key ] ?? null ) !== $expected ) {
					return 0;
				}
				if ( $this->rows[ $key ] === $new ) {
					return 0;
				}
				$this->rows[ $key ] = $new;
				return 1;
			}
		};

		$wpdb->rows[ $key ] = (string) json_encode(
			array(
				'version'     => 2,
				'active'      => true,
				'generation'  => 1,
				'owner_token' => $token,
				'operation'   => 'migrate',
				'acquired_at' => $now,
				'expires'     => $now + 300,
				'renewed_at'  => $now,
			)
		);

		$GLOBALS['wpdb'] = $wpdb;
		try {
			$lock  = new AttachmentLock();
			$lease = new AttachmentLeaseHandle( 5041, $token, 1, 'migrate', $now + 300 );

			$this->assertTrue( $lock->is_current( $lease ) );
			$this->assertTrue(
				$lock->renew( $lease ),
				'A current owner renewing twice inside one second must not lose ownership merely because MySQL reports 0 changed rows for an identical CAS value.'
			);
		} finally {
			if ( $previous_wpdb === null ) {
				unset( $GLOBALS['wpdb'] );
			} else {
				$GLOBALS['wpdb'] = $previous_wpdb;
			}
		}
	}

	public function test_stale_owner_cannot_renew_or_release_new_generation(): void {
		$lock  = new AttachmentLock();
		$old   = $lock->acquire_lease( 505, 'migrate' );
		$this->assertNotNull( $old );
		$this->expire_lock( 505 );

		$new = $lock->acquire_lease( 505, 'delete' );
		$this->assertNotNull( $new );
		$this->assertSame( 2, $new->generation );

		$this->assertFalse( $lock->renew( $old ) );
		$this->assertFalse( $lock->release_lease( $old ) );
		$this->assertTrue( $lock->is_current( $new ) );
	}

	public function test_generation_is_monotonic_across_release(): void {
		$lock = new AttachmentLock();

		$first = $lock->acquire_lease( 506, 'migrate' );
		$this->assertNotNull( $first );
		$this->assertSame( 1, $first->generation );
		$this->assertTrue( $lock->release_lease( $first ) );

		$second = $lock->acquire_lease( 506, 'restore' );
		$this->assertNotNull( $second );
		$this->assertSame( 2, $second->generation );
	}

	public function test_two_takeover_workers_result_in_one_current_owner(): void {
		$lock = new AttachmentLock();

		$first = $lock->acquire_lease( 507, 'migrate' );
		$this->assertNotNull( $first );
		$this->expire_lock( 507 );

		$second = $lock->acquire_lease( 507, 'delete' );
		$third  = $lock->acquire_lease( 507, 'restore' );

		$this->assertNotNull( $second );
		$this->assertNull( $third );
		$this->assertSame( 2, $second->generation );
		$this->assertTrue( $lock->is_current( $second ) );
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
		$existing['expires']        = time() - 1;
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
