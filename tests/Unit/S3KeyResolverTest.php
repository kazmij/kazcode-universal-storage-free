<?php
/**
 * S3KeyResolver unit tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Storage\S3KeyResolver;

final class S3KeyResolverTest extends TestCase {

	private function resolver(string $prefix = ''): S3KeyResolver {
		$settings = $this->createMock(Settings::class);
		$settings->method('get')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($prefix): mixed {
				return $key === 'object_prefix' ? $prefix : $default;
			}
		);
		return new S3KeyResolver($settings);
	}

	public function test_resolve_without_prefix(): void {
		$this->assertSame('2026/08/photo.jpg', $this->resolver()->resolve('2026/08/photo.jpg'));
	}

	public function test_resolve_with_prefix(): void {
		$this->assertSame('wordpress/2026/08/photo.jpg', $this->resolver('wordpress/')->resolve('2026/08/photo.jpg'));
	}

	public function test_strips_uploads_prefix(): void {
		$this->assertSame('2026/08/photo.jpg', $this->resolver()->normalize_relative('wp-content/uploads/2026/08/photo.jpg'));
	}

	public function test_relative_for_size_basename(): void {
		$r = $this->resolver();
		$this->assertSame(
			'2026/08/photo-150x150.jpg',
			$r->relative_for_size('2026/08/photo.jpg', 'photo-150x150.jpg')
		);
	}

	public function test_relative_for_size_full_path(): void {
		$r = $this->resolver();
		$this->assertSame(
			'2026/08/photo-150x150.jpg',
			$r->relative_for_size('2026/08/photo.jpg', '2026/08/photo-150x150.jpg')
		);
	}
}
