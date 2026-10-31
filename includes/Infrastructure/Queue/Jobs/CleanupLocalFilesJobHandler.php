<?php
/**
 * Deferred local cleanup after full verify (v2 Phase 5/6).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure\Queue\Jobs;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Attachment\AttachmentFileResolver;
use Kazcode\WpStorage\Domain\ManifestBuilder;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\Queue\JobHandlerInterface;
use Kazcode\WpStorage\Infrastructure\Queue\QueueJobType;
use Kazcode\WpStorage\Plugin;
use Kazcode\WpStorage\Services\CleanupLocalFiles;

/**
 * Re-derives the manifest from persisted metadata and lets CleanupEligibility
 * decide; each variant is checked on its recorded profile/key (#53).
 */
final class CleanupLocalFilesJobHandler implements JobHandlerInterface {

	public function type(): string {
		return QueueJobType::CLEANUP_LOCAL_FILES;
	}

	/**
	 * @param array<string, mixed> $payload attachment_id (required), delete_local?, profile_id?
	 */
	public function handle( array $payload ): array {
		$attachment_id = (int) ( $payload['attachment_id'] ?? 0 );
		if ( $attachment_id <= 0 ) {
			return array(
				'success' => false,
				'message' => 'attachment_id is required.',
			);
		}

		if ( ( new ObjectRepository() )->find_by_attachment( $attachment_id ) === array() ) {
			return array(
				'success' => false,
				'message' => 'No object rows for attachment.',
			);
		}

		$plugin   = Plugin::instance();
		$files    = new AttachmentFileResolver( $plugin->key_resolver() );
		$manifest = ( new ManifestBuilder( $files ) )->build( $attachment_id );
		$local    = $files->existing_local_files_for_manifest_paths( $manifest->relative_paths() );
		if ( $local === array() ) {
			return array(
				'success' => true,
				'message' => 'No local files to clean up.',
			);
		}

		$delete     = array_key_exists( 'delete_local', $payload ) && $payload['delete_local'] !== null ? (bool) $payload['delete_local'] : null;
		$profile_id = isset( $payload['profile_id'] ) && (int) $payload['profile_id'] > 0 ? (int) $payload['profile_id'] : null;

		$result = ( new CleanupLocalFiles( $plugin->settings(), $plugin->storage() ) )->maybe_cleanup(
			$attachment_id,
			$local,
			$manifest->items(),
			$delete,
			$profile_id
		);

		// A blocked cleanup is a safe, final outcome — files stay local; not a retryable job failure.
		return array(
			'success' => true,
			'message' => (string) ( $result['message'] ?? '' ),
		);
	}
}
