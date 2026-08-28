<?php
/**
 * Failed / ignored attachment listing and actions.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Attachment\AttachmentOffloader;

/**
 * Query helpers for failed offloads.
 */
final class FailedItemsService {

	public const META_IGNORED = '_s3ms_ignored';

	/**
	 * Paginated failed list.
	 *
	 * @param int    $page Page (1-based).
	 * @param int    $per_page Per page.
	 * @param string $filter all|retryable|missing_local|ignored.
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int}
	 */
	public function list( int $page = 1, int $per_page = 20, string $filter = 'all' ): array {
		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'     => '_s3ms_status',
					'value'   => array(
						AttachmentOffloader::STATUS_FAILED,
						AttachmentOffloader::STATUS_PARTIAL,
					),
					'compare' => 'IN',
				),
			),
		);

		if ( $filter === 'ignored' ) {
			$args['meta_query'][] = array(
				'key'   => self::META_IGNORED,
				'value' => '1',
			);
		} elseif ( $filter !== 'all' ) {
			$args['meta_query'][] = array(
				'relation' => 'OR',
				array(
					'key'     => self::META_IGNORED,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => self::META_IGNORED,
					'value'   => '1',
					'compare' => '!=',
				),
			);
		}

		$q     = new \WP_Query( $args );
		$items = array();
		foreach ( $q->posts as $id ) {
			$id = (int) $id;
			$row = $this->row( $id );
			if ( $filter === 'missing_local' && empty( $row['missing_local'] ) ) {
				continue;
			}
			if ( $filter === 'retryable' && ( ! empty( $row['ignored'] ) || ! empty( $row['missing_local'] ) ) ) {
				continue;
			}
			$items[] = $row;
		}

		// For missing_local/retryable we may under-fill a page; still report found_posts for failed total when filter=all.
		$total = (int) $q->found_posts;
		if ( in_array( $filter, array( 'missing_local', 'retryable' ), true ) ) {
			$total = count( $items );
		}

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * @param int $attachment_id ID.
	 * @return array<string, mixed>
	 */
	public function row( int $attachment_id ): array {
		$error   = (string) get_post_meta( $attachment_id, '_s3ms_last_error', true );
		$ignored = (string) get_post_meta( $attachment_id, self::META_IGNORED, true ) === '1';
		$file    = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$missing = $this->looks_missing_local( $error, $attachment_id );

		return array(
			'id'            => $attachment_id,
			'title'         => get_the_title( $attachment_id ),
			'file'          => $file,
			'error'         => $error,
			'ignored'       => $ignored,
			'missing_local' => $missing,
			'edit_link'     => get_edit_post_link( $attachment_id, 'raw' ),
		);
	}

	/**
	 * Mark ignored.
	 *
	 * @param list<int> $ids IDs.
	 * @param bool      $ignored Ignored flag.
	 */
	public function set_ignored( array $ids, bool $ignored ): int {
		$n = 0;
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}
			if ( $ignored ) {
				update_post_meta( $id, self::META_IGNORED, '1' );
			} else {
				delete_post_meta( $id, self::META_IGNORED );
			}
			++$n;
		}
		return $n;
	}

	/**
	 * CSV export string.
	 *
	 * @param int $limit Max rows.
	 */
	public function to_csv( int $limit = 5000 ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp is an in-memory PHP stream, not a real filesystem path; WP_Filesystem has no equivalent for building a CSV string in memory via fputcsv()/stream_get_contents().
		$out  = fopen( 'php://temp', 'r+' );
		if ( $out === false ) {
			return '';
		}
		fputcsv( $out, array( 'id', 'title', 'file', 'error', 'ignored', 'missing_local' ) );
		$page = 1;
		$done = 0;
		while ( $done < $limit ) {
			$batch = $this->list( $page, 100, 'all' );
			if ( $batch['items'] === array() ) {
				break;
			}
			foreach ( $batch['items'] as $row ) {
				fputcsv(
					$out,
					array(
						$row['id'],
						$row['title'],
						$row['file'],
						$row['error'],
						! empty( $row['ignored'] ) ? '1' : '0',
						! empty( $row['missing_local'] ) ? '1' : '0',
					)
				);
				++$done;
				if ( $done >= $limit ) {
					break 2;
				}
			}
			++$page;
			if ( $page > 200 ) {
				break;
			}
		}
		rewind( $out );
		$csv = stream_get_contents( $out );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- releases the in-memory php://temp stream opened above; no real filesystem path involved, WP_Filesystem is not applicable.
		fclose( $out );
		return is_string( $csv ) ? $csv : '';
	}

	/**
	 * @param string $error Error text.
	 * @param int    $attachment_id ID.
	 */
	private function looks_missing_local( string $error, int $attachment_id ): bool {
		if ( stripos( $error, 'no local' ) !== false || stripos( $error, 'not found' ) !== false || stripos( $error, 'missing' ) !== false ) {
			return true;
		}
		$file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( $file === '' ) {
			return true;
		}
		$uploads = wp_upload_dir();
		$path    = trailingslashit( $uploads['basedir'] ) . ltrim( $file, '/' );
		return ! is_readable( $path );
	}
}
