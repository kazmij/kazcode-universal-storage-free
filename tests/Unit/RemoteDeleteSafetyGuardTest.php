<?php
/**
 * Remote delete safety guard tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Attachment\AttachmentFileResolver;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
use Kazcode\WpStorage\Services\RemoteDeleteSafetyGuard;
use Kazcode\WpStorage\Storage\S3KeyResolver;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class RemoteDeleteSafetyGuardTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_returns_safe_to_delete_for_unshared_present_rows_on_current_profile(): void {
		$this->attachment( 10, '2026/08/photo.jpg' );
		$guard  = $this->guard_with_rows(
			array(
				$this->row( 10, 1, 'uploads/2026/08/photo.jpg', '2026/08/photo.jpg' ),
			),
			1
		);
		$result = $guard->evaluate( 10 );

		$this->assertSame( RemoteDeleteSafetyGuard::SAFE_TO_DELETE, $result['status'] );
		$this->assertSame( array( 'uploads/2026/08/photo.jpg' ), $result['keys'] );
	}

	public function test_returns_shared_reference_when_another_attachment_has_same_attached_file(): void {
		$this->attachment( 10, '2026/08/photo.jpg' );
		$this->attachment( 11, '2026/08/photo.jpg' );
		$guard  = $this->guard_with_rows(
			array(
				$this->row( 10, 1, 'uploads/2026/08/photo.jpg', '2026/08/photo.jpg' ),
			),
			1
		);
		$result = $guard->evaluate( 10 );

		$this->assertSame( RemoteDeleteSafetyGuard::SHARED_REFERENCE, $result['status'] );
		$this->assertSame( array(), $result['keys'] );
	}

	public function test_second_shared_attachment_becomes_eligible_after_first_reference_is_deleted(): void {
		$this->attachment( 10, '2026/08/photo.jpg' );
		$this->attachment( 11, '2026/08/photo.jpg' );
		$guard = $this->guard_with_rows(
			array(
				$this->row( 11, 1, 'uploads/2026/08/photo.jpg', '2026/08/photo.jpg' ),
			),
			1
		);

		$this->assertSame( RemoteDeleteSafetyGuard::SHARED_REFERENCE, $guard->evaluate( 11 )['status'] );

		unset( WpStubs::$posts[10], WpStubs::$post_meta[10] );
		$result = $guard->evaluate( 11 );

		$this->assertSame( RemoteDeleteSafetyGuard::SAFE_TO_DELETE, $result['status'] );
		$this->assertSame( array( 'uploads/2026/08/photo.jpg' ), $result['keys'] );
	}

	public function test_reverse_shared_attachment_delete_order_is_also_fail_closed(): void {
		$this->attachment( 10, '2026/08/photo.jpg' );
		$this->attachment( 11, '2026/08/photo.jpg' );
		$guard = $this->guard_with_rows(
			array(
				$this->row( 10, 1, 'uploads/2026/08/photo.jpg', '2026/08/photo.jpg' ),
			),
			1
		);

		$this->assertSame( RemoteDeleteSafetyGuard::SHARED_REFERENCE, $guard->evaluate( 10 )['status'] );

		unset( WpStubs::$posts[11], WpStubs::$post_meta[11] );
		$result = $guard->evaluate( 10 );

		$this->assertSame( RemoteDeleteSafetyGuard::SAFE_TO_DELETE, $result['status'] );
		$this->assertSame( array( 'uploads/2026/08/photo.jpg' ), $result['keys'] );
	}

	public function test_returns_safe_to_delete_for_unshared_object_on_non_default_profile(): void {
		$this->attachment( 10, '2026/08/photo.jpg' );
		$guard  = $this->guard_with_rows(
			array(
				$this->row( 10, 2, 'r2/2026/08/photo.jpg', '2026/08/photo.jpg' ),
			),
			1
		);
		$result = $guard->evaluate( 10 );

		$this->assertSame( RemoteDeleteSafetyGuard::SAFE_TO_DELETE, $result['status'] );
		$this->assertSame( 2, $result['locations'][0]->storage_profile->id );
		$this->assertSame( 'r2/2026/08/photo.jpg', $result['locations'][0]->object_key );
	}

	public function test_returns_unknown_for_ambiguous_inventory_owner(): void {
		$this->attachment( 10, '2026/08/photo.jpg' );
		$guard  = $this->guard_with_rows(
			array(
				$this->row( 99, 1, 'uploads/2026/08/photo.jpg', '2026/08/photo.jpg' ),
			),
			1
		);
		$result = $guard->evaluate( 10 );

		$this->assertSame( RemoteDeleteSafetyGuard::UNKNOWN, $result['status'] );
		$this->assertSame( 'ambiguous_owner', $result['reason'] );
	}

	private function attachment( int $id, string $attached ): void {
		$post            = new \stdClass();
		$post->ID        = $id;
		$post->post_type = 'attachment';
		WpStubs::$posts[ $id ] = $post;
		WpStubs::set_meta( $id, '_wp_attached_file', $attached );
		WpStubs::set_meta(
			$id,
			'_wp_attachment_metadata',
			array(
				'file'  => $attached,
				'sizes' => array(),
			)
		);
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function guard_with_rows( array $rows, int $default_profile_id ): RemoteDeleteSafetyGuard {
		$settings = $this->createMock( \Kazcode\WpStorage\Core\Settings::class );
		$settings->method( 'get' )->willReturnCallback(
			static fn( string $key, mixed $default = null ): mixed => $key === 'object_prefix' ? 'uploads/' : $default
		);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->willReturn( $rows );

		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find_default_upload_target' )->willReturn( $this->profile( $default_profile_id ) );
		$profiles->method( 'find' )->willReturnCallback(
			fn( int $id ): StorageProfile => $this->profile( $id )
		);

		return new RemoteDeleteSafetyGuard(
			$objects,
			$profiles,
			new AttachmentFileResolver( new S3KeyResolver( $settings ) )
		);
	}

	private function profile( int $id ): StorageProfile {
		return new StorageProfile(
			$id,
			'uuid-' . $id,
			'Profile ' . $id,
			'aws',
			'bucket-' . $id,
			'us-east-1',
			'',
			false,
			'uploads/',
			'storage',
			'',
			false,
			'keys',
			'legacy',
			true,
			false,
			false,
			gmdate( 'Y-m-d H:i:s' ),
			gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row( int $attachment_id, int $profile_id, string $key, string $relative ): array {
		return array(
			'id'                  => 1,
			'attachment_id'       => $attachment_id,
			'storage_profile_id'  => $profile_id,
			'object_key'          => $key,
			'local_relative_path' => $relative,
			'remote_status'       => ObjectRemoteStatus::PRESENT,
		);
	}
}
