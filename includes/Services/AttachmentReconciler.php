<?php
/**
 * Lifecycle sync: diff manifest vs object rows, mark stale, refresh cache meta.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Attachment\AttachmentOffloader;
use Kazcode\WpStorage\Domain\AttachmentSyncDeriver;
use Kazcode\WpStorage\Domain\ManifestBuilder;
use Kazcode\WpStorage\Domain\ManifestDiff;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;

/**
 * Reconcile on metadata changes — never immediate remote delete of stale keys.
 */
final class AttachmentReconciler {

	public function __construct(
		private ?ManifestBuilder $manifest_builder = null,
		private ?ObjectRepository $objects = null,
	) {
		$this->manifest_builder = $manifest_builder ?? new ManifestBuilder();
		$this->objects          = $objects ?? new ObjectRepository();
	}

	/**
	 * @param array<string, mixed>|null $metadata_override Metadata from WP filter.
	 * @return array{skipped?:bool,added:list<string>,removed:list<string>,unchanged:int,stale_marked:int,status?:string}
	 */
	public function reconcile( int $attachment_id, ?array $metadata_override = null ): array {
		if ( ! ObjectOffloadService::is_enabled() ) {
			return array(
				'skipped'      => true,
				'added'        => array(),
				'removed'      => array(),
				'unchanged'    => 0,
				'stale_marked' => 0,
			);
		}

		$manifest = $this->manifest_builder->build( $attachment_id, $metadata_override );
		$rows     = $this->objects->find_by_attachment( $attachment_id );
		$diff     = ManifestDiff::compare( $manifest->relative_paths(), $rows );

		$stale_marked = 0;
		if ( $diff->removed !== array() ) {
			$stale_marked = $this->objects->mark_stale_by_relative_paths( $attachment_id, $diff->removed );
		}

		$status = '';
		if ( $rows !== array() ) {
			$rows   = $this->objects->find_by_attachment( $attachment_id );
			$status = AttachmentSyncDeriver::derive_status( $rows );
			update_post_meta( $attachment_id, '_s3ms_status', $status );

			$original = AttachmentSyncDeriver::original_key( $rows );
			if ( $original !== '' ) {
				update_post_meta( $attachment_id, '_s3ms_original_key', $original );
			}

			if ( $status !== AttachmentOffloader::STATUS_OFFLOADED ) {
				$error = AttachmentSyncDeriver::last_error( $rows );
				if ( $error !== '' ) {
					update_post_meta( $attachment_id, '_s3ms_last_error', $error );
				}
			}
		}

		return array(
			'added'        => $diff->added,
			'removed'      => $diff->removed,
			'unchanged'    => count( $diff->unchanged ),
			'stale_marked' => $stale_marked,
			'status'       => $status,
		);
	}
}
