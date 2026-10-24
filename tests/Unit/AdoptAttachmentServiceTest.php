<?php
/**
 * AdoptAttachmentService — HEAD only, no Put (P8-T04).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\ManifestBuilder;
use Kazcode\WpStorage\Domain\MediaManifest;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Infrastructure\AttachmentLeaseHandle;
use Kazcode\WpStorage\Infrastructure\AttachmentLock;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
use Kazcode\WpStorage\Services\AdoptAttachmentService;
use Kazcode\WpStorage\Storage\ProfileStorageGateway;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class AdoptAttachmentServiceTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_dry_run_heads_without_persisting_rows(): void {
		WpStubs::$posts[5] = (object) array(
			'ID'        => 5,
			'post_type' => 'attachment',
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->once() )
			->method( 'head_key' )
			->with( 'uploads/2026/08/photo.jpg' )
			->willReturn( array( 'exists' => true, 'content_length' => 100 ) );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->never() )->method( 'insert_owned_or_conflict' );

		$result = $this->service( $gateway, $objects )->adopt( 5, 1, true );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( 1, $result['adopted'] );
	}

	public function test_adopt_persists_present_rows_without_upload(): void {
		WpStubs::$posts[7] = (object) array(
			'ID'        => 7,
			'post_type' => 'attachment',
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 256 ) );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )
			->method( 'insert_owned_or_conflict' )
			->with(
				$this->callback(
					static function ( array $data ): bool {
						return ( $data['remote_status'] ?? '' ) === ObjectRemoteStatus::PRESENT
							&& ! empty( $data['verified_at'] );
					}
				)
			)
			->willReturn( array( 'ok' => true, 'id' => 1 ) );
		$objects->method( 'find_by_attachment' )->willReturn(
			array(
				array(
					'attachment_id'      => 7,
					'storage_profile_id' => 1,
					'object_key'         => 'uploads/2026/08/photo.jpg',
					'remote_status'      => ObjectRemoteStatus::PRESENT,
					'variant_type'       => 'original',
					'verified_at'        => gmdate( 'Y-m-d H:i:s' ),
				),
			)
		);

		$result = $this->service( $gateway, $objects )->adopt( 7, 1, false );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['adopted'] );
		$this->assertSame( 0, $result['missing'] );
	}

	public function test_confirmed_missing_records_missing_row(): void {
		WpStubs::$posts[9] = (object) array(
			'ID'        => 9,
			'post_type' => 'attachment',
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => false, 'confirmed_missing' => true ) );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )
			->method( 'insert_owned_or_conflict' )
			->with(
				$this->callback(
					static function ( array $data ): bool {
						return ( $data['remote_status'] ?? '' ) === ObjectRemoteStatus::MISSING;
					}
				)
			)
			->willReturn( array( 'ok' => true, 'id' => 2 ) );
		$objects->method( 'find_by_attachment' )->willReturn( array() );

		$result = $this->service( $gateway, $objects )->adopt( 9, 1, false );

		$this->assertSame( 0, $result['adopted'] );
		$this->assertSame( 1, $result['missing'] );
		$this->assertSame( 0, $result['errors'] );
	}

	public function test_transient_head_error_does_not_record_missing_row(): void {
		WpStubs::$posts[11] = (object) array(
			'ID'        => 11,
			'post_type' => 'attachment',
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn(
			array( 'exists' => false, 'confirmed_missing' => false, 'error' => 'Connection timed out.' )
		);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->never() )->method( 'insert_owned_or_conflict' );
		$objects->method( 'find_by_attachment' )->willReturn( array() );

		$result = $this->service( $gateway, $objects )->adopt( 11, 1, false );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $result['adopted'] );
		$this->assertSame( 0, $result['missing'] );
		$this->assertSame( 1, $result['errors'] );
		$this->assertSame( 'Connection timed out.', $result['results'][0]['error'] );
	}

	public function test_apply_fails_closed_when_lease_renewal_is_lost(): void {
		WpStubs::$posts[15] = (object) array(
			'ID'        => 15,
			'post_type' => 'attachment',
		);
		WpStubs::set_meta( 15, '_wp_attached_file', '2026/08/photo.jpg' );
		WpStubs::set_meta( 15, '_wp_attachment_metadata', array() );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn(
			array( 'exists' => true, 'content_length' => 10 )
		);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->willReturn( array() );
		$objects->expects( $this->never() )->method( 'insert_owned_or_conflict' );

		$lease = new AttachmentLeaseHandle( 15, 'token', 1, 'adopt', time() + 300 );
		$lock  = $this->createMock( AttachmentLock::class );
		$lock->method( 'acquire_lease' )->willReturn( $lease );
		$lock->method( 'renew' )->willReturn( false );
		$lock->expects( $this->once() )->method( 'release_lease' )->with( $lease );

		$result = $this->service( $gateway, $objects, $lock )->adopt( 15, 1, false );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $result['adopted'] );
		$this->assertSame( 1, $result['conflicts'] );
		$this->assertStringContainsString( 'lease lost', strtolower( (string) $result['results'][0]['error'] ) );
	}

	public function test_adopt_does_not_steal_key_owned_by_other_attachment(): void {
		WpStubs::$posts[16] = (object) array(
			'ID'        => 16,
			'post_type' => 'attachment',
		);
		WpStubs::set_meta( 16, '_wp_attached_file', '2026/08/photo.jpg' );
		WpStubs::set_meta( 16, '_wp_attachment_metadata', array() );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn(
			array( 'exists' => true, 'content_length' => 10 )
		);

		$objects = new class() extends ObjectRepository {
			/** @var list<array<string,mixed>> */
			private array $rows = array(
				array(
					'id'                  => 1,
					'attachment_id'       => 99,
					'storage_profile_id'  => 1,
					'local_relative_path' => 'other.jpg',
					'object_key'          => 'uploads/2026/08/photo.jpg',
					'remote_status'       => ObjectRemoteStatus::FAILED,
				),
			);

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

			public function insert_owned_or_conflict( array $data ): array {
				$existing = $this->find_by_profile_and_key(
					(int) ( $data['storage_profile_id'] ?? 0 ),
					(string) ( $data['object_key'] ?? '' )
				);
				if ( $existing !== null && (int) ( $existing['attachment_id'] ?? 0 ) !== (int) ( $data['attachment_id'] ?? 0 ) ) {
					return array(
						'ok'       => false,
						'reason'   => 'key_owned_by_other_attachment',
						'existing' => $existing,
					);
				}
				return array( 'ok' => true, 'id' => 2 );
			}

			public function upsert( array $data ): int {
				throw new \RuntimeException( 'Adopt must not call unrestricted upsert.' );
			}
		};

		$builder = $this->createMock( ManifestBuilder::class );
		$builder->method( 'build' )->willReturn(
			new MediaManifest(
				16,
				array(
					array(
						'relative'     => '2026/08/photo.jpg',
						'variant_type' => 'original',
					),
				)
			)
		);
		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->willReturn( $this->profile( 1 ) );
		$settings = $this->createMock( Settings::class );
		$service  = new AdoptAttachmentService(
			$settings,
			$builder,
			$objects,
			$profiles,
			static fn (): ProfileStorageGateway => $gateway
		);

		$result = $service->adopt( 16, 1, false );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $result['adopted'] );
		$this->assertSame( 1, $result['conflicts'] );
		$this->assertSame( 99, (int) ( $objects->find_by_profile_and_key( 1, 'uploads/2026/08/photo.jpg' )['attachment_id'] ?? 0 ) );
	}

	public function test_partial_adopt_still_invalidates_stats_when_final_lease_renew_fails(): void {
		WpStubs::$posts[17] = (object) array(
			'ID'        => 17,
			'post_type' => 'attachment',
		);
		WpStubs::set_meta( 17, '_wp_attached_file', '2026/08/photo.jpg' );
		WpStubs::set_meta( 17, '_wp_attachment_metadata', array() );
		WpStubs::$options[ \Kazcode\WpStorage\Services\ObjectStatsAggregator::CACHE_OPTION ] = array(
			'cached_at' => time(),
			'total'     => 42,
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn(
			array( 'exists' => true, 'content_length' => 10 )
		);

		$objects = new class() extends ObjectRepository {
			/** @var list<array<string,mixed>> */
			private array $rows = array();
			private int $next_id = 1;

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

			public function insert_owned_or_conflict( array $data ): array {
				$id           = $this->next_id++;
				$data['id']   = $id;
				$this->rows[] = $data;
				return array( 'ok' => true, 'id' => $id );
			}
		};

		$lease = new AttachmentLeaseHandle( 17, 'token', 1, 'adopt', time() + 300 );
		$lock  = $this->createMock( AttachmentLock::class );
		$lock->method( 'acquire_lease' )->willReturn( $lease );
		$lock->method( 'renew' )->willReturnOnConsecutiveCalls( true, false );
		$lock->expects( $this->once() )->method( 'release_lease' )->with( $lease );

		$builder = $this->createMock( ManifestBuilder::class );
		$builder->method( 'build' )->willReturn(
			new MediaManifest(
				17,
				array(
					array(
						'relative'     => '2026/08/photo.jpg',
						'variant_type' => 'original',
					),
				)
			)
		);
		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->willReturn( $this->profile( 1 ) );
		$service = new AdoptAttachmentService(
			$this->createMock( Settings::class ),
			$builder,
			$objects,
			$profiles,
			static fn (): ProfileStorageGateway => $gateway,
			$lock
		);

		$result = $service->adopt( 17, 1, false );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 1, $result['adopted'] );
		$this->assertSame( 1, $result['conflicts'] );
		$this->assertFalse( isset( WpStubs::$options[ \Kazcode\WpStorage\Services\ObjectStatsAggregator::CACHE_OPTION ] ) );
	}

	public function test_missing_write_syncs_meta_before_later_lease_loss(): void {
		WpStubs::$posts[18] = (object) array(
			'ID'        => 18,
			'post_type' => 'attachment',
		);
		WpStubs::set_meta( 18, '_wp_attached_file', '2026/08/gone.jpg' );
		WpStubs::set_meta( 18, '_wp_attachment_metadata', array() );
		WpStubs::set_meta( 18, '_s3ms_status', \Kazcode\WpStorage\Attachment\AttachmentOffloader::STATUS_OFFLOADED );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturnOnConsecutiveCalls(
			array(
				'exists'            => false,
				'confirmed_missing' => true,
			),
			array(
				'exists'            => false,
				'confirmed_missing' => true,
			)
		);

		$objects = new class() extends ObjectRepository {
			/** @var list<array<string,mixed>> */
			private array $rows = array();
			private int $next_id = 1;

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

			public function insert_owned_or_conflict( array $data ): array {
				$id           = $this->next_id++;
				$data['id']   = $id;
				$this->rows[] = $data;
				return array( 'ok' => true, 'id' => $id );
			}
		};

		$lease = new AttachmentLeaseHandle( 18, 'token', 1, 'adopt', time() + 300 );
		$lock  = $this->createMock( AttachmentLock::class );
		$lock->method( 'acquire_lease' )->willReturn( $lease );
		// First renew (before first missing write) succeeds; second renew (before second write) fails.
		$lock->method( 'renew' )->willReturnOnConsecutiveCalls( true, false );
		$lock->expects( $this->once() )->method( 'release_lease' )->with( $lease );

		$builder = $this->createMock( ManifestBuilder::class );
		$builder->method( 'build' )->willReturn(
			new MediaManifest(
				18,
				array(
					array(
						'relative'     => '2026/08/gone.jpg',
						'variant_type' => 'original',
					),
					array(
						'relative'     => '2026/08/gone-150x150.jpg',
						'variant_type' => 'size',
					),
				)
			)
		);
		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->willReturn( $this->profile( 1 ) );
		$service = new AdoptAttachmentService(
			$this->createMock( Settings::class ),
			$builder,
			$objects,
			$profiles,
			static fn (): ProfileStorageGateway => $gateway,
			$lock
		);

		$result = $service->adopt( 18, 1, false );

		$this->assertGreaterThanOrEqual( 1, $result['missing'] );
		$this->assertGreaterThan( 0, $result['conflicts'] );
		$this->assertSame( ObjectRemoteStatus::MISSING, (string) $objects->find_by_profile_and_key( 1, 'uploads/2026/08/gone.jpg' )['remote_status'] );
		$this->assertNotSame(
			\Kazcode\WpStorage\Attachment\AttachmentOffloader::STATUS_OFFLOADED,
			(string) get_post_meta( 18, '_s3ms_status', true )
		);
	}


	public function test_multi_variant_present_does_not_mark_offloaded_mid_scan_on_lease_loss(): void {
		WpStubs::$posts[19] = (object) array(
			'ID'        => 19,
			'post_type' => 'attachment',
		);
		WpStubs::set_meta( 19, '_wp_attached_file', '2026/08/multi.jpg' );
		WpStubs::set_meta( 19, '_wp_attachment_metadata', array() );
		WpStubs::set_meta( 19, '_s3ms_status', '' );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 10 ) );

		$objects = new class() extends ObjectRepository {
			/** @var list<array<string,mixed>> */
			private array $rows = array();
			private int $next_id = 1;

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

			public function insert_owned_or_conflict( array $data ): array {
				$id           = $this->next_id++;
				$data['id']   = $id;
				$this->rows[] = $data;
				return array( 'ok' => true, 'id' => $id );
			}
		};

		$lease = new AttachmentLeaseHandle( 19, 'token', 1, 'adopt', time() + 300 );
		$lock  = $this->createMock( AttachmentLock::class );
		$lock->method( 'acquire_lease' )->willReturn( $lease );
		$lock->method( 'renew' )->willReturnOnConsecutiveCalls( true, false );
		$lock->expects( $this->once() )->method( 'release_lease' )->with( $lease );

		$builder = $this->createMock( ManifestBuilder::class );
		$builder->method( 'build' )->willReturn(
			new MediaManifest(
				19,
				array(
					array(
						'relative'     => '2026/08/multi.jpg',
						'variant_type' => 'original',
					),
					array(
						'relative'     => '2026/08/multi-150x150.jpg',
						'variant_type' => 'size',
					),
				)
			)
		);
		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->willReturn( $this->profile( 1 ) );
		$service = new AdoptAttachmentService(
			$this->createMock( Settings::class ),
			$builder,
			$objects,
			$profiles,
			static fn (): ProfileStorageGateway => $gateway,
			$lock
		);

		$result = $service->adopt( 19, 1, false );

		$this->assertSame( 1, $result['adopted'] );
		$this->assertGreaterThan( 0, $result['conflicts'] );
		$this->assertNotNull( $objects->find_by_profile_and_key( 1, 'uploads/2026/08/multi.jpg' ) );
		$this->assertNotSame(
			\Kazcode\WpStorage\Attachment\AttachmentOffloader::STATUS_OFFLOADED,
			(string) get_post_meta( 19, '_s3ms_status', true )
		);
	}


	public function test_partial_present_with_transient_error_does_not_mark_offloaded(): void {
		WpStubs::$posts[21] = (object) array(
			'ID'        => 21,
			'post_type' => 'attachment',
		);
		WpStubs::set_meta( 21, '_wp_attached_file', '2026/08/partial.jpg' );
		WpStubs::set_meta( 21, '_wp_attachment_metadata', array() );
		WpStubs::set_meta( 21, '_s3ms_status', '' );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturnCallback(
			static function ( string $key ): array {
				if ( str_contains( $key, '150x150' ) ) {
					return array(
						'exists'            => false,
						'confirmed_missing' => false,
						'error'             => 'Connection timed out.',
					);
				}
				return array( 'exists' => true, 'content_length' => 20 );
			}
		);

		$objects = new class() extends ObjectRepository {
			/** @var list<array<string,mixed>> */
			private array $rows = array();
			private int $next_id = 1;

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

			public function insert_owned_or_conflict( array $data ): array {
				$id           = $this->next_id++;
				$data['id']   = $id;
				$this->rows[] = $data;
				return array( 'ok' => true, 'id' => $id );
			}
		};

		$builder = $this->createMock( ManifestBuilder::class );
		$builder->method( 'build' )->willReturn(
			new MediaManifest(
				21,
				array(
					array(
						'relative'     => '2026/08/partial.jpg',
						'variant_type' => 'original',
					),
					array(
						'relative'     => '2026/08/partial-150x150.jpg',
						'variant_type' => 'size',
					),
				)
			)
		);
		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->willReturn( $this->profile( 1 ) );
		$service = new AdoptAttachmentService(
			$this->createMock( Settings::class ),
			$builder,
			$objects,
			$profiles,
			static fn (): ProfileStorageGateway => $gateway,
			null
		);

		$result = $service->adopt( 21, 1, false );

		$this->assertSame( 1, $result['adopted'] );
		$this->assertSame( 1, $result['errors'] );
		$this->assertFalse( $result['success'] );
		$this->assertNotNull( $objects->find_by_profile_and_key( 1, 'uploads/2026/08/partial.jpg' ) );
		$this->assertNotSame(
			\Kazcode\WpStorage\Attachment\AttachmentOffloader::STATUS_OFFLOADED,
			(string) get_post_meta( 21, '_s3ms_status', true )
		);
	}

	public function test_complete_multi_variant_adopt_sets_offloaded_once(): void {
		WpStubs::$posts[20] = (object) array(
			'ID'        => 20,
			'post_type' => 'attachment',
		);
		WpStubs::set_meta( 20, '_wp_attached_file', '2026/08/done.jpg' );
		WpStubs::set_meta( 20, '_wp_attachment_metadata', array() );
		WpStubs::set_meta( 20, '_s3ms_status', '' );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturnCallback(
			static function ( string $key ): array {
				$len = str_contains( $key, '150x150' ) ? 5 : 20;
				return array( 'exists' => true, 'content_length' => $len );
			}
		);

		$objects = new class() extends ObjectRepository {
			/** @var list<array<string,mixed>> */
			private array $rows = array();
			private int $next_id = 1;

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

			public function insert_owned_or_conflict( array $data ): array {
				$id           = $this->next_id++;
				$data['id']   = $id;
				$this->rows[] = $data;
				return array( 'ok' => true, 'id' => $id );
			}
		};

		$builder = $this->createMock( ManifestBuilder::class );
		$builder->method( 'build' )->willReturn(
			new MediaManifest(
				20,
				array(
					array(
						'relative'     => '2026/08/done.jpg',
						'variant_type' => 'original',
					),
					array(
						'relative'     => '2026/08/done-150x150.jpg',
						'variant_type' => 'size',
					),
				)
			)
		);
		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->willReturn( $this->profile( 1 ) );
		$service = new AdoptAttachmentService(
			$this->createMock( Settings::class ),
			$builder,
			$objects,
			$profiles,
			static fn (): ProfileStorageGateway => $gateway,
			null
		);

		$result = $service->adopt( 20, 1, false );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['adopted'] );
		$this->assertSame(
			\Kazcode\WpStorage\Attachment\AttachmentOffloader::STATUS_OFFLOADED,
			(string) get_post_meta( 20, '_s3ms_status', true )
		);
	}

	private function service(
		ProfileStorageGateway $gateway,
		ObjectRepository $objects,
		?AttachmentLock $lock = null
	): AdoptAttachmentService {
		$profile = $this->profile( 1 );
		$builder = $this->createMock( ManifestBuilder::class );
		$builder->method( 'build' )->willReturn(
			new MediaManifest(
				5,
				array(
					array(
						'relative'     => '2026/08/photo.jpg',
						'variant_type' => 'original',
					),
				)
			)
		);

		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->willReturn( $profile );

		$settings = $this->createMock( Settings::class );
		$factory  = static fn( StorageProfile $p ): ProfileStorageGateway => $gateway;

		return new AdoptAttachmentService( $settings, $builder, $objects, $profiles, $factory, $lock );
	}

	private function profile( int $id ): StorageProfile {
		$now = gmdate( 'Y-m-d H:i:s' );
		return new StorageProfile(
			$id,
			'uuid-' . $id,
			'Legacy',
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
			'legacy_default',
			true,
			false,
			false,
			$now,
			$now
		);
	}
}
