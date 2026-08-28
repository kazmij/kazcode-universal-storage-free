<?php
/**
 * Uninstall handler for KAZCODE Universal Storage.
 *
 * Removes plugin options and attachment meta. Never deletes S3 bucket contents.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

delete_option('s3ms_settings');
delete_option('s3ms_encrypted_secret');

global $wpdb;

$s3ms_meta_keys = array(
	'_s3ms_status',
	'_s3ms_original_key',
	'_s3ms_offloaded_at',
	'_s3ms_last_error',
	'_s3ms_verified_at',
);

foreach ($s3ms_meta_keys as $s3ms_meta_key) {
	$wpdb->delete($wpdb->postmeta, array('meta_key' => $s3ms_meta_key));
}
