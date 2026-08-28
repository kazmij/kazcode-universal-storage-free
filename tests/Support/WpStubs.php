<?php
/**
 * In-memory WordPress stubs for characterization unit tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests\Support;

/**
 * Mutable WP-like globals used by plugin code under PHPUnit.
 */
final class WpStubs {

	/** @var array<int, array<string, mixed>> */
	public static array $post_meta = array();

	/** @var array<int, object> */
	public static array $posts = array();

	/** @var array<string, mixed> */
	public static array $options = array();

	/** @var array<string, mixed> */
	public static array $site_options = array();

	/** @var bool */
	public static bool $is_multisite = false;

	/** @var list<string> */
	public static array $deleted_files = array();

	/** @var array<string, int> */
	public static array $scheduled_hooks = array();

	/** @var string */
	public static string $uploads_basedir = '';

	/**
	 * Reset between tests.
	 */
	public static function reset(): void {
		self::$post_meta       = array();
		self::$posts           = array();
		self::$options         = array();
		self::$site_options    = array();
		self::$is_multisite    = false;
		self::$deleted_files   = array();
		self::$scheduled_hooks = array();
		self::$uploads_basedir = '';
		// Full reset, not an allowlist of hook names — every add_filter()/
		// add_action() call in a test must not leak into the next one.
		if ( isset( $GLOBALS['s3ms_test_filters'] ) ) {
			$GLOBALS['s3ms_test_filters'] = array();
		}
		\Kazcode\WpStorage\Core\Module\ModuleRegistry::reset_for_tests();
	}

	/**
	 * @param int    $id  Attachment ID.
	 * @param string $key Meta key.
	 * @param mixed  $value Value.
	 */
	public static function set_meta( int $id, string $key, mixed $value ): void {
		self::$post_meta[ $id ][ $key ] = $value;
	}
}
