<?php
/**
 * Legacy settings → profile field mapping tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Services\LegacyProfileMigrator;

final class LegacyProfileMigratorTest extends TestCase {

	public function test_maps_aws_settings_to_profile_fields(): void {
		$fields = LegacyProfileMigrator::map_settings_to_profile_fields(
			array(
				'provider_preset'     => 'aws',
				'bucket'              => 'company-media',
				'region'              => 'eu-central-1',
				'endpoint'            => '',
				'force_path_style'    => false,
				'object_prefix'       => 'uploads',
				'cdn_url'             => 'https://media.example.com',
				'public_base_url'     => 'https://ignored.example.com',
				'cdn_includes_prefix' => true,
				'credential_mode'     => 'keys',
			)
		);

		$this->assertSame( 'aws', $fields['provider_type'] );
		$this->assertSame( 'company-media', $fields['bucket'] );
		$this->assertSame( 'eu-central-1', $fields['region'] );
		$this->assertSame( 'uploads/', $fields['prefix'] );
		$this->assertSame( 'cdn', $fields['delivery_type'] );
		$this->assertSame( 'https://media.example.com', $fields['delivery_base_url'] );
		$this->assertTrue( $fields['cdn_includes_prefix'] );
		$this->assertSame( 'keys', $fields['credential_mode'] );
		$this->assertSame( LegacyProfileMigrator::CREDENTIALS_REF, $fields['credentials_ref'] );
	}

	public function test_maps_iam_role_to_iam_mode(): void {
		$fields = LegacyProfileMigrator::map_settings_to_profile_fields(
			array(
				'credential_mode' => 'iam_role',
				'bucket'          => 'b',
			)
		);
		$this->assertSame( 'iam', $fields['credential_mode'] );
	}

	public function test_public_base_used_when_cdn_empty(): void {
		$fields = LegacyProfileMigrator::map_settings_to_profile_fields(
			array(
				'cdn_url'         => '',
				'public_base_url' => 'https://pub.example.com/',
			)
		);
		$this->assertSame( 'cdn', $fields['delivery_type'] );
		$this->assertSame( 'https://pub.example.com', $fields['delivery_base_url'] );
	}
}
