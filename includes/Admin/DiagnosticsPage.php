<?php
/**
 * Diagnostics / Health admin page.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Admin;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\Features;
use Kazcode\WpStorage\Plugin;
use Kazcode\WpStorage\Services\AuditLog;
use Kazcode\WpStorage\Services\AwsAssistant;
use Kazcode\WpStorage\Services\HealthCheckService;

/**
 * Health checks, AWS assistant, audit log.
 */
final class DiagnosticsPage {

	/**
	 * Render page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! Features::enabled( 'diagnostics' ) ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Diagnostics require Pro features.', 'kazcode-universal-storage' ) . '</p></div>';
			return;
		}

		$settings = Plugin::instance()->settings();
		$health   = ( new HealthCheckService( $settings ) )->run();
		$audit    = ( new AuditLog() )->latest( 40 );
		$assist   = ( new AwsAssistant() )->build(
			(string) $settings->get( 'bucket', '' ),
			(string) $settings->get( 'object_prefix', '' )
		);
		?>
		<div class="wrap s3ms-wrap">
			<header class="s3ms-header">
				<div class="s3ms-header__brand">
					<span class="s3ms-header__icon dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<div>
						<h1 class="s3ms-header__title"><?php esc_html_e( 'Diagnostics', 'kazcode-universal-storage' ); ?></h1>
						<p class="s3ms-header__tagline"><?php esc_html_e( 'Health checks, AWS setup helper, and recent audit events.', 'kazcode-universal-storage' ); ?></p>
					</div>
				</div>
				<div class="s3ms-header__actions">
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminMenu::MENU_SLUG ) ); ?>"><?php esc_html_e( '← Settings', 'kazcode-universal-storage' ); ?></a>
				</div>
			</header>

			<div class="notice notice-info s3ms-notice"><p>
				<?php esc_html_e( 'Theme/plugin static assets (CSS, JS, images under /themes or /plugins) are never offloaded. Only Media Library attachments are rewritten to S3.', 'kazcode-universal-storage' ); ?>
			</p></div>

			<section class="s3ms-card">
				<div class="s3ms-card__head"><div>
					<h2 class="s3ms-card__title"><?php esc_html_e( 'Health', 'kazcode-universal-storage' ); ?></h2>
					<p class="s3ms-card__intro"><?php
					printf(
						/* translators: %s: plan */
						esc_html__( 'Plan: %s', 'kazcode-universal-storage' ),
						esc_html( strtoupper( (string) $health['plan'] ) )
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

			<section class="s3ms-card">
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

			<section class="s3ms-card">
				<div class="s3ms-card__head"><div>
					<h2 class="s3ms-card__title"><?php esc_html_e( 'Audit log', 'kazcode-universal-storage' ); ?></h2>
				</div></div>
				<div class="s3ms-card__body">
					<?php if ( $audit === array() ) : ?>
						<p><?php esc_html_e( 'No events yet.', 'kazcode-universal-storage' ); ?></p>
					<?php else : ?>
						<table class="widefat striped s3ms-table">
							<thead><tr><th><?php esc_html_e( 'When (UTC)', 'kazcode-universal-storage' ); ?></th><th><?php esc_html_e( 'User', 'kazcode-universal-storage' ); ?></th><th><?php esc_html_e( 'Action', 'kazcode-universal-storage' ); ?></th><th><?php esc_html_e( 'Context', 'kazcode-universal-storage' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( $audit as $row ) : ?>
								<tr>
									<td><?php echo esc_html( (string) ( $row['at'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( (string) ( $row['user'] ?? '' ) ); ?></td>
									<td><code><?php echo esc_html( (string) ( $row['action'] ?? '' ) ); ?></code></td>
									<td><code><?php echo esc_html( wp_json_encode( $row['context'] ?? array() ) ?: '' ); ?></code></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</section>
		</div>
		<?php
	}
}
