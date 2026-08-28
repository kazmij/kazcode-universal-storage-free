<?php
/**
 * Health reconcile / repair admin page (v2 Phase 11).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Admin;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\Features;
use Kazcode\WpStorage\Plugin;
use Kazcode\WpStorage\Services\AwsAssistant;
use Kazcode\WpStorage\Services\HealthCheckService;
use Kazcode\WpStorage\Services\ObjectStatsAggregator;

/**
 * Health checks, object inventory scan, repair, orphan scan.
 */
final class HealthPage {

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = Plugin::instance()->settings();
		$health   = ( new HealthCheckService( $settings ) )->run();
		$objects  = ( new ObjectStatsAggregator() )->get();
		$assist   = ( new AwsAssistant() )->build(
			(string) $settings->get( 'bucket', '' ),
			(string) $settings->get( 'object_prefix', '' )
		);
		$orphan   = is_array( $health['orphan_scan'] ?? null ) ? $health['orphan_scan'] : null;
		?>
		<div class="wrap s3ms-wrap">
			<?php
			AdminLayout::brand_header();
			AdminLayout::header(
				__( 'Health', 'kazcode-universal-storage' ),
				__( 'Reconcile object inventory, repair missing remotes, and inspect orphan scan status.', 'kazcode-universal-storage' ),
				'yes-alt',
				array(
					admin_url( 'admin.php?page=' . AdminMenu::LOGS_SLUG ) => __( 'View logs', 'kazcode-universal-storage' ),
				)
			);
			AdminLayout::subnav( AdminMenu::HEALTH_SLUG );
			?>
			<?php AdminLayout::tour_replay_button( 4 ); ?>

			<div class="notice notice-info inline s3ms-notice"><p>
				<?php esc_html_e( 'Theme/plugin static assets are never offloaded. Only Media Library attachments are tracked in s3ms_objects.', 'kazcode-universal-storage' ); ?>
			</p></div>

