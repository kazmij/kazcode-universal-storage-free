<?php
/**
 * PublicUrlResolver unit tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Storage\PublicUrlResolver;

final class PublicUrlResolverTest extends TestCase {

	/**
	 * @param array<string, mixed> $map Settings map.
	 */
	private function resolver(array $map): PublicUrlResolver {
		$settings = $this->createMock(Settings::class);
		$settings->method('get')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($map): mixed {
				return $map[ $key ] ?? $default;
			}
		);
		return new PublicUrlResolver($settings);
	}

	public function test_cdn_priority(): void {
		$url = $this->resolver(
			array(
				'cdn_url'         => 'https://cdn.example.com',
				'public_base_url' => 'https://media.example.com',
				'bucket'          => 'my-bucket',
				'region'          => 'us-east-1',
				'object_prefix'   => 'wordpress/',
			)
		)->url_for_relative('2026/08/photo.jpg');

		$this->assertSame('https://cdn.example.com/2026/08/photo.jpg', $url);
	}

	public function test_public_base_fallback(): void {
		$url = $this->resolver(
			array(
				'cdn_url'         => '',
				'public_base_url' => 'https://media.example.com',
				'bucket'          => 'my-bucket',
				'region'          => 'eu-west-1',
				'object_prefix'   => '',
			)
		)->url_for_relative('2026/08/photo.jpg');

		$this->assertSame('https://media.example.com/2026/08/photo.jpg', $url);
	}

	public function test_default_s3_url_includes_prefix(): void {
		$url = $this->resolver(
			array(
				'cdn_url'         => '',
				'public_base_url' => '',
				'bucket'          => 'my-bucket',
				'region'          => 'eu-west-1',
				'object_prefix'   => 'wordpress/',
				'endpoint'        => '',
				'force_path_style'=> false,
			)
		)->url_for_relative('2026/08/photo.jpg');

		$this->assertSame('https://my-bucket.s3.eu-west-1.amazonaws.com/wordpress/2026/08/photo.jpg', $url);
	}

	public function test_encodes_spaces(): void {
		$url = $this->resolver(
			array(
				'cdn_url'         => 'https://cdn.example.com',
				'public_base_url' => '',
				'object_prefix'   => '',
			)
		)->url_for_relative('2026/08/my photo.jpg');

		$this->assertSame('https://cdn.example.com/2026/08/my%20photo.jpg', $url);
	}
}
