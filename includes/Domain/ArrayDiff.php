<?php
/**
 * Pure array-diffing helper.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by every "log which settings fields changed" call site
 * (Core\Plugin, Pro\Admin\NetworkSettingsPage) — kept as a small pure
 * function so it's independently unit-testable without booting a full
 * settings-save flow, and so both call sites stay in sync with each other.
 */
final class ArrayDiff {

	/**
	 * Top-level keys present in either array whose value differs (loose
	 * `!==` comparison). Deliberately returns key NAMES only, never the old/
	 * new values themselves — safe to log even when a caller's array might
	 * later grow a sensitive field it forgot to redact.
	 *
	 * @param array<string, mixed> $old
	 * @param array<string, mixed> $new
	 * @return list<string>
	 */
	public static function changed_keys( array $old, array $new ): array {
		$changed = array();
		foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) ) as $key ) {
			if ( ( $old[ $key ] ?? null ) !== ( $new[ $key ] ?? null ) ) {
				$changed[] = $key;
			}
		}
		return $changed;
	}
}
