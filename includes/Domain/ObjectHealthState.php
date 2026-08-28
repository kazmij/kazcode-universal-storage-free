<?php
/**
 * Object / attachment health classification (v2 Phase 6).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Domain;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Domain\ObjectRemoteStatus;

/**
 * DB-first health buckets for reconcile/repair dashboards.
 */
final class ObjectHealthState {

	public const HEALTHY         = 'healthy';
	public const REMOTE_MISSING  = 'remote_missing';
	public const LOCAL_MISSING   = 'local_missing';
	public const FAILED_UPLOAD   = 'failed_upload';
	public const UNVERIFIED      = 'unverified';
	public const STALE           = 'stale';
	public const POSSIBLE_ORPHAN = 'possible_orphan';

	/**
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::HEALTHY,
			self::REMOTE_MISSING,
			self::LOCAL_MISSING,
			self::FAILED_UPLOAD,
			self::UNVERIFIED,
			self::STALE,
			self::POSSIBLE_ORPHAN,
		);
	}

	/**
	 * Classify one object row using persisted remote_status and local presence.
	 *
	 * @param array<string, mixed> $row Object row from s3ms_objects.
	 */
	public static function classify_row( array $row, bool $local_present ): string {
		$remote = (string) ( $row['remote_status'] ?? '' );

		if ( $remote === ObjectRemoteStatus::STALE ) {
			return self::STALE;
		}
		if ( $remote === ObjectRemoteStatus::FAILED ) {
			return self::FAILED_UPLOAD;
		}
		if ( $remote === ObjectRemoteStatus::MISSING ) {
			return self::REMOTE_MISSING;
		}
		if ( in_array( $remote, array( ObjectRemoteStatus::PENDING, ObjectRemoteStatus::UPLOADING ), true ) ) {
			return self::UNVERIFIED;
		}
		if ( $remote === ObjectRemoteStatus::PRESENT ) {
			if ( empty( $row['verified_at'] ) ) {
				return self::UNVERIFIED;
			}
			return $local_present ? self::HEALTHY : self::LOCAL_MISSING;
		}

		return self::UNVERIFIED;
	}

	public static function is_repairable( string $health, bool $local_present ): bool {
		if ( ! $local_present ) {
			return false;
		}
		return in_array(
			$health,
			array(
				self::REMOTE_MISSING,
				self::FAILED_UPLOAD,
				self::UNVERIFIED,
			),
			true
		);
	}
}
