<?php
/**
 * Verifies offloaded attachments against S3.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Attachment\AttachmentFileResolver;
use Kazcode\WpStorage\Attachment\AttachmentOffloader;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Domain\RemoteObservation;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Storage\S3Storage;

/**
 * Per-attachment verification reports.
 */
final class VerificationService {

	private Settings $settings;
	private S3Storage $storage;
	private AttachmentFileResolver $files;
	private ProfileAwareObjectOperations $profile_ops;
	private ObjectRepository $objects;

	public function __construct(
		Settings $settings,
		S3Storage $storage,
		?AttachmentFileResolver $files = null,
		?ProfileAwareObjectOperations $profile_ops = null,
		?ObjectRepository $objects = null
	) {
		$this->settings    = $settings;
		$this->storage     = $storage;
		$this->files       = $files ?? new AttachmentFileResolver($storage->keys());
		$this->profile_ops = $profile_ops ?? new ProfileAwareObjectOperations( legacy: $storage, settings: $settings );
		$this->objects     = $objects ?? new ObjectRepository();
	}

	/**
	 * Verify one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{status:string,details:list<string>,attachment_id:int,present?:list<string>,missing?:list<string>,unknown?:list<string>}
	 */
	public function verify(int $attachment_id): array {
		$details  = array();
		$attached = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
		$s3_status = (string) get_post_meta($attachment_id, '_s3ms_status', true);
		$paths    = $this->files->relative_paths($attachment_id);

		if ($attached === '' || $paths === array()) {
			return array(
				'attachment_id' => $attachment_id,
				'status'        => 'bad_metadata',
				'details'       => array('Missing _wp_attached_file or empty file list.'),
			);
		}

		$local_count = count($this->files->existing_local_files($attachment_id));
		$missing_s3  = array();
		$unknown_s3  = array();
		$size_mismatch = array();
		$exists_only = array();
		$present_paths = array();
		$present_s3  = 0;

		if ($this->settings->is_aws_configured()) {
			foreach ($paths as $relative) {
				$local_path = $this->files->absolute_path( $relative );
				$expected_size = is_string( $local_path ) && is_file( $local_path ) ? (int) filesize( $local_path ) : null;
				$head = $this->profile_ops->head_attachment_relative($attachment_id, $relative);
				if ( $expected_size === null ) {
					$expected_size = $this->inventory_size_for_head( $attachment_id, $relative, $head );
				}
				$observation = RemoteObservation::from_head_result( $head, $expected_size );
				if ($observation->verification_level === RemoteObservation::SIZE_MISMATCH) {
					$size_mismatch[] = $relative;
				} elseif ($observation->is_unknown()) {
					$unknown_s3[] = $relative;
				} elseif ($observation->is_confirmed_missing()) {
					$missing_s3[] = $relative;
				} elseif ($observation->status === RemoteObservation::REMOTE_PRESENT) {
					// Existence-only HEADs (no trustworthy expected size) must not count as
					// size-verified present — otherwise verify can promote _s3ms_status to
					// offloaded for variants rebuild only confirmed existed.
					if ( $observation->verification_level === RemoteObservation::SIZE_VERIFIED ) {
						++$present_s3;
						$present_paths[] = $relative;
					} else {
						$exists_only[] = $relative;
					}
				}
			}
		} else {
			$details[] = 'AWS not configured; skipped remote HEAD checks.';
		}

		$is_original_missing = false;
		$original_rel        = $this->storage->keys()->normalize_relative($attached);
		if (in_array($original_rel, $missing_s3, true)) {
			$is_original_missing = true;
		}

		$thumb_missing = false;
		$meta          = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
		if (is_array($meta) && !empty($meta['sizes']['thumbnail']['file'])) {
			$thumb_rel = $this->storage->keys()->relative_for_size($attached, (string) $meta['sizes']['thumbnail']['file']);
			if (in_array($thumb_rel, $missing_s3, true)) {
				$thumb_missing = true;
			}
		}

		if ($unknown_s3 !== array()) {
			$status = 'remote_unknown';
		} elseif ($size_mismatch !== array()) {
			$status = 'remote_size_mismatch';
		} elseif ($missing_s3 !== array() && $present_s3 > 0) {
			$status = 'partial_offload';
		} elseif ($is_original_missing) {
			$status = 'missing_s3_original';
		} elseif ($thumb_missing) {
			$status = 'missing_s3_thumbnail';
		} elseif ($exists_only !== array()) {
			// Existence-only is informational only when nothing is confirmed missing.
			$status = 'remote_existence_only';
			$details[] = 'Remote existence confirmed without size verification: ' . implode( ', ', array_slice( $exists_only, 0, 10 ) );
		} elseif ($present_s3 === 0 && $local_count > 0 && $missing_s3 === array()) {
			$status = 'local_only';
		} elseif ($local_count === 0 && $present_s3 > 0 && $missing_s3 === array() && $present_s3 === count( $paths )) {
			$status = 's3_only';
			update_post_meta($attachment_id, '_s3ms_verified_at', gmdate('c'));
		} elseif ($missing_s3 === array() && $present_s3 > 0 && $present_s3 === count( $paths )) {
			$status = 'OK';
			update_post_meta($attachment_id, '_s3ms_verified_at', gmdate('c'));
			if ($s3_status !== AttachmentOffloader::STATUS_OFFLOADED) {
				update_post_meta($attachment_id, '_s3ms_status', AttachmentOffloader::STATUS_OFFLOADED);
			}
		} elseif ($present_s3 === 0 && $local_count === 0) {
			$status = 'bad_metadata';
			$details[] = 'No local files and no S3 objects found.';
		} else {
			$status = 'OK';
		}

		if ($missing_s3 !== array()) {
			$details[] = 'Confirmed missing on S3: ' . implode(', ', array_slice($missing_s3, 0, 10));
		}
		if ($unknown_s3 !== array()) {
			$details[] = 'Remote status unknown: ' . implode(', ', array_slice($unknown_s3, 0, 10));
		}
		if ($size_mismatch !== array()) {
			$details[] = 'Remote size mismatch: ' . implode(', ', array_slice($size_mismatch, 0, 10));
		}

		return array(
			'attachment_id' => $attachment_id,
			'status'        => $status,
			'details'       => $details,
			'present'       => $present_paths,
			'missing'       => $missing_s3,
			'unknown'       => $unknown_s3,
		);
	}

