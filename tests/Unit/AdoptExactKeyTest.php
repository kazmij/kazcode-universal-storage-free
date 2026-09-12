<?php
/**
 * Exact-key adoption for competitor import descriptors.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Attachment\AttachmentOffloader;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
use Kazcode\WpStorage\Services\AdoptAttachmentService;
use Kazcode\WpStorage\Storage\ProfileStorageGateway;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class AdoptExactKeyTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_adopt_descriptors_uses_exact_source_key_without_upload(): void {
		WpStubs::$posts[42] = (object) array(
			'ID'        => 42,
			'post_type' => 'attachment',
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->once() )
			->method( 'head_key' )
			->with( 'legacy/custom-prefix/abc-photo.jpg' )
			->willReturn( array( 'exists' => true, 'content_length' => 321 ) );
		$gateway->expects( $this->never() )->method( 'put_object_from_file' );
		$gateway->expects( $this->never() )->method( 'copy_object' );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )
			->method( 'upsert' )
			->with(
				$this->callback(
					static function ( array $data ): bool {
						return ( $data['local_relative_path'] ?? '' ) === '2026/09/photo.jpg'
							&& ( $data['object_key'] ?? '' ) === 'legacy/custom-prefix/abc-photo.jpg';
					}
				)
			)
			->willReturn( 1 );
		$objects->method( 'find_by_attachment' )->willReturn(
			array(
				array(
					'attachment_id'      => 42,
					'storage_profile_id' => 7,
					'local_relative_path' => '2026/09/photo.jpg',
					'object_key'         => 'legacy/custom-prefix/abc-photo.jpg',
					'remote_status'      => 'present',
					'variant_type'       => 'original',
				),
			)
		);

		$result = $this->service( $gateway, $objects )->adopt_descriptors(
			42,
			7,
			array(
				array(
					'relative'     => '2026/09/photo.jpg',
					'source_key'   => 'legacy/custom-prefix/abc-photo.jpg',
					'variant_type' => 'original',
				),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['adopted'] );
		$this->assertSame( 'legacy/custom-prefix/abc-photo.jpg', $result['results'][0]['key'] );
	}

	public function test_adopt_descriptors_accepts_imported_object_descriptor_contract(): void {
		WpStubs::$posts[50] = (object) array(
			'ID'        => 50,
			'post_type' => 'attachment',
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->once() )
			->method( 'head_key' )
			->with( 'legacy/custom-prefix/from-dto.jpg' )
			->willReturn( array( 'exists' => true, 'content_length' => 321 ) );

		$objects = new AdoptExactKeyInMemoryObjectRepository();
		$result  = $this->service( $gateway, $objects )->adopt_descriptors(
			50,
			7,
			array(
				new \Kazcode\WpStorage\Import\ImportedObjectDescriptor(
					50,
					'2026/09/from-dto.jpg',
					'original',
					'legacy/custom-prefix/from-dto.jpg',
					321,
					'image/jpeg',
					'wpom:bucket:dto'
				),
			)
		);

		$this->assertTrue( $result['success'], (string) ( $result['message'] ?? '' ) );
		$this->assertSame( 1, $result['adopted'] );
		$this->assertSame( '2026/09/from-dto.jpg', $objects->all()[0]['local_relative_path'] );
		$this->assertSame( 'legacy/custom-prefix/from-dto.jpg', $objects->all()[0]['object_key'] );
	}

	public function test_adopt_descriptors_preserves_whitespace_in_exact_source_key(): void {
		WpStubs::$posts[48] = (object) array(
			'ID'        => 48,
			'post_type' => 'attachment',
		);

		$source_key = ' legacy/custom-prefix/abc-photo.jpg ';

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->once() )
			->method( 'head_key' )
			->with( $source_key )
			->willReturn( array( 'exists' => true, 'content_length' => 321 ) );
		$gateway->expects( $this->never() )->method( 'put_object_from_file' );
		$gateway->expects( $this->never() )->method( 'copy_object' );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )
			->method( 'upsert' )
			->with(
				$this->callback(
					static function ( array $data ) use ( $source_key ): bool {
						return ( $data['local_relative_path'] ?? '' ) === '2026/09/photo.jpg'
							&& ( $data['object_key'] ?? '' ) === $source_key;
					}
				)
			)
			->willReturn( 1 );
		$objects->method( 'find_by_attachment' )->willReturn(
			array(
				array(
					'attachment_id'       => 48,
					'storage_profile_id'  => 7,
					'local_relative_path' => '2026/09/photo.jpg',
					'object_key'          => $source_key,
					'remote_status'       => 'present',
					'variant_type'        => 'original',
				),
			)
		);

		$result = $this->service( $gateway, $objects )->adopt_descriptors(
			48,
			7,
			array(
				array(
					'relative'     => '2026/09/photo.jpg',
					'source_key'   => $source_key,
					'variant_type' => 'original',
				),
			)
		);

		$this->assertSame( $source_key, $result['results'][0]['key'] );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['adopted'] );
	}

	public function test_whitespace_only_exact_source_key_is_skipped_without_head_or_writes(): void {
		WpStubs::$posts[49] = (object) array(
			'ID'        => 49,
			'post_type' => 'attachment',
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );
		$gateway->expects( $this->never() )->method( 'put_object_from_file' );
		$gateway->expects( $this->never() )->method( 'copy_object' );

		$objects = new AdoptExactKeyInMemoryObjectRepository();

		$result = $this->service( $gateway, $objects )->adopt_descriptors(
			49,
			7,
			array(
				array(
					'relative'     => '2026/09/photo.jpg',
					'source_key'   => '   ',
					'variant_type' => 'original',
				),
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $result['adopted'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 'skipped', $result['results'][0]['classification'] );
		$this->assertSame( array(), $objects->all() );
		$this->assertSame( array(), WpStubs::$post_meta[49] ?? array() );
	}

	public function test_transient_head_error_does_not_create_missing_row(): void {
		WpStubs::$posts[43] = (object) array(
			'ID'        => 43,
			'post_type' => 'attachment',
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->once() )
			->method( 'head_key' )
			->with( 'legacy/custom-prefix/abc-photo.jpg' )
			->willReturn(
				array(
					'exists'            => false,
					'confirmed_missing' => false,
					'error'             => 'Connection timed out.',
				)
			);

		$objects = new AdoptExactKeyInMemoryObjectRepository();

		$result = $this->service( $gateway, $objects )->adopt_descriptors(
			43,
			7,
			array( $this->descriptor() )
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $result['adopted'] );
		$this->assertSame( 0, $result['missing'] );
		$this->assertSame( 1, $result['errors'] );
		$this->assertSame( 'transient_error', $result['results'][0]['classification'] );
		$this->assertSame( array(), $objects->all() );
		$this->assertSame( array(), WpStubs::$post_meta[43] ?? array() );
	}

	public function test_existing_active_row_on_different_profile_key_is_conflict(): void {
		WpStubs::$posts[44] = (object) array(
			'ID'        => 44,
			'post_type' => 'attachment',
		);
		WpStubs::$post_meta[44] = array(
			'_s3ms_status'       => AttachmentOffloader::STATUS_OFFLOADED,
			'_s3ms_original_key' => 'uploads/2026/09/photo.jpg',
		);

		$existing = array(
			'id'                  => 1,
			'attachment_id'       => 44,
			'storage_profile_id'  => 9,
			'local_relative_path' => '2026/09/photo.jpg',
			'object_key'          => 'uploads/2026/09/photo.jpg',
			'remote_status'       => ObjectRemoteStatus::PRESENT,
			'variant_type'        => 'original',
		);
		$objects  = new AdoptExactKeyInMemoryObjectRepository( array( $existing ) );
		$gateway  = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );

		$result = $this->service( $gateway, $objects )->adopt_descriptors(
			44,
			7,
			array( $this->descriptor() )
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $result['adopted'] );
		$this->assertSame( 1, $result['conflicts'] );
		$this->assertSame( 'conflict', $result['results'][0]['classification'] );
		$this->assertSame( array( $existing ), $objects->all() );
		$this->assertSame( AttachmentOffloader::STATUS_OFFLOADED, WpStubs::$post_meta[44]['_s3ms_status'] );
		$this->assertSame( 'uploads/2026/09/photo.jpg', WpStubs::$post_meta[44]['_s3ms_original_key'] );
	}

	public function test_size_mismatch_is_conflict_without_persisting_row(): void {
		WpStubs::$posts[46] = (object) array(
			'ID'        => 46,
			'post_type' => 'attachment',
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->once() )
			->method( 'head_key' )
			->with( 'legacy/custom-prefix/abc-photo.jpg' )
			->willReturn( array( 'exists' => true, 'content_length' => 999 ) );

		$objects = new AdoptExactKeyInMemoryObjectRepository();

		$result = $this->service( $gateway, $objects )->adopt_descriptors(
			46,
			7,
			array(
				array(
					'relative'     => '2026/09/photo.jpg',
					'source_key'   => 'legacy/custom-prefix/abc-photo.jpg',
					'source_size'  => 321,
					'variant_type' => 'original',
				),
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $result['adopted'] );
		$this->assertSame( 1, $result['conflicts'] );
		$this->assertSame( 'conflict', $result['results'][0]['classification'] );
		$this->assertSame( array(), $objects->all() );
		$this->assertSame( array(), WpStubs::$post_meta[46] ?? array() );
	}

	public function test_existing_active_profile_key_for_another_attachment_is_conflict(): void {
		WpStubs::$posts[47] = (object) array(
			'ID'        => 47,
			'post_type' => 'attachment',
		);

		$existing = array(
			'id'                  => 1,
			'attachment_id'       => 999,
			'storage_profile_id'  => 7,
			'local_relative_path' => '2025/01/original.jpg',
			'object_key'          => 'legacy/custom-prefix/abc-photo.jpg',
			'remote_status'       => ObjectRemoteStatus::PRESENT,
			'variant_type'        => 'original',
		);
		$objects  = new AdoptExactKeyInMemoryObjectRepository( array( $existing ) );
		$gateway  = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'head_key' );

		$result = $this->service( $gateway, $objects )->adopt_descriptors(
			47,
			7,
			array( $this->descriptor() )
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $result['adopted'] );
		$this->assertSame( 1, $result['conflicts'] );
		$this->assertSame( 'conflict', $result['results'][0]['classification'] );
		$this->assertSame( array( $existing ), $objects->all() );
		$this->assertSame( array(), WpStubs::$post_meta[47] ?? array() );
	}

	public function test_repeat_adoption_is_idempotent(): void {
		WpStubs::$posts[45] = (object) array(
			'ID'        => 45,
			'post_type' => 'attachment',
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->exactly( 2 ) )
			->method( 'head_key' )
			->with( 'legacy/custom-prefix/abc-photo.jpg' )
			->willReturn( array( 'exists' => true, 'content_length' => 321 ) );
		$gateway->expects( $this->never() )->method( 'put_object_from_file' );
		$gateway->expects( $this->never() )->method( 'copy_object' );

		$objects = new AdoptExactKeyInMemoryObjectRepository();
		$service = $this->service( $gateway, $objects );

		$first  = $service->adopt_descriptors( 45, 7, array( $this->descriptor() ) );
		$status = WpStubs::$post_meta[45]['_s3ms_status'];
		$key    = WpStubs::$post_meta[45]['_s3ms_original_key'];
		$second = $service->adopt_descriptors( 45, 7, array( $this->descriptor() ) );

		$this->assertTrue( $first['success'] );
		$this->assertTrue( $second['success'] );
		$this->assertSame( AttachmentOffloader::STATUS_OFFLOADED, $first['status'] );
		$this->assertSame( AttachmentOffloader::STATUS_OFFLOADED, $second['status'] );
		$this->assertSame( 1, count( $objects->all() ) );
		$this->assertSame( 'legacy/custom-prefix/abc-photo.jpg', $objects->all()[0]['object_key'] );
		$this->assertSame( $status, WpStubs::$post_meta[45]['_s3ms_status'] );
		$this->assertSame( $key, WpStubs::$post_meta[45]['_s3ms_original_key'] );
	}

	private function service( ProfileStorageGateway $gateway, ObjectRepository $objects ): AdoptAttachmentService {
		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->willReturnCallback(
			fn( int $id ): StorageProfile => $this->profile( $id )
		);

		$settings = $this->createMock( Settings::class );
		$factory  = static fn( StorageProfile $profile ): ProfileStorageGateway => $gateway;

		return new AdoptAttachmentService( $settings, null, $objects, $profiles, $factory );
	}

	/**
	 * @return array<string, string>
	 */
	private function descriptor(): array {
		return array(
			'relative'     => '2026/09/photo.jpg',
			'source_key'   => 'legacy/custom-prefix/abc-photo.jpg',
			'variant_type' => 'original',
		);
	}

	private function profile( int $id ): StorageProfile {
		$now = gmdate( 'Y-m-d H:i:s' );
		return new StorageProfile(
			$id,
			'uuid-' . $id,
			'Import',
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
			'import',
			true,
			false,
			false,
			$now,
			$now
		);
	}
}

