<?php
/**
 * Append-only audit log in wp_options (ring buffer).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight audit trail for settings and migration actions.
 */
final class AuditLog {

	public const OPTION_KEY = 's3ms_audit_log';
	public const MAX_ENTRIES = 200;

	/**
	 * Record an event.
	 *
	 * @param string               $action Action slug.
	 * @param array<string, mixed> $context Context.
	 */
	public function record( string $action, array $context = array() ): void {
		$user_id = get_current_user_id();
		$entry   = array(
			'at'      => gmdate( 'c' ),
			'action'  => sanitize_key( $action ),
			'user_id' => $user_id,
			'user'    => $user_id ? (string) wp_get_current_user()->user_login : 'system',
			'context' => $context,
		);

		$log = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, self::MAX_ENTRIES );
		update_option( self::OPTION_KEY, $log, false );
	}

	/**
	 * Latest entries.
	 *
	 * @param int $limit Max rows.
	 * @return list<array<string, mixed>>
	 */
	public function latest( int $limit = 50 ): array {
		$log = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $log ) ) {
			return array();
		}
		return array_slice( $log, 0, max( 1, $limit ) );
	}

	/**
	 * Clear log.
	 */
	public function clear(): void {
		delete_option( self::OPTION_KEY );
	}
}
