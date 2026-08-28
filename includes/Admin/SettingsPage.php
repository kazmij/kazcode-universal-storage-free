<?php
/**
 * Settings admin page (product UI).
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

/**
 * Registers settings + renders the guided Settings screen.
 */
final class SettingsPage {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hooks (menu is registered by AdminMenu).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_kazus_test_connection', array( $this, 'ajax_test_connection' ) );
	}

	/**
	 * Enqueue admin assets on plugin screens.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public function enqueue( string $hook ): void {
		if ( strpos( $hook, 'kazcode-universal-storage' ) === false && $hook !== 'upload.php' ) {
			return;
		}

		$js_ver = KAZUS_VERSION;
		$js     = KAZUS_PLUGIN_DIR . 'assets/admin.js';
		if ( is_readable( $js ) ) {
			$js_ver .= '.' . (string) filemtime( $js );
		}

		// Same filemtime-based busting as the JS enqueue above — without it,
		// browsers cache admin.css at the same ?ver=<plugin-version> URL across
		// every CSS-only release/hotfix until the plugin's own version number
		// changes, silently serving stale styles.
		$css_ver = KAZUS_VERSION;
		$css     = KAZUS_PLUGIN_DIR . 'assets/admin.css';
		if ( is_readable( $css ) ) {
			$css_ver .= '.' . (string) filemtime( $css );
		}

		wp_enqueue_style(
			's3ms-admin',
			KAZUS_PLUGIN_URL . 'assets/admin.css',
			array(),
			$css_ver
		);
		wp_enqueue_script(
			's3ms-admin',
			KAZUS_PLUGIN_URL . 'assets/admin.js',
			array(),
			$js_ver,
			true
		);
		wp_localize_script(
			's3ms-admin',
			's3msAdmin',
			array(
				'restUrl'   => esc_url_raw( rest_url( 'kazcode-storage/v1/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'ajaxNonce' => wp_create_nonce( 's3ms_admin' ),
				'i18n'      => array(
					'testing' => __( 'Testing connection…', 'kazcode-universal-storage' ),
					'success' => __( 'Connection successful.', 'kazcode-universal-storage' ),
					'failed'  => __( 'Connection failed.', 'kazcode-universal-storage' ),
					'running' => __( 'Running…', 'kazcode-universal-storage' ),
					'done'    => __( 'Done.', 'kazcode-universal-storage' ),
					'stop'    => __( 'Stopped.', 'kazcode-universal-storage' ),
				),
			)
		);
	}

	/**
	 * AJAX: Test S3 connection (preferred over REST in wp-admin).
	 *
	 * @return void
	 */
	public function ajax_test_connection(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Forbidden.', 'kazcode-universal-storage' ),
				),
				403
			);
		}
		check_ajax_referer( 's3ms_admin', 'nonce' );

		$result = Plugin::instance()->connection_test()->run();
		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		}
		wp_send_json_error( $result );
	}

	/**
	 * Register option.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			's3ms_settings_group',
			Settings::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Sanitize callback for register_setting.
	 *
	 * @param array<string, mixed>|null $input Input.
	 * @return array<string, mixed>
	 */
	public function sanitize_options( $input ): array {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$current = $this->settings->all();
		$clean   = $this->settings->sanitize( $input, $current );

		// Handle secret separately — empty means keep existing.
		if ( isset( $_POST['s3ms_secret_access_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$secret = (string) wp_unslash( $_POST['s3ms_secret_access_key'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( $secret !== '' ) {
				$this->settings->set_secret_access_key( $secret );
			}
		}

		return $clean;
	}

	/**
	 * Render page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s          = $this->settings->all();
		$has_secret = $this->settings->has_secret_access_key();
		$ready      = $this->settings->is_aws_configured();
		$enabled    = $this->settings->is_enabled();
		$serving    = ! empty( $s['serve_from_s3'] );
		$stats      = Plugin::instance()->migration_service()->stats();
		$opt        = Settings::OPTION_KEY;

		$secret_placeholder = $has_secret
			? __( '••••••••  (saved — leave blank to keep)', 'kazcode-universal-storage' )
			: __( 'Enter secret access key', 'kazcode-universal-storage' );
		?>
		<div class="wrap s3ms-wrap">
			<?php
			AdminLayout::brand_header();
			AdminLayout::header(
				__( 'Settings', 'kazcode-universal-storage' ),
				__( 'Offload Media Library files to Amazon S3 or S3-compatible storage. WordPress keeps the database and media UI; only binaries move to the cloud.', 'kazcode-universal-storage' ),
				'admin-settings',
				array(
					admin_url( 'admin.php?page=' . AdminMenu::STORAGE_SLUG ) => __( 'Storage', 'kazcode-universal-storage' ),
					admin_url( 'admin.php?page=' . AdminMenu::MIGRATION_SLUG ) => __( 'Migration', 'kazcode-universal-storage' ),
				)
			);
			AdminLayout::subnav( AdminMenu::SETTINGS_SLUG );
			?>
			<?php AdminLayout::tour_replay_button( 5 ); ?>

			<?php settings_errors(); ?>

			<section class="s3ms-status-grid" aria-label="<?php esc_attr_e( 'Plugin status', 'kazcode-universal-storage' ); ?>"
				data-s3ms-tour-step="1"
				data-s3ms-tour-title="<?php esc_attr_e( 'Your setup at a glance', 'kazcode-universal-storage' ); ?>"
				data-s3ms-tour-text="<?php esc_attr_e( 'Configuration turns Ready once bucket, region, and credentials are filled in — that alone doesn\'t move anything. Offload and Public URLs are the two switches that actually change behavior; both live in the Operating mode card below.', 'kazcode-universal-storage' ); ?>"
			>
				<div class="s3ms-stat <?php echo $ready ? 'is-ok' : 'is-warn'; ?>">
					<span class="s3ms-stat__label"><?php esc_html_e( 'Configuration', 'kazcode-universal-storage' ); ?></span>
					<strong class="s3ms-stat__value"><?php echo $ready ? esc_html__( 'Ready', 'kazcode-universal-storage' ) : esc_html__( 'Incomplete', 'kazcode-universal-storage' ); ?></strong>
					<span class="s3ms-stat__hint"><?php echo $ready ? esc_html__( 'Bucket, region, and credentials look set.', 'kazcode-universal-storage' ) : esc_html__( 'Fill in connection settings below.', 'kazcode-universal-storage' ); ?></span>
				</div>
				<div class="s3ms-stat <?php echo $enabled ? 'is-ok' : 'is-muted'; ?>">
					<span class="s3ms-stat__label"><?php esc_html_e( 'Offload', 'kazcode-universal-storage' ); ?></span>
					<strong class="s3ms-stat__value"><?php echo $enabled ? esc_html__( 'On', 'kazcode-universal-storage' ) : esc_html__( 'Off', 'kazcode-universal-storage' ); ?></strong>
					<span class="s3ms-stat__hint"><?php esc_html_e( 'New uploads go to S3 when On.', 'kazcode-universal-storage' ); ?></span>
				</div>
				<div class="s3ms-stat <?php echo ( $enabled && $serving ) ? 'is-ok' : 'is-muted'; ?>">
					<span class="s3ms-stat__label"><?php esc_html_e( 'Public URLs', 'kazcode-universal-storage' ); ?></span>
					<strong class="s3ms-stat__value"><?php echo ( $enabled && $serving ) ? esc_html__( 'S3 / CDN', 'kazcode-universal-storage' ) : esc_html__( 'Local WP', 'kazcode-universal-storage' ); ?></strong>
					<span class="s3ms-stat__hint"><?php esc_html_e( 'Where browsers load media from.', 'kazcode-universal-storage' ); ?></span>
				</div>
				<div class="s3ms-stat is-info">
					<span class="s3ms-stat__label"><?php esc_html_e( 'Library', 'kazcode-universal-storage' ); ?></span>
					<strong class="s3ms-stat__value"><?php echo esc_html( (string) (int) $stats['offloaded'] ); ?> <span class="s3ms-stat__unit"><?php esc_html_e( 'offloaded', 'kazcode-universal-storage' ); ?></span></strong>
					<span class="s3ms-stat__hint">
						<?php
						printf(
							/* translators: 1: pending 2: failed */
							esc_html__( '%1$d pending · %2$d failed', 'kazcode-universal-storage' ),
							(int) $stats['pending'],
							(int) $stats['failed']
						);
						?>
					</span>
				</div>
			</section>

			<div class="s3ms-layout">
				<div class="s3ms-layout__main">
					<form method="post" action="options.php" class="s3ms-form">
						<?php settings_fields( 's3ms_settings_group' ); ?>
						<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[_s3ms_full_form]" value="1" />

						<section class="s3ms-card" id="s3ms-section-mode"
							data-s3ms-tour-step="2"
							data-s3ms-tour-title="<?php esc_attr_e( 'Two switches, in order', 'kazcode-universal-storage' ); ?>"
							data-s3ms-tour-text="<?php esc_attr_e( 'Enable offload sends new uploads to your bucket. Serve media from S3 / CDN rewrites URLs so browsers actually load from there. Turn on offload first, run Migrate for existing media, verify it looks right, then turn on serving.', 'kazcode-universal-storage' ); ?>"
						>
							<div class="s3ms-card__head">
								<span class="s3ms-step">1</span>
								<div>
									<h2 class="s3ms-card__title"><?php esc_html_e( 'Operating mode', 'kazcode-universal-storage' ); ?></h2>
									<p class="s3ms-card__intro"><?php esc_html_e( 'Master switches. Turn these on after the connection test succeeds.', 'kazcode-universal-storage' ); ?></p>
								</div>
							</div>
							<div class="s3ms-card__body">
								<?php
								$this->toggle(
									$opt,
									'enabled',
									__( 'Enable offload', 'kazcode-universal-storage' ),
									__( 'When enabled, new Media Library uploads are copied to your bucket after WordPress finishes generating thumbnails. Existing files are not moved until you run Tools → Migrate.', 'kazcode-universal-storage' ),
									! empty( $s['enabled'] )
								);
								$this->toggle(
									$opt,
									'serve_from_s3',
									__( 'Serve media from S3 / CDN', 'kazcode-universal-storage' ),
									__( 'Rewrites attachment URLs on the front end and in the Media Library so browsers load files from your bucket (or CDN), not from the WordPress uploads directory. Safe to leave off while you migrate.', 'kazcode-universal-storage' ),
									! empty( $s['serve_from_s3'] )
								);
								?>
							</div>
						</section>

						<section class="s3ms-card" id="s3ms-section-connection"
							data-s3ms-tour-step="3"
							data-s3ms-tour-title="<?php esc_attr_e( 'Fields adapt to your provider', 'kazcode-universal-storage' ); ?>"
							data-s3ms-tour-text="<?php esc_attr_e( 'Pick a provider preset first — Endpoint, Force path-style, and IAM instance role only appear when they\'re actually relevant (IAM role is AWS-only; Endpoint/Force path-style are for S3-compatible providers). The Secret access key field always shows a placeholder once one is saved — leave it blank to keep the current value.', 'kazcode-universal-storage' ); ?>"
						>
							<div class="s3ms-card__head">
								<span class="s3ms-step">2</span>
								<div>
									<h2 class="s3ms-card__title"><?php esc_html_e( 'S3 connection', 'kazcode-universal-storage' ); ?></h2>
									<p class="s3ms-card__intro"><?php esc_html_e( 'Credentials and bucket location. Prefer an IAM user limited to this one bucket.', 'kazcode-universal-storage' ); ?></p>
								</div>
							</div>
							<div class="s3ms-card__body s3ms-fields">
								<div class="s3ms-field">
									<label class="s3ms-field__label" for="s3ms_provider_preset"><?php esc_html_e( 'Provider preset', 'kazcode-universal-storage' ); ?></label>
									<select class="s3ms-field__input" name="<?php echo esc_attr( $opt ); ?>[provider_preset]" id="s3ms_provider_preset">
										<?php foreach ( ProviderPresets::all() as $slug => $preset ) : ?>
											<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( (string) ( $s['provider_preset'] ?? 'aws' ), $slug ); ?>><?php echo esc_html( $preset['label'] ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="s3ms-field__help"><?php esc_html_e( 'Applies recommended endpoint/path-style defaults in the Setup wizard. You can still edit endpoint fields manually below.', 'kazcode-universal-storage' ); ?></p>
								</div>
								<div class="s3ms-field">
									<span class="s3ms-field__label"><?php esc_html_e( 'Credential mode', 'kazcode-universal-storage' ); ?></span>
									<label class="s3ms-toggle__row"><input type="radio" name="<?php echo esc_attr( $opt ); ?>[credential_mode]" value="keys" <?php checked( (string) ( $s['credential_mode'] ?? 'keys' ), 'keys' ); ?> /> <span><?php esc_html_e( 'Access key + secret (stored encrypted)', 'kazcode-universal-storage' ); ?></span></label>
									<label class="s3ms-toggle__row" data-s3ms-show-only-for-provider="aws"><input type="radio" name="<?php echo esc_attr( $opt ); ?>[credential_mode]" value="iam_role" <?php checked( (string) ( $s['credential_mode'] ?? 'keys' ), 'iam_role' ); ?> /> <span><?php esc_html_e( 'IAM instance role / default AWS credential chain (no secret in WordPress)', 'kazcode-universal-storage' ); ?></span></label>
									<p class="s3ms-field__help" data-s3ms-show-only-for-provider="aws"><?php esc_html_e( 'Use IAM role on EC2/ECS/EKS when the host already has an instance profile. Access key fields are ignored in that mode. Only available for Amazon S3 — other providers don\'t support this credential chain.', 'kazcode-universal-storage' ); ?></p>
								</div>
								<?php
								$this->text_field(
									$opt,
									'bucket',
									__( 'Bucket name', 'kazcode-universal-storage' ),
									__( 'Exact S3 bucket name (for example my-site-uploads). The bucket must already exist; the plugin does not create it.', 'kazcode-universal-storage' ),
									(string) $s['bucket'],
									'my-company-uploads'
								);
								$this->text_field(
									$opt,
									'region',
									__( 'AWS region', 'kazcode-universal-storage' ),
									__( 'Region where the bucket lives (for example us-east-1). Wrong region causes signature or redirect errors.', 'kazcode-universal-storage' ),
									(string) $s['region'],
									'us-east-1'
								);
								$this->text_field(
									$opt,
									'access_key_id',
									__( 'Access key ID', 'kazcode-universal-storage' ),
									__( 'IAM access key for a user that can PutObject, GetObject, DeleteObject, ListBucket, and HeadObject on this bucket.', 'kazcode-universal-storage' ),
									(string) $s['access_key_id'],
									'AKIA…',
									'text',
									'data-s3ms-hide-for-credential-mode',
									'iam_role'
								);
								?>
								<div class="s3ms-field" data-s3ms-hide-for-credential-mode="iam_role">
									<label class="s3ms-field__label" for="s3ms_secret_access_key"><?php esc_html_e( 'Secret access key', 'kazcode-universal-storage' ); ?></label>
									<input class="s3ms-field__input regular-text" type="password" autocomplete="new-password" name="s3ms_secret_access_key" id="s3ms_secret_access_key" value="" placeholder="<?php echo esc_attr( $secret_placeholder ); ?>" />
									<p class="s3ms-field__help"><?php esc_html_e( 'Stored encrypted in the WordPress database (not as plain text). Leave blank when updating other settings to keep the current secret.', 'kazcode-universal-storage' ); ?></p>
								</div>
								<?php
								$this->text_field(
									$opt,
									'object_prefix',
									__( 'Object key prefix (optional)', 'kazcode-universal-storage' ),
									__( 'Folder-like prefix prepended to every object key (for example wordpress/ or production/). Useful when one bucket serves multiple environments. No leading slash.', 'kazcode-universal-storage' ),
									(string) $s['object_prefix'],
									'wordpress/'
								);
								$this->text_field(
									$opt,
									'endpoint',
									__( 'Custom endpoint', 'kazcode-universal-storage' ),
									__( 'Full API endpoint URL for this provider (MinIO, DigitalOcean Spaces, Cloudflare R2, etc.).', 'kazcode-universal-storage' ),
									(string) $s['endpoint'],
									'https://s3.example.com',
									'url',
									'data-s3ms-hide-for-provider',
									'aws'
								);
								$this->toggle(
									$opt,
									'force_path_style',
									__( 'Force path-style addressing', 'kazcode-universal-storage' ),
									__( 'Required by some S3-compatible endpoints.', 'kazcode-universal-storage' ),
									! empty( $s['force_path_style'] ),
									false,
									'data-s3ms-hide-for-provider',
									'aws'
								);
								?>
							</div>
						</section>

						<section class="s3ms-card" id="s3ms-section-delivery"
							data-s3ms-tour-step="4"
							data-s3ms-tour-title="<?php esc_attr_e( 'Priority order for public URLs', 'kazcode-universal-storage' ); ?>"
							data-s3ms-tour-text="<?php esc_attr_e( 'CDN URL wins if set, then Public base URL, then a default S3 URL built from bucket + region. Most sites only need one of these three filled in.', 'kazcode-universal-storage' ); ?>"
						>
							<div class="s3ms-card__head">
								<span class="s3ms-step">3</span>
								<div>
									<h2 class="s3ms-card__title"><?php esc_html_e( 'Public delivery', 'kazcode-universal-storage' ); ?></h2>
									<p class="s3ms-card__intro"><?php esc_html_e( 'How browsers build public URLs. Priority: CDN URL → Public base URL → default S3 URL.', 'kazcode-universal-storage' ); ?></p>
								</div>
							</div>
							<div class="s3ms-card__body s3ms-fields">
								<?php
								$this->text_field(
									$opt,
									'public_base_url',
									__( 'S3 public / base URL', 'kazcode-universal-storage' ),
									__( 'Optional override for virtual-hosted or custom domain URLs without a separate CDN (example: https://my-bucket.s3.us-east-1.amazonaws.com). No trailing slash.', 'kazcode-universal-storage' ),
									(string) $s['public_base_url'],
									'https://my-bucket.s3.amazonaws.com',
									'url'
								);
								$this->text_field(
									$opt,
									'cdn_url',
									__( 'CDN or custom domain URL', 'kazcode-universal-storage' ),
									__( 'Highest priority when set (example: https://cdn.example.com). Used when Serve media from S3 / CDN is on.', 'kazcode-universal-storage' ),
									(string) $s['cdn_url'],
									'https://cdn.example.com',
									'url'
								);
								$this->toggle(
									$opt,
									'cdn_includes_prefix',
									__( 'Append object prefix to CDN / public paths', 'kazcode-universal-storage' ),
									__( 'Enable when the CDN origin is the bucket root and public URLs must include your object prefix. Disable if the CDN is already pointed at bucket/prefix/.', 'kazcode-universal-storage' ),
									! empty( $s['cdn_includes_prefix'] )
								);
								$this->text_field(
									$opt,
									'cache_control',
									__( 'Cache-Control header', 'kazcode-universal-storage' ),
									__( 'Sent with uploaded objects. Default is suitable for immutable media filenames.', 'kazcode-universal-storage' ),
									(string) $s['cache_control'],
									'public, max-age=31536000'
								);
								?>
							</div>
						</section>

						<section class="s3ms-card" id="s3ms-section-local">
							<div class="s3ms-card__head">
								<span class="s3ms-step">4</span>
								<div>
									<h2 class="s3ms-card__title"><?php esc_html_e( 'Local disk &amp; deletes', 'kazcode-universal-storage' ); ?></h2>
									<p class="s3ms-card__intro"><?php esc_html_e( 'Control whether files remain on the server after offload, and what happens when media is deleted in WordPress.', 'kazcode-universal-storage' ); ?></p>
								</div>
							</div>
							<div class="s3ms-card__body">
								<?php
								$policy = \Kazcode\WpStorage\Domain\LocalStoragePolicy::normalize(
									(string) ( $s['local_storage_policy'] ?? \Kazcode\WpStorage\Domain\LocalStoragePolicy::KEEP_ALL )
								);
								?>
								<div class="s3ms-field">
									<label class="s3ms-field__label" for="s3ms_local_storage_policy"><?php esc_html_e( 'Local file policy after offload', 'kazcode-universal-storage' ); ?></label>
									<select class="s3ms-field__input" name="<?php echo esc_attr( $opt ); ?>[local_storage_policy]" id="s3ms_local_storage_policy">
										<option value="keep_all" <?php selected( $policy, 'keep_all' ); ?>><?php esc_html_e( 'Keep all local files', 'kazcode-universal-storage' ); ?></option>
										<option value="keep_originals" <?php selected( $policy, 'keep_originals' ); ?>><?php esc_html_e( 'Keep originals only (delete size variants after verify)', 'kazcode-universal-storage' ); ?></option>
										<option value="remote_only" <?php selected( $policy, 'remote_only' ); ?>><?php esc_html_e( 'Remote only (delete all local files after verify)', 'kazcode-universal-storage' ); ?></option>
									</select>
									<p class="s3ms-field__help"><?php esc_html_e( 'Local files are always verified on S3 before deletion. Recommended: Keep all while validating migration, then switch to Remote only to save disk.', 'kazcode-universal-storage' ); ?></p>
								</div>
								<?php
								$this->toggle(
									$opt,
									'delete_remote_on_delete',
									__( 'Delete remote objects when attachment is deleted', 'kazcode-universal-storage' ),
									__( 'Removes the original and known size variants from S3 when you trash/delete media in WordPress. Never performs a recursive prefix wipe.', 'kazcode-universal-storage' ),
									! empty( $s['delete_remote_on_delete'] )
								);
								?>
							</div>
						</section>

						<section class="s3ms-card" id="s3ms-section-advanced">
							<div class="s3ms-card__head">
								<span class="s3ms-step">5</span>
								<div>
									<h2 class="s3ms-card__title"><?php esc_html_e( 'Private media, background &amp; multisite', 'kazcode-universal-storage' ); ?></h2>
									<p class="s3ms-card__intro"><?php esc_html_e( 'Signed delivery and cron migration. Network inheritance requires Pro.', 'kazcode-universal-storage' ); ?></p>
								</div>
							</div>
							<div class="s3ms-card__body">
								<?php
								$this->toggle(
									$opt,
									'private_media',
									__( 'Private media (signed URLs)', 'kazcode-universal-storage' ),
									__( 'Serve offloaded files via time-limited signed GET URLs instead of public CDN/S3 URLs. Requires working credentials. CDN URL is skipped in this mode.', 'kazcode-universal-storage' ),
									! empty( $s['private_media'] ),
									true
								);
								?>
								<div class="s3ms-field">
									<label class="s3ms-field__label" for="s3ms_signed_url_ttl"><?php esc_html_e( 'Signed URL lifetime (seconds)', 'kazcode-universal-storage' ); ?></label>
									<input class="s3ms-field__input small-text" type="number" min="60" max="86400" name="<?php echo esc_attr( $opt ); ?>[signed_url_ttl]" id="s3ms_signed_url_ttl" value="<?php echo esc_attr( (string) (int) ( $s['signed_url_ttl'] ?? 3600 ) ); ?>" />
									<p class="s3ms-field__help"><?php esc_html_e( '60–86400. Shorter is safer for private downloads; longer reduces URL churn in HTML caches.', 'kazcode-universal-storage' ); ?></p>
								</div>
								<div class="s3ms-field">
									<label class="s3ms-field__label" for="s3ms_background_batch_size"><?php esc_html_e( 'Background batch size', 'kazcode-universal-storage' ); ?></label>
									<input class="s3ms-field__input small-text" type="number" min="1" max="50" name="<?php echo esc_attr( $opt ); ?>[background_batch_size]" id="s3ms_background_batch_size" value="<?php echo esc_attr( (string) (int) ( $s['background_batch_size'] ?? 20 ) ); ?>" />
									<p class="s3ms-field__help"><?php esc_html_e( 'Attachments processed per WP-Cron tick when background migrate is running (Tools).', 'kazcode-universal-storage' ); ?></p>
								</div>
								<?php if ( is_multisite() ) : ?>
									<?php
									$this->toggle(
										$opt,
										'inherit_network_settings',
										__( 'Inherit network settings', 'kazcode-universal-storage' ),
										__( 'Use Network Admin → Universal Storage defaults as the base for this site (site values still override when saved here).', 'kazcode-universal-storage' ),
										! empty( $s['inherit_network_settings'] )
									);
									?>
								<?php endif; ?>
								<?php
								$this->toggle(
									$opt,
									'compat_gutenberg',
									__( 'Compatibility: Gutenberg / core blocks', 'kazcode-universal-storage' ),
									__( 'Documented target. Featured images and core image blocks use standard attachment URL filters.', 'kazcode-universal-storage' ),
									! empty( $s['compat_gutenberg'] )
								);
								$this->toggle(
									$opt,
									'compat_acf',
									__( 'Compatibility: ACF image fields', 'kazcode-universal-storage' ),
									__( 'ACF image fields that store attachment IDs go through wp_get_attachment_url / image_src filters.', 'kazcode-universal-storage' ),
									! empty( $s['compat_acf'] )
								);
								$this->toggle(
									$opt,
									'compat_elementor',
									__( 'Compatibility: Elementor', 'kazcode-universal-storage' ),
									__( 'Elementor usually stores attachment IDs; URL filters apply. Hard-coded theme asset paths are never rewritten.', 'kazcode-universal-storage' ),
									! empty( $s['compat_elementor'] )
								);
								?>
							</div>
						</section>

						<p class="s3ms-form__submit">
							<?php submit_button( __( 'Save settings', 'kazcode-universal-storage' ), 'primary large', 'submit', false ); ?>
						</p>
					</form>
				</div>

				<aside class="s3ms-layout__side">
					<section class="s3ms-card s3ms-card--accent" id="s3ms-test-panel">
						<div class="s3ms-card__head">
							<span class="s3ms-step s3ms-step--accent">✓</span>
							<div>
								<h2 class="s3ms-card__title"><?php esc_html_e( 'Test connection', 'kazcode-universal-storage' ); ?></h2>
								<p class="s3ms-card__intro"><?php esc_html_e( 'Writes a tiny object, reads it back, then deletes it. Does not change your media library.', 'kazcode-universal-storage' ); ?></p>
							</div>
						</div>
						<div class="s3ms-card__body">
							<button type="button" class="button button-secondary button-hero" id="s3ms-test-connection"><?php esc_html_e( 'Run test', 'kazcode-universal-storage' ); ?></button>
							<p id="s3ms-test-result" class="s3ms-test-result" aria-live="polite"></p>
							<ul id="s3ms-test-steps" class="s3ms-test-steps"></ul>
						</div>
					</section>

					<section class="s3ms-card">
						<div class="s3ms-card__head">
							<div>
								<h2 class="s3ms-card__title"><?php esc_html_e( 'Quick start', 'kazcode-universal-storage' ); ?></h2>
							</div>
						</div>
						<ol class="s3ms-steps-list">
							<li><?php esc_html_e( 'Create a bucket and IAM user with least-privilege S3 access.', 'kazcode-universal-storage' ); ?></li>
							<li><?php esc_html_e( 'Fill in connection settings and Save.', 'kazcode-universal-storage' ); ?></li>
							<li><?php esc_html_e( 'Run Test connection until it succeeds.', 'kazcode-universal-storage' ); ?></li>
							<li><?php esc_html_e( 'Enable offload (and later Serve from S3).', 'kazcode-universal-storage' ); ?></li>
							<li><?php esc_html_e( 'Use Tools to migrate existing media, then optionally delete local copies.', 'kazcode-universal-storage' ); ?></li>
						</ol>
						<p class="s3ms-side-note"><?php esc_html_e( 'CLI:', 'kazcode-universal-storage' ); ?> <code>wp universal-storage status</code></p>
						<p class="s3ms-side-note" style="padding-top:0">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminMenu::WIZARD_SLUG ) ); ?>"><?php esc_html_e( '↻ Restart the setup wizard', 'kazcode-universal-storage' ); ?></a>
						</p>
					</section>

					<section class="s3ms-card">
						<div class="s3ms-card__head">
							<div>
								<h2 class="s3ms-card__title"><?php esc_html_e( 'Product scope', 'kazcode-universal-storage' ); ?></h2>
							</div>
						</div>
						<ul class="s3ms-bullets">
							<li><?php esc_html_e( 'Does not move posts, users, or the WordPress database to S3.', 'kazcode-universal-storage' ); ?></li>
							<li><?php esc_html_e( 'Does not replace the Media Library UI — you keep the native experience.', 'kazcode-universal-storage' ); ?></li>
							<li><?php esc_html_e( 'Does not create the AWS bucket for you.', 'kazcode-universal-storage' ); ?></li>
						</ul>
					</section>

					<?php if ( ! Features::is_pro_active() ) : ?>
					<section class="s3ms-card" aria-label="<?php esc_attr_e( 'Go Pro', 'kazcode-universal-storage' ); ?>">
						<div class="s3ms-card__head">
							<div>
								<h2 class="s3ms-card__title"><?php esc_html_e( 'Go further with Pro', 'kazcode-universal-storage' ); ?></h2>
							</div>
						</div>
						<ul class="s3ms-bullets">
							<li><?php esc_html_e( 'Multiple storage profiles and cross-provider migration', 'kazcode-universal-storage' ); ?></li>
							<li><?php esc_html_e( 'Orphan scan and advanced health checks', 'kazcode-universal-storage' ); ?></li>
							<li><?php esc_html_e( 'Background migration, audit log, and signed URLs for private media', 'kazcode-universal-storage' ); ?></li>
							<li><?php esc_html_e( 'Multisite network defaults', 'kazcode-universal-storage' ); ?></li>
						</ul>
						<p><button type="button" class="button button-primary" data-kazus-pro-modal-open><?php esc_html_e( 'See Pro features', 'kazcode-universal-storage' ); ?></button></p>
					</section>
					<?php endif; ?>

					<section class="s3ms-card" aria-label="<?php esc_attr_e( 'About this plugin', 'kazcode-universal-storage' ); ?>">
						<div class="s3ms-card__head">
							<div>
								<h2 class="s3ms-card__title"><?php esc_html_e( 'About', 'kazcode-universal-storage' ); ?></h2>
							</div>
						</div>
						<p class="s3ms-card__intro">
							<?php esc_html_e( 'KAZCODE Universal Storage helps you offload, migrate, restore and verify WordPress media using cloud and object storage while keeping WordPress as the source of truth.', 'kazcode-universal-storage' ); ?>
						</p>
						<p class="s3ms-side-note">
							<?php
							printf(
								/* translators: %s: plugin version */
								esc_html__( 'Version: %s', 'kazcode-universal-storage' ),
								esc_html( defined( 'KAZUS_VERSION' ) ? KAZUS_VERSION : '' )
							);
							?>
							<br />
							<?php esc_html_e( 'Built by KAZCODE', 'kazcode-universal-storage' ); ?>
						</p>
						<ul class="s3ms-bullets">
							<li><a href="https://kazcode.net/universal-storage/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Website', 'kazcode-universal-storage' ); ?></a></li>
							<li><a href="https://kazcode.net/universal-storage/docs/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Documentation', 'kazcode-universal-storage' ); ?></a></li>
							<li><a href="https://kazcode.net/universal-storage/support/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Support', 'kazcode-universal-storage' ); ?></a></li>
							<li><a href="https://kazcode.net/universal-storage/changelog/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Release notes', 'kazcode-universal-storage' ); ?></a></li>
						</ul>
					</section>
				</aside>
			</div>
			<?php AdminLayout::footer(); ?>
		</div>
		<?php
	}

	/**
	 * @param string $opt Option array name.
	 * @param string $key Field key.
	 * @param string $label Label.
	 * @param string $help Help text.
	 * @param bool   $checked Checked.
	 * @param bool   $caution Caution badge.
	 * @param string $data_attr Extra data-attribute name on the wrapper div (e.g. for JS-driven show/hide), no leading space.
	 * @param string $data_value Value for $data_attr.
	 * @return void
	 */
	private function toggle( string $opt, string $key, string $label, string $help, bool $checked, bool $caution = false, string $data_attr = '', string $data_value = '' ): void {
		$id = 's3ms_' . $key;
		?>
		<div class="s3ms-toggle<?php echo $caution ? ' s3ms-toggle--caution' : ''; ?>"<?php echo $data_attr !== '' ? ' ' . esc_attr( $data_attr ) . '="' . esc_attr( $data_value ) . '"' : ''; ?>>
			<label class="s3ms-toggle__row" for="<?php echo esc_attr( $id ); ?>">
				<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[<?php echo esc_attr( $key ); ?>]" id="<?php echo esc_attr( $id ); ?>" value="1" <?php checked( $checked ); ?> />
				<span class="s3ms-toggle__text">
					<span class="s3ms-toggle__label"><?php echo esc_html( $label ); ?></span>
					<?php if ( $caution ) : ?>
						<span class="s3ms-badge"><?php esc_html_e( 'Advanced', 'kazcode-universal-storage' ); ?></span>
					<?php endif; ?>
				</span>
			</label>
			<p class="s3ms-toggle__help"><?php echo esc_html( $help ); ?></p>
		</div>
		<?php
	}

	/**
	 * @param string $opt Option array name.
	 * @param string $key Field key.
	 * @param string $label Label.
	 * @param string $help Help text.
	 * @param string $value Value.
	 * @param string $placeholder Placeholder.
	 * @param string $type Input type.
	 * @param string $data_attr Extra data-attribute name on the wrapper div (e.g. for JS-driven show/hide), no leading space.
	 * @param string $data_value Value for $data_attr.
	 * @return void
	 */
	private function text_field( string $opt, string $key, string $label, string $help, string $value, string $placeholder = '', string $type = 'text', string $data_attr = '', string $data_value = '' ): void {
		$id = 's3ms_' . $key;
		?>
		<div class="s3ms-field"<?php echo $data_attr !== '' ? ' ' . esc_attr( $data_attr ) . '="' . esc_attr( $data_value ) . '"' : ''; ?>>
			<label class="s3ms-field__label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<input class="s3ms-field__input regular-text" type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $opt ); ?>[<?php echo esc_attr( $key ); ?>]" id="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" autocomplete="off" />
			<p class="s3ms-field__help"><?php echo esc_html( $help ); ?></p>
		</div>
		<?php
	}
}
