<?php
/**
 * Sync readme.txt Stable tag / Version from kazcode-universal-storage.php header.
 *
 * Usage: php build/sync-readme-version.php
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

$root    = dirname( __DIR__ );
$main    = $root . '/kazcode-universal-storage.php';
$readme  = $root . '/readme.txt';
$content = file_get_contents( $main );
if ( $content === false || ! preg_match( "/define\\('KAZUS_VERSION',\\s*'([^']+)'\\)/", $content, $m ) ) {
	fwrite( STDERR, "Could not read KAZUS_VERSION from kazcode-universal-storage.php\n" );
	exit( 1 );
}
$version = $m[1];

if ( ! is_readable( $readme ) ) {
	fwrite( STDERR, "readme.txt not found\n" );
	exit( 1 );
}

$text = file_get_contents( $readme );
if ( $text === false ) {
	exit( 1 );
}

$text = preg_replace( '/^Stable tag:.*$/m', 'Stable tag: ' . $version, $text, 1, $count_stable );
if ( $count_stable === 0 ) {
	fwrite( STDERR, "Stable tag line missing in readme.txt\n" );
	exit( 1 );
}

file_put_contents( $readme, $text );
echo "readme.txt synced to {$version}\n";
