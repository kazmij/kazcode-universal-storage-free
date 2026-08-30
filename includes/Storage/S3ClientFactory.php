<?php
/**
 * Builds AWS S3 client instances.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Storage;

defined( 'ABSPATH' ) || exit;

use Aws\S3\S3Client;
use Kazcode\WpStorage\Core\Settings;

/**
 * Factory for Aws\S3\S3Client.
 */
final class S3ClientFactory {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Create a configured S3 client.
	 *
	 * @throws \RuntimeException When AWS SDK is missing or credentials incomplete.
	 */
	public function create(): S3Client {
		if ( ! class_exists( S3Client::class ) ) {
			throw new \RuntimeException( 'AWS SDK for PHP is not installed. Run composer install in the plugin directory.' );
		}

		if ( ! $this->settings->is_aws_configured() ) {
			throw new \RuntimeException( 'AWS credentials or bucket settings are incomplete.' );
		}

		$config = array(
			'version' => 'latest',
			'region'  => (string) $this->settings->get( 'region', 'us-east-1' ),
		);

		if ( $this->settings->credential_mode() === 'keys' ) {
			$config['credentials'] = array(
				'key'    => (string) $this->settings->get( 'access_key_id', '' ),
				'secret' => $this->settings->get_secret_access_key(),
			);
		}
		// iam_role: omit credentials → AWS SDK default provider chain (instance profile, env, etc.).

		$is_aws_preset = (string) $this->settings->get( 'provider_preset', 'aws' ) === 'aws';
		$endpoint      = $is_aws_preset ? '' : (string) $this->settings->get( 'endpoint', '' );
		if ( $endpoint !== '' ) {
			$config['endpoint'] = $endpoint;
		}

		if ( ! $is_aws_preset && (bool) $this->settings->get( 'force_path_style', false ) ) {
			$config['use_path_style_endpoint'] = true;
		}

		return new S3Client( $config );
	}
}
