<?php
/**
 * Provider preset client configuration regressions.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Services\LegacyProfileMigrator;
use Kazcode\WpStorage\Storage\ProfileS3ClientFactory;
use Kazcode\WpStorage\Storage\S3ClientFactory;
use PHPUnit\Framework\TestCase;

final class S3ClientFactoryProviderPresetTest extends TestCase {

	protected function setUp(): void {
		Support\WpStubs::reset();
	}

	protected function tearDown(): void {
		Support\WpStubs::reset();
	}

	public function test_aws_preset_ignores_stale_custom_endpoint_from_hidden_settings_fields(): void {
		update_option(
			Settings::OPTION_KEY,
			array_merge(
				Settings::defaults(),
				array(
					'bucket'           => 'kazcode-test',
					'region'           => 'us-east-1',
					'credential_mode'  => 'iam_role',
					'provider_preset'  => 'aws',
					'endpoint'         => 'http://minio:9000',
					'force_path_style' => true,
				)
			)
		);

		$client = ( new S3ClientFactory( new Settings() ) )->create();

		$this->assertSame( 'https://s3.amazonaws.com', (string) $client->getEndpoint() );
		$this->assertFalse( $client->getConfig( 'use_path_style_endpoint' ) );
	}

	public function test_aws_profile_ignores_stale_custom_endpoint_from_hidden_profile_fields(): void {
		$settings = $this->createMock( Settings::class );
		$profile  = StorageProfile::from_row(
			array(
				'id'                       => 1,
				'uuid'                     => 'aws-profile',
				'name'                     => 'AWS Profile',
				'provider_type'            => 'aws',
				'bucket'                   => 'kazcode-test',
				'region'                   => 'us-east-1',
				'endpoint'                 => 'http://minio:9000',
				'path_style'               => 1,
				'prefix'                   => '',
				'delivery_type'            => 'storage',
				'delivery_base_url'        => '',
				'cdn_includes_prefix'      => 0,
				'credential_mode'          => 'iam_role',
				'credentials_ref'          => LegacyProfileMigrator::CREDENTIALS_REF,
				'is_default_upload_target' => 1,
				'is_read_only'             => 0,
				'location_locked'          => 0,
				'created_at'               => '2026-08-31 00:00:00',
				'updated_at'               => '2026-08-31 00:00:00',
			)
		);

		$client = ProfileS3ClientFactory::create( $profile, $settings );

		$this->assertSame( 'https://s3.amazonaws.com', (string) $client->getEndpoint() );
		$this->assertFalse( $client->getConfig( 'use_path_style_endpoint' ) );
	}
}
