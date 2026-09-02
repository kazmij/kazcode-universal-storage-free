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

foreach (
	array(
		'Kazcode\\WpStorage\\Vendor\\Aws\\manifest'     => __DIR__ . '/aws/aws-sdk-php/src/functions.php',
		'Kazcode\\WpStorage\\Vendor\\JmesPath\\search' => __DIR__ . '/mtdowling/jmespath.php/src/JmesPath.php',
	) as $function => $file
) {
	if ( ! function_exists( $function ) && is_readable( $file ) ) {
		require_once $file;
	}
}
