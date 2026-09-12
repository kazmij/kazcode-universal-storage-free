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

/**
 * PHP-Scoper only rewrites `Aws\...` references inside `vendor/`; it does not
 * rewrite the plugin's own `includes/` source (that tree is deliberately left
 * in the global namespace via `exclude-namespaces` so the same code runs
 * against unscoped `Aws\` in dev/test). `includes/` still imports the SDK as
 * plain `Aws\S3\S3Client` etc., so bridge those three classes the plugin
 * actually uses to their scoped equivalents here, once, right after the
 * scoped autoloader is registered. Without this the release build fatals
 * with "Class Aws\S3\S3Client not found" the moment anything touches S3.
 *
 * Keep this list in sync with the `use Aws\...` imports under `includes/`.
 */
foreach (
	array(
		'Aws\\S3\\S3Client'                    => 'Kazcode\\WpStorage\\Vendor\\Aws\\S3\\S3Client',
		'Aws\\S3\\MultipartUploader'            => 'Kazcode\\WpStorage\\Vendor\\Aws\\S3\\MultipartUploader',
		'Aws\\Exception\\MultipartUploadException' => 'Kazcode\\WpStorage\\Vendor\\Aws\\Exception\\MultipartUploadException',
	) as $unscoped => $scoped
) {
	if ( ! class_exists( $unscoped, false ) && class_exists( $scoped ) ) {
		class_alias( $scoped, $unscoped );
	}
}
