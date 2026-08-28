<?php
/**
 * Batch migration of existing Media Library attachments.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Attachment\AttachmentOffloader;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Plugin;

/**
 * Migration queries and batch processing.
 */
final class MigrationService {

	public const STATUS_FILTER_RETRY = 'retry';

	private Settings $settings;
	private AttachmentOffloader $offloader;
	private VerificationService $verification;

	public function __construct(Settings $settings, AttachmentOffloader $offloader, VerificationService $verification) {
		$this->settings     = $settings;
		$this->offloader    = $offloader;
		$this->verification = $verification;
	}

	/**
	 * Aggregate counts for admin dashboard.
	 *
	 * @return array{total:int,offloaded:int,pending:int,failed:int,verified:int,uploading:int}
	 */
	public function stats(): array {
		$total     = $this->count_attachments();
		$offloaded = $this->count_by_status(AttachmentOffloader::STATUS_OFFLOADED);
		$failed    = $this->count_by_status(AttachmentOffloader::STATUS_FAILED);
		$uploading = $this->count_by_status(AttachmentOffloader::STATUS_UPLOADING);

		return array(
			'total'     => $total,
			'offloaded' => $offloaded,
			'pending'   => max(0, $total - $offloaded - $failed - $uploading),
			'failed'    => $failed,
			'verified'  => $this->count_with_meta('_s3ms_verified_at'),
			'uploading' => $uploading,
		);
	}

	/**
	 * Total attachment count.
	 */
	private function count_attachments(): int {
		$q = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		return (int) $q->found_posts;
	}

