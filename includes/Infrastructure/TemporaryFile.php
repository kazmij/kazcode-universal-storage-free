<?php
/**
 * Temporary-file creation compatible with current WordPress runtimes.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class TemporaryFile {

	/**
	 * Create a writable temporary file. WordPress 7.1 no longer exposes the
	 * legacy wp_tempnam() helper on ordinary REST requests, so retain its use
	 * where available and fall back to PHP's safe system temporary directory.
	 *
	 * @return string|false
	 */
	public static function create( string $prefix ): string|false {
		if ( function_exists( 'wp_tempnam' ) ) {
			return wp_tempnam( $prefix );
		}

		return tempnam( sys_get_temp_dir(), substr( $prefix, 0, 63 ) );
	}
}
