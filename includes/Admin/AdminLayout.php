<?php
/**
 * Shared admin chrome for Universal Storage screens (v2 Phase 11).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Admin;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\Features;

/**
 * Header + nav helpers.
 */
final class AdminLayout {

	/**
	 * Compact product identity strip rendered at the very top of every Universal
	 * Storage admin screen (Free and Pro alike — Pro pages call this same method
	 * rather than duplicating the asset URL or markup). Separate from header(),
	 * which carries per-page title/tagline/actions.
	 */
	public static function brand_header(): void {
		$logo_url = defined( 'KAZUS_PLUGIN_URL' ) ? KAZUS_PLUGIN_URL . 'assets/brand/logo-mark.svg' : '';
		?>
		<div class="kazus-brand-header">
			<?php if ( $logo_url !== '' ) : ?>
				<img class="kazus-brand-mark" src="<?php echo esc_url( $logo_url ); ?>" width="32" height="32" alt="" />
			<?php endif; ?>
			<div class="kazus-brand-header__text">
				<p class="kazus-brand-title">
					<?php esc_html_e( 'KAZCODE Universal Storage', 'kazcode-universal-storage' ); ?>
					<?php if ( Features::is_pro_active() ) : ?>
						<span class="kazus-brand-pro-badge"><?php esc_html_e( 'PRO', 'kazcode-universal-storage' ); ?></span>
					<?php endif; ?>
				</p>
				<p class="kazus-brand-tagline"><?php esc_html_e( 'Reliable cloud & object storage for WordPress', 'kazcode-universal-storage' ); ?></p>
			</div>
		</div>
		<?php
		self::pro_modal();
	}

