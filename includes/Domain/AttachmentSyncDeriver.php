<?php
/**
 * Derives attachment-level status from object rows.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Domain;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Attachment\AttachmentOffloader;

/**
 * Roll-up rules for legacy _s3ms_status cache.
 */
final class AttachmentSyncDeriver {

	/**
	 * @param list<array<string, mixed>> $object_rows DB rows for one attachment.
	 */
	public static function derive_status( array $object_rows ): string {
		if ( $object_rows === array() ) {
			return AttachmentOffloader::STATUS_FAILED;
		}

		$present = 0;
		$failed  = 0;
		$pending = 0;
		$active  = 0;

		foreach ( $object_rows as $row ) {
			$remote = (string) ( $row['remote_status'] ?? '' );
			// Superseded (stale) or removed (deleted) variants no longer represent the
			// attachment's current file set — e.g. after Image Editor crop, Regenerate
			// Thumbnails, or a storage-profile migration — so they must not count against
			// the active total, or a fully-offloaded attachment would wrongly derive as failed.
			if ( $remote === ObjectRemoteStatus::STALE || $remote === ObjectRemoteStatus::DELETED ) {
				continue;
			}
			++$active;
			if ( $remote === ObjectRemoteStatus::PRESENT ) {
				++$present;
			} elseif ( $remote === ObjectRemoteStatus::FAILED ) {
				++$failed;
			} elseif ( in_array( $remote, array( ObjectRemoteStatus::PENDING, ObjectRemoteStatus::UPLOADING, ObjectRemoteStatus::MISSING ), true ) ) {
				++$pending;
			}
		}

		if ( $active === 0 ) {
			return AttachmentOffloader::STATUS_FAILED;
		}
		if ( $present === $active ) {
			return AttachmentOffloader::STATUS_OFFLOADED;
		}
		if ( $present > 0 && ( $failed > 0 || $pending > 0 ) ) {
			return AttachmentOffloader::STATUS_PARTIAL;
		}
		return AttachmentOffloader::STATUS_FAILED;
	}

	/**
	 * Pick original object_key from rows (variant_type=original, else first present key).
	 *
	 * @param list<array<string, mixed>> $object_rows Rows.
	 */
	public static function original_key( array $object_rows ): string {
		foreach ( $object_rows as $row ) {
			if ( ( $row['variant_type'] ?? '' ) === 'original' && ( $row['remote_status'] ?? '' ) === ObjectRemoteStatus::PRESENT ) {
				return (string) ( $row['object_key'] ?? '' );
			}
		}
		foreach ( $object_rows as $row ) {
			if ( ( $row['remote_status'] ?? '' ) === ObjectRemoteStatus::PRESENT ) {
				return (string) ( $row['object_key'] ?? '' );
			}
		}
		return '';
	}

	/**
	 * Roll up last error from object rows.
	 *
	 * @param list<array<string, mixed>> $object_rows Rows.
	 */
	public static function last_error( array $object_rows ): string {
		foreach ( $object_rows as $row ) {
			if ( ! empty( $row['last_error_message'] ) ) {
				return (string) $row['last_error_message'];
			}
		}
		return '';
	}
}
