<?php
/**
 * Tests S3 connectivity from admin / CLI.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Storage\S3Storage;

/**
 * Connection test: client → HeadBucket → Put → Head → Delete.
 */
final class ConnectionTestService {

	private Settings $settings;
	private S3Storage $storage;

	public function __construct(Settings $settings, S3Storage $storage) {
		$this->settings = $settings;
		$this->storage  = $storage;
	}

	/**
	 * Run connection test.
	 *
	 * @return array{success:bool,steps:list<array{name:string,ok:bool,detail:string}>}
	 */
	public function run(): array {
		$steps = array();

		if (!$this->settings->is_aws_configured()) {
			return array(
				'success' => false,
				'steps'   => array(
					array(
						'name'   => 'configuration',
						'ok'     => false,
						'detail' => 'Access Key ID, Secret Access Key, Region, and Bucket are required.',
					),
				),
			);
		}

		try {
			$this->storage->client();
			$steps[] = array(
				'name'   => 'client',
				'ok'     => true,
				'detail' => 'S3 client initialized.',
			);
		} catch (\Throwable $e) {
			return array(
				'success' => false,
				'steps'   => array(
					array(
						'name'   => 'client',
						'ok'     => false,
						'detail' => 'Failed to initialize S3 client.',
					),
				),
			);
		}

		try {
			$this->storage->assert_bucket_exists();
			$steps[] = array(
				'name'   => 'bucket',
				'ok'     => true,
				'detail' => 'Bucket is reachable.',
			);
		} catch (\Throwable $e) {
			$steps[] = array(
				'name'   => 'bucket',
				'ok'     => false,
				'detail' => 'Unable to access bucket.',
			);
			return array(
				'success' => false,
				'steps'   => $steps,
			);
		}

		$relative = 's3ms-connection-test/' . wp_generate_password(12, false) . '.txt';
		$tmp      = wp_tempnam('s3ms-test');
		if ($tmp === false) {
			$steps[] = array(
				'name'   => 'upload',
				'ok'     => false,
				'detail' => 'Unable to create temporary file.',
			);
			return array(
				'success' => false,
				'steps'   => $steps,
			);
		}

		file_put_contents($tmp, 's3ms-connection-test');

		$key = '';
		try {
			$key = $this->storage->upload_file($tmp, $relative);
			$steps[] = array(
				'name'   => 'upload',
				'ok'     => true,
				'detail' => 'Test object uploaded.',
			);

			$head = $this->storage->head_key($key);
			if (empty($head['exists'])) {
				throw new \RuntimeException('HEAD failed after upload.');
			}
			$steps[] = array(
				'name'   => 'head',
				'ok'     => true,
				'detail' => 'HEAD succeeded.',
			);

			$steps[] = $this->check_public_access($key);

			$this->storage->delete_keys(array($key));
			$key = '';
			$steps[] = array(
				'name'   => 'delete',
				'ok'     => true,
				'detail' => 'Test object deleted.',
			);
		} catch (\Throwable $e) {
			if ($key !== '') {
				try {
					$this->storage->delete_keys(array($key));
				} catch (\Throwable $ignored) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				}
			}
			$steps[] = array(
				'name'   => 'upload',
				'ok'     => false,
				'detail' => 'Upload/HEAD/delete failed.',
			);
			wp_delete_file($tmp);
			return array(
				'success' => false,
				'steps'   => $steps,
			);
		}

		wp_delete_file($tmp);

		return array(
			'success' => true,
			'steps'   => $steps,
		);
	}

	/**
	 * Warns (without failing the overall test) when the bucket won't actually
	 * serve media publicly — connectivity can be perfect while every offloaded
	 * image still 403s in a browser, which is confusing to discover only after
	 * migrating a whole library. Skipped entirely when Private Media is on,
	 * since a private bucket is then the intended, correct configuration.
	 *
	 * @return array{name:string,ok:bool,detail:string}
	 */
	private function check_public_access(string $key): array {
		if ($this->settings->is_private_media()) {
			return array(
				'name'   => 'public_access',
				'ok'     => true,
				'detail' => 'Private Media is enabled — delivery uses signed URLs, so bucket-level public access was not checked.',
			);
		}

		$url = $this->storage->urls()->url_for_key($key);
		if ($url === '') {
			return array(
				'name'   => 'public_access',
				'ok'     => true,
				'detail' => 'Could not build a delivery URL to check — skipped.',
			);
		}

		// wp_safe_remote_get(), not wp_remote_get(): $url is built from
		// admin-configured storage/CDN settings, which a malicious or
		// compromised admin account (or a copy-pasted bad CDN URL) could
		// point at an internal/private address — the safe variant applies
		// WordPress core's SSRF protections (blocks loopback/private/link-local
		// IPs, including on redirect) before the request is made.
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 2,
			)
		);

		if (is_wp_error($response)) {
			return array(
				'name'   => 'public_access',
				'ok'     => true,
				'detail' => 'Could not verify public access (request failed: ' . $response->get_error_message() . ') — check manually that offloaded media loads in a browser.',
			);
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code === 200) {
			return array(
				'name'   => 'public_access',
				'ok'     => true,
				'detail' => 'Bucket serves objects publicly — offloaded media will load directly.',
			);
		}

		if ($code === 403 || $code === 401) {
			return array(
				'name'   => 'public_access',
				'ok'     => false,
				'detail' => "Bucket denied anonymous access (HTTP {$code}) — offloaded media will NOT display via its direct URL. Enable public read on the bucket, put a CDN in front of it, or enable Private Media (Pro) for signed URLs.",
			);
		}

		return array(
			'name'   => 'public_access',
			'ok'     => true,
			'detail' => "Public access check was inconclusive (HTTP {$code}) — verify manually that offloaded media loads in a browser.",
		);
	}
}
