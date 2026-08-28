<?php
/**
 * DB-only attachment object inventory summary (v2 Phase 11 ML column).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;

/**
 * Summarize s3ms_objects rows for one attachment — no S3 HEAD on list render.
 */
final class AttachmentObjectSummary {

	public function __construct(
		private ?ObjectRepository $objects = null,
		private ?WpdbStorageProfileRepository $profiles = null,
	) {
		$this->objects  = $objects ?? new ObjectRepository();
		$this->profiles = $profiles ?? new WpdbStorageProfileRepository();
	}

	/**
	 * @return array{
	 *   state:string,
	 *   label:string,
	 *   profile:string,
	 *   object_count:int,
	 *   present_count:int,
	 *   last_verified:?string,
	 *   legacy_status:string
	 * }
	 */
	public function summarize( int $attachment_id ): array {
		$legacy = (string) get_post_meta( $attachment_id, '_s3ms_status', true );
		$rows   = $this->objects->find_by_attachment( $attachment_id );

		if ( $rows === array() ) {
			return array(
				'state'          => 'local',
				'label'          => __( 'Local', 'kazcode-universal-storage' ),
				'profile'        => '',
				'object_count'   => 0,
				'present_count'  => 0,
				'last_verified'  => null,
				'legacy_status'  => $legacy,
			);
		}

		$present = 0;
		$failed  = 0;
		$missing = 0;
		$last    = null;
		$profile_id = 0;
		$active  = 0;

		foreach ( $rows as $row ) {
			$status = (string) ( $row['remote_status'] ?? '' );
			// Superseded (stale) or removed (deleted) variants — e.g. left behind by a
			// storage-profile migration, Image Editor crop, or Regenerate Thumbnails —
			// no longer represent the attachment's current file set. Counting them
			// against the total made every migrated attachment show "Partial" here
			// forever after, even once fully re-offloaded on its new profile (mirrors
			// the same fix already applied in AttachmentSyncDeriver::derive_status()).
			if ( $status === ObjectRemoteStatus::STALE || $status === ObjectRemoteStatus::DELETED ) {
				continue;
			}
			++$active;
			if ( $status === ObjectRemoteStatus::PRESENT ) {
				++$present;
			} elseif ( $status === ObjectRemoteStatus::FAILED ) {
				++$failed;
			} elseif ( $status === ObjectRemoteStatus::MISSING ) {
				++$missing;
			}
			$verified = (string) ( $row['verified_at'] ?? '' );
			if ( $verified !== '' && ( $last === null || $verified > $last ) ) {
				$last = $verified;
			}
			// All active (non-stale/deleted) rows belong to the same current profile —
			// the first one seen is as good as any.
			if ( $profile_id <= 0 ) {
				$profile_id = (int) ( $row['storage_profile_id'] ?? 0 );
			}
		}

		$total = $active;
		$state = 'partial';
		$label = __( 'Partial', 'kazcode-universal-storage' );

		if ( $failed > 0 || $legacy === 'failed' ) {
			$state = 'failed';
			$label = __( 'Failed', 'kazcode-universal-storage' );
		} elseif ( $present === $total && $total > 0 ) {
			$state = 'remote';
			$label = __( 'Remote', 'kazcode-universal-storage' );
		} elseif ( $present === 0 && $missing > 0 ) {
			$state = 'missing';
			$label = __( 'Missing', 'kazcode-universal-storage' );
		}

		$profile_name = '';
		if ( $profile_id > 0 ) {
			$profile = $this->profiles->find( $profile_id );
			$profile_name = $profile !== null ? $profile->name : ( '#' . $profile_id );
		}

		return array(
			'state'         => $state,
			'label'         => $label,
			'profile'       => $profile_name,
			'object_count'  => $total,
			'present_count' => $present,
			'last_verified' => $last,
			'legacy_status' => $legacy,
		);
	}
}
