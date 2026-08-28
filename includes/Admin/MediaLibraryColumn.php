<?php
/**
 * Media Library list column + row/bulk actions.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Admin;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Plugin;
use Kazcode\WpStorage\Services\AttachmentObjectSummary;
use Kazcode\WpStorage\Services\AuditLog;
use Kazcode\WpStorage\Services\FailedItemsService;

/**
 * Adds S3 status column and offload/restore/verify actions.
 */
final class MediaLibraryColumn {

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'manage_media_columns', array( $this, 'columns' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'media_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
		add_action( 'admin_post_kazus_attachment_action', array( $this, 'handle_row_action' ) );
	}

	/**
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function columns( array $columns ): array {
		$columns['s3ms_status'] = __( 'S3', 'kazcode-universal-storage' );
		return $columns;
	}

	/**
	 * @param string $column Column key.
	 * @param int    $post_id Attachment ID.
	 */
	public function render_column( string $column, int $post_id ): void {
		if ( 's3ms_status' !== $column ) {
			return;
		}

		$summary = ( new AttachmentObjectSummary() )->summarize( $post_id );
		$state   = (string) ( $summary['state'] ?? 'local' );
		$class   = 's3ms-ml-badge--' . sanitize_html_class( $state );
		$label   = (string) ( $summary['label'] ?? ucfirst( $state ) );

		$ignored = (string) get_post_meta( $post_id, FailedItemsService::META_IGNORED, true ) === '1';
		if ( $ignored ) {
			$label .= ' / ' . __( 'ignored', 'kazcode-universal-storage' );
		}

		echo '<span class="s3ms-ml-badge ' . esc_attr( $class ) . '" title="' . esc_attr( $this->column_title( $summary ) ) . '">' . esc_html( $label ) . '</span>';

		if ( (int) ( $summary['object_count'] ?? 0 ) > 0 ) {
			echo '<br /><span class="s3ms-ml-meta">';
			if ( (string) ( $summary['profile'] ?? '' ) !== '' ) {
				echo esc_html( (string) $summary['profile'] );
			}
			if ( ! empty( $summary['last_verified'] ) ) {
				if ( (string) ( $summary['profile'] ?? '' ) !== '' ) {
					echo ' · ';
				}
				echo esc_html( (string) $summary['last_verified'] );
			}
			echo '</span>';
		}
	}

	/**
	 * @param array<string, mixed> $summary AttachmentObjectSummary row.
	 */
	private function column_title( array $summary ): string {
		$parts = array(
			(string) ( $summary['label'] ?? '' ),
			sprintf(
				/* translators: 1: present count 2: total object rows */
				__( '%1$d/%2$d objects present', 'kazcode-universal-storage' ),
				(int) ( $summary['present_count'] ?? 0 ),
				(int) ( $summary['object_count'] ?? 0 )
			),
		);
		if ( (string) ( $summary['profile'] ?? '' ) !== '' ) {
			$parts[] = (string) $summary['profile'];
		}
		if ( ! empty( $summary['last_verified'] ) ) {
			$parts[] = (string) $summary['last_verified'];
		}
		return implode( ' · ', array_filter( $parts ) );
	}

	/**
	 * @param array<string, string> $actions Actions.
	 * @param \WP_Post              $post Post.
	 * @return array<string, string>
	 */
	public function row_actions( array $actions, \WP_Post $post ): array {
		if ( ! current_user_can( 'manage_options' ) || 'attachment' !== $post->post_type ) {
			return $actions;
		}
		$id = (int) $post->ID;
		foreach ( array( 'offload', 'verify', 'restore', 'ignore', 'unignore' ) as $act ) {
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=kazus_attachment_action&s3ms_do=' . $act . '&attachment_id=' . $id ),
				'kazus_att_' . $id
			);
			$actions[ 's3ms_' . $act ] = '<a href="' . esc_url( $url ) . '">' . esc_html( $this->action_label( $act ) ) . '</a>';
		}
		return $actions;
	}

	/**
	 * @param array<string, string> $actions Actions.
	 * @return array<string, string>
	 */
	public function bulk_actions( array $actions ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $actions;
		}
		$actions['s3ms_offload']  = __( 'S3: Offload', 'kazcode-universal-storage' );
		$actions['s3ms_verify']   = __( 'S3: Verify', 'kazcode-universal-storage' );
		$actions['s3ms_restore']  = __( 'S3: Restore local', 'kazcode-universal-storage' );
		$actions['s3ms_ignore']   = __( 'S3: Ignore failed', 'kazcode-universal-storage' );
		$actions['s3ms_unignore'] = __( 'S3: Unignore', 'kazcode-universal-storage' );
		return $actions;
	}

	/**
	 * @param string        $redirect Redirect URL.
	 * @param string        $doaction Action.
	 * @param array<int,int> $post_ids IDs.
	 */
	public function handle_bulk( string $redirect, string $doaction, array $post_ids ): string {
		if ( strpos( $doaction, 's3ms_' ) !== 0 || ! current_user_can( 'manage_options' ) ) {
			return $redirect;
		}
		$act = substr( $doaction, 5 );
		$n   = $this->run_many( $act, array_map( 'intval', $post_ids ) );
		return add_query_arg( 's3ms_bulk', $n, $redirect );
	}

	/**
	 * Admin notice after bulk.
	 */
	public function bulk_notice(): void {
		if ( ! isset( $_GET['s3ms_bulk'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$n = (int) $_GET['s3ms_bulk']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: count */
					__( 'KAZCODE Universal Storage processed %d attachment(s).', 'kazcode-universal-storage' ),
					$n
				)
			)
		);
	}

	/**
	 * Single row action handler.
	 */
	public function handle_row_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'kazcode-universal-storage' ) );
		}
		$id  = isset( $_GET['attachment_id'] ) ? (int) $_GET['attachment_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$act = isset( $_GET['s3ms_do'] ) ? sanitize_key( (string) $_GET['s3ms_do'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'kazus_att_' . $id );
		$this->run_many( $act, array( $id ) );
		wp_safe_redirect( admin_url( 'upload.php' ) );
		exit;
	}

	/**
	 * @param string   $act Action.
	 * @param list<int> $ids IDs.
	 */
	private function run_many( string $act, array $ids ): int {
		$plugin = Plugin::instance();
		$failed = new FailedItemsService();
		$audit  = new AuditLog();
		$n      = 0;
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}
			if ( $act === 'offload' ) {
				$plugin->offloader()->offload( $id );
			} elseif ( $act === 'verify' ) {
				$plugin->verification_service()->verify( $id );
			} elseif ( $act === 'restore' ) {
				$plugin->restorer()->restore( $id );
			} elseif ( $act === 'ignore' ) {
				$failed->set_ignored( array( $id ), true );
			} elseif ( $act === 'unignore' ) {
				$failed->set_ignored( array( $id ), false );
			} else {
				continue;
			}
			++$n;
		}
		$audit->record( 'media_library_' . $act, array( 'count' => $n, 'ids' => array_slice( $ids, 0, 20 ) ) );
		return $n;
	}

	private function action_label( string $act ): string {
		$map = array(
			'offload'   => __( 'S3 Offload', 'kazcode-universal-storage' ),
			'verify'    => __( 'S3 Verify', 'kazcode-universal-storage' ),
			'restore'   => __( 'S3 Restore', 'kazcode-universal-storage' ),
			'ignore'    => __( 'S3 Ignore failed', 'kazcode-universal-storage' ),
			'unignore'  => __( 'S3 Unignore', 'kazcode-universal-storage' ),
		);
		return $map[ $act ] ?? $act;
	}
}
