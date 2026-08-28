<?php
/**
 * Storage profiles, delivery, and connection test (v2 Phase 11).
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
use Kazcode\WpStorage\Services\StorageProfileAdminService;

/**
 * Profiles list, CRUD editor, delivery preview, credentials test.
 */
final class StoragePage {

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings  = Plugin::instance()->settings();
		$s         = $settings->all();
		$profiles  = ( new StorageProfileAdminService( $settings ) )->list_summaries();
		$resolver  = Plugin::instance()->url_resolver();
		$sample    = '2026/08/sample.jpg';
		$preview   = $resolver->url_for_relative( $sample );
		$cdn       = untrailingslashit( (string) ( $s['cdn_url'] ?? '' ) );
		$public    = untrailingslashit( (string) ( $s['public_base_url'] ?? '' ) );
		$mode      = $cdn !== '' ? 'cdn' : ( $public !== '' ? 'custom' : 'storage' );
		$opt       = Settings::OPTION_KEY;
		$can_multi = Features::enabled( 'multiple_profiles' );
		$presets   = ProviderPresets::all();
		?>
		<div class="wrap s3ms-wrap">
			<?php
			AdminLayout::brand_header();
			AdminLayout::header(
				__( 'Storage', 'kazcode-universal-storage' ),
				__( 'Storage profiles, public delivery URLs, and live connection test.', 'kazcode-universal-storage' ),
				'admin-site-alt3',
				array(
					admin_url( 'admin.php?page=' . AdminMenu::SETTINGS_SLUG ) => __( 'Full settings', 'kazcode-universal-storage' ),
				)
			);
			AdminLayout::subnav( AdminMenu::STORAGE_SLUG );
			?>
			<?php AdminLayout::tour_replay_button( 4 ); ?>

			<section class="s3ms-card"
				data-s3ms-tour-step="1"
				data-s3ms-tour-title="<?php esc_attr_e( 'Always test before you rely on it', 'kazcode-universal-storage' ); ?>"
				data-s3ms-tour-text="<?php esc_attr_e( 'Run test writes a tiny object, reads it back, and deletes it — it never touches your real media. Do this after any credential or bucket change, and again after switching providers.', 'kazcode-universal-storage' ); ?>"
			>
				<div class="s3ms-card__head"><div>
					<h2 class="s3ms-card__title"><?php esc_html_e( 'Connection test', 'kazcode-universal-storage' ); ?></h2>
					<p class="s3ms-card__intro"><?php esc_html_e( 'Verify bucket access with current credentials before migrating or changing delivery.', 'kazcode-universal-storage' ); ?></p>
				</div></div>
				<div class="s3ms-card__body">
					<p>
						<button type="button" class="button button-secondary" id="s3ms-test-connection"><?php esc_html_e( 'Run test', 'kazcode-universal-storage' ); ?></button>
						<span id="s3ms-test-result" class="s3ms-test-result"></span>
					</p>
					<ul id="s3ms-test-steps" class="s3ms-steps-list"></ul>
				</div>
			</section>

