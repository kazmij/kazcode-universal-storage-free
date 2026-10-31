<?php
/**
 * PathGuard unit tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Storage\PathGuard;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class PathGuardTest extends TestCase {

	public function test_normalizes_simple_path(): void {
		$this->assertSame('2026/08/photo.jpg', PathGuard::normalize_relative('2026/08/photo.jpg'));
	}

	public function test_rejects_traversal(): void {
		$this->expectException(\InvalidArgumentException::class);
		PathGuard::normalize_relative('2026/../etc/passwd');
	}

	public function test_strips_uploads_prefix(): void {
		$this->assertSame('2026/08/a.jpg', PathGuard::normalize_relative('wp-content/uploads/2026/08/a.jpg'));
	}

	public function test_rejects_absolute(): void {
		$this->expectException(\InvalidArgumentException::class);
		PathGuard::normalize_relative('/var/www/html/wp-content/uploads/a.jpg');
	}

	public function test_resolves_attachment_relative_path_under_custom_uploads_root(): void {
		$custom_root = sys_get_temp_dir() . '/kazus-bedrock-layout-' . uniqid('', true) . '/web/app/uploads';
		mkdir($custom_root . '/2026/10', 0777, true);

		try {
			WpStubs::$uploads_basedir = $custom_root;

			$this->assertSame(
				$custom_root . '/2026/10/photo.jpg',
				PathGuard::absolute_under_uploads('2026/10/photo.jpg')
			);
			$this->assertNull(PathGuard::absolute_under_uploads('../outside.jpg'));
		} finally {
			WpStubs::$uploads_basedir = '';
			rmdir($custom_root . '/2026/10');
			rmdir($custom_root . '/2026');
			rmdir($custom_root . '/web/app/uploads');
			rmdir($custom_root . '/web/app');
			rmdir($custom_root . '/web');
			rmdir(dirname($custom_root));
		}
	}
}
