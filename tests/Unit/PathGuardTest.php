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
}
