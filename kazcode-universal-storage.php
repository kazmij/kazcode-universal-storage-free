<?php
/**
 * Plugin Name:       KAZCODE Universal Storage
 * Plugin URI:        https://kazcode.net/universal-storage/
 * Description:       Offload your WordPress Media Library to Amazon S3, Cloudflare R2, or S3-compatible object storage while keeping the native Media Library UX and attachment metadata in WordPress.
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      8.3
 * Author:            KAZCODE
 * Author URI:        https://kazcode.net/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kazcode-universal-storage
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

define('KAZUS_VERSION', '1.0.0');
define('KAZUS_PLUGIN_FILE', __FILE__);
define('KAZUS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('KAZUS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('KAZUS_PLUGIN_BASENAME', plugin_basename(__FILE__));

$s3ms_autoload = KAZUS_PLUGIN_DIR . 'vendor/autoload.php';
if (is_readable($s3ms_autoload)) {
	require_once $s3ms_autoload;
}

$kazcode_scoped = KAZUS_PLUGIN_DIR . 'vendor/kazcode-scoped.php';
if (is_readable($kazcode_scoped)) {
	require_once $kazcode_scoped;
}

spl_autoload_register(
	static function (string $class): void {
		$prefix = 'Kazcode\WpStorage\\';
		if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
			return;
		}
		$relative = substr($class, strlen($prefix));
		$file     = KAZUS_PLUGIN_DIR . 'includes/' . str_replace('\\', '/', $relative) . '.php';
		if (is_readable($file)) {
			require_once $file;
		}
	}
);

/**
 * Bootstrap the plugin.
 */
function kazus_bootstrap(): void {
	\Kazcode\WpStorage\Plugin::instance()->boot();
}

add_action('plugins_loaded', 'kazus_bootstrap');

register_activation_hook(
	__FILE__,
	static function (): void {
		\Kazcode\WpStorage\Plugin::instance()->activate();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		\Kazcode\WpStorage\Plugin::instance()->deactivate();
	}
);
