<?php
/**
 * First-run product tour (dismissible, replayable per user).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Localizes tour-seen state for admin.js and persists dismissal per user,
 * per admin page — every Universal Storage screen has its own short tour
 * (see each Page class's data-s3ms-tour-step markup), and each is
 * independently dismissible/replayable.
 *
 * Step content itself lives in page markup via data-s3ms-tour-step /
 * data-s3ms-tour-title / data-s3ms-tour-text attributes; this class only
 * tracks whether the current user has already seen the current page's tour.
 */
final class OnboardingTour {

	/** @var string User meta key; value is array<string,bool> keyed by admin page slug. */
	private const USER_META_KEY = 's3ms_tour_dismissed';

	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		add_action( 'wp_ajax_kazus_dismiss_tour', array( $this, 'ajax_dismiss' ) );
	}

	/**
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public function enqueue( string $hook ): void {
		if ( strpos( $hook, 'kazcode-universal-storage' ) === false && $hook !== 'upload.php' ) {
			return;
		}
		if ( ! wp_script_is( 's3ms-admin', 'enqueued' ) ) {
			return;
		}

		$page      = $this->current_page_slug();
		$dismissed = $this->is_dismissed( $page );

		wp_add_inline_script(
			's3ms-admin',
			'window.s3msTour = ' . wp_json_encode(
				array(
					'dismissed' => $dismissed,
					'page'      => $page,
				)
			) . ';',
			'before'
		);
	}

	/**
	 * AJAX: mark the current page's tour dismissed for the current user (skip or finish).
	 *
	 * @return void
	 */
	public function ajax_dismiss(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'kazcode-universal-storage' ) ), 403 );
		}
		check_ajax_referer( 's3ms_admin', 'nonce' );

		$page = isset( $_POST['page'] ) ? sanitize_key( (string) $_POST['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $page === '' ) {
			wp_send_json_error( array( 'message' => 'Missing page.' ), 400 );
		}

		$all         = $this->all_dismissed();
		$all[ $page ] = true;
		update_user_meta( get_current_user_id(), self::USER_META_KEY, $all );
		wp_send_json_success();
	}

	/**
	 * Admin page slug for the current request ("media" from
	 * kazcode-universal-storage-media, "dashboard" for the bare top-level
	 * slug), or "media-library" for WP core's own Media Library screen.
	 */
	private function current_page_slug(): string {
		if ( isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$page = sanitize_key( (string) wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$page = preg_replace( '/^kazcode-universal-storage-?/', '', $page );
			return $page === '' ? 'dashboard' : $page;
		}
		return 'media-library';
	}

	/**
	 * @return array<string,bool>
	 */
	private function all_dismissed(): array {
		$stored = get_user_meta( get_current_user_id(), self::USER_META_KEY, true );
		return is_array( $stored ) ? $stored : array();
	}

	private function is_dismissed( string $page ): bool {
		return ! empty( $this->all_dismissed()[ $page ] );
	}
}
