<?php
/**
 * Release-only marker loaded after scoped vendor/autoload.php.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'KAZUS_VENDOR_SCOPED' ) ) {
	define( 'KAZUS_VENDOR_SCOPED', true );
}
