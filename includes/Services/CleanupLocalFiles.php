<?php
/**
 * Policy-aware local file cleanup after verified remote offload.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\LocalStoragePolicy;
use Kazcode\WpStorage\Storage\ObjectKeyService;
use Kazcode\WpStorage\Storage\S3Storage;

/**
 * Always verifies remote Heads before unlinking locals (P5-T03).
 */
final class CleanupLocalFiles {

	public function __construct(
		private Settings $settings,
		private S3Storage $storage,
	) {
	}

	/**
	 * @param array<string, string>                          $local_files   relative => absolute.
	 * @param list<array{relative:string,variant_type?:string}> $manifest_items Manifest rows.
	 * @param bool|null                                      $delete_override CLI/migrate override (true=remote_only, false=keep_all).
	 * @return array{deleted:int,skipped:bool,message:string,policy:string}
	 */
	public function maybe_cleanup(
		int $attachment_id,
		array $local_files,
		array $manifest_items,
		?bool $delete_override = null,
		?string $profile_prefix = null,
	): array {
		$policy = $this->resolve_policy( $delete_override );
		if ( $policy === LocalStoragePolicy::KEEP_ALL ) {
			return array(
				'deleted' => 0,
				'skipped' => true,
				'message' => 'Local retention policy keeps all files.',
				'policy'  => $policy,
			);
		}

		$targets = $this->paths_to_delete( $local_files, $manifest_items, $policy, $attachment_id );
		if ( $targets === array() ) {
			return array(
				'deleted' => 0,
				'skipped' => true,
				'message' => 'No local files eligible for cleanup under current policy.',
				'policy'  => $policy,
			);
		}

		foreach ( array_keys( $targets ) as $relative ) {
			if ( ! $this->verify_remote_exists( $relative, $profile_prefix ) ) {
				update_post_meta(
					$attachment_id,
					'_s3ms_last_error',
					'Offloaded but local delete skipped; remote missing: ' . $relative
				);
				return array(
					'deleted' => 0,
					'skipped' => true,
					'message' => 'Local delete skipped after verify failure for ' . $relative,
					'policy'  => $policy,
				);
			}
		}

		LocalFileCleanup::delete_files( array_values( $targets ) );

		return array(
			'deleted' => count( $targets ),
			'skipped' => false,
			'message' => 'Local cleanup completed.',
			'policy'  => $policy,
		);
	}

	private function resolve_policy( ?bool $delete_override ): string {
		if ( $delete_override === true ) {
			return LocalStoragePolicy::REMOTE_ONLY;
		}
		if ( $delete_override === false ) {
			return LocalStoragePolicy::KEEP_ALL;
		}
		return $this->settings->local_storage_policy();
	}

	/**
	 * @param array<string, string> $local_files
	 * @return array<string, string> relative => absolute
	 */
	private function paths_to_delete(
		array $local_files,
		array $manifest_items,
		string $policy,
		int $attachment_id,
	): array {
		if ( $policy === LocalStoragePolicy::REMOTE_ONLY ) {
			return $local_files;
		}

		if ( $policy !== LocalStoragePolicy::KEEP_ORIGINALS ) {
			return array();
		}

		$original = $this->original_relative( $manifest_items, $attachment_id );
		$out      = array();
		foreach ( $local_files as $relative => $absolute ) {
			if ( $relative !== $original ) {
				$out[ $relative ] = $absolute;
			}
		}
		return $out;
	}

	/**
	 * @param list<array{relative:string,variant_type?:string}> $manifest_items
	 */
	private function original_relative( array $manifest_items, int $attachment_id ): string {
		foreach ( $manifest_items as $item ) {
			if ( ( $item['variant_type'] ?? '' ) === 'original' ) {
				return (string) $item['relative'];
			}
		}

		$attached = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		try {
			return \Kazcode\WpStorage\Storage\PathGuard::normalize_relative( $attached );
		} catch ( \InvalidArgumentException $e ) {
			return '';
		}
	}

	private function verify_remote_exists( string $relative, ?string $profile_prefix ): bool {
		if ( $profile_prefix !== null && $profile_prefix !== '' ) {
			$key  = ObjectKeyService::key_for( $profile_prefix, $relative );
			$head = $this->storage->head_key( $key );
		} else {
			$head = $this->storage->head_relative( $relative );
		}
		return ! empty( $head['exists'] );
	}
}