	/**
	 * Shared "what's in Pro and why it's worth it" modal. Rendered hidden on
	 * every screen (once, from brand_header()) when Pro isn't active; opened
	 * via any button carrying [data-kazus-pro-modal-open] (see admin.js).
	 * The purchase/details CTA inside still links out to kazcode.net — there
	 * is no in-plugin checkout yet — but reading about Pro no longer requires
	 * leaving wp-admin first.
	 */
	private static function pro_modal(): void {
		if ( Features::is_pro_active() ) {
			return;
		}
		$features = array(
			array(
				'title' => __( 'Multiple storage profiles', 'kazcode-universal-storage' ),
				'text'  => __( 'Connect and deliver from more than one bucket or provider at once, each with its own credentials.', 'kazcode-universal-storage' ),
			),
			array(
				'title' => __( 'Cross-provider migration', 'kazcode-universal-storage' ),
				'text'  => __( 'Move existing media between providers — e.g. Amazon S3 → Cloudflare R2 — with verify-before-switch safety: delivery only changes after the destination is confirmed.', 'kazcode-universal-storage' ),
			),
			array(
				'title' => __( 'Orphan scan', 'kazcode-universal-storage' ),
				'text'  => __( 'Dry-run report of remote objects with no matching WordPress attachment — reports only, never deletes.', 'kazcode-universal-storage' ),
			),
			array(
				'title' => __( 'Advanced health & reconcile', 'kazcode-universal-storage' ),
				'text'  => __( 'Deeper diagnostics and repair tools beyond the Free health check.', 'kazcode-universal-storage' ),
			),
			array(
				'title' => __( 'Background migration', 'kazcode-universal-storage' ),
				'text'  => __( 'Move large libraries in the background instead of one batch at a time in the browser.', 'kazcode-universal-storage' ),
			),
			array(
				'title' => __( 'Audit log', 'kazcode-universal-storage' ),
				'text'  => __( 'See who changed settings and when, plus background job and REST batch history.', 'kazcode-universal-storage' ),
			),
			array(
				'title' => __( 'Signed URLs for private media', 'kazcode-universal-storage' ),
				'text'  => __( 'Serve a private bucket without making objects public.', 'kazcode-universal-storage' ),
			),
			array(
				'title' => __( 'Multisite network defaults', 'kazcode-universal-storage' ),
				'text'  => __( 'Configure storage once for an entire multisite network.', 'kazcode-universal-storage' ),
			),
		);
		?>
		<div id="kazus-pro-modal" class="kazus-modal" hidden aria-hidden="true">
			<div class="kazus-modal__overlay" data-kazus-pro-modal-close></div>
			<div class="kazus-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="kazus-pro-modal-title">
				<button type="button" class="kazus-modal__close" data-kazus-pro-modal-close aria-label="<?php esc_attr_e( 'Close', 'kazcode-universal-storage' ); ?>">&times;</button>
				<h2 id="kazus-pro-modal-title" class="kazus-modal__title">
					<?php esc_html_e( 'KAZCODE Universal Storage', 'kazcode-universal-storage' ); ?>
					<span class="kazus-brand-pro-badge"><?php esc_html_e( 'PRO', 'kazcode-universal-storage' ); ?></span>
				</h2>
				<p class="kazus-modal__intro">
					<?php esc_html_e( 'This plugin (Free) is a complete, non-trial product — automatic offload, migrate, verify, retry, restore, and native Media Library integration, all with one storage profile. As your setup grows — more storage destinations, migrations between providers, larger libraries — Pro is a separate add-on that adds:', 'kazcode-universal-storage' ); ?>
				</p>
				<dl class="kazus-modal__features">
					<?php foreach ( $features as $feature ) : ?>
						<div class="kazus-modal__feature">
							<dt><?php echo esc_html( $feature['title'] ); ?></dt>
							<dd><?php echo esc_html( $feature['text'] ); ?></dd>
						</div>
					<?php endforeach; ?>
				</dl>
				<p class="kazus-modal__reassurance">
					<?php esc_html_e( 'Deactivating Pro never deletes data or breaks media that\'s already being served — existing profiles, credentials, and delivery keep working; only new premium operations become unavailable until reactivated.', 'kazcode-universal-storage' ); ?>
				</p>
				<div class="kazus-modal__actions">
					<button type="button" class="button" data-kazus-pro-modal-close><?php esc_html_e( 'Maybe later', 'kazcode-universal-storage' ); ?></button>
					<a class="button button-primary" href="https://kazcode.net/universal-storage/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'See pricing & Pro details', 'kazcode-universal-storage' ); ?></a>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * @param string               $title Page title.
	 * @param string               $tagline Subtitle.
	 * @param string               $icon Dashicons class suffix.
	 * @param array<string, string> $actions slug => label links (optional).
	 */
	public static function header( string $title, string $tagline, string $icon = 'cloud', array $actions = array() ): void {
		?>
		<header class="s3ms-header">
			<div class="s3ms-header__brand">
				<span class="s3ms-header__icon dashicons dashicons-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
				<div>
					<h1 class="s3ms-header__title"><?php echo esc_html( $title ); ?></h1>
					<p class="s3ms-header__tagline"><?php echo esc_html( $tagline ); ?></p>
				</div>
			</div>
			<?php if ( $actions !== array() ) : ?>
				<div class="s3ms-header__actions">
					<?php foreach ( $actions as $url => $label ) : ?>
						<a class="button" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</header>
		<?php
	}

	/**
	 * Prominent free-plan banner pointing to Pro. No-ops when Pro is active.
	 */
	public static function pro_upsell_banner(): void {
		if ( Features::is_pro_active() ) {
			return;
		}
		?>
		<div class="kazus-pro-banner">
			<?php
			// This banner is deliberately NOT a tour step (no data-s3ms-tour-*
			// attributes) — it's a persistent marketing banner, not tutorial
			// content. It previously hardcoded data-s3ms-tour-step="6", which
			// silently outranked whatever step number the page's own
			// tour_replay_button() was given (always meant to be the true
			// final step — see its docblock), making the tour end on this
			// banner instead of closing cleanly. See tests/CHARACTERIZATION.md.
			?>
			<div class="kazus-pro-banner__text">
				<strong><?php esc_html_e( "You're on the Free plan", 'kazcode-universal-storage' ); ?></strong>
				<p><?php esc_html_e( 'Upgrade to Pro for multiple storage destinations, cross-provider migration, orphan scan, advanced health checks, and multisite network defaults.', 'kazcode-universal-storage' ); ?></p>
			</div>
			<button type="button" class="button button-primary kazus-pro-banner__cta" data-kazus-pro-modal-open><?php esc_html_e( 'See Pro features', 'kazcode-universal-storage' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Small attribution line rendered at the bottom of every admin screen.
	 */
	public static function footer(): void {
		?>
		<p class="kazus-brand-footer">
			<?php
			printf(
				/* translators: 1: plugin version, 2: KAZCODE link */
				esc_html__( 'KAZCODE Universal Storage %1$s — Built by %2$s', 'kazcode-universal-storage' ),
				esc_html( defined( 'KAZUS_VERSION' ) ? KAZUS_VERSION : '' ),
				'<a href="https://kazcode.net/" target="_blank" rel="noopener noreferrer">KAZCODE</a>'
			);
			?>
			<span class="kazus-brand-footer__sep" aria-hidden="true">·</span>
			<a href="https://kazcode.net/universal-storage/docs/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Documentation', 'kazcode-universal-storage' ); ?></a>
			<span class="kazus-brand-footer__sep" aria-hidden="true">·</span>
			<a href="https://kazcode.net/universal-storage/support/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Support', 'kazcode-universal-storage' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Replay button for this page's onboarding tour. Always pass the highest
	 * step number on the page — the tour engine sorts steps by that number,
	 * not by DOM order, so this reliably plays last regardless of where it
	 * sits in the markup.
	 */
	public static function tour_replay_button( int $step_number ): void {
		?>
		<p class="s3ms-tour-launch"
			data-s3ms-tour-step="<?php echo esc_attr( (string) $step_number ); ?>"
			data-s3ms-tour-title="<?php esc_attr_e( 'Replay this any time', 'kazcode-universal-storage' ); ?>"
			data-s3ms-tour-text="<?php esc_attr_e( 'Click this button whenever you want to see this page\'s tour again — nothing is lost, and it never runs automatically after the first time.', 'kazcode-universal-storage' ); ?>"
		>
			<button type="button" id="s3ms-tour-replay" class="button">
				<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
				<?php esc_html_e( 'Show tutorial', 'kazcode-universal-storage' ); ?>
			</button>
			<button type="button" id="s3ms-tour-disable-all" class="button">
				<?php esc_html_e( 'Disable tutorials', 'kazcode-universal-storage' ); ?>
			</button>
		</p>
		<?php
	}

	/**
	 * Subnav for primary IA sections.
	 */
	public static function subnav( string $active ): void {
		$items = array(
			AdminMenu::DASHBOARD_SLUG  => __( 'Dashboard', 'kazcode-universal-storage' ),
			AdminMenu::MEDIA_SLUG      => __( 'Media', 'kazcode-universal-storage' ),
			AdminMenu::STORAGE_SLUG    => __( 'Storage', 'kazcode-universal-storage' ),
			AdminMenu::MIGRATION_SLUG  => __( 'Migration', 'kazcode-universal-storage' ),
			AdminMenu::HEALTH_SLUG     => __( 'Health', 'kazcode-universal-storage' ),
			AdminMenu::LOGS_SLUG       => __( 'Logs', 'kazcode-universal-storage' ),
			AdminMenu::SETTINGS_SLUG   => __( 'Settings', 'kazcode-universal-storage' ),
		);
		echo '<nav class="s3ms-subnav" aria-label="' . esc_attr__( 'Universal Storage sections', 'kazcode-universal-storage' ) . '">';
		foreach ( $items as $slug => $label ) {
			$url   = admin_url( 'admin.php?page=' . $slug );
			$class = $slug === $active ? ' is-active' : '';
			printf(
				'<a class="s3ms-subnav__link%s" href="%s">%s</a>',
				esc_attr( $class ),
				esc_url( $url ),
				esc_html( $label )
			);
		}
		echo '</nav>';
	}
}
