<?php
/**
 * PHPUnit bootstrap (no WordPress required for unit tests).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

// Every includes/ file guards against direct access with defined('ABSPATH') || exit —
// a real WordPress.org requirement (see common-issues) — so it must be defined before
// anything under includes/ is autoloaded, or the exit call silently kills the test run.
if (!defined('ABSPATH')) {
	define('ABSPATH', dirname(__DIR__) . '/');
}

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_readable($autoload)) {
	fwrite(STDERR, "Run composer install in the plugin directory first.\n");
	exit(1);
}
require_once $autoload;

\DG\BypassFinals::enable();

use Kazcode\WpStorage\Tests\Support\WpStubs;

// Minimal stubs used by Settings/PublicUrlResolver when WP is absent.
if (!function_exists('untrailingslashit')) {
	function untrailingslashit($value) {
		return rtrim((string) $value, '/\\');
	}
}
if (!function_exists('trailingslashit')) {
	function trailingslashit($value) {
		return untrailingslashit($value) . '/';
	}
}
if (!function_exists('sanitize_text_field')) {
	function sanitize_text_field($str) {
		return trim(strip_tags((string) $str));
	}
}
if (!function_exists('esc_url_raw')) {
	function esc_url_raw($url) {
		return filter_var((string) $url, FILTER_SANITIZE_URL) ?: (string) $url;
	}
}
if (!function_exists('sanitize_key')) {
	function sanitize_key($key) {
		$key = strtolower((string) $key);
		return (string) preg_replace('/[^a-z0-9_\-]/', '', $key);
	}
}
if (!function_exists('apply_filters')) {
	/** @var array<string, list<callable>> */
	$GLOBALS['s3ms_test_filters'] = array();
	function apply_filters($hook_name, $value, ...$args) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		foreach ($GLOBALS['s3ms_test_filters'][ (string) $hook_name ] ?? array() as $callback) {
			$value = $callback($value, ...$args);
		}
		return $value;
	}
}
if (!function_exists('add_filter')) {
	function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$GLOBALS['s3ms_test_filters'][ (string) $hook_name ][] = $callback;
	}
}
if (!function_exists('remove_all_filters')) {
	function remove_all_filters($hook_name) {
		unset($GLOBALS['s3ms_test_filters'][ (string) $hook_name ]);
	}
}
if (!function_exists('add_action')) {
	// Real WordPress implements add_action() as add_filter() against the same
	// hook storage — mirrored here so the kazus_register_job_handlers action
	// and friends work identically to a filter under test.
	function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		add_filter($hook_name, $callback, $priority, $accepted_args);
	}
}
if (!function_exists('do_action')) {
	function do_action($hook_name, ...$args) {
		foreach ($GLOBALS['s3ms_test_filters'][ (string) $hook_name ] ?? array() as $callback) {
			$callback(...$args);
		}
	}
}
if (!function_exists('__')) {
	function __($text, $domain = null) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return (string) $text;
	}
}
if (!function_exists('is_admin')) {
	function is_admin() {
		return false;
	}
}

if (!defined('AUTH_KEY')) {
	define('AUTH_KEY', 'unit-test-auth-key-' . str_repeat('a', 32));
}
if (!defined('SECURE_AUTH_KEY')) {
	define('SECURE_AUTH_KEY', 'unit-test-secure-auth-key-' . str_repeat('b', 32));
}
if (!defined('AUTH_SALT')) {
	define('AUTH_SALT', 'unit-test-auth-salt-' . str_repeat('c', 32));
}
if (!defined('SECURE_AUTH_SALT')) {
	define('SECURE_AUTH_SALT', 'unit-test-secure-auth-salt-' . str_repeat('d', 32));
}

