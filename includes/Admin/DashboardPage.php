<?php
/**
 * Universal Storage dashboard — cached widgets (v2 Phase 11).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Admin;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\Features;
use Kazcode\WpStorage\Plugin;
use Kazcode\WpStorage\Services\FailedItemsService;
use Kazcode\WpStorage\Services\HealthCheckService;
use Kazcode\WpStorage\Services\MigrationService;
use Kazcode\WpStorage\Services\ObjectStatsAggregator;

/**
 * Default landing page.
 */
final class DashboardPage {

	public function register(): void {
		// Menu registered by AdminMenu.
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Plugin::instance()->settings();
		$migration = Plugin::instance()->migration_service()->stats();
		$objects   = ( new ObjectStatsAggregator() )->get();
		$queue     = Plugin::instance()->queue()->status();
		$bulk      = is_array( $queue['bulk'] ?? null ) ? $queue['bulk'] : $queue;
		$failed    = ( new FailedItemsService() )->list( 1, 5, 'all' );
		$health    = ( new HealthCheckService( $settings ) )->run();
		$conn_ok   = false;
		foreach ( $health['checks'] as $check ) {
			if ( ( $check['id'] ?? '' ) === 'connection' && ( $check['status'] ?? '' ) === 'ok' ) {
				$conn_ok = true;
				break;
			}
		}
		?>
		<div class="wrap s3ms-wrap">
			<?php
			AdminLayout::brand_header();
			AdminLayout::header(
				__( 'Dashboard', 'kazcode-universal-storage' ),
				__( 'Connection health, object inventory cache, queue activity, and recent failures.', 'kazcode-universal-storage' ),
				'dashboard',
				array(
					admin_url( 'admin.php?page=' . AdminMenu::SETTINGS_SLUG ) => __( 'Settings', 'kazcode-universal-storage' ),
					admin_url( 'admin.php?page=' . AdminMenu::MIGRATION_SLUG ) => __( 'Migration', 'kazcode-universal-storage' ),
				)
			);
			?>
			<?php AdminLayout::tour_replay_button( 5 ); ?>
			<?php AdminLayout::pro_upsell_banner(); ?>
			<div data-s3ms-tour-step="1"
				data-s3ms-tour-title="<?php esc_attr_e( 'Find your way around', 'kazcode-universal-storage' ); ?>"
				data-s3ms-tour-text="<?php esc_attr_e( 'Every Universal Storage screen is one click away here: Dashboard, Media, Storage, Migration, Health, Logs, and Settings.', 'kazcode-universal-storage' ); ?>"
			>
				<?php AdminLayout::subnav( AdminMenu::DASHBOARD_SLUG ); ?>
			</div>

			<section class="s3ms-status-grid"
				data-s3ms-tour-step="2"
				data-s3ms-tour-title="<?php esc_attr_e( 'Status at a glance', 'kazcode-universal-storage' ); ?>"
				data-s3ms-tour-text="<?php esc_attr_e( 'See whether your storage connection is configured and reachable, whether offload is on, and how many objects and attachments are tracked.', 'kazcode-universal-storage' ); ?>"
			>
				<div class="s3ms-stat <?php echo $settings->is_aws_configured() ? 'is-ok' : 'is-warn'; ?>">
					<span class="s3ms-stat__label"><?php esc_html_e( 'Connection', 'kazcode-universal-storage' ); ?></span>
					<strong class="s3ms-stat__value"><?php echo $settings->is_aws_configured() ? esc_html__( 'Configured', 'kazcode-universal-storage' ) : esc_html__( 'Incomplete', 'kazcode-universal-storage' ); ?></strong>
					<span class="s3ms-stat__hint"><?php echo $conn_ok ? esc_html__( 'Live test OK', 'kazcode-universal-storage' ) : esc_html__( 'Run test under Storage', 'kazcode-universal-storage' ); ?></span>
				</div>
				<div class="s3ms-stat <?php echo $settings->is_enabled() ? 'is-ok' : 'is-muted'; ?>">
					<span class="s3ms-stat__label"><?php esc_html_e( 'Offload', 'kazcode-universal-storage' ); ?></span>
					<strong class="s3ms-stat__value"><?php echo $settings->is_enabled() ? esc_html__( 'On', 'kazcode-universal-storage' ) : esc_html__( 'Off', 'kazcode-universal-storage' ); ?></strong>
				</div>
				<div class="s3ms-stat is-info">
					<span class="s3ms-stat__label"><?php esc_html_e( 'Object rows', 'kazcode-universal-storage' ); ?></span>
					<strong class="s3ms-stat__value"><?php echo esc_html( (string) (int) ( $objects['total_objects'] ?? 0 ) ); ?></strong>
					<span class="s3ms-stat__hint"><?php esc_html_e( 'Cached SQL aggregate', 'kazcode-universal-storage' ); ?></span>
				</div>
				<div class="s3ms-stat is-info">
					<span class="s3ms-stat__label"><?php esc_html_e( 'Attachments', 'kazcode-universal-storage' ); ?></span>
					<strong class="s3ms-stat__value"><?php echo esc_html( (string) (int) ( $migration['offloaded'] ?? 0 ) ); ?> / <?php echo esc_html( (string) (int) ( $migration['total'] ?? 0 ) ); ?></strong>
					<span class="s3ms-stat__hint"><?php esc_html_e( 'Offloaded / total', 'kazcode-universal-storage' ); ?></span>
				</div>
			</section>

			<div class="s3ms-layout s3ms-layout--dashboard">
				<section class="s3ms-card">
					<div class="s3ms-card__head"><div>
						<h2 class="s3ms-card__title"><?php esc_html_e( 'Object inventory (cached)', 'kazcode-universal-storage' ); ?></h2>
						<p class="s3ms-card__intro"><?php esc_html_e( 'Counts from s3ms_objects — refreshed hourly or on demand from Health.', 'kazcode-universal-storage' ); ?></p>
					</div></div>
					<div class="s3ms-card__body">
						<div class="s3ms-status-grid s3ms-status-grid--compact">
							<?php foreach ( array( 'present', 'pending', 'missing', 'failed', 'stale' ) as $k ) : ?>
								<div class="s3ms-stat is-info">
									<span class="s3ms-stat__label"><?php echo esc_html( ucfirst( $k ) ); ?></span>
									<strong class="s3ms-stat__value"><?php echo esc_html( (string) (int) ( $objects[ $k ] ?? 0 ) ); ?></strong>
								</div>
							<?php endforeach; ?>
						</div>
						<p class="description">
							<?php
							printf(
								/* translators: %s: ISO datetime */
								esc_html__( 'Cache generated: %s', 'kazcode-universal-storage' ),
								esc_html( (string) ( $objects['generated_at'] ?? '—' ) )
							);
							?>
						</p>
					</div>
				</section>

				<section class="s3ms-card"
					data-s3ms-tour-step="3"
					data-s3ms-tour-title="<?php esc_attr_e( 'Move existing media', 'kazcode-universal-storage' ); ?>"
					data-s3ms-tour-text="<?php esc_attr_e( 'Batch migrations run here — start one from Migration and watch progress on this card.', 'kazcode-universal-storage' ); ?>"
				>
					<div class="s3ms-card__head"><div>
						<h2 class="s3ms-card__title"><?php esc_html_e( 'Background queue', 'kazcode-universal-storage' ); ?></h2>
					</div></div>
					<div class="s3ms-card__body">
						<?php if ( ! empty( $bulk['running'] ) ) : ?>
							<p>
								<?php
								printf(
									/* translators: 1: action name 2: processed count 3: success count 4: failed count */
									esc_html__( 'Running “%1$s” — processed %2$d (ok %3$d / fail %4$d).', 'kazcode-universal-storage' ),
									esc_html( (string) ( $bulk['action'] ?? '' ) ),
									(int) ( $bulk['processed'] ?? 0 ),
									(int) ( $bulk['success'] ?? 0 ),
									(int) ( $bulk['failed'] ?? 0 )
								);
								?>
							</p>
						<?php else : ?>
							<p><?php esc_html_e( 'No background job running.', 'kazcode-universal-storage' ); ?></p>
						<?php endif; ?>
						<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminMenu::MIGRATION_SLUG ) ); ?>"><?php esc_html_e( 'Open Migration', 'kazcode-universal-storage' ); ?></a></p>
					</div>
				</section>

				<section class="s3ms-card"
					data-s3ms-tour-step="4"
					data-s3ms-tour-title="<?php esc_attr_e( 'Catch problems early', 'kazcode-universal-storage' ); ?>"
					data-s3ms-tour-text="<?php esc_attr_e( 'Anything that failed to offload shows up here so you can jump to it and retry.', 'kazcode-universal-storage' ); ?>"
				>
					<div class="s3ms-card__head"><div>
						<h2 class="s3ms-card__title"><?php esc_html_e( 'Recent failures', 'kazcode-universal-storage' ); ?></h2>
					</div></div>
					<div class="s3ms-card__body">
						<?php if ( empty( $failed['items'] ) ) : ?>
							<p><?php esc_html_e( 'No failed attachments in the last page.', 'kazcode-universal-storage' ); ?></p>
						<?php else : ?>
							<ul class="s3ms-health-list">
								<?php foreach ( $failed['items'] as $item ) : ?>
									<li class="s3ms-health-list__item is-fail">
										<strong>#<?php echo esc_html( (string) ( $item['id'] ?? '' ) ); ?> <?php echo esc_html( (string) ( $item['title'] ?? '' ) ); ?></strong>
										<span><?php echo esc_html( (string) ( $item['error'] ?? '' ) ); ?></span>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminMenu::MEDIA_SLUG ) ); ?>"><?php esc_html_e( 'View all failed media →', 'kazcode-universal-storage' ); ?></a></p>
					</div>
				</section>

				<?php if ( ! Features::is_pro_active() ) : ?>
					<section class="s3ms-card s3ms-card--pro">
						<div class="s3ms-card__head"><div>
							<h2 class="s3ms-card__title"><?php esc_html_e( 'Growing beyond one profile?', 'kazcode-universal-storage' ); ?> <span class="kazus-brand-pro-badge"><?php esc_html_e( 'PRO', 'kazcode-universal-storage' ); ?></span></h2>
							<p class="s3ms-card__intro"><?php esc_html_e( 'This Free plugin is a complete, non-trial product — one storage profile, full offload/migrate/verify/restore, no upload caps. KAZCODE Universal Storage Pro is a separate add-on for teams that need more:', 'kazcode-universal-storage' ); ?></p>
						</div></div>
						<div class="s3ms-card__body">
							<ul class="s3ms-bullets" style="padding-left:1.2em">
								<li><?php esc_html_e( 'Multiple storage profiles with independent credentials', 'kazcode-universal-storage' ); ?></li>
								<li><?php esc_html_e( 'Cross-provider migration (e.g. Amazon S3 → Cloudflare R2)', 'kazcode-universal-storage' ); ?></li>
								<li><?php esc_html_e( 'Orphan scan and advanced storage health', 'kazcode-universal-storage' ); ?></li>
								<li><?php esc_html_e( 'Multisite network defaults', 'kazcode-universal-storage' ); ?></li>
							</ul>
							<p><button type="button" class="button" data-kazus-pro-modal-open><?php esc_html_e( 'See what Pro adds', 'kazcode-universal-storage' ); ?></button></p>
						</div>
					</section>
				<?php endif; ?>
			</div>
			<?php AdminLayout::footer(); ?>
		</div>
		<?php
	}
}