	/**
	 * Prefer inventory size_bytes from the same profile/key the HEAD used.
	 *
	 * @param array<string, mixed> $head Head result from ProfileAwareObjectOperations.
	 */
	private function inventory_size_for_head( int $attachment_id, string $relative, array $head ): ?int {
		$profile_id = isset( $head['storage_profile_id'] ) ? (int) $head['storage_profile_id'] : 0;
		$object_key = (string) ( $head['object_key'] ?? '' );

		foreach ( $this->objects->find_by_attachment( $attachment_id ) as $row ) {
			if ( (string) ( $row['local_relative_path'] ?? '' ) !== $relative ) {
				continue;
			}
			$status = (string) ( $row['remote_status'] ?? '' );
			if ( in_array( $status, array( ObjectRemoteStatus::STALE, ObjectRemoteStatus::DELETED ), true ) ) {
				continue;
			}
			if ( ! array_key_exists( 'size_bytes', $row ) || $row['size_bytes'] === null || $row['size_bytes'] === '' ) {
				continue;
			}
			if ( $profile_id > 0 && (int) ( $row['storage_profile_id'] ?? 0 ) !== $profile_id ) {
				continue;
			}
			if ( $object_key !== '' && (string) ( $row['object_key'] ?? '' ) !== $object_key ) {
				continue;
			}
			// Without locator identity (legacy fallback), only trust a PRESENT row size.
			if ( $profile_id <= 0 && $object_key === '' && $status !== ObjectRemoteStatus::PRESENT ) {
				continue;
			}
			return (int) $row['size_bytes'];
		}
		return null;
	}
}