			<section class="s3ms-card" id="s3ms-profiles-panel"
				data-s3ms-tour-step="2"
				data-s3ms-tour-title="<?php esc_attr_e( 'One profile = one storage destination', 'kazcode-universal-storage' ); ?>"
				data-s3ms-tour-text="<?php esc_attr_e( 'A profile bundles bucket, region, endpoint, and delivery URL under a name. Free includes one profile (edit it in place below); Pro unlocks adding more and migrating between them. Bucket/region/prefix lock automatically once a profile has stored objects, to prevent accidental data-location changes.', 'kazcode-universal-storage' ); ?>"
			>
				<div class="s3ms-card__head">
					<div>
						<h2 class="s3ms-card__title"><?php esc_html_e( 'Storage profiles', 'kazcode-universal-storage' ); ?></h2>
						<p class="s3ms-card__intro"><?php esc_html_e( 'Named remote targets for uploads and object inventory rows. Location fields lock after objects are stored.', 'kazcode-universal-storage' ); ?></p>
					</div>
					<?php if ( $can_multi ) : ?>
						<button type="button" class="button button-primary" id="s3ms-profile-add"><?php esc_html_e( 'Add profile', 'kazcode-universal-storage' ); ?></button>
					<?php endif; ?>
				</div>
				<div class="s3ms-card__body">
					<?php if ( $profiles === array() ) : ?>
						<p><?php esc_html_e( 'No profiles yet. Legacy settings are migrated on plugin activation.', 'kazcode-universal-storage' ); ?></p>
					<?php else : ?>
						<table class="widefat striped s3ms-table" id="s3ms-profiles-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Name', 'kazcode-universal-storage' ); ?></th>
									<th><?php esc_html_e( 'Bucket', 'kazcode-universal-storage' ); ?></th>
									<th><?php esc_html_e( 'Region', 'kazcode-universal-storage' ); ?></th>
									<th><?php esc_html_e( 'Objects', 'kazcode-universal-storage' ); ?></th>
									<th><?php esc_html_e( 'Delivery', 'kazcode-universal-storage' ); ?></th>
									<th><?php esc_html_e( 'Default', 'kazcode-universal-storage' ); ?></th>
									<th><?php esc_html_e( 'Actions', 'kazcode-universal-storage' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $profiles as $profile ) : ?>
									<tr data-profile-id="<?php echo esc_attr( (string) ( $profile['id'] ?? 0 ) ); ?>">
										<td>
											<strong><?php echo esc_html( (string) ( $profile['name'] ?? '' ) ); ?></strong>
											<?php if ( ! empty( $profile['location_locked'] ) ) : ?>
												<span class="s3ms-badge s3ms-badge--muted"><?php esc_html_e( 'locked', 'kazcode-universal-storage' ); ?></span>
											<?php endif; ?>
										</td>
										<td><code><?php echo esc_html( (string) ( $profile['bucket'] ?? '' ) ); ?></code></td>
										<td><?php echo esc_html( (string) ( $profile['region'] ?? '' ) ); ?></td>
										<td><?php echo esc_html( (string) (int) ( $profile['object_count'] ?? 0 ) ); ?></td>
										<td><?php echo esc_html( (string) ( $profile['delivery_type'] ?? '' ) ); ?></td>
										<td><?php echo ! empty( $profile['is_default_upload_target'] ) ? esc_html__( 'Yes', 'kazcode-universal-storage' ) : '—'; ?></td>
										<td class="s3ms-table-actions">
											<button type="button" class="button button-small s3ms-profile-edit" data-profile-id="<?php echo esc_attr( (string) ( $profile['id'] ?? 0 ) ); ?>"><?php esc_html_e( 'Edit', 'kazcode-universal-storage' ); ?></button>
											<?php if ( empty( $profile['is_default_upload_target'] ) ) : ?>
												<button type="button" class="button button-small s3ms-profile-default" data-profile-id="<?php echo esc_attr( (string) ( $profile['id'] ?? 0 ) ); ?>"><?php esc_html_e( 'Set default', 'kazcode-universal-storage' ); ?></button>
											<?php endif; ?>
											<?php if ( ! empty( $profile['can_delete'] ) ) : ?>
												<button type="button" class="button button-small s3ms-profile-delete" data-profile-id="<?php echo esc_attr( (string) ( $profile['id'] ?? 0 ) ); ?>"><?php esc_html_e( 'Delete', 'kazcode-universal-storage' ); ?></button>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>

