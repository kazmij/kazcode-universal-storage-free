<?php
/**
 * Characterization: live settings drive URL generation.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Storage\PublicUrlResolver;

final class UrlSettingsRetargetCharacterizationTest extends TestCase {

	public function test_bucket_change_retargets_default_s3_url_for_same_relative_path(): void {
		$relative = '2026/08/photo.jpg';

		$url_a = $this->resolver(
			array(
				'cdn_url'         => '',
				'public_base_url' => '',
				'bucket'          => 'bucket-a',
				'region'          => 'eu-central-1',
				'object_prefix'   => 'uploads/',
				'endpoint'        => '',
			)
		)->url_for_relative($relative);

		$url_b = $this->resolver(
			array(
				'cdn_url'         => '',
				'public_base_url' => '',
				'bucket'          => 'bucket-b',
				'region'          => 'eu-central-1',
				'object_prefix'   => 'uploads/',
				'endpoint'        => '',
			)
		)->url_for_relative($relative);

		$this->assertSame(
			'https://bucket-a.s3.eu-central-1.amazonaws.com/uploads/2026/08/photo.jpg',
			$url_a
		);
		$this->assertSame(
			'https://bucket-b.s3.eu-central-1.amazonaws.com/uploads/2026/08/photo.jpg',
			$url_b
		);
		$this->assertNotSame($url_a, $url_b, 'CONFIRMED: no per-attachment location snapshot');
	}

	public function test_cdn_change_retargets_without_changing_relative_path(): void {
		$relative = '2026/08/photo.jpg';
		$url_old  = $this->resolver(
			array(
				'cdn_url'       => 'https://old.cdn.example',
				'object_prefix' => 'wordpress/',
			)
		)->url_for_relative($relative);
		$url_new = $this->resolver(
			array(
				'cdn_url'       => 'https://new.cdn.example',
				'object_prefix' => 'wordpress/',
			)
		)->url_for_relative($relative);

		$this->assertSame('https://old.cdn.example/2026/08/photo.jpg', $url_old);
		$this->assertSame('https://new.cdn.example/2026/08/photo.jpg', $url_new);
	}

	/**
	 * @param array<string, mixed> $map
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
}
