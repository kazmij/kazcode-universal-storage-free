<?php
/**
 * Queue job: adopt one attachment via HEAD inventory.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure\Queue\Jobs;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Infrastructure\Queue\JobHandlerInterface;
use Kazcode\WpStorage\Infrastructure\Queue\QueueJobType;
use Kazcode\WpStorage\Plugin;
use Kazcode\WpStorage\Services\AdoptAttachmentService;

final class AdoptAttachmentJobHandler implements JobHandlerInterface {

	public function type(): string {
		return QueueJobType::ADOPT_ATTACHMENT;
	}

	/**
	 * @param array<string, mixed> $payload attachment_id (required), profile_id?
	 */
	public function handle( array $payload ): array {
		$attachment_id = (int) ( $payload['attachment_id'] ?? 0 );
		if ( $attachment_id <= 0 ) {
			return array(
				'success' => false,
				'message' => 'attachment_id is required.',
			);
		}

		$profile_id = isset( $payload['profile_id'] ) ? (int) $payload['profile_id'] : null;
		$result     = ( new AdoptAttachmentService( Plugin::instance()->settings() ) )->adopt(
			$attachment_id,
			$profile_id > 0 ? $profile_id : null,
			false
		);

		return array(
			'success' => ! empty( $result['success'] ),
			'message' => (string) ( $result['message'] ?? '' ),
		);
	}
}
