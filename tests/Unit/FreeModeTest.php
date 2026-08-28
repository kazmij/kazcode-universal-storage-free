<?php
/**
 * Free-alone behavior: the picture a WordPress.org install (core only,
 * no Pro plugin registered) must present.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\Features;
use Kazcode\WpStorage\Core\ProServices;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
use Kazcode\WpStorage\Services\StorageProfileAdminService;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class FreeModeTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_is_pro_active_false(): void {
		$this->assertFalse( Features::is_pro_active() );
		$this->assertSame( 'lite', Features::plan() );
	}

	public function test_single_profile_create_succeeds(): void {
		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'count' )->willReturn( 0 );
		$profiles->expects( $this->once() )->method( 'insert' )->willReturn( 1 );
		$profiles->method( 'find' )->willReturn(
			new \Kazcode\WpStorage\Domain\StorageProfile(
				1, 'uuid-1', 'Default', 'aws', 'bucket', 'us-east-1', '', false,
				'', 'storage', '', false, 'keys', 'legacy_default', true, false, false,
				gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s' )
			)
		);

		$result = ( new StorageProfileAdminService(
			$this->createMock( \Kazcode\WpStorage\Core\Settings::class ),
			$profiles,
			$this->createMock( ObjectRepository::class )
		) )->create( array( 'name' => 'Default', 'bucket' => 'bucket' ) );

		$this->assertTrue( $result['success'], (string) ( $result['message'] ?? '' ) );
	}

	public function test_second_profile_create_blocked(): void {
		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'count' )->willReturn( 1 );
		$profiles->expects( $this->never() )->method( 'insert' );

		$result = ( new StorageProfileAdminService(
			$this->createMock( \Kazcode\WpStorage\Core\Settings::class ),
			$profiles
		) )->create( array( 'name' => 'Second', 'bucket' => 'bucket-2' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Pro', (string) ( $result['message'] ?? '' ) );
	}

	public function test_provider_migration_unavailable(): void {
		// No Pro module registered -> no factory -> ProServices returns null.
		// This is the actual runtime gate now (see docs/FREE-PRO-CODE-AUDIT.md);
		// callers (CLI storage_migrate, REST /storage-migrate*) degrade to the
		// same "requires Pro" message this would throw.
		$this->assertNull( ProServices::get( 'storage_migration' ) );
		$this->assertNull( ProServices::get( 'orphan_scan' ) );
	}

	public function test_basic_offload_migrate_verify_restore_remain_ungated(): void {
		// None of these are in Features::pro_feature_keys() — a Free install
		// must be able to run its core workflow end to end. Restore mechanics
		// themselves are covered in DeleteAttachmentCharacterizationTest; this
		// asserts the Free/Pro boundary specifically.
		foreach ( array( 'migrate_existing', 'restore', 'verify', 'retry', 'offload' ) as $key ) {
			$this->assertNotContains( $key, Features::pro_feature_keys(), "{$key} must not be Pro-gated" );
		}
	}
}
