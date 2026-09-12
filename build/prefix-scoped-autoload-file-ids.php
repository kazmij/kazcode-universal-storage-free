<?php
/**
 * Prefix Composer files-autoload identifiers in scoped release builds.
 *
 * Composer guards `autoload.files` includes process-wide via
 * $GLOBALS['__composer_autoload_files'][$identifier]. PHP-Scoper rewrites the
 * files themselves, but leaves those identifiers unchanged, so a global
 * Composer autoloader can cause the scoped functions.php file to be skipped.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$root = $argv[1] ?? '';
if ( $root === '' || ! is_dir( $root ) ) {
	fwrite( STDERR, "Usage: php build/prefix-scoped-autoload-file-ids.php path/to/plugin-root\n" );
	exit( 1 );
}

$composer_dir = rtrim( $root, '/\\' ) . '/vendor/composer';
$files         = array(
	$composer_dir . '/autoload_files.php',
	$composer_dir . '/autoload_static.php',
);

foreach ( $files as $file ) {
	if ( ! is_file( $file ) ) {
		fwrite( STDERR, "ERROR: missing Composer autoload file: {$file}\n" );
		exit( 1 );
	}

	$contents = (string) file_get_contents( $file );
	$patched  = preg_replace_callback(
		"/'([a-f0-9]{32})'(\\s*=>\\s*(?:\\\$vendorDir|__DIR__)\\s*\\.\\s*[^,)]+)/m",
		static function ( array $matches ): string {
			return "'kazcode-universal-storage-scoped-{$matches[1]}'{$matches[2]}";
		},
		$contents,
		-1,
		$count
	);

	if ( $patched === null || $count === 0 ) {
		fwrite( STDERR, "ERROR: no Composer files-autoload identifiers patched in {$file}\n" );
		exit( 1 );
	}

	if ( file_put_contents( $file, $patched ) === false ) {
		fwrite( STDERR, "ERROR: failed writing patched Composer autoload file: {$file}\n" );
		exit( 1 );
	}
}
