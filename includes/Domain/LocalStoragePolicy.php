<?php
/**
 * Local file retention policy after successful remote offload.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces keep_local / delete_local / verify_before_delete toggles (v2 Phase 5).
 */
final class LocalStoragePolicy {

	public const KEEP_ALL        = 'keep_all';
	public const KEEP_ORIGINALS  = 'keep_originals';
	public const REMOTE_ONLY     = 'remote_only';

	/**
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::KEEP_ALL,
			self::KEEP_ORIGINALS,
			self::REMOTE_ONLY,
		);
	}

	public static function is_valid( string $policy ): bool {
		return in_array( $policy, self::all(), true );
	}

	public static function normalize( string $policy ): string {
		return self::is_valid( $policy ) ? $policy : self::KEEP_ALL;
	}

	/**
	 * Map legacy boolean settings to a policy slug.
	 *
	 * @param array<string, mixed> $settings Settings map.
	 */
	public static function from_legacy_settings( array $settings ): string {
		if ( ! empty( $settings['local_storage_policy'] ) && is_string( $settings['local_storage_policy'] ) ) {
			return self::normalize( $settings['local_storage_policy'] );
		}
		if ( ! empty( $settings['delete_local_after_upload'] ) ) {
			return self::REMOTE_ONLY;
		}
		return self::KEEP_ALL;
	}

	public static function deletes_any_local( string $policy ): bool {
		return in_array( self::normalize( $policy ), array( self::KEEP_ORIGINALS, self::REMOTE_ONLY ), true );
	}

	/**
	 * Sync legacy keys for backward-compatible reads / exports.
	 *
	 * @return array{keep_local_files:bool,delete_local_after_upload:bool,verify_before_delete:bool}
	 */
	public static function legacy_flags_for( string $policy ): array {
		$policy = self::normalize( $policy );
		return match ( $policy ) {
			self::REMOTE_ONLY => array(
				'keep_local_files'          => false,
				'delete_local_after_upload' => true,
				'verify_before_delete'      => true,
			),
			self::KEEP_ORIGINALS => array(
				'keep_local_files'          => true,
				'delete_local_after_upload' => false,
				'verify_before_delete'      => true,
			),
			default => array(
				'keep_local_files'          => true,
				'delete_local_after_upload' => false,
				'verify_before_delete'      => true,
			),
		};
	}
}