			<section class="s3ms-card"
				data-s3ms-tour-step="1"
				data-s3ms-tour-title="<?php esc_attr_e( 'System-level checks, not object status', 'kazcode-universal-storage' ); ?>"
				data-s3ms-tour-text="<?php esc_attr_e( 'This checklist covers environment and connectivity — PHP version, AWS SDK, uploads directory permissions, and a live S3 round-trip test. It runs on every page load; it does not scan your media library (that\'s the section below).', 'kazcode-universal-storage' ); ?>"
			>
				<div class="s3ms-card__head"><div>
					<h2 class="s3ms-card__title"><?php esc_html_e( 'Health checks', 'kazcode-universal-storage' ); ?></h2>
					<p class="s3ms-card__intro"><?php
					printf(
						/* translators: %s: plan name (e.g. LITE, PRO) */
						esc_html__( 'Plan: %s', 'kazcode-universal-storage' ),
						esc_html( strtoupper( (string) ( $health['plan'] ?? '' ) ) )
					);
					?></p>
				</div></div>
				<div class="s3ms-card__body">
					<ul class="s3ms-health-list">
						<?php foreach ( $health['checks'] as $check ) : ?>
							<li class="s3ms-health-list__item is-<?php echo esc_attr( (string) $check['status'] ); ?>">
								<strong><?php echo esc_html( (string) $check['label'] ); ?></strong>
								<span><?php echo esc_html( (string) $check['detail'] ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
					<div class="s3ms-status-grid s3ms-status-grid--compact" style="margin-top:16px">
						<?php foreach ( array( 'total', 'offloaded', 'pending', 'failed', 'verified' ) as $k ) : ?>
							<div class="s3ms-stat is-info">
								<span class="s3ms-stat__label"><?php echo esc_html( ucfirst( $k ) ); ?></span>
								<strong class="s3ms-stat__value"><?php echo esc_html( (string) ( $health['stats'][ $k ] ?? 0 ) ); ?></strong>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			</section>

			<section class="s3ms-card" id="s3ms-health-objects"
				data-s3ms-tour-step="2"
				data-s3ms-tour-title="<?php esc_attr_e( 'Cached counts, refreshed on demand', 'kazcode-universal-storage' ); ?>"
				data-s3ms-tour-text="<?php esc_attr_e( 'These numbers come from the s3ms_objects table, not a live S3 LIST — that keeps this page fast. Click Refresh cache to recompute them right now, or Run DB health scan to reconcile one page of rows against their stored status (both read the database only; neither one re-uploads anything).', 'kazcode-universal-storage' ); ?>"
			>
				<div class="s3ms-card__head"><div>
					<h2 class="s3ms-card__title"><?php esc_html_e( 'Object inventory (cached)', 'kazcode-universal-storage' ); ?></h2>
					<p class="s3ms-card__intro"><?php esc_html_e( 'SQL aggregates from s3ms_objects — no S3 LIST on page load.', 'kazcode-universal-storage' ); ?></p>
				</div></div>
				<div class="s3ms-card__body">
					<div class="s3ms-status-grid s3ms-status-grid--compact" id="s3ms-object-stats">
						<?php foreach ( array( 'total_objects', 'present', 'pending', 'missing', 'failed', 'stale' ) as $k ) : ?>
							<div class="s3ms-stat is-info">
								<span class="s3ms-stat__label"><?php echo esc_html( str_replace( '_', ' ', ucfirst( $k ) ) ); ?></span>
								<strong class="s3ms-stat__value"><?php echo esc_html( (string) (int) ( $objects[ $k ] ?? 0 ) ); ?></strong>
							</div>
						<?php endforeach; ?>
					</div>
					<p class="description" id="s3ms-object-stats-meta">
						<?php
						printf(
							/* translators: %s: cache-generated timestamp */
							esc_html__( 'Cache generated: %s', 'kazcode-universal-storage' ),
							esc_html( (string) ( $objects['generated_at'] ?? '—' ) )
						);
						?>
					</p>
					<p>
						<button type="button" class="button" id="s3ms-refresh-stats"><?php esc_html_e( 'Refresh cache', 'kazcode-universal-storage' ); ?></button>
						<button type="button" class="button" id="s3ms-health-scan"><?php esc_html_e( 'Run DB health scan (one page)', 'kazcode-universal-storage' ); ?></button>
					</p>
					<pre id="s3ms-health-scan-log" class="s3ms-log" aria-live="polite"></pre>
				</div>
			</section>

			<?php if ( Features::enabled( 'advanced_health' ) && $orphan !== null ) : ?>
			<section class="s3ms-card" id="s3ms-orphan-panel">
				<div class="s3ms-card__head"><div>
					<h2 class="s3ms-card__title"><?php esc_html_e( 'Orphan scan (dry-run)', 'kazcode-universal-storage' ); ?></h2>
					<p class="s3ms-card__intro"><?php esc_html_e( 'LIST prefix keys not present in object inventory. Never deletes automatically.', 'kazcode-universal-storage' ); ?></p>
				</div></div>
				<div class="s3ms-card__body">
					<p id="s3ms-orphan-status">
						<?php
						if ( ! empty( $orphan['last_scanned_at'] ) ) {
							printf(
								/* translators: 1: last-scanned timestamp 2: keys scanned 3: orphans found */
								esc_html__( 'Last page: %1$s — keys scanned %2$d, orphans %3$d.', 'kazcode-universal-storage' ),
								esc_html( (string) $orphan['last_scanned_at'] ),
								(int) ( $orphan['keys_scanned'] ?? 0 ),
								(int) ( $orphan['orphans_found'] ?? 0 )
							);
						} else {
							esc_html_e( 'No orphan scan run yet.', 'kazcode-universal-storage' );
						}
						?>
					</p>
					<p>
						<button type="button" class="button" id="s3ms-orphan-scan"><?php esc_html_e( 'Scan one LIST page', 'kazcode-universal-storage' ); ?></button>
						<button type="button" class="button" id="s3ms-orphan-async"><?php esc_html_e( 'Enqueue async scan', 'kazcode-universal-storage' ); ?></button>
					</p>
					<pre id="s3ms-orphan-log" class="s3ms-log" aria-live="polite"></pre>
				</div>
			</section>
			<?php endif; ?>

			<section class="s3ms-card"
				data-s3ms-tour-step="3"
				data-s3ms-tour-title="<?php esc_attr_e( 'A ready-to-paste least-privilege policy', 'kazcode-universal-storage' ); ?>"
				data-s3ms-tour-text="<?php esc_attr_e( 'This IAM policy JSON is scoped to exactly the bucket and prefix currently configured in Settings — copy it when creating the IAM user AWS Console links below jump straight to.', 'kazcode-universal-storage' ); ?>"
			>
				<div class="s3ms-card__head"><div>
					<h2 class="s3ms-card__title"><?php esc_html_e( 'AWS setup assistant', 'kazcode-universal-storage' ); ?></h2>
					<p class="s3ms-card__intro"><?php esc_html_e( 'Checklist and a least-privilege IAM policy for your current bucket/prefix.', 'kazcode-universal-storage' ); ?></p>
				</div></div>
				<div class="s3ms-card__body">
					<ol class="s3ms-steps-list" style="padding-left:1.2em;padding-bottom:0">
						<?php foreach ( $assist['checklist'] as $item ) : ?>
							<li><?php echo esc_html( $item ); ?></li>
						<?php endforeach; ?>
					</ol>
					<p>
						<a class="button" href="<?php echo esc_url( $assist['console_links']['buckets'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'S3 buckets', 'kazcode-universal-storage' ); ?></a>
						<a class="button" href="<?php echo esc_url( $assist['console_links']['iam'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'IAM users', 'kazcode-universal-storage' ); ?></a>
					</p>
					<label class="s3ms-field__label" for="s3ms-iam-policy"><?php esc_html_e( 'Suggested IAM policy JSON', 'kazcode-universal-storage' ); ?></label>
					<textarea id="s3ms-iam-policy" class="s3ms-code large-text" rows="16" readonly><?php echo esc_textarea( $assist['policy'] ); ?></textarea>
				</div>
			</section>
			<?php AdminLayout::footer(); ?>
		</div>
		<?php
	}
}
