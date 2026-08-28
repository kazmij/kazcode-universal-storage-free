<?php
/**
 * Batch adopt existing remote media into object inventory (v2 Phase 8).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Attachment\AttachmentOffloader;
use Kazcode\WpStorage\Core\Settings;

/**
 * Resumable HEAD-based adoption for legacy offloaded attachments.
 */
final class AdoptService {

	public const STATE_OPTION = 's3ms_adopt_run';

	public function __construct(
		private Settings $settings,
		private ?AdoptAttachmentService $adopt_attachment = null,
	) {
		$this->adopt_attachment = $adopt_attachment ?? new AdoptAttachmentService( $settings );
	}

	/**
	 * @return list<int>
	 */
	public function query_ids(
		int $batch_size = 50,
		int $after_id = 0,
		bool $legacy_only = true,
		?int $attachment_id = null,
	): array {
		if ( $attachment_id !== null && $attachment_id > 0 ) {
			$post = get_post( $attachment_id );
			return ( $post && $post->post_type === 'attachment' ) ? array( $attachment_id ) : array();
		}

		global $wpdb;
		$objects_table = $wpdb->prefix . 's3ms_objects';
		$limit         = max( 1, min( 200, $batch_size ) );

		$sql = "SELECT p.ID FROM {$wpdb->posts} p
			LEFT JOIN (
				SELECT attachment_id, COUNT(*) AS cnt FROM {$objects_table} GROUP BY attachment_id
			) o ON o.attachment_id = p.ID
			WHERE p.post_type = 'attachment'
			AND p.post_status = 'inherit'
			AND p.ID > %d
			AND (o.cnt IS NULL OR o.cnt = 0)";

		$params = array( $after_id );

		if ( $legacy_only ) {
			$sql .= " AND (
				EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm1
					WHERE pm1.post_id = p.ID AND pm1.meta_key = '_s3ms_status' AND pm1.meta_value = %s
				)
				OR EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm2
					WHERE pm2.post_id = p.ID AND pm2.meta_key = '_s3ms_original_key' AND pm2.meta_value != ''
				)
			)";
			$params[] = AttachmentOffloader::STATUS_OFFLOADED;
		}

		$sql     .= ' ORDER BY p.ID ASC LIMIT %d';
		$params[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built entirely from fixed table-name literals ($wpdb->posts, $wpdb->postmeta, $objects_table, all non-user-controlled) and hardcoded SQL keywords; every actual value is bound via the %d/%s placeholders passed to $wpdb->prepare() below. Plugin-owned s3ms_objects table, no core API for this query.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, ...$params ) );
		if ( ! is_array( $ids ) ) {
			return array();
		}
		return array_map( 'intval', $ids );
	}

	/**
	 * @return array{processed:int,success:int,failed:int,next_after_id:int,results:list<array<string,mixed>>}
	 */
	public function adopt_batch(
		int $batch_size = 50,
		int $after_id = 0,
		bool $dry_run = false,
		bool $legacy_only = true,
		?int $attachment_id = null,
		?int $profile_id = null,
	): array {
		$ids     = $this->query_ids( $batch_size, $after_id, $legacy_only, $attachment_id );
		$success = 0;
		$failed  = 0;
		$results = array();
		$last_id = $after_id;

		foreach ( $ids as $id ) {
			$last_id = max( $last_id, $id );
			$result  = $this->adopt_attachment->adopt( $id, $profile_id, $dry_run );
			$results[] = array_merge(
				$result,
				array( 'attachment_id' => $id )
			);
			if ( ! empty( $result['success'] ) ) {
				++$success;
			} else {
				++$failed;
			}
		}

		if ( ! $dry_run && $ids !== array() ) {
			update_option(
				self::STATE_OPTION,
				array(
					'after_id'    => $last_id,
					'processed'   => (int) ( $this->status()['processed'] ?? 0 ) + count( $ids ),
					'success'     => (int) ( $this->status()['success'] ?? 0 ) + $success,
					'failed'      => (int) ( $this->status()['failed'] ?? 0 ) + $failed,
					'updated_at'  => gmdate( 'c' ),
					'legacy_only' => $legacy_only,
				),
				false
			);
		}

		return array(
			'processed'     => count( $ids ),
			'success'       => $success,
			'failed'        => $failed,
			'next_after_id' => $last_id,
			'results'       => $results,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function status(): array {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	public function reset_state(): void {
		delete_option( self::STATE_OPTION );
	}
}
