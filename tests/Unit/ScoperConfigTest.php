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

	private const AWS_FUNCTIONS_AUTOLOAD_ID = '8a9dc1de0ca7e01f3e08231539562f61';

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

	public function test_scoped_composer_files_autoload_ids_do_not_collide_with_upstream_aws_ids(): void {
		$root = $this->make_composer_files_autoload_fixture();

		$script = dirname( __DIR__, 2 ) . '/build/prefix-scoped-autoload-file-ids.php';
		$this->assertFileExists( $script );
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $root ) . ' 2>&1';
		exec( $command, $output, $exit_code );

		$this->assertSame( 0, $exit_code, implode( "\n", $output ) );

		$autoload_files = require $root . '/vendor/composer/autoload_files.php';
		$this->assertArrayHasKey( 'kazcode-universal-storage-scoped-' . self::AWS_FUNCTIONS_AUTOLOAD_ID, $autoload_files );
		$this->assertArrayNotHasKey( self::AWS_FUNCTIONS_AUTOLOAD_ID, $autoload_files );

		require $root . '/vendor/composer/autoload_static.php';
		$this->assertArrayHasKey(
			'kazcode-universal-storage-scoped-' . self::AWS_FUNCTIONS_AUTOLOAD_ID,
			\Composer\Autoload\KazcodeScopedAutoloadFileIdFixture::$files
		);
		$this->assertArrayNotHasKey(
			self::AWS_FUNCTIONS_AUTOLOAD_ID,
			\Composer\Autoload\KazcodeScopedAutoloadFileIdFixture::$files
		);

		$previous_autoload_files = $GLOBALS['__composer_autoload_files'] ?? null;
		try {
			$GLOBALS['__composer_autoload_files'] = array(
				self::AWS_FUNCTIONS_AUTOLOAD_ID => true,
			);

			require $root . '/vendor/autoload.php';

			$this->assertTrue(
				function_exists( 'Kazcode\\WpStorage\\Vendor\\Aws\\manifest' ),
				'scoped Aws\\manifest() must load even when global Composer already marked the upstream AWS functions.php ID loaded'
			);
		} finally {
			if ( $previous_autoload_files === null ) {
				unset( $GLOBALS['__composer_autoload_files'] );
			} else {
				$GLOBALS['__composer_autoload_files'] = $previous_autoload_files;
			}
		}
	}

	private function make_composer_files_autoload_fixture(): string {
		$root = sys_get_temp_dir() . '/kazus-scoped-autoload-' . bin2hex( random_bytes( 8 ) );
		$this->assertTrue( mkdir( $root . '/vendor/composer', 0777, true ) );
		$this->assertTrue( mkdir( $root . '/vendor/aws/aws-sdk-php/src', 0777, true ) );

		file_put_contents(
			$root . '/vendor/aws/aws-sdk-php/src/functions.php',
			"<?php\nnamespace Kazcode\\WpStorage\\Vendor\\Aws;\nfunction manifest() { return 'scoped'; }\n"
		);
		file_put_contents(
			$root . '/vendor/composer/autoload_files.php',
			"<?php\n\$vendorDir = dirname(__DIR__);\nreturn array(\n    '" . self::AWS_FUNCTIONS_AUTOLOAD_ID . "' => \$vendorDir . '/aws/aws-sdk-php/src/functions.php'\n);\n"
		);
		file_put_contents(
			$root . '/vendor/composer/autoload_static.php',
			"<?php\nnamespace Composer\\Autoload;\nclass KazcodeScopedAutoloadFileIdFixture\n{\n    public static \$files = array (\n        '" . self::AWS_FUNCTIONS_AUTOLOAD_ID . "' => __DIR__ . '/..' . '/aws/aws-sdk-php/src/functions.php'\n    );\n}\n"
		);
		file_put_contents(
			$root . '/vendor/autoload.php',
			"<?php\n\$files = require __DIR__ . '/composer/autoload_files.php';\nforeach (\$files as \$identifier => \$file) {\n    if (empty(\$GLOBALS['__composer_autoload_files'][\$identifier])) {\n        \$GLOBALS['__composer_autoload_files'][\$identifier] = true;\n        require \$file;\n    }\n}\n"
		);

		return $root;
	}
}
