<?php
/**
 * Tools & migration admin page.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Admin;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Plugin;
use Kazcode\WpStorage\Services\MigrationService;
use Kazcode\WpStorage\Services\StorageProfileAdminService;

/**
 * Batch migrate / verify / restore UI.
 */
final class MigrationPage {

	private MigrationService $migration;

	public function __construct( MigrationService $migration ) {
		$this->migration = $migration;
	}

	/**
	 * Hooks (menu is registered by AdminMenu).
	 *
	 * @return void
	 */
	public function register(): void {
		// Intentionally empty — assets enqueue via SettingsPage on both screens.
	}

	/**
	 * Render tools UI.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$stats    = $this->migration->stats();
		$settings = Plugin::instance()->settings();
		$profiles = $this->storage_profiles();
		$total    = (int) $stats['total'];
		$off      = (int) $stats['offloaded'];
		$pct      = $total > 0 ? (int) round( ( $off / $total ) * 100 ) : 0;
		?>
		<div class="wrap s3ms-wrap">
			<?php
			AdminLayout::brand_header();
			AdminLayout::header(
				__( 'Migration', 'kazcode-universal-storage' ),
				__( 'Migrate existing Media Library items, verify objects on S3, retry failures, or restore files back to the server.', 'kazcode-universal-storage' ),
				'cloud-upload',
				array(
					admin_url( 'admin.php?page=' . AdminMenu::MEDIA_SLUG ) => __( 'Failed media', 'kazcode-universal-storage' ),
				)
			);
			AdminLayout::subnav( AdminMenu::MIGRATION_SLUG );
			?>
			<?php AdminLayout::tour_replay_button( 5 ); ?>

			<div class="notice notice-info inline s3ms-notice"><p><?php esc_html_e( 'Reminder: images hard-coded in the theme (e.g. /wp-content/themes/.../assets/) are not Media Library items and will never show S3 URLs. Only attachments are offloaded.', 'kazcode-universal-storage' ); ?></p></div>

			<?php if ( ! $settings->is_aws_configured() ) : ?>
				<div class="notice notice-warning inline s3ms-notice"><p><?php esc_html_e( 'Connection settings are incomplete. Configure the bucket and credentials under Settings before running tools.', 'kazcode-universal-storage' ); ?></p></div>
			<?php elseif ( ! $settings->is_enabled() ) : ?>
				<div class="notice notice-info inline s3ms-notice"><p><?php esc_html_e( 'Offload is currently disabled. You can still migrate for testing, but new uploads will stay local until Enable offload is turned on.', 'kazcode-universal-storage' ); ?></p></div>
			<?php endif; ?>

			<section class="s3ms-card"
				data-s3ms-tour-step="1"
				data-s3ms-tour-title="<?php esc_attr_e( 'Where your library stands', 'kazcode-universal-storage' ); ?>"
				data-s3ms-tour-text="<?php esc_attr_e( 'Offloaded / Pending / Failed / Verified are live counts from attachment post meta. Pending should trend toward zero as you run Migrate below; anything Failed is also visible in detail on the Media tab.', 'kazcode-universal-storage' ); ?>"
			>
				<div class="s3ms-card__head">
					<div>
						<h2 class="s3ms-card__title"><?php esc_html_e( 'Library overview', 'kazcode-universal-storage' ); ?></h2>
						<p class="s3ms-card__intro"><?php esc_html_e( 'Counts are based on attachment post meta managed by this plugin.', 'kazcode-universal-storage' ); ?></p>
					</div>
				</div>
				<div class="s3ms-card__body">
					<div class="s3ms-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $pct ); ?>">
						<div class="s3ms-progress__bar" style="width: <?php echo esc_attr( (string) $pct ); ?>%"></div>
					</div>
					<p class="s3ms-progress__caption">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: offloaded count 2: total 3: percent */
								__( '%1$d of %2$d attachments offloaded (%3$d%%)', 'kazcode-universal-storage' ),
								$off,
								$total,
								$pct
							)
						);
						?>
					</p>
					<div class="s3ms-status-grid s3ms-status-grid--compact" id="s3ms-stats">
						<div class="s3ms-stat is-muted"><span class="s3ms-stat__label"><?php esc_html_e( 'Total', 'kazcode-universal-storage' ); ?></span><strong class="s3ms-stat__value"><?php echo esc_html( (string) $stats['total'] ); ?></strong></div>
						<div class="s3ms-stat is-ok"><span class="s3ms-stat__label"><?php esc_html_e( 'Offloaded', 'kazcode-universal-storage' ); ?></span><strong class="s3ms-stat__value"><?php echo esc_html( (string) $stats['offloaded'] ); ?></strong></div>
						<div class="s3ms-stat is-warn"><span class="s3ms-stat__label"><?php esc_html_e( 'Pending', 'kazcode-universal-storage' ); ?></span><strong class="s3ms-stat__value"><?php echo esc_html( (string) $stats['pending'] ); ?></strong></div>
						<div class="s3ms-stat is-error"><span class="s3ms-stat__label"><?php esc_html_e( 'Failed', 'kazcode-universal-storage' ); ?></span><strong class="s3ms-stat__value"><?php echo esc_html( (string) $stats['failed'] ); ?></strong></div>
						<div class="s3ms-stat is-info"><span class="s3ms-stat__label"><?php esc_html_e( 'Verified', 'kazcode-universal-storage' ); ?></span><strong class="s3ms-stat__value"><?php echo esc_html( (string) $stats['verified'] ); ?></strong></div>
					</div>
				</div>
			</section>

			<section class="s3ms-card" id="s3ms-import-wizard" data-s3ms-import-state="detect">
				<div class="s3ms-card__head">
					<div>
						<h2 class="s3ms-card__title"><?php esc_html_e( 'Switch from another plugin', 'kazcode-universal-storage' ); ?></h2>
						<p class="s3ms-card__intro"><?php esc_html_e( 'Detect existing offload metadata, analyze remote objects, then adopt verified media into KAZCODE inventory without re-uploading.', 'kazcode-universal-storage' ); ?></p>
					</div>
					<ol class="s3ms-import-steps" aria-label="<?php esc_attr_e( 'Import progress', 'kazcode-universal-storage' ); ?>">
						<li class="is-active" data-s3ms-import-step="detect"><?php esc_html_e( 'Detect', 'kazcode-universal-storage' ); ?></li>
						<li data-s3ms-import-step="storage"><?php esc_html_e( 'Storage', 'kazcode-universal-storage' ); ?></li>
						<li data-s3ms-import-step="analyze"><?php esc_html_e( 'Analyze', 'kazcode-universal-storage' ); ?></li>
						<li data-s3ms-import-step="import"><?php esc_html_e( 'Import', 'kazcode-universal-storage' ); ?></li>
						<li data-s3ms-import-step="report"><?php esc_html_e( 'Report', 'kazcode-universal-storage' ); ?></li>
					</ol>
				</div>
				<div class="s3ms-card__body">
					<div class="s3ms-fields s3ms-import-fields">
						<div class="s3ms-field" id="s3ms-import-source-wrap">
							<label class="s3ms-field__label" for="s3ms-import-source"><?php esc_html_e( 'Source plugin', 'kazcode-universal-storage' ); ?></label>
							<select class="s3ms-field__input" id="s3ms-import-source">
								<option value=""><?php esc_html_e( 'Detect sources first', 'kazcode-universal-storage' ); ?></option>
							</select>
						</div>
						<div class="s3ms-field">
							<label class="s3ms-field__label" for="s3ms-import-profile"><?php esc_html_e( 'Storage profile', 'kazcode-universal-storage' ); ?></label>
							<select class="s3ms-field__input" id="s3ms-import-profile">
								<option value=""><?php esc_html_e( 'Choose a KAZCODE storage profile', 'kazcode-universal-storage' ); ?></option>
								<?php foreach ( $profiles as $profile ) : ?>
									<option value="<?php echo esc_attr( (string) ( $profile['id'] ?? '' ) ); ?>">
										<?php echo esc_html( (string) ( $profile['name'] ?? __( 'Storage profile', 'kazcode-universal-storage' ) ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<span class="s3ms-field__help"><?php esc_html_e( 'Use credentials already configured in KAZCODE. Competitor secrets are never copied or shown here.', 'kazcode-universal-storage' ); ?></span>
						</div>
					</div>

					<p class="s3ms-actions s3ms-import-actions">
						<button type="button" class="button" data-s3ms-import-action="detect"><?php esc_html_e( 'Detect sources', 'kazcode-universal-storage' ); ?></button>
						<button type="button" class="button button-secondary" data-s3ms-import-action="analyze"><?php esc_html_e( 'Analyze', 'kazcode-universal-storage' ); ?></button>
						<button type="button" class="button button-primary" data-s3ms-import-action="commit" disabled><?php esc_html_e( 'Commit import', 'kazcode-universal-storage' ); ?></button>
						<button type="button" class="button" data-s3ms-import-action="pause" disabled><?php esc_html_e( 'Pause', 'kazcode-universal-storage' ); ?></button>
						<button type="button" class="button" data-s3ms-import-action="resume" disabled><?php esc_html_e( 'Resume', 'kazcode-universal-storage' ); ?></button>
						<button type="button" class="button" data-s3ms-import-action="cancel" disabled><?php esc_html_e( 'Cancel', 'kazcode-universal-storage' ); ?></button>
					</p>
					<div class="s3ms-test-result" id="s3ms-import-status" role="status" aria-live="polite"></div>

					<div class="s3ms-status-grid s3ms-status-grid--import" id="s3ms-import-report">
						<div class="s3ms-stat is-muted"><span class="s3ms-stat__label"><?php esc_html_e( 'Attachments scanned', 'kazcode-universal-storage' ); ?></span><strong class="s3ms-stat__value" data-s3ms-import-count="processed">0</strong></div>
						<div class="s3ms-stat is-muted"><span class="s3ms-stat__label"><?php esc_html_e( 'Objects checked', 'kazcode-universal-storage' ); ?></span><strong class="s3ms-stat__value" data-s3ms-import-count="objects_checked">0</strong></div>
						<div class="s3ms-stat is-ok"><span class="s3ms-stat__label"><?php esc_html_e( 'Verified / adopted', 'kazcode-universal-storage' ); ?></span><strong class="s3ms-stat__value" data-s3ms-import-count="verified">0</strong></div>
						<div class="s3ms-stat is-warn"><span class="s3ms-stat__label"><?php esc_html_e( 'Confirmed missing', 'kazcode-universal-storage' ); ?></span><strong class="s3ms-stat__value" data-s3ms-import-count="missing">0</strong></div>
						<div class="s3ms-stat is-error"><span class="s3ms-stat__label"><?php esc_html_e( 'Transient errors', 'kazcode-universal-storage' ); ?></span><strong class="s3ms-stat__value" data-s3ms-import-count="transient_errors">0</strong></div>
						<div class="s3ms-stat is-error"><span class="s3ms-stat__label"><?php esc_html_e( 'Conflicts', 'kazcode-universal-storage' ); ?></span><strong class="s3ms-stat__value" data-s3ms-import-count="conflicts">0</strong></div>
						<div class="s3ms-stat is-muted"><span class="s3ms-stat__label"><?php esc_html_e( 'Skipped', 'kazcode-universal-storage' ); ?></span><strong class="s3ms-stat__value" data-s3ms-import-count="skipped">0</strong></div>
						<div class="s3ms-stat is-info"><span class="s3ms-stat__label"><?php esc_html_e( 'Updated', 'kazcode-universal-storage' ); ?></span><strong class="s3ms-stat__value" data-s3ms-import-count="updated">—</strong></div>
					</div>
					<p class="description" id="s3ms-import-transfer-progress"><?php esc_html_e( 'Large objects resume transfer progress automatically when interrupted; no S3 multipart tuning is required.', 'kazcode-universal-storage' ); ?></p>
				</div>
			</section>

			<section class="s3ms-card"
				data-s3ms-tour-step="2"
				data-s3ms-tour-title="<?php esc_attr_e( 'Dry run first, always', 'kazcode-universal-storage' ); ?>"
				data-s3ms-tour-text="<?php esc_attr_e( 'Dry run scans and reports without uploading anything — use it right after configuring credentials to sanity-check before Migrate touches real files. Retry only re-queues items already marked failed; Verify and Adopt don\'t upload at all, they only HEAD-check what\'s already on S3.', 'kazcode-universal-storage' ); ?>"
			>
				<div class="s3ms-card__head">
					<div>
						<h2 class="s3ms-card__title"><?php esc_html_e( 'Actions', 'kazcode-universal-storage' ); ?></h2>
						<p class="s3ms-card__intro"><?php esc_html_e( 'Each action runs in small REST batches and can be resumed. Prefer WP-CLI for very large libraries.', 'kazcode-universal-storage' ); ?></p>
					</div>
				</div>
				<div class="s3ms-card__body">
					<div class="s3ms-batch-size">
						<label for="s3ms-batch-size"><?php esc_html_e( 'Batch size', 'kazcode-universal-storage' ); ?></label>
						<input type="number" id="s3ms-batch-size" min="1" max="100" value="20" />
						<span class="s3ms-field__help"><?php esc_html_e( 'Attachments per request (1–100). Lower on shared hosting if requests time out.', 'kazcode-universal-storage' ); ?></span>
					</div>

					<div class="s3ms-action-grid">
						<article class="s3ms-action">
							<h3 class="s3ms-action__title"><?php esc_html_e( 'Dry run', 'kazcode-universal-storage' ); ?></h3>
							<p class="s3ms-action__desc"><?php esc_html_e( 'Scan what would be migrated without uploading. Useful as a first check after configuring credentials.', 'kazcode-universal-storage' ); ?></p>
							<button type="button" class="button" data-s3ms-action="dry-run"><?php esc_html_e( 'Dry run', 'kazcode-universal-storage' ); ?></button>
						</article>
						<article class="s3ms-action">
							<h3 class="s3ms-action__title"><?php esc_html_e( 'Migrate to S3', 'kazcode-universal-storage' ); ?></h3>
							<p class="s3ms-action__desc"><?php esc_html_e( 'Upload local originals and sizes that are not yet offloaded. Skips items already marked failed — use Retry for those.', 'kazcode-universal-storage' ); ?></p>
							<button type="button" class="button button-primary" data-s3ms-action="migrate"><?php esc_html_e( 'Start migration', 'kazcode-universal-storage' ); ?></button>
						</article>
						<article class="s3ms-action">
							<h3 class="s3ms-action__title"><?php esc_html_e( 'Retry failed', 'kazcode-universal-storage' ); ?></h3>
							<p class="s3ms-action__desc"><?php esc_html_e( 'Re-queue attachments that previously failed (for example temporary S3 errors, or local files you restored).', 'kazcode-universal-storage' ); ?></p>
							<button type="button" class="button" data-s3ms-action="retry"><?php esc_html_e( 'Retry failed', 'kazcode-universal-storage' ); ?></button>
						</article>
						<article class="s3ms-action">
							<h3 class="s3ms-action__title"><?php esc_html_e( 'Verify on S3', 'kazcode-universal-storage' ); ?></h3>
							<p class="s3ms-action__desc"><?php esc_html_e( 'HEAD-check objects for offloaded attachments and refresh the verified timestamp. Use after CDN or bucket changes.', 'kazcode-universal-storage' ); ?></p>
							<button type="button" class="button" data-s3ms-action="verify"><?php esc_html_e( 'Verify', 'kazcode-universal-storage' ); ?></button>
						</article>
						<article class="s3ms-action">
							<h3 class="s3ms-action__title"><?php esc_html_e( 'Restore to local disk', 'kazcode-universal-storage' ); ?></h3>
							<p class="s3ms-action__desc"><?php esc_html_e( 'Download S3 objects back into uploads. Useful before disabling the plugin or changing hosts. Does not delete S3 objects.', 'kazcode-universal-storage' ); ?></p>
							<button type="button" class="button" data-s3ms-action="restore"><?php esc_html_e( 'Restore local files', 'kazcode-universal-storage' ); ?></button>
						</article>
						<article class="s3ms-action">
							<h3 class="s3ms-action__title"><?php esc_html_e( 'Adopt existing on S3', 'kazcode-universal-storage' ); ?></h3>
							<p class="s3ms-action__desc"><?php esc_html_e( 'For media already on S3 (legacy offload or imports): HEAD expected keys and build object inventory rows without re-uploading.', 'kazcode-universal-storage' ); ?></p>
							<button type="button" class="button" data-s3ms-action="adopt"><?php esc_html_e( 'Adopt inventory', 'kazcode-universal-storage' ); ?></button>
						</article>
					</div>

					<div class="s3ms-batch-log">
						<div class="s3ms-batch-log__head">
							<strong><?php esc_html_e( 'Batch log', 'kazcode-universal-storage' ); ?></strong>
							<button type="button" class="button-link" id="s3ms-batch-stop" hidden><?php esc_html_e( 'Stop', 'kazcode-universal-storage' ); ?></button>
						</div>
						<pre id="s3ms-migration-log" class="s3ms-log" aria-live="polite"></pre>
					</div>
				</div>
			</section>

			<section class="s3ms-card"
				data-s3ms-tour-step="3"
				data-s3ms-tour-title="<?php esc_attr_e( 'For libraries too big for one browser session', 'kazcode-universal-storage' ); ?>"
				data-s3ms-tour-text="<?php esc_attr_e( 'Background jobs run via WP-Cron, so you can close this tab and they keep going — the trade-off is needing real WP-Cron activity (either visitor-triggered or a system cron hitting wp-cron.php) to make progress. For very large libraries, WP-CLI (see below) is still the most reliable option.', 'kazcode-universal-storage' ); ?>"
			>
				<div class="s3ms-card__head">
					<div>
						<h2 class="s3ms-card__title"><?php esc_html_e( 'Background jobs', 'kazcode-universal-storage' ); ?></h2>
						<p class="s3ms-card__intro"><?php esc_html_e( 'Run migrate/verify/retry/restore via WP-Cron so you can close the browser. Requires WP-Cron or a real system cron hitting wp-cron.php.', 'kazcode-universal-storage' ); ?></p>
					</div>
				</div>
				<div class="s3ms-card__body">
					<?php
					$bg = Plugin::instance()->background()->status();
					?>
					<p id="s3ms-bg-status">
						<?php if ( ! empty( $bg['running'] ) ) : ?>
							<?php
							printf(
								/* translators: 1: action 2: processed 3: success 4: failed 5: cursor after_id */
								esc_html__( 'Running “%1$s” — processed %2$d (ok %3$d / fail %4$d). Cursor after_id=%5$d.', 'kazcode-universal-storage' ),
								esc_html( (string) $bg['action'] ),
								(int) $bg['processed'],
								(int) $bg['success'],
								(int) $bg['failed'],
								(int) $bg['after_id']
							);
							?>
						<?php else : ?>
							<?php esc_html_e( 'No background job running.', 'kazcode-universal-storage' ); ?>
							<?php if ( ! empty( $bg['finished_at'] ) ) : ?>
								<?php
								printf(
									/* translators: %s: finished-at timestamp (UTC) */
									' ' . esc_html__( 'Last finished at %s UTC.', 'kazcode-universal-storage' ),
									esc_html( (string) $bg['finished_at'] )
								);
								?>
							<?php endif; ?>
						<?php endif; ?>
					</p>
					<p class="s3ms-actions">
						<button type="button" class="button button-primary" data-s3ms-bg="migrate"><?php esc_html_e( 'Background migrate', 'kazcode-universal-storage' ); ?></button>
						<button type="button" class="button" data-s3ms-bg="retry"><?php esc_html_e( 'Background retry failed', 'kazcode-universal-storage' ); ?></button>
						<button type="button" class="button" data-s3ms-bg="verify"><?php esc_html_e( 'Background verify', 'kazcode-universal-storage' ); ?></button>
						<button type="button" class="button" data-s3ms-bg="restore"><?php esc_html_e( 'Background restore', 'kazcode-universal-storage' ); ?></button>
						<button type="button" class="button" id="s3ms-bg-stop"><?php esc_html_e( 'Stop background job', 'kazcode-universal-storage' ); ?></button>
					</p>
				</div>
			</section>

			<div class="notice notice-info inline s3ms-notice"><p>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s: link to Media admin page */
						__( 'Failed items moved to %s — inspect, export CSV, and ignore from there.', 'kazcode-universal-storage' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=' . AdminMenu::MEDIA_SLUG ) ) . '">' . esc_html__( 'Media', 'kazcode-universal-storage' ) . '</a>'
					)
				);
				?>
			</p></div>

			<section class="s3ms-card"
				data-s3ms-tour-step="4"
				data-s3ms-tour-title="<?php esc_attr_e( 'The safe order to do this in', 'kazcode-universal-storage' ); ?>"
				data-s3ms-tour-text="<?php esc_attr_e( 'Follow this list top to bottom, especially the last two steps: verify the site looks right with Serve from S3 on BEFORE you ever turn on deleting local files after upload. That toggle is the only genuinely destructive one here.', 'kazcode-universal-storage' ); ?>"
			>
				<div class="s3ms-card__head">
					<div>
						<h2 class="s3ms-card__title"><?php esc_html_e( 'Recommended order', 'kazcode-universal-storage' ); ?></h2>
					</div>
				</div>
				<ol class="s3ms-steps-list">
					<li><?php esc_html_e( 'Storage → Test connection until green.', 'kazcode-universal-storage' ); ?></li>
					<li><?php esc_html_e( 'Enable offload (optional: keep Serve from S3 off until a sample looks good).', 'kazcode-universal-storage' ); ?></li>
					<li><?php esc_html_e( 'Run Migrate until pending reaches zero.', 'kazcode-universal-storage' ); ?></li>
					<li><?php esc_html_e( 'Turn on Serve from S3 / CDN and spot-check the site.', 'kazcode-universal-storage' ); ?></li>
					<li><?php esc_html_e( 'Only then enable Delete local files after successful upload.', 'kazcode-universal-storage' ); ?></li>
				</ol>
			</section>

			<section class="s3ms-card">
				<div class="s3ms-card__head">
					<div>
						<h2 class="s3ms-card__title"><?php esc_html_e( 'WP-CLI', 'kazcode-universal-storage' ); ?></h2>
						<p class="s3ms-card__intro"><?php esc_html_e( 'For large libraries, CLI is more reliable than the browser UI.', 'kazcode-universal-storage' ); ?></p>
					</div>
				</div>
				<pre class="s3ms-code">wp universal-storage status
wp universal-storage migrate --dry-run
wp universal-storage migrate --batch-size=100
wp universal-storage verify
wp universal-storage retry-failed
wp universal-storage restore
wp universal-storage adopt --dry-run</pre>
			</section>
			<?php AdminLayout::footer(); ?>
		</div>
		<?php
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function storage_profiles(): array {
		try {
			$profiles = ( new StorageProfileAdminService( Plugin::instance()->settings() ) )->list_summaries();
			return is_array( $profiles ) ? array_values( $profiles ) : array();
		} catch ( \Throwable ) {
			return array();
		}
	}
}
