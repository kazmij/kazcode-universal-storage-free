<?php
/**
 * First-run setup wizard.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Admin;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\Features;
use Kazcode\WpStorage\Core\ProviderPresets;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Plugin;
use Kazcode\WpStorage\Services\AuditLog;

/**
 * Guided 4-step onboarding.
 */
final class SetupWizardPage {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_redirect' ) );
		add_action( 'admin_post_kazus_wizard_save', array( $this, 'save' ) );
	}

	/**
	 * Redirect to the wizard once, on the first admin page load after a
	 * single-plugin activation, when setup hasn't been completed yet. Mirrors
	 * the common WP-plugin pattern (WooCommerce, Yoast, etc.): activate() sets
	 * a short-lived transient; this consumes and deletes it immediately so it
	 * can never fire twice or on an unrelated later page load. Explicitly
	 * skipped for network-admin, AJAX, and bulk-activation (WP core's own
	 * `activate-multi` query var) — a redirect during bulk-activate would only
	 * ever show the wizard for the last plugin in the batch, which is
	 * confusing rather than helpful.
	 */
	public function maybe_redirect(): void {
		if ( ! is_admin() || is_network_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( ! get_transient( 'kazus_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'kazus_activation_redirect' );

		if ( ! current_user_can( 'manage_options' ) || ! $this->settings->needs_wizard() ) {
			return;
		}
		if ( isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . AdminMenu::WIZARD_SLUG ) );
		exit;
	}

	/**
	 * Persist wizard step.
	 */
	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'kazcode-universal-storage' ) );
		}
		check_admin_referer( 'kazus_wizard' );

		$step = isset( $_POST['step'] ) ? (int) $_POST['step'] : 1;
		$data = isset( $_POST[ Settings::OPTION_KEY ] ) && is_array( $_POST[ Settings::OPTION_KEY ] )
			? wp_unslash( $_POST[ Settings::OPTION_KEY ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array();

		$current = $this->settings->all();
		$clean   = $this->settings->sanitize( is_array( $data ) ? $data : array(), $current );
		update_option( Settings::OPTION_KEY, $clean, false );
		$this->settings->flush_cache();

		if ( isset( $_POST['s3ms_secret_access_key'] ) ) {
			$secret = (string) wp_unslash( $_POST['s3ms_secret_access_key'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( $secret !== '' ) {
				$this->settings->set_secret_access_key( $secret );
			}
		}

		( new AuditLog() )->record( 'wizard_step', array( 'step' => $step ) );

		if ( ! empty( $_POST['finish'] ) ) {
			$this->settings->complete_wizard();
			wp_safe_redirect( admin_url( 'admin.php?page=' . AdminMenu::MENU_SLUG ) );
			exit;
		}

		$next = min( 4, $step + 1 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . AdminMenu::WIZARD_SLUG . '&step=' . $next ) );
		exit;
	}

	/**
	 * Render wizard.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$step = isset( $_GET['step'] ) ? max( 1, min( 4, (int) $_GET['step'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$s    = $this->settings->all();
		$opt  = Settings::OPTION_KEY;
		$presets = ProviderPresets::all();
		?>
		<div class="wrap s3ms-wrap">
			<?php AdminLayout::brand_header(); ?>
			<header class="s3ms-header">
				<div class="s3ms-header__brand">
					<span class="s3ms-header__icon dashicons dashicons-admin-generic" aria-hidden="true"></span>
					<div>
						<h1 class="s3ms-header__title"><?php esc_html_e( 'Setup wizard', 'kazcode-universal-storage' ); ?></h1>
						<p class="s3ms-header__tagline"><?php
						echo esc_html(
							sprintf(
								/* translators: %d: step */
								__( 'Step %d of 4 — get offloading in minutes.', 'kazcode-universal-storage' ),
								$step
							)
						);
						?></p>
					</div>
				</div>
			</header>

			<?php
			$step_labels = array(
				1 => __( 'Provider', 'kazcode-universal-storage' ),
				2 => __( 'Connection', 'kazcode-universal-storage' ),
				3 => __( 'Delivery', 'kazcode-universal-storage' ),
				4 => __( 'Finish', 'kazcode-universal-storage' ),
			);
			?>
			<ol class="s3ms-wizard-steps" aria-label="<?php esc_attr_e( 'Setup progress', 'kazcode-universal-storage' ); ?>">
				<?php foreach ( $step_labels as $num => $label ) : ?>
					<?php
					$state = $num === $step ? 'is-current' : ( $num < $step ? 'is-done' : '' );
					?>
					<li class="s3ms-wizard-steps__item <?php echo esc_attr( $state ); ?>" <?php echo $num === $step ? 'aria-current="step"' : ''; ?>>
						<span class="s3ms-wizard-steps__num" aria-hidden="true"><?php echo $num < $step ? '&#10003;' : esc_html( (string) $num ); ?></span>
						<span class="s3ms-wizard-steps__label"><?php echo esc_html( $label ); ?></span>
					</li>
				<?php endforeach; ?>
			</ol>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="s3ms-card">
				<input type="hidden" name="action" value="kazus_wizard_save" />
				<input type="hidden" name="step" value="<?php echo esc_attr( (string) $step ); ?>" />
				<?php wp_nonce_field( 'kazus_wizard' ); ?>

				<div class="s3ms-card__body">
					<?php if ( 1 === $step ) : ?>
						<h2><?php esc_html_e( '1. Choose provider', 'kazcode-universal-storage' ); ?></h2>
						<p><?php esc_html_e( 'Pick a preset to pre-fill endpoint and path-style defaults. You can edit everything later in Settings.', 'kazcode-universal-storage' ); ?></p>
						<?php foreach ( $presets as $slug => $preset ) : ?>
							<label class="s3ms-toggle__row" style="margin-bottom:10px">
								<input type="radio" name="<?php echo esc_attr( $opt ); ?>[provider_preset]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( (string) $s['provider_preset'], $slug ); ?> />
								<span><strong><?php echo esc_html( $preset['label'] ); ?></strong> — <?php echo esc_html( $preset['help'] ); ?></span>
							</label>
						<?php endforeach; ?>
					<?php
					elseif ( 2 === $step ) :
						$is_aws = (string) $s['provider_preset'] === 'aws';
						?>
						<h2><?php esc_html_e( '2. Connection', 'kazcode-universal-storage' ); ?></h2>
						<p>
							<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[credential_mode]" value="keys" <?php checked( (string) $s['credential_mode'], 'keys' ); ?> /> <?php esc_html_e( 'Access key + secret', 'kazcode-universal-storage' ); ?></label><br />
							<?php if ( $is_aws ) : ?>
								<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[credential_mode]" value="iam_role" <?php checked( (string) $s['credential_mode'], 'iam_role' ); ?> /> <?php esc_html_e( 'IAM instance role / default credential chain (EC2, ECS, etc.)', 'kazcode-universal-storage' ); ?></label>
							<?php endif; ?>
						</p>
						<p><label><?php esc_html_e( 'Bucket', 'kazcode-universal-storage' ); ?><br /><input class="regular-text" name="<?php echo esc_attr( $opt ); ?>[bucket]" value="<?php echo esc_attr( (string) $s['bucket'] ); ?>" /></label></p>
						<p><label><?php esc_html_e( 'Region', 'kazcode-universal-storage' ); ?><br /><input class="regular-text" name="<?php echo esc_attr( $opt ); ?>[region]" value="<?php echo esc_attr( (string) $s['region'] ); ?>" /></label></p>
						<p data-s3ms-hide-for-credential-mode="iam_role"><label><?php esc_html_e( 'Access key ID', 'kazcode-universal-storage' ); ?><br /><input class="regular-text" name="<?php echo esc_attr( $opt ); ?>[access_key_id]" value="<?php echo esc_attr( (string) $s['access_key_id'] ); ?>" autocomplete="off" /></label></p>
						<p data-s3ms-hide-for-credential-mode="iam_role"><label><?php esc_html_e( 'Secret access key', 'kazcode-universal-storage' ); ?><br /><input class="regular-text" type="password" name="s3ms_secret_access_key" value="" autocomplete="new-password" /></label></p>
						<?php if ( ! $is_aws ) : ?>
							<p><label><?php esc_html_e( 'Endpoint', 'kazcode-universal-storage' ); ?><br /><input class="regular-text" name="<?php echo esc_attr( $opt ); ?>[endpoint]" value="<?php echo esc_attr( (string) $s['endpoint'] ); ?>" placeholder="<?php echo esc_attr( (string) ( $presets[ $s['provider_preset'] ]['endpoint'] ?? '' ) ); ?>" /></label></p>
							<p><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[force_path_style]" value="1" <?php checked( ! empty( $s['force_path_style'] ) ); ?> /> <?php esc_html_e( 'Force path-style', 'kazcode-universal-storage' ); ?></label></p>
						<?php endif; ?>
					<?php elseif ( 3 === $step ) : ?>
						<h2><?php esc_html_e( '3. Delivery & mode', 'kazcode-universal-storage' ); ?></h2>
						<p><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[enabled]" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?> /> <?php esc_html_e( 'Enable offload for new uploads', 'kazcode-universal-storage' ); ?></label></p>
						<p><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[serve_from_s3]" value="1" <?php checked( ! empty( $s['serve_from_s3'] ) ); ?> /> <?php esc_html_e( 'Serve media from S3 / CDN', 'kazcode-universal-storage' ); ?></label></p>
						<p><label><?php esc_html_e( 'CDN URL (optional)', 'kazcode-universal-storage' ); ?><br /><input class="regular-text" name="<?php echo esc_attr( $opt ); ?>[cdn_url]" value="<?php echo esc_attr( (string) $s['cdn_url'] ); ?>" /></label></p>
						<p class="description"><?php esc_html_e( 'Leave delete-local off until you verify URLs. Run Test connection from Settings after finishing.', 'kazcode-universal-storage' ); ?></p>
					<?php else : ?>
						<h2><?php esc_html_e( '4. What this plugin covers', 'kazcode-universal-storage' ); ?></h2>
						<ul class="s3ms-bullets" style="padding-left:1.2em">
							<li><?php esc_html_e( 'Media Library binaries → S3; WordPress keeps metadata and the native UI.', 'kazcode-universal-storage' ); ?></li>
							<li><?php esc_html_e( 'Theme/plugin assets are not offloaded automatically.', 'kazcode-universal-storage' ); ?></li>
							<li><?php esc_html_e( 'Next: Settings → Test connection, then Tools → Migrate (or background migrate).', 'kazcode-universal-storage' ); ?></li>
						</ul>
						<?php if ( ! Features::is_pro_active() ) : ?>
							<div class="s3ms-card s3ms-card--pro" style="margin-top:16px">
								<div class="s3ms-card__head"><div>
									<h3 class="s3ms-card__title"><?php esc_html_e( 'One storage profile is included free', 'kazcode-universal-storage' ); ?> <span class="kazus-brand-pro-badge"><?php esc_html_e( 'PRO', 'kazcode-universal-storage' ); ?></span></h3>
									<p class="s3ms-card__intro"><?php esc_html_e( 'If you outgrow it later, KAZCODE Universal Storage Pro (a separate add-on, not a trial) adds:', 'kazcode-universal-storage' ); ?></p>
								</div></div>
								<div class="s3ms-card__body" style="padding-top:0">
									<ul class="s3ms-bullets" style="padding-left:1.2em">
										<li><?php esc_html_e( 'Multiple storage profiles, each with independent credentials', 'kazcode-universal-storage' ); ?></li>
										<li><?php esc_html_e( 'Cross-provider migration (e.g. Amazon S3 → Cloudflare R2) with verify-before-switch', 'kazcode-universal-storage' ); ?></li>
										<li><?php esc_html_e( 'Orphan scan and advanced storage health', 'kazcode-universal-storage' ); ?></li>
										<li><?php esc_html_e( 'Multisite network defaults', 'kazcode-universal-storage' ); ?></li>
									</ul>
									<p class="s3ms-side-note" style="padding:0"><button type="button" class="button-link" data-kazus-pro-modal-open><?php esc_html_e( 'See what Pro adds', 'kazcode-universal-storage' ); ?></button></p>
								</div>
							</div>
						<?php endif; ?>
						<input type="hidden" name="finish" value="1" />
						<?php
						// Preserve key settings through final step.
						foreach ( array( 'provider_preset', 'credential_mode', 'bucket', 'region', 'access_key_id', 'endpoint', 'cdn_url' ) as $k ) {
							printf( '<input type="hidden" name="%1$s[%2$s]" value="%3$s" />', esc_attr( $opt ), esc_attr( $k ), esc_attr( (string) ( $s[ $k ] ?? '' ) ) );
						}
						if ( ! empty( $s['enabled'] ) ) {
							printf( '<input type="hidden" name="%s[enabled]" value="1" />', esc_attr( $opt ) );
						}
						if ( ! empty( $s['serve_from_s3'] ) ) {
							printf( '<input type="hidden" name="%s[serve_from_s3]" value="1" />', esc_attr( $opt ) );
						}
						if ( ! empty( $s['force_path_style'] ) ) {
							printf( '<input type="hidden" name="%s[force_path_style]" value="1" />', esc_attr( $opt ) );
						}
						?>
					<?php endif; ?>
				</div>
				<p class="s3ms-card__body" style="padding-top:0">
					<?php if ( $step < 4 ) : ?>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Continue', 'kazcode-universal-storage' ); ?></button>
					<?php else : ?>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Finish setup', 'kazcode-universal-storage' ); ?></button>
					<?php endif; ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminMenu::MENU_SLUG ) ); ?>"><?php esc_html_e( 'Skip to Settings', 'kazcode-universal-storage' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}
}
