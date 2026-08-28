<?php
/**
 * Aggregate third-party LICENSE notices from composer vendor/ (release staging).
 *
 * Usage: php build/collect-licenses.php [target-dir]
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

$target = $argv[1] ?? dirname( __DIR__ );
$installed_file = rtrim( $target, '/' ) . '/vendor/composer/installed.json';
if ( ! is_readable( $installed_file ) ) {
	fwrite( STDERR, "Missing {$installed_file}\n" );
	exit( 1 );
}

/** @var array<string, mixed> $data */
$data     = json_decode( (string) file_get_contents( $installed_file ), true );
$packages = $data['packages'] ?? $data;
if ( ! is_array( $packages ) ) {
	fwrite( STDERR, "Invalid installed.json\n" );
	exit( 1 );
}

$lines   = array();
$lines[] = 'THIRD-PARTY LICENSES — KAZCODE Universal Storage';
$lines[] = 'Generated for release ZIP. Plugin license: GPL-2.0-or-later.';
$lines[] = str_repeat( '=', 72 );
$lines[] = '';

foreach ( $packages as $package ) {
	if ( ! is_array( $package ) ) {
		continue;
	}
	$name    = (string) ( $package['name'] ?? 'unknown' );
	$version = (string) ( $package['version'] ?? '' );
	$license = $package['license'] ?? array();
	if ( is_string( $license ) ) {
		$license = array( $license );
	}
	$license_str = is_array( $license ) ? implode( ', ', $license ) : '';

	$lines[] = "{$name} {$version}";
	$lines[] = 'License: ' . ( $license_str !== '' ? $license_str : 'see package' );

	$install_path = (string) ( $package['install-path'] ?? '' );
	$license_file = '';
	if ( $install_path !== '' ) {
		foreach ( array( 'LICENSE', 'LICENSE.md', 'LICENSE.txt', 'LICENCE' ) as $candidate ) {
			$path = rtrim( $target, '/' ) . '/' . $install_path . '/' . $candidate;
			if ( is_readable( $path ) ) {
				$license_file = $path;
				break;
			}
		}
	}

	if ( $license_file !== '' ) {
		$body    = trim( (string) file_get_contents( $license_file ) );
		$lines[] = str_repeat( '-', 40 );
		$lines[] = $body;
	} else {
		$lines[] = '(License text not bundled; see vendor package.)';
	}

	$lines[] = '';
}

$output = rtrim( $target, '/' ) . '/THIRD-PARTY-LICENSES.txt';
file_put_contents( $output, implode( "\n", $lines ) . "\n" );
echo "Wrote {$output}\n";