final class AdoptExactKeyInMemoryObjectRepository extends ObjectRepository {

	/**
	 * @var list<array<string, mixed>>
	 */
	private array $rows = array();

	private int $next_id = 1;

	/**
	 * @param list<array<string, mixed>> $rows Seed rows.
	 */
	public function __construct( array $rows = array() ) {
		foreach ( $rows as $row ) {
			$id             = (int) ( $row['id'] ?? $this->next_id );
			$row['id']      = $id;
			$this->next_id  = max( $this->next_id, $id + 1 );
			$this->rows[]   = $row;
		}
	}

	/**
	 * @param array<string, mixed> $data Row fields.
	 */
	public function upsert( array $data ): int {
		$profile_id = (int) ( $data['storage_profile_id'] ?? 0 );
		$key        = (string) ( $data['object_key'] ?? '' );
		foreach ( $this->rows as $index => $row ) {
			if ( (int) ( $row['storage_profile_id'] ?? 0 ) === $profile_id && (string) ( $row['object_key'] ?? '' ) === $key ) {
				$id                  = (int) $row['id'];
				$this->rows[ $index ] = array_merge( $row, $data, array( 'id' => $id ) );
				return $id;
			}
		}

		$id            = $this->next_id++;
		$data['id']    = $id;
		$this->rows[]  = $data;
		return $id;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function find_by_attachment( int $attachment_id ): array {
		return array_values(
			array_filter(
				$this->rows,
				static fn( array $row ): bool => (int) ( $row['attachment_id'] ?? 0 ) === $attachment_id
			)
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_by_profile_and_key( int $storage_profile_id, string $object_key ): ?array {
		foreach ( $this->rows as $row ) {
			if ( (int) ( $row['storage_profile_id'] ?? 0 ) === $storage_profile_id && (string) ( $row['object_key'] ?? '' ) === $object_key ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function all(): array {
		return $this->rows;
	}
}