if (!function_exists('get_post')) {
	function get_post($post) {
		$id = is_object($post) ? (int) $post->ID : (int) $post;
		return WpStubs::$posts[ $id ] ?? null;
	}
}
if (!function_exists('get_post_meta')) {
	function get_post_meta($post_id, $key = '', $single = false) {
		$id = (int) $post_id;
		if ($key === '') {
			return WpStubs::$post_meta[ $id ] ?? array();
		}
		$value = WpStubs::$post_meta[ $id ][ (string) $key ] ?? '';
		return $single ? $value : array( $value );
	}
}
if (!function_exists('update_post_meta')) {
	function update_post_meta($post_id, $meta_key, $meta_value, $prev_value = '') { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		WpStubs::$post_meta[ (int) $post_id ][ (string) $meta_key ] = $meta_value;
		return true;
	}
}
if (!function_exists('delete_post_meta')) {
	function delete_post_meta($post_id, $meta_key, $meta_value = '') { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		unset(WpStubs::$post_meta[ (int) $post_id ][ (string) $meta_key ]);
		return true;
	}
}
if (!function_exists('is_multisite')) {
	function is_multisite() {
		return WpStubs::$is_multisite;
	}
}
if (!function_exists('get_site_option')) {
	function get_site_option($option, $default = false) {
		$key = (string) $option;
		return array_key_exists($key, WpStubs::$site_options) ? WpStubs::$site_options[ $key ] : $default;
	}
}
if (!function_exists('update_site_option')) {
	function update_site_option($option, $value) {
		WpStubs::$site_options[ (string) $option ] = $value;
		return true;
	}
}
if (!function_exists('get_option')) {
	function get_option($option, $default = false) {
		$key = (string) $option;
		return array_key_exists($key, WpStubs::$options) ? WpStubs::$options[ $key ] : $default;
	}
}
if (!function_exists('update_option')) {
	function update_option($option, $value, $autoload = null) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		WpStubs::$options[ (string) $option ] = $value;
		return true;
	}
}
if (!function_exists('add_option')) {
	function add_option($option, $value = '', $deprecated = '', $autoload = 'yes') { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$key = (string) $option;
		if (array_key_exists($key, WpStubs::$options)) {
			return false;
		}
		WpStubs::$options[ $key ] = $value;
		return true;
	}
}
if (!function_exists('delete_option')) {
	function delete_option($option) {
		unset(WpStubs::$options[ (string) $option ]);
		return true;
	}
}
if (!function_exists('wp_upload_dir')) {
	function wp_upload_dir($time = null, $create_dir = true, $refresh_cache = false) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$basedir = WpStubs::$uploads_basedir !== '' ? WpStubs::$uploads_basedir : sys_get_temp_dir() . '/s3ms-uploads';
		return array(
			'path'    => $basedir,
			'url'     => 'http://example.test/uploads',
			'subdir'  => '',
			'basedir' => $basedir,
			'baseurl' => 'http://example.test/uploads',
			'error'   => false,
		);
	}
}
if (!function_exists('wp_delete_file')) {
	function wp_delete_file($file) {
		WpStubs::$deleted_files[] = (string) $file;
		if (is_file($file)) {
			return unlink($file);
		}
		return false;
	}
}
if (!function_exists('wp_mkdir_p')) {
	function wp_mkdir_p($target) {
		return is_dir($target) || mkdir($target, 0777, true);
	}
}
if (!function_exists('wp_tempnam')) {
	function wp_tempnam($filename = '', $dir = '') { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$tmp = tempnam(sys_get_temp_dir(), 's3ms-test-');
		return $tmp === false ? false : $tmp;
	}
}
if (!function_exists('wp_generate_password')) {
	function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return substr(bin2hex(random_bytes((int) max(1, $length))), 0, (int) $length);
	}
}
if (!function_exists('is_wp_error')) {
	function is_wp_error($thing) {
		return is_object($thing) && method_exists($thing, 'get_error_message');
	}
}
if (!function_exists('wp_remote_get')) {
	// Tests set $GLOBALS['s3ms_test_wp_remote_get'] to a WP_Error, or an array
	// like ['response' => ['code' => 200]], to control what this returns.
	function wp_remote_get($url, $args = array()) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return $GLOBALS['s3ms_test_wp_remote_get'] ?? array('response' => array('code' => 200));
	}
}
if (!function_exists('wp_safe_remote_get')) {
	// Real WP core: wp_safe_remote_get() is wp_remote_get() plus the
	// 'reject_unsafe_urls' arg, which core's http_request_host_is_external
	// filter uses to block loopback/private/reserved IPs (incl. on
	// redirect). Tests reuse the same s3ms_test_wp_remote_get hook — this
	// shim only needs to prove the safe API is the one actually called.
	function wp_safe_remote_get($url, $args = array()) {
		$args['reject_unsafe_urls'] = true;
		$GLOBALS['s3ms_test_wp_safe_remote_get_args'] = $args;
		return wp_remote_get($url, $args);
	}
}
if (!function_exists('wp_remote_retrieve_response_code')) {
	function wp_remote_retrieve_response_code($response) {
		if (is_wp_error($response)) {
			return '';
		}
		return $response['response']['code'] ?? '';
	}
}
if (!class_exists('WP_Error')) {
	class WP_Error {
		private string $code;
		private string $message;
		/** @var array<string, mixed> */
		private array $data;

		public function __construct(string $code = '', string $message = '', $data = array()) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = is_array($data) ? $data : array($data);
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}
if (!function_exists('wp_next_scheduled')) {
	function wp_next_scheduled($hook, $args = array()) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return WpStubs::$scheduled_hooks[ (string) $hook ] ?? false;
	}
}
if (!function_exists('wp_schedule_single_event')) {
	function wp_schedule_single_event($timestamp, $hook, $args = array(), $wp_error = false) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		WpStubs::$scheduled_hooks[ (string) $hook ] = (int) $timestamp;
		return true;
	}
}
if (!function_exists('wp_schedule_event')) {
	function wp_schedule_event($timestamp, $recurrence, $hook, $args = array(), $wp_error = false) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		WpStubs::$scheduled_hooks[ (string) $hook ] = (int) $timestamp;
		return true;
	}
}
if (!function_exists('spawn_cron')) {
	function spawn_cron($gmt_time = 0) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return;
	}
}