					<?php if ( ! $can_multi ) : ?>
						<div class="notice notice-info inline s3ms-notice" style="margin-top:12px">
							<p><?php esc_html_e( 'Multiple storage profiles require KAZCODE Universal Storage Pro. You can still edit the default profile below.', 'kazcode-universal-storage' ); ?></p>
						</div>
					<?php elseif ( count( $profiles ) > 1 && Features::enabled( 'storage_profile_migration' ) ) : ?>
						<p style="margin-top:12px">
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminMenu::STORAGE_WIZARD_SLUG ) ); ?>"><?php esc_html_e( 'Safe storage change wizard →', 'kazcode-universal-storage' ); ?></a>
						</p>
					<?php endif; ?>
				</div>
			</section>

			<section class="s3ms-card s3ms-profile-editor" id="s3ms-profile-editor" hidden>
				<div class="s3ms-card__head"><div>
					<h2 class="s3ms-card__title" id="s3ms-profile-editor-title"><?php esc_html_e( 'Edit storage profile', 'kazcode-universal-storage' ); ?></h2>
					<p class="s3ms-card__intro" id="s3ms-profile-editor-intro"><?php esc_html_e( 'Delivery settings can always be changed. Bucket, region, and prefix lock once objects exist.', 'kazcode-universal-storage' ); ?></p>
				</div></div>
				<div class="s3ms-card__body">
					<form id="s3ms-profile-form">
						<input type="hidden" id="s3ms-profile-id" value="" />
						<div class="s3ms-fields">
							<div class="s3ms-field">
								<label class="s3ms-field__label" for="s3ms-profile-name"><?php esc_html_e( 'Profile name', 'kazcode-universal-storage' ); ?></label>
								<input class="s3ms-field__input regular-text" type="text" id="s3ms-profile-name" required />
							</div>
							<div class="s3ms-field s3ms-profile-location-field">
								<label class="s3ms-field__label" for="s3ms-profile-provider"><?php esc_html_e( 'Provider preset', 'kazcode-universal-storage' ); ?></label>
								<select class="s3ms-field__input" id="s3ms-profile-provider">
									<?php foreach ( $presets as $slug => $preset ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $preset['label'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="s3ms-field s3ms-profile-location-field">
								<label class="s3ms-field__label" for="s3ms-profile-bucket"><?php esc_html_e( 'Bucket', 'kazcode-universal-storage' ); ?></label>
								<input class="s3ms-field__input regular-text" type="text" id="s3ms-profile-bucket" required />
							</div>
							<div class="s3ms-field s3ms-profile-location-field">
								<label class="s3ms-field__label" for="s3ms-profile-region"><?php esc_html_e( 'Region', 'kazcode-universal-storage' ); ?></label>
								<input class="s3ms-field__input regular-text" type="text" id="s3ms-profile-region" />
							</div>
							<div class="s3ms-field s3ms-profile-location-field">
								<label class="s3ms-field__label" for="s3ms-profile-endpoint"><?php esc_html_e( 'Endpoint URL', 'kazcode-universal-storage' ); ?></label>
								<input class="s3ms-field__input large-text" type="url" id="s3ms-profile-endpoint" placeholder="https://..." />
							</div>
							<div class="s3ms-field s3ms-profile-location-field">
								<label class="s3ms-field__label" for="s3ms-profile-prefix"><?php esc_html_e( 'Object prefix', 'kazcode-universal-storage' ); ?></label>
								<input class="s3ms-field__input regular-text" type="text" id="s3ms-profile-prefix" placeholder="uploads/" />
							</div>
							<div class="s3ms-field s3ms-profile-location-field">
								<label>
									<input type="checkbox" id="s3ms-profile-path-style" />
									<?php esc_html_e( 'Force path-style endpoint', 'kazcode-universal-storage' ); ?>
								</label>
							</div>
							<div class="s3ms-field">
								<span class="s3ms-field__label"><?php esc_html_e( 'Delivery mode', 'kazcode-universal-storage' ); ?></span>
								<label><input type="radio" name="s3ms_profile_delivery" value="storage" checked /> <?php esc_html_e( 'Default storage URL', 'kazcode-universal-storage' ); ?></label><br />
								<label><input type="radio" name="s3ms_profile_delivery" value="cdn" /> <?php esc_html_e( 'CDN / custom base URL', 'kazcode-universal-storage' ); ?></label>
							</div>
							<div class="s3ms-field" id="s3ms-profile-delivery-url-wrap">
								<label class="s3ms-field__label" for="s3ms-profile-delivery-url"><?php esc_html_e( 'Delivery base URL', 'kazcode-universal-storage' ); ?></label>
								<input class="s3ms-field__input large-text" type="url" id="s3ms-profile-delivery-url" placeholder="https://cdn.example.com" />
							</div>
							<div class="s3ms-field">
								<label>
									<input type="checkbox" id="s3ms-profile-cdn-prefix" />
									<?php esc_html_e( 'Append object prefix to delivery URLs', 'kazcode-universal-storage' ); ?>
								</label>
							</div>
						</div>
						<p class="description" id="s3ms-profile-credentials-note"><?php esc_html_e( 'Profiles use site credentials from Settings until per-profile secrets are configured.', 'kazcode-universal-storage' ); ?></p>
						<p class="description s3ms-profile-location-locked-note" id="s3ms-profile-location-locked" hidden><?php esc_html_e( 'Location fields are locked because this profile already stores objects or was frozen after migration.', 'kazcode-universal-storage' ); ?></p>
						<p>
							<button type="submit" class="button button-primary" id="s3ms-profile-save"><?php esc_html_e( 'Save profile', 'kazcode-universal-storage' ); ?></button>
							<button type="button" class="button" id="s3ms-profile-cancel"><?php esc_html_e( 'Cancel', 'kazcode-universal-storage' ); ?></button>
							<span id="s3ms-profile-form-result" class="s3ms-test-result"></span>
						</p>
					</form>
				</div>
			</section>

			<script type="application/json" id="s3ms-profiles-json"><?php echo wp_json_encode( $profiles ); ?></script>

			<section class="s3ms-card" id="s3ms-section-delivery"
				data-s3ms-tour-step="3"
				data-s3ms-tour-title="<?php esc_attr_e( 'How public URLs get built', 'kazcode-universal-storage' ); ?>"
				data-s3ms-tour-text="<?php esc_attr_e( 'Priority is CDN URL, then public/base URL, then the default S3 URL. This legacy section is a fallback for attachments without their own profile-scoped delivery setting — prefer editing delivery on the profile itself above. Use the preview at the bottom to sanity-check the result before saving.', 'kazcode-universal-storage' ); ?>"
			>
				<div class="s3ms-card__head"><div>
					<h2 class="s3ms-card__title"><?php esc_html_e( 'Public delivery (legacy)', 'kazcode-universal-storage' ); ?></h2>
					<p class="s3ms-card__intro"><?php esc_html_e( 'Global fallback for attachments without profile-scoped delivery. Prefer editing delivery on each profile above.', 'kazcode-universal-storage' ); ?></p>
				</div></div>
				<div class="s3ms-card__body">
					<form method="post" action="options.php">
						<?php settings_fields( 's3ms_settings_group' ); ?>
						<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[bucket]" value="<?php echo esc_attr( (string) ( $s['bucket'] ?? '' ) ); ?>" />
						<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[region]" value="<?php echo esc_attr( (string) ( $s['region'] ?? '' ) ); ?>" />

						<div class="s3ms-field">
							<span class="s3ms-field__label"><?php esc_html_e( 'Delivery mode', 'kazcode-universal-storage' ); ?></span>
							<label><input type="radio" name="s3ms_delivery_mode_ui" value="storage" <?php checked( $mode, 'storage' ); ?> disabled /> <?php esc_html_e( 'Default S3 URL', 'kazcode-universal-storage' ); ?></label><br />
							<label><input type="radio" name="s3ms_delivery_mode_ui" value="custom" <?php checked( $mode, 'custom' ); ?> /> <?php esc_html_e( 'Custom S3 / public base URL', 'kazcode-universal-storage' ); ?></label><br />
							<label><input type="radio" name="s3ms_delivery_mode_ui" value="cdn" <?php checked( $mode, 'cdn' ); ?> /> <?php esc_html_e( 'CDN or custom domain', 'kazcode-universal-storage' ); ?></label>
						</div>

						<div class="s3ms-fields">
							<div class="s3ms-field">
								<label class="s3ms-field__label" for="s3ms_public_base_url"><?php esc_html_e( 'S3 public / base URL', 'kazcode-universal-storage' ); ?></label>
								<input class="s3ms-field__input large-text" type="url" id="s3ms_public_base_url" name="<?php echo esc_attr( $opt ); ?>[public_base_url]" value="<?php echo esc_attr( (string) ( $s['public_base_url'] ?? '' ) ); ?>" placeholder="https://my-bucket.s3.amazonaws.com" />
							</div>
							<div class="s3ms-field">
								<label class="s3ms-field__label" for="s3ms_cdn_url"><?php esc_html_e( 'CDN or custom domain URL', 'kazcode-universal-storage' ); ?></label>
								<input class="s3ms-field__input large-text" type="url" id="s3ms_cdn_url" name="<?php echo esc_attr( $opt ); ?>[cdn_url]" value="<?php echo esc_attr( (string) ( $s['cdn_url'] ?? '' ) ); ?>" placeholder="https://cdn.example.com" />
							</div>
							<div class="s3ms-field">
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[cdn_includes_prefix]" value="1" <?php checked( ! empty( $s['cdn_includes_prefix'] ) ); ?> />
									<?php esc_html_e( 'Append object prefix to CDN / public paths', 'kazcode-universal-storage' ); ?>
								</label>
							</div>
						</div>

						<p class="description">
							<?php esc_html_e( 'Preview (sample path):', 'kazcode-universal-storage' ); ?>
							<code id="s3ms-delivery-preview"><?php echo esc_html( $preview !== '' ? $preview : __( 'Configure bucket first', 'kazcode-universal-storage' ) ); ?></code>
						</p>

						<?php submit_button( __( 'Save delivery settings', 'kazcode-universal-storage' ) ); ?>
					</form>
				</div>
			</section>
			<?php AdminLayout::footer(); ?>
		</div>
		<?php
	}
}
