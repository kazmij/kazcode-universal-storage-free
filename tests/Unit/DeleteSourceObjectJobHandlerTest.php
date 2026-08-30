<?php
/**
 * DeleteSourceObjectJobHandler safety tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\Queue\Jobs\DeleteSourceObjectJobHandler;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
use Kazcode\WpStorage\Storage\ProfileStorageGateway;
use Kazcode\WpStorage\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class DeleteSourceObjectJobHandlerTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_source_cleanup_skips_remote_delete_when_same_key_has_active_reference(): void {
		$profile = $this->profile( 1 );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_profile_and_key' )->with( 1, 'uploads/2026/08/photo.jpg' )->willReturn(
			array(
				array(
					'attachment_id'  => 10,
					'remote_status'  => ObjectRemoteStatus::STALE,
					'object_key'      => 'uploads/2026/08/photo.jpg',
				),
				array(
					'attachment_id'  => 11,
					'remote_status'  => ObjectRemoteStatus::PRESENT,
					'object_key'      => 'uploads/2026/08/photo.jpg',
				),
			)
		);
		$objects->expects( $this->never() )->method( 'mark_deleted_by_profile_key_if_stale' );

		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->with( 1 )->willReturn( $profile );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'delete_key' );

		$handler = new DeleteSourceObjectJobHandler(
			$objects,
			$profiles,
			$this->createMock( Settings::class ),
			static fn (): ProfileStorageGateway => $gateway
		);

		$result = $handler->handle(
			array(
				'source_profile_id' => 1,
				'object_key'        => 'uploads/2026/08/photo.jpg',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'skipped', strtolower( (string) ( $result['message'] ?? '' ) ) );
	}

	public function test_source_cleanup_deletes_only_stale_unshared_key(): void {
		$profile = $this->profile( 1 );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_profile_and_key' )->with( 1, 'uploads/2026/08/photo.jpg' )->willReturn(
			array(
				array(
					'attachment_id'  => 10,
					'remote_status'  => ObjectRemoteStatus::STALE,
					'object_key'      => 'uploads/2026/08/photo.jpg',
				),
			)
		);
		$objects->expects( $this->once() )
			->method( 'mark_deleted_by_profile_key_if_stale' )
			->with( 1, 'uploads/2026/08/photo.jpg' )
			->willReturn( 1 );

		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->with( 1 )->willReturn( $profile );

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->once() )->method( 'delete_key' )->with( 'uploads/2026/08/photo.jpg' );

		$handler = new DeleteSourceObjectJobHandler(
			$objects,
			$profiles,
			$this->createMock( Settings::class ),
			static fn (): ProfileStorageGateway => $gateway
		);

		$result = $handler->handle(
			array(
				'source_profile_id' => 1,
				'object_key'        => 'uploads/2026/08/photo.jpg',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'deleted', strtolower( (string) ( $result['message'] ?? '' ) ) );
	}

	private function profile( int $id ): StorageProfile {
		$now = gmdate( 'Y-m-d H:i:s' );
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
			'legacy_default',
			false,
			false,
			false,
			$now,
			$now
		);
	}
}
