<?php
/**
 * Redirect deprecated admin slugs (v2 Phase 11).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps bookmarks working after menu IA change.
 */
final class AdminLegacyRedirects {

	public function register(): void {
		add_action( 'init', array( $this, 'maybe_redirect' ), 1 );
	}

	public function maybe_redirect(): void {
		if ( ! is_admin() || ! isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$page = sanitize_key( (string) wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// Keys are the original v1.x slugs (predate both rebrands) — never
		// rename these, they represent real historical bookmarks.
		$map  = array(
			's3-media-storage-tools'       => AdminMenu::MIGRATION_SLUG,
			's3-media-storage-diagnostics' => AdminMenu::HEALTH_SLUG,
			's3-media-storage-profiles'    => AdminMenu::STORAGE_SLUG,
		);
		if ( ! isset( $map[ $page ] ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . $map[ $page ] ) );
		exit;
	}
}
