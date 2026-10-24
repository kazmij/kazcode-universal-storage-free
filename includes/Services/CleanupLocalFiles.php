<?php
/**
 * Policy-aware local file cleanup after verified remote offload.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Attachment\AttachmentFileResolver;
use Kazcode\WpStorage\Core\SafeMode;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\LocalStoragePolicy;
use Kazcode\WpStorage\Domain\ManifestBuilder;
use Kazcode\WpStorage\Storage\S3Storage;

/**
 * Deletes locals only after CleanupEligibility authorizes the attachment's
 * current manifest, and only if that manifest is unchanged at delete time.
 */
final class CleanupLocalFiles {

	public const MANIFEST_CHANGED = 'manifest_changed';
	public const SAFE_MODE        = 'safe_mode';

	private ?CleanupEligibility $eligibility;

	/** @var (callable(int):list<array{relative:string,variant_type?:string}>)|null */
	private $current_manifest;

	/**
	 * @param (callable(int):list<array{relative:string,variant_type?:string}>)|null $current_manifest Re-reads persisted metadata.
	 */
	public function __construct(
		private Settings $settings,
		private S3Storage $storage,
		?CleanupEligibility $eligibility = null,
		?callable $current_manifest = null,
	) {
		$this->eligibility      = $eligibility;
		$this->current_manifest = $current_manifest;
	}

	/**
	 * @param array<string, string>                          $local_files   relative => absolute.
	 * @param list<array{relative:string,variant_type?:string}> $manifest_items Manifest rows.
	 * @param bool|null                                      $delete_override CLI/migrate override (true=remote_only, false=keep_all).
	 * @param int|null                                       $profile_id    Profile every artifact must be bound to (null = recorded binding).
	 * @return array{deleted:int,skipped:bool,message:string,policy:string,blocked_reason?:string}
	 */
	public function maybe_cleanup(
		int $attachment_id,
		array $local_files,
		array $manifest_items,
		?bool $delete_override = null,
		?int $profile_id = null,
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

		// Safe Mode (#61): a clone cannot rely on remote copies staying put. A
		// namespace conflict already stops this site's remote writes per profile.
		$safe_mode = SafeMode::identity_block();
		if ( $safe_mode !== null ) {
			return $this->blocked( $attachment_id, $policy, self::SAFE_MODE, (string) $safe_mode['reason'] );
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

		$decision = $this->eligibility()->evaluate( $attachment_id, $manifest_items, $local_files, $targets, $profile_id );
		if ( ! $decision['eligible'] ) {
			return $this->blocked( $attachment_id, $policy, $decision['reason'], $decision['relative'] );
		}

		// Metadata may change while remote checks run (crop, regenerate, client-side
		// sizes). Verification of an older variant set never authorizes deletion.
		$verified = CleanupEligibility::relative_paths( $manifest_items );
		try {
			$current = CleanupEligibility::relative_paths( $this->read_current_manifest( $attachment_id ) );
		} catch ( \Throwable $e ) {
			$current = array();
		}
		if ( $current !== $verified ) {
			return $this->blocked( $attachment_id, $policy, self::MANIFEST_CHANGED, '' );
		}

		LocalFileCleanup::delete_files( array_values( $targets ) );

		return array(
			'deleted' => count( $targets ),
			'skipped' => false,
			'message' => 'Local cleanup completed.',
			'policy'  => $policy,
		);
	}

	/**
	 * @return array{deleted:int,skipped:bool,message:string,policy:string,blocked_reason:string}
	 */
	private function blocked( int $attachment_id, string $policy, string $reason, string $relative ): array {
		$detail = $relative !== '' ? $reason . ': ' . $relative : $reason;
		update_post_meta( $attachment_id, '_s3ms_last_error', 'Offloaded but local delete skipped (' . $detail . ')' );
		return array(
			'deleted'        => 0,
			'skipped'        => true,
			'message'        => 'Local delete skipped after verify failure (' . $detail . ')',
			'policy'         => $policy,
			'blocked_reason' => $reason,
		);
	}

	private function eligibility(): CleanupEligibility {
		return $this->eligibility ??= new CleanupEligibility(
			new CleanupDeliveryProbe( $this->settings ),
			null,
			new ProfileAwareObjectOperations( null, null, null, $this->settings )
		);
	}

	/**
	 * @return list<array{relative:string,variant_type?:string}>
	 */
	private function read_current_manifest( int $attachment_id ): array {
		if ( $this->current_manifest !== null ) {
			return ( $this->current_manifest )( $attachment_id );
		}
		return ( new ManifestBuilder( new AttachmentFileResolver( $this->storage->keys() ) ) )->build( $attachment_id )->items();
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
}
