<?php
/**
 * Test-only MU plugin that loads a foreign Composer dependency tree before
 * normal plugins.
 *
 * @package Kazcode\WpStorage
 */

$autoload = getenv( 'KAZUS_FOREIGN_VENDOR_AUTOLOAD' ) ?: '/tmp/kazus-foreign-vendor/autoload.php';
if ( is_readable( $autoload ) ) {
	require_once $autoload;
}
