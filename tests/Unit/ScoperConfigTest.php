<?php
/**
 * PHP-Scoper config smoke test (Phase 10).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;

final class ScoperConfigTest extends TestCase {

	public function test_scoper_config_declares_vendor_prefix(): void {
		$path = dirname( __DIR__, 2 ) . '/scoper.inc.php';
		$this->assertFileExists( $path );
		$content = (string) file_get_contents( $path );
		$this->assertStringContainsString( "'prefix'", $content );
		$this->assertStringContainsString( 'Kazcode\\WpStorage\\Vendor', $content );
		$this->assertStringContainsString( 'Kazcode\WpStorage', $content );
	}

	public function test_readme_version_matches_plugin_header(): void {
		$main = (string) file_get_contents( dirname( __DIR__, 2 ) . '/kazcode-universal-storage.php' );
		$readme = (string) file_get_contents( dirname( __DIR__, 2 ) . '/readme.txt' );
		preg_match( "/define\\('KAZUS_VERSION',\\s*'([^']+)'\\)/", $main, $version );
		preg_match( '/^Stable tag:\s*(.+)$/m', $readme, $stable );
		$this->assertSame( $version[1] ?? '', trim( $stable[1] ?? '' ) );
	}
}
