<?php
/**
 * Builds S3 clients bound to a Storage Profile.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Storage;

defined( 'ABSPATH' ) || exit;

use Aws\S3\S3Client;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Services\LegacyProfileMigrator;
use Kazcode\WpStorage\Services\ProfileCredentialStore;

/**
 * Profile-scoped client factory (credentials via Settings for legacy profile).
 */
final class ProfileS3ClientFactory {

	public static function create( StorageProfile $profile, Settings $settings ): S3Client {
		if ( ! class_exists( S3Client::class ) ) {
			throw new \RuntimeException( 'AWS SDK for PHP is not installed.' );
		}

		if ( $profile->bucket === '' ) {
			throw new \RuntimeException( 'Storage profile bucket is not configured.' );
		}

		$config = array(
			'version' => 'latest',
			'region'  => $profile->region !== '' ? $profile->region : 'us-east-1',
		);

		if ( $profile->credential_mode === 'keys' ) {
			if ( $profile->credentials_ref === LegacyProfileMigrator::CREDENTIALS_REF ) {
				$config['credentials'] = array(
					'key'    => (string) $settings->get( 'access_key_id', '' ),
					'secret' => $settings->get_secret_access_key(),
				);
			} else {
				$store  = new ProfileCredentialStore();
				$key    = $store->get_access_key_id( $profile->credentials_ref );
				$secret = $store->get_secret( $profile->credentials_ref );
				if ( $key === '' || $secret === '' ) {
					throw new \RuntimeException( 'Profile credentials are not configured: ' . $profile->credentials_ref );
				}
				$config['credentials'] = array(
					'key'    => $key,
					'secret' => $secret,
				);
			}
		}

		if ( $profile->endpoint !== '' ) {
			$config['endpoint'] = $profile->endpoint;
		}
		if ( $profile->path_style ) {
			$config['use_path_style_endpoint'] = true;
		}

		return new S3Client( $config );
	}
}
