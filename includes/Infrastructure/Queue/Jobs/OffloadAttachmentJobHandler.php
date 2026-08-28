<?php
/**
 * Idempotent single-attachment offload job.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure\Queue\Jobs;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Attachment\AttachmentOffloader;
use Kazcode\WpStorage\Infrastructure\Queue\JobHandlerInterface;
use Kazcode\WpStorage\Infrastructure\Queue\QueueJobType;
use Kazcode\WpStorage\Plugin;

/**
 * Re-run safe: skips attachments already offloaded unless force=true.
 */
final class OffloadAttachmentJobHandler implements JobHandlerInterface {

	public function type(): string {
		return QueueJobType::OFFLOAD_ATTACHMENT;
	}

	/**
	 * @param array<string, mixed> $payload attachment_id (required), force?, delete_local?
	 */
	public function handle( array $payload ): array {
		$attachment_id = (int) ( $payload['attachment_id'] ?? 0 );
		if ( $attachment_id <= 0 ) {
			return array(
				'success' => false,
				'message' => 'attachment_id is required.',
			);
		}

		$force        = ! empty( $payload['force'] );
		$delete_local = array_key_exists( 'delete_local', $payload ) ? (bool) $payload['delete_local'] : null;
		$offloader    = Plugin::instance()->offloader();

		if ( ! $force ) {
			$status = (string) get_post_meta( $attachment_id, '_s3ms_status', true );
			if ( $status === AttachmentOffloader::STATUS_OFFLOADED ) {
				return array(
					'success' => true,
					'message' => 'Already offloaded.',
				);
			}
		}

		$result = $offloader->offload( $attachment_id, $delete_local );
		return array(
			'success' => ! empty( $result['success'] ),
			'message' => (string) ( $result['message'] ?? '' ),
		);
	}
}
