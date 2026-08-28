<?php
/**
 * LegacyProfileMigrator::sync_default_profile_from_settings() — Settings page and
 * Setup Wizard saves only ever write s3ms_settings; this sync is what keeps the
 * site-credentials-linked default Storage Profile from going stale, which used to
 * silently break profile-scoped delivery URLs (ProfileDeliveryUrlResolver) even
 * though offload/verify kept working against the correct bucket.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Domain\StorageProfileRepositoryInterface;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Services\LegacyProfileMigrator;

final class LegacyProfileMigratorSyncTest extends TestCase {

	public function test_no_op_when_no_default_profile_exists(): void {
		$profiles = $this->createMock( StorageProfileRepositoryInterface::class );
		$profiles->method( 'find_default_upload_target' )->willReturn( null );
		$profiles->expects( $this->never() )->method( 'update' );

		$objects = $this->createMock( ObjectRepository::class );

		( new LegacyProfileMigrator( $this->settingsWith( array( 'bucket' => 'new-bucket' ) ), $profiles, $objects ) )
			->sync_default_profile_from_settings();
	}

	public function test_no_op_when_profile_detached_from_site_credentials(): void {
		$profile = $this->profile( array( 'credentials_ref' => 'some-pro-managed-ref' ) );

		$profiles = $this->createMock( StorageProfileRepositoryInterface::class );
		$profiles->method( 'find_default_upload_target' )->willReturn( $profile );
		$profiles->expects( $this->never() )->method( 'update' );

		$objects = $this->createMock( ObjectRepository::class );

		( new LegacyProfileMigrator( $this->settingsWith( array( 'bucket' => 'new-bucket' ) ), $profiles, $objects ) )
			->sync_default_profile_from_settings();
	}

	public function test_syncs_location_when_no_objects_reference_the_profile(): void {
		$profile = $this->profile( array( 'bucket' => 'old-bucket', 'region' => 'us-east-1' ) );

		$profiles = $this->createMock( StorageProfileRepositoryInterface::class );
		$profiles->method( 'find_default_upload_target' )->willReturn( $profile );
		$profiles->expects( $this->once() )->method( 'update' )->with(
			$this->callback(
				static function ( StorageProfile $p ): bool {
					return $p->bucket === 'new-bucket' && $p->region === 'eu-central-1' && $p->endpoint === 'http://minio:9000';
				}
			)
		);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'count_by_profile' )->with( 1 )->willReturn( 0 );

		( new LegacyProfileMigrator(
			$this->settingsWith(
				array(
					'bucket'   => 'new-bucket',
					'region'   => 'eu-central-1',
					'endpoint' => 'http://minio:9000',
				)
			),
			$profiles,
			$objects
		) )->sync_default_profile_from_settings();
	}

	public function test_does_not_repoint_location_once_objects_exist(): void {
		$profile = $this->profile( array( 'bucket' => 'old-bucket', 'region' => 'us-east-1', 'endpoint' => '' ) );

		$profiles = $this->createMock( StorageProfileRepositoryInterface::class );
		$profiles->method( 'find_default_upload_target' )->willReturn( $profile );
		$profiles->expects( $this->once() )->method( 'update' )->with(
			$this->callback(
				static function ( StorageProfile $p ): bool {
					// Location untouched — still the original bucket/region/endpoint —
					// but delivery/credential fields still follow Settings.
					return $p->bucket === 'old-bucket'
						&& $p->region === 'us-east-1'
						&& $p->endpoint === ''
						&& $p->delivery_type === 'cdn'
						&& $p->delivery_base_url === 'https://cdn.example.com';
				}
			)
		);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'count_by_profile' )->with( 1 )->willReturn( 42 );

		( new LegacyProfileMigrator(
			$this->settingsWith(
				array(
					'bucket'  => 'attempted-new-bucket',
					'region'  => 'eu-west-1',
					'cdn_url' => 'https://cdn.example.com',
				)
			),
			$profiles,
			$objects
		) )->sync_default_profile_from_settings();
	}

	public function test_syncs_location_when_bucket_was_never_set_even_if_objects_already_exist(): void {
		// Reproduces a real bug: offload() reads Settings directly, so objects can
		// exist under a profile whose own bucket field was never actually
		// populated (e.g. the very first Settings save race'd ahead of this sync,
		// or a legacy profile was seeded before Settings had a bucket at all).
		// An empty bucket is never a real "location" worth protecting — refusing
		// to fill it in once objects exist bricked ProfileDeliveryUrlResolver's
		// delivery URLs permanently, with no way to recover.
		$profile = $this->profile( array( 'bucket' => '', 'region' => 'us-east-1', 'endpoint' => '' ) );

		$profiles = $this->createMock( StorageProfileRepositoryInterface::class );
		$profiles->method( 'find_default_upload_target' )->willReturn( $profile );
		$profiles->expects( $this->once() )->method( 'update' )->with(
			$this->callback(
				static function ( StorageProfile $p ): bool {
					return $p->bucket === 'kazcode-test' && $p->region === 'us-east-1';
				}
			)
		);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'count_by_profile' )->with( 1 )->willReturn( 10 );

		( new LegacyProfileMigrator(
			$this->settingsWith(
				array(
					'bucket' => 'kazcode-test',
					'region' => 'us-east-1',
				)
			),
			$profiles,
			$objects
		) )->sync_default_profile_from_settings();
	}

	public function test_location_locked_flag_also_blocks_location_sync(): void {
		$profile = $this->profile( array( 'bucket' => 'old-bucket', 'location_locked' => true ) );

		$profiles = $this->createMock( StorageProfileRepositoryInterface::class );
		$profiles->method( 'find_default_upload_target' )->willReturn( $profile );
		$profiles->expects( $this->once() )->method( 'update' )->with(
			$this->callback(
				static fn( StorageProfile $p ): bool => $p->bucket === 'old-bucket'
			)
		);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->never() )->method( 'count_by_profile' );

		( new LegacyProfileMigrator( $this->settingsWith( array( 'bucket' => 'new-bucket' ) ), $profiles, $objects ) )
			->sync_default_profile_from_settings();
	}

	private function settingsWith( array $overrides ): Settings {
		$all = array_merge(
			array(
				'provider_preset'     => 'minio',
				'bucket'              => '',
				'region'              => 'us-east-1',
				'endpoint'            => '',
				'force_path_style'    => true,
				'object_prefix'       => '',
				'cdn_url'             => '',
				'public_base_url'     => '',
				'cdn_includes_prefix' => false,
				'credential_mode'     => 'keys',
			),
			$overrides
		);

		$settings = $this->createMock( Settings::class );
		$settings->method( 'all' )->willReturn( $all );
		return $settings;
	}

	private function profile( array $overrides ): StorageProfile {
		$now  = gmdate( 'Y-m-d H:i:s' );
		$base = array(
			'id'                       => 1,
			'uuid'                     => 'uuid-1',
			'name'                     => 'Legacy Default Storage',
			'provider_type'            => 'aws',
			'bucket'                   => '',
			'region'                   => 'us-east-1',
			'endpoint'                 => '',
			'path_style'               => false,
			'prefix'                   => '',
			'delivery_type'            => 'storage',
			'delivery_base_url'        => '',
			'cdn_includes_prefix'      => false,
			'credential_mode'          => 'keys',
			'credentials_ref'          => LegacyProfileMigrator::CREDENTIALS_REF,
			'is_default_upload_target' => true,
			'is_read_only'             => false,
			'location_locked'          => false,
			'created_at'               => $now,
			'updated_at'               => $now,
		);
		$fields = array_merge( $base, $overrides );

		return new StorageProfile(
			$fields['id'],
			$fields['uuid'],
			$fields['name'],
			$fields['provider_type'],
			$fields['bucket'],
			$fields['region'],
			$fields['endpoint'],
			$fields['path_style'],
			$fields['prefix'],
			$fields['delivery_type'],
			$fields['delivery_base_url'],
			$fields['cdn_includes_prefix'],
			$fields['credential_mode'],
			$fields['credentials_ref'],
			$fields['is_default_upload_target'],
			$fields['is_read_only'],
			$fields['location_locked'],
			$fields['created_at'],
			$fields['updated_at']
		);
	}
}
