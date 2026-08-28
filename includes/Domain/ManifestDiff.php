<?php
/**
 * Diff between expected manifest paths and persisted object rows.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Deterministic manifest vs inventory comparison.
 */
final class ManifestDiff {

	/**
	 * @param list<string> $added     Paths expected but not present remotely.
	 * @param list<string> $removed   Paths in inventory but no longer in manifest.
	 * @param list<string> $unchanged Paths present in both with remote present.
	 */
	public function __construct(
		public readonly array $added,
		public readonly array $removed,
		public readonly array $unchanged,
	) {
	}

	/**
	 * @param list<string>               $manifest_paths Relative paths from MediaManifest.
	 * @param list<array<string, mixed>> $object_rows    s3ms_objects rows for attachment.
	 */
	public static function compare( array $manifest_paths, array $object_rows ): self {
		$manifest_set = array_fill_keys( $manifest_paths, true );

		$row_by_path = array();
		foreach ( $object_rows as $row ) {
			$rel = (string) ( $row['local_relative_path'] ?? '' );
			if ( $rel === '' ) {
				continue;
			}
			$row_by_path[ $rel ] = $row;
		}

		$added      = array();
		$removed    = array();
		$unchanged  = array();

		foreach ( $manifest_paths as $relative ) {
			if ( ! isset( $row_by_path[ $relative ] ) ) {
				$added[] = $relative;
				continue;
			}
			$remote = (string) ( $row_by_path[ $relative ]['remote_status'] ?? '' );
			if ( $remote === ObjectRemoteStatus::PRESENT ) {
				$unchanged[] = $relative;
			} else {
				$added[] = $relative;
			}
		}

		foreach ( $row_by_path as $relative => $row ) {
			if ( isset( $manifest_set[ $relative ] ) ) {
				continue;
			}
			$remote = (string) ( $row['remote_status'] ?? '' );
			if ( in_array( $remote, array( ObjectRemoteStatus::STALE, ObjectRemoteStatus::DELETED ), true ) ) {
				continue;
			}
			$removed[] = $relative;
		}

		return new self(
			array_values( $added ),
			array_values( $removed ),
			array_values( $unchanged ),
		);
	}
}
