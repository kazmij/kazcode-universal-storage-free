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
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\Queue\JobHandlerInterface;
use Kazcode\WpStorage\Infrastructure\Queue\QueueJobType;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
use Kazcode\WpStorage\Plugin;
use Kazcode\WpStorage\Services\CleanupLocalFiles;
use Kazcode\WpStorage\Services\LegacyProfileMigrator;

/**
 * Only deletes locals when every object row is present and verified.
 */
final class CleanupLocalFilesJobHandler implements JobHandlerInterface {

	public function type(): string {
		return QueueJobType::CLEANUP_LOCAL_FILES;
	}

	/**
	 * @param array<string, mixed> $payload attachment_id (required), delete_local?, profile_prefix?
	 */
	public function handle( array $payload ): array {
		$attachment_id = (int) ( $payload['attachment_id'] ?? 0 );
		if ( $attachment_id <= 0 ) {
			return array(
				'success' => false,
				'message' => 'attachment_id is required.',
			);
		}

		$objects = new ObjectRepository();
		$rows    = $objects->find_by_attachment( $attachment_id );
		if ( $rows === array() ) {
			return array(
				'success' => false,
				'message' => 'No object rows for attachment.',
			);
		}

		foreach ( $rows as $row ) {
			if ( ( $row['remote_status'] ?? '' ) !== ObjectRemoteStatus::PRESENT || empty( $row['verified_at'] ) ) {
				return array(
					'success' => false,
					'message' => 'Cleanup skipped: attachment not fully verified.',
				);
			}
		}

		$plugin   = Plugin::instance();
		$files    = new AttachmentFileResolver( $plugin->key_resolver() );
		$manifest = ( new ManifestBuilder( $files ) )->build( $attachment_id );
		$local    = $files->existing_local_files( $attachment_id );
		if ( $local === array() ) {
			return array(
				'success' => true,
				'message' => 'No local files to clean up.',
			);
		}

		$prefix = isset( $payload['profile_prefix'] ) ? (string) $payload['profile_prefix'] : $this->resolve_prefix( $plugin );
		$delete = array_key_exists( 'delete_local', $payload ) ? (bool) $payload['delete_local'] : null;

		$result = ( new CleanupLocalFiles( $plugin->settings(), $plugin->storage() ) )->maybe_cleanup(
			$attachment_id,
			$local,
			$manifest->items(),
			$delete,
			$prefix !== '' ? $prefix : null
		);

		return array(
			'success' => ! $result['skipped'] || $result['deleted'] === 0,
			'message' => (string) ( $result['message'] ?? '' ),
		);
	}

	private function resolve_prefix( Plugin $plugin ): string {
		$repo    = new WpdbStorageProfileRepository();
		$profile = $repo->find_default_upload_target();
		if ( $profile === null ) {
			$profile = ( new LegacyProfileMigrator( $plugin->settings(), $repo ) )->ensure_legacy_profile();
		}
		return $profile !== null ? (string) $profile->prefix : (string) $plugin->settings()->get( 'object_prefix', '' );
	}
}
