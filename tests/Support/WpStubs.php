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

	/** @var array<int, array<string, bool>> */
	public static array $post_meta_update_failures = array();

	/** @var array<int, array<string, bool>> */
	public static array $post_meta_add_failures = array();

	/** @var array<int, array<string, mixed>> */
	public static array $post_meta_add_conflicts = array();

	/** Simulate WordPress's stripslashes() behavior for post-meta writes. */
	public static bool $post_meta_stripslashes = false;

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

	/** @var array<int, array<string, int>> Per-blog WP-Cron fixtures for multisite lifecycle tests. */
	public static array $scheduled_hooks_by_blog = array();

	/** @var list<int> */
	public static array $site_ids = array( 1 );

	public static int $current_blog_id = 1;

	/** @var list<int> */
	public static array $blog_stack = array();

	/** @var string */
	public static string $uploads_basedir = '';

	/** @var int 0 = no current user ("system"), matching a real logged-out/CLI/cron request. */
	public static int $current_user_id = 0;

	/** @var string */
	public static string $current_user_login = '';

	/** Mirrors WordPress is_admin() for unit tests that boot admin-only surfaces. */
	public static bool $is_admin = false;

	/**
	 * Reset between tests.
	 */
	public static function reset(): void {
		self::$post_meta          = array();
		self::$post_meta_update_failures = array();
		self::$post_meta_add_failures = array();
		self::$post_meta_add_conflicts = array();
		self::$post_meta_stripslashes = false;
		self::$posts              = array();
		self::$options            = array();
		self::$site_options       = array();
		self::$is_multisite       = false;
		self::$deleted_files      = array();
		self::$scheduled_hooks    = array();
		self::$scheduled_hooks_by_blog = array();
		self::$site_ids           = array( 1 );
		self::$current_blog_id    = 1;
		self::$blog_stack         = array();
		self::$uploads_basedir    = '';
		self::$current_user_id    = 0;
		self::$current_user_login = '';
		self::$is_admin           = false;
		// Full reset, not an allowlist of hook names — every add_filter()/
		// add_action() call in a test must not leak into the next one.
		if ( isset( $GLOBALS['s3ms_test_filters'] ) ) {
			$GLOBALS['s3ms_test_filters'] = array();
		}
		$GLOBALS['s3ms_test_rest_routes'] = array();
		$GLOBALS['s3ms_test_admin_pages'] = array();
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