	/**
	 * Count attachments with a given _s3ms_status.
	 */
	private function count_by_status(string $status): int {
		$q = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_s3ms_status',
				'meta_value'     => $status,
			)
		);
		return (int) $q->found_posts;
	}

	/**
	 * Count attachments that have a meta key set.
	 */
	private function count_with_meta(string $key): int {
		$q = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => $key,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		return (int) $q->found_posts;
	}

	/**
	 * Query attachment IDs for migration.
	 *
	 * Default migrate queue: never offloaded OR pending/uploading — excludes failed (use retry-failed).
	 *
	 * @param int         $batch_size Batch size.
	 * @param string|null $status_filter pending|failed|retry|null.
	 * @param int|null    $attachment_id Single ID.
	 * @param int         $after_id Only IDs greater than this (cursor).
	 * @return list<int>
	 */
	public function query_ids(
		int $batch_size = 100,
		?string $status_filter = null,
		?int $attachment_id = null,
		int $after_id = 0
	): array {
		if ($attachment_id !== null && $attachment_id > 0) {
			$post = get_post($attachment_id);
			if ($post && $post->post_type === 'attachment') {
				return array($attachment_id);
			}
			return array();
		}

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $batch_size,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);

		$where_filter = null;
		if ($after_id > 0) {
			$where_filter = static function (string $where) use ($after_id): string {
				global $wpdb;
				return $where . $wpdb->prepare(" AND {$wpdb->posts}.ID > %d", $after_id);
			};
			add_filter('posts_where', $where_filter, 10, 1);
		}

		if ( $status_filter === self::STATUS_FILTER_RETRY ) {
			$args['meta_query'] = array(
				array(
					'key'     => '_s3ms_status',
					'value'   => array(
						AttachmentOffloader::STATUS_FAILED,
						AttachmentOffloader::STATUS_PARTIAL,
					),
					'compare' => 'IN',
				),
			);
		} elseif ( $status_filter === AttachmentOffloader::STATUS_FAILED ) {
			$args['meta_key']   = '_s3ms_status';
			$args['meta_value'] = AttachmentOffloader::STATUS_FAILED;
		} else {
			// Pending / never touched: no status, pending, or uploading (recover). Exclude failed + offloaded.
			$args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key'     => '_s3ms_status',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_s3ms_status',
					'value'   => AttachmentOffloader::STATUS_PENDING,
					'compare' => '=',
				),
				array(
					'key'     => '_s3ms_status',
					'value'   => AttachmentOffloader::STATUS_UPLOADING,
					'compare' => '=',
				),
			);
		}

		$q = new \WP_Query($args);

		if ($where_filter !== null) {
			remove_filter('posts_where', $where_filter, 10);
		}

		return array_map('intval', $q->posts);
	}

	/**
	 * Process a batch.
	 *
	 * @param int       $batch_size Batch size.
	 * @param bool      $dry_run Dry run.
	 * @param bool      $retry_failed Only failed.
	 * @param int|null  $attachment_id Single ID.
	 * @param bool|null $delete_local Override delete local.
	 * @param int       $after_id Cursor.
	 * @return array{processed:int,success:int,failed:int,next_after_id:int,results:list<array<string,mixed>>}
	 */
	public function migrate_batch(
		int $batch_size = 100,
		bool $dry_run = false,
		bool $retry_failed = false,
		?int $attachment_id = null,
		?bool $delete_local = null,
		int $after_id = 0
	): array {
		$filter = $retry_failed ? self::STATUS_FILTER_RETRY : null;
		$ids    = $this->query_ids($batch_size, $filter, $attachment_id, $after_id);

		$results = array();
		$success = 0;
		$failed  = 0;

		foreach ($ids as $id) {
			if ($dry_run) {
				$results[] = $this->offloader->dry_run($id);
				++$success;
				continue;
			}

			$result                  = $this->offloader->offload($id, $delete_local);
			$result['attachment_id'] = $id;
			$results[]               = $result;
			if (!empty($result['success'])) {
				++$success;
			} else {
				++$failed;
			}
		}

		$next = $ids === array() ? $after_id : max($ids);

		return array(
			'processed'      => count($ids),
			'success'        => $success,
			'failed'         => $failed,
			'next_after_id'  => $next,
			'results'        => $results,
		);
	}

	/**
	 * Verify a batch of attachments.
	 *
	 * @param int      $batch_size Batch size.
	 * @param int|null $attachment_id Single ID.
	 * @param int      $after_id Cursor.
	 * @return array{processed:int,next_after_id:int,results:list<array<string,mixed>>}
	 */
	public function verify_batch(int $batch_size = 100, ?int $attachment_id = null, int $after_id = 0): array {
		if ($attachment_id) {
			$ids = array($attachment_id);
		} else {
			$args = array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => $batch_size,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'   => '_s3ms_status',
						'value' => AttachmentOffloader::STATUS_OFFLOADED,
					),
				),
			);
			if ($after_id > 0) {
				$where = static function (string $where) use ($after_id): string {
					global $wpdb;
					return $where . $wpdb->prepare(" AND {$wpdb->posts}.ID > %d", $after_id);
				};
				add_filter('posts_where', $where, 10, 1);
				$q = new \WP_Query($args);
				remove_filter('posts_where', $where, 10);
			} else {
				$q = new \WP_Query($args);
			}
			$ids = array_map('intval', $q->posts);
		}

		$results = array();
		foreach ($ids as $id) {
			$results[] = $this->verification->verify($id);
		}

		return array(
			'processed'     => count($ids),
			'next_after_id' => $ids === array() ? $after_id : max($ids),
			'results'       => $results,
		);
	}

	/**
	 * Restore batch from S3.
	 *
	 * @param int      $batch_size Batch size.
	 * @param int|null $attachment_id Single ID.
	 * @param int      $after_id Cursor.
	 * @return array{processed:int,success:int,failed:int,next_after_id:int,results:list<array<string,mixed>>}
	 */
	public function restore_batch(int $batch_size = 100, ?int $attachment_id = null, int $after_id = 0): array {
		$restorer = Plugin::instance()->restorer();

		if ($attachment_id !== null && $attachment_id > 0) {
			$ids = array($attachment_id);
		} else {
			$args = array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => $batch_size,
				'fields'         => 'ids',
				'meta_key'       => '_s3ms_status',
				'meta_value'     => AttachmentOffloader::STATUS_OFFLOADED,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			);
			if ($after_id > 0) {
				$where = static function (string $where) use ($after_id): string {
					global $wpdb;
					return $where . $wpdb->prepare(" AND {$wpdb->posts}.ID > %d", $after_id);
				};
				add_filter('posts_where', $where, 10, 1);
				$q = new \WP_Query($args);
				remove_filter('posts_where', $where, 10);
			} else {
				$q = new \WP_Query($args);
			}
			$ids = array_map('intval', $q->posts);
		}

		$success = 0;
		$failed  = 0;
		$results = array();
		foreach ($ids as $id) {
			$r                  = $restorer->restore($id);
			$r['attachment_id'] = $id;
			$results[]          = $r;
			if (!empty($r['success'])) {
				++$success;
			} else {
				++$failed;
			}
		}

		return array(
			'processed'     => count($ids),
			'success'       => $success,
			'failed'        => $failed,
			'next_after_id' => $ids === array() ? $after_id : max($ids),
			'results'       => $results,
		);
	}
}
