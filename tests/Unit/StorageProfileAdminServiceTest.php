<?php
/**
 * StorageProfileAdminService unit tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
use Kazcode\WpStorage\Services\LegacyProfileMigrator;
use Kazcode\WpStorage\Services\ProfileCredentialStore;
use Kazcode\WpStorage\Services\StorageProfileAdminService;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class StorageProfileAdminServiceTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_update_delivery_while_location_locked(): void {
		$profile = $this->profile( 1, true, true );
		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->willReturn( $profile );
		$profiles->method( 'count' )->willReturn( 1 );
		$profiles->expects( $this->once() )
			->method( 'update' )
			->with(
				$this->callback(
					static function ( StorageProfile $saved ): bool {
						return $saved->name === 'Renamed'
							&& $saved->delivery_type === 'cdn'
							&& $saved->delivery_base_url === 'https://cdn.example.com'
							&& $saved->bucket === 'locked-bucket';
					}
				)
			);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'count_by_profile' )->willReturn( 5 );

		$service = new StorageProfileAdminService(
			$this->createMock( Settings::class ),
			$profiles,
			$objects
		);

		$result = $service->update(
			1,
			array(
				'name'                => 'Renamed',
				'bucket'              => 'hacker-bucket',
				'region'              => 'eu-west-1',
				'delivery_type'       => 'cdn',
				'delivery_base_url'   => 'https://cdn.example.com',
				'cdn_includes_prefix' => true,
			)
		);

		$this->assertTrue( $result['success'] );
	}

	public function test_update_fills_in_bucket_when_it_was_never_set_even_if_objects_exist(): void {
		// Same bug/fix as LegacyProfileMigratorSyncTest's matching case: a profile
		// whose bucket was never actually populated (empty) is not a real
		// "location" worth protecting, even once objects reference it — refusing
		// to fill it in bricks delivery URLs with no recovery path.
		$profile = new StorageProfile(
			1,
			'uuid-1',
			'Profile 1',
			'aws',
			'',
			'us-east-1',
			'',
			false,
			'uploads/',
			'storage',
			'',
			false,
			'keys',
			LegacyProfileMigrator::CREDENTIALS_REF,
			true,
			false,
			false,
			gmdate( 'Y-m-d H:i:s' ),
			gmdate( 'Y-m-d H:i:s' ),
		);

		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->willReturn( $profile );
		$profiles->method( 'count' )->willReturn( 1 );
		$profiles->expects( $this->once() )
			->method( 'update' )
			->with(
				$this->callback(
					static fn( StorageProfile $saved ): bool => $saved->bucket === 'kazcode-test' && $saved->region === 'eu-west-1'
				)
			);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'count_by_profile' )->willReturn( 10 );

		$service = new StorageProfileAdminService(
			$this->createMock( Settings::class ),
			$profiles,
			$objects
		);

		$result = $service->update(
			1,
			array(
				'name'   => 'Profile 1',
				'bucket' => 'kazcode-test',
				'region' => 'eu-west-1',
			)
		);

		$this->assertTrue( $result['success'] );
	}

	/**
	 * Deleting one of several profiles is a Pro-owned product operation
	 * (see AdditionalStorageProfileService in the Pro plugin, and its own
	 * test suite for the actual "objects exist"/"is default" business rules)
	 * — Free's service only ever manages the sole profile directly and must
	 * degrade cleanly (not fatally) when no Pro implementation is registered.
	 */
	public function test_delete_delegates_to_pro_when_multiple_profiles_exist(): void {
		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'count' )->willReturn( 2 );
		$profiles->expects( $this->never() )->method( 'delete' );

		$result = ( new StorageProfileAdminService(
			$this->createMock( Settings::class ),
			$profiles
		) )->delete( 2 );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Pro', (string) ( $result['message'] ?? '' ) );
	}

	public function test_update_delegates_to_pro_when_multiple_profiles_exist(): void {
		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'count' )->willReturn( 2 );
		$profiles->expects( $this->never() )->method( 'update' );

		$result = ( new StorageProfileAdminService(
			$this->createMock( Settings::class ),
			$profiles
		) )->update( 2, array( 'name' => 'Renamed' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Pro', (string) ( $result['message'] ?? '' ) );
	}

	public function test_set_default_delegates_to_pro_when_multiple_profiles_exist(): void {
		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->willReturn( $this->profile( 2, false, false ) );
		$profiles->method( 'count' )->willReturn( 2 );
		$profiles->expects( $this->never() )->method( 'set_default_upload_target' );

		$result = ( new StorageProfileAdminService(
			$this->createMock( Settings::class ),
			$profiles
		) )->set_default( 2 );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Pro', (string) ( $result['message'] ?? '' ) );
	}

	/**
	 * A 2nd+ profile is a Pro product operation, physically implemented only
	 * in the Pro plugin — Free's create() has no working code path for it and
	 * must degrade cleanly (not fatally) when no Pro implementation is
	 * registered. See AdditionalStorageProfileServiceTest (Pro) for the actual
	 * create/validate/credential business rules for a 2nd+ profile.
	 */
	public function test_create_second_profile_requires_pro(): void {
		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'count' )->willReturn( 1 );
		$profiles->expects( $this->never() )->method( 'insert' );

		$result = ( new StorageProfileAdminService(
			$this->createMock( Settings::class ),
			$profiles
		) )->create(
			array(
				'name'   => 'Second',
				'bucket' => 'other-bucket',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Pro', (string) ( $result['message'] ?? '' ) );
	}

	/**
	 * Per-profile custom credentials only have a purpose once there's more
	 * than one profile to differentiate — meaningless (not a paywalled
	 * feature) for Free's sole profile, so the input is simply ignored rather
	 * than rejected: the profile is still created, using site-wide creds.
	 */
	public function test_custom_credentials_input_ignored_for_sole_profile(): void {
		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'count' )->willReturn( 0 );
		$profiles->method( 'insert' )->willReturn( 1 );
		$profiles->method( 'find' )->willReturn( $this->profile( 1, true, false ) );

		$result = ( new StorageProfileAdminService(
			$this->createMock( Settings::class ),
			$profiles,
			$this->createMock( ObjectRepository::class )
		) )->create(
			array(
				'name'                 => 'Only Profile',
				'bucket'               => 'bucket-1',
				'use_site_credentials' => false,
				'access_key_id'        => 'R2KEY',
				'secret_access_key'    => 'r2-secret',
			)
		);

		$this->assertTrue( $result['success'], (string) ( $result['message'] ?? '' ) );
		$this->assertSame( LegacyProfileMigrator::CREDENTIALS_REF, $result['profile']['credentials_ref'] );
	}

	private function profileWithCredentials( int $id, string $credentials_ref ): StorageProfile {
		return new StorageProfile(
			$id,
			'uuid-' . $id,
			'Profile ' . $id,
			'r2',
			'bucket-' . $id,
			'auto',
			'https://acct.r2.cloudflarestorage.com',
			true,
			'uploads/',
			'storage',
			'',
			false,
			'keys',
			$credentials_ref,
			false,
			false,
			false,
			gmdate( 'Y-m-d H:i:s' ),
			gmdate( 'Y-m-d H:i:s' ),
		);
	}

	private function profile( int $id, bool $default, bool $location_locked ): StorageProfile {
		return new StorageProfile(
			$id,
			'uuid-' . $id,
			'Profile ' . $id,
			'aws',
			$location_locked ? 'locked-bucket' : 'bucket-' . $id,
			'us-east-1',
			'',
			false,
			'uploads/',
			'storage',
			'',
			false,
			'keys',
			LegacyProfileMigrator::CREDENTIALS_REF,
			$default,
			false,
			$location_locked,
			gmdate( 'Y-m-d H:i:s' ),
			gmdate( 'Y-m-d H:i:s' ),
		);
	}
}
