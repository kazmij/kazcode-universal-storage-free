<?php
/**
 * Audit log admin page (v2 Phase 11).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Admin;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Services\AuditLog;

/**
 * Recent audit events and job errors.
 */
final class LogsPage {

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$audit = ( new AuditLog() )->latest( 80 );
		?>
		<div class="wrap s3ms-wrap">
			<?php
			AdminLayout::brand_header();
			AdminLayout::header(
				__( 'Logs', 'kazcode-universal-storage' ),
				__( 'Recent admin actions, background jobs, and REST batch activity.', 'kazcode-universal-storage' ),
				'list-view',
				array(
					admin_url( 'admin.php?page=' . AdminMenu::HEALTH_SLUG ) => __( 'Health', 'kazcode-universal-storage' ),
				)
			);
			AdminLayout::subnav( AdminMenu::LOGS_SLUG );
			?>
			<?php AdminLayout::tour_replay_button( 2 ); ?>

			<section class="s3ms-card"
				data-s3ms-tour-step="1"
				data-s3ms-tour-title="<?php esc_attr_e( 'A record of what changed, and when', 'kazcode-universal-storage' ); ?>"
				data-s3ms-tour-text="<?php esc_attr_e( 'Newest first: settings saves, background job runs, and REST batch activity. Credentials and signed URLs are never logged, only who did what and when.', 'kazcode-universal-storage' ); ?>"
			>
				<div class="s3ms-card__head"><div>
					<h2 class="s3ms-card__title"><?php esc_html_e( 'Audit log', 'kazcode-universal-storage' ); ?></h2>
					<p class="s3ms-card__intro"><?php esc_html_e( 'Newest events first. Credentials and signed URLs are never logged.', 'kazcode-universal-storage' ); ?></p>
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
			<?php AdminLayout::footer(); ?>
		</div>
		<?php
	}
}
