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
use Kazcode\WpStorage\Storage\S3Storage;

/**
 * Per-attachment verification reports.
 */
final class VerificationService {

	private Settings $settings;
	private S3Storage $storage;
	private AttachmentFileResolver $files;

	public function __construct(Settings $settings, S3Storage $storage) {
		$this->settings = $settings;
		$this->storage  = $storage;
		$this->files    = new AttachmentFileResolver($storage->keys());
	}

	/**
	 * Verify one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{status:string,details:list<string>,attachment_id:int}
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
		$present_s3  = 0;

		if ($this->settings->is_aws_configured()) {
			foreach ($paths as $relative) {
				$head = $this->storage->head_relative($relative);
				if (!empty($head['exists'])) {
					++$present_s3;
				} else {
					$missing_s3[] = $relative;
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

		if ($present_s3 === 0 && $local_count > 0) {
			$status = 'local_only';
		} elseif ($local_count === 0 && $present_s3 > 0 && $missing_s3 === array()) {
			$status = 's3_only';
			update_post_meta($attachment_id, '_s3ms_verified_at', gmdate('c'));
		} elseif ($missing_s3 !== array() && $present_s3 > 0) {
			$status = 'partial_offload';
		} elseif ($is_original_missing) {
			$status = 'missing_s3_original';
		} elseif ($thumb_missing) {
			$status = 'missing_s3_thumbnail';
		} elseif ($missing_s3 === array() && $present_s3 > 0) {
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
			$details[] = 'Missing on S3: ' . implode(', ', array_slice($missing_s3, 0, 10));
		}

		return array(
			'attachment_id' => $attachment_id,
			'status'        => $status,
			'details'       => $details,
		);
	}
}
