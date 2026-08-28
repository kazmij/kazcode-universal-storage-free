<?php
/**
 * Media failures & library shortcuts (v2 Phase 11).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Failed items dashboard (moved from Migration tools).
 */
final class MediaPage {

	public function register(): void {
		// Menu via AdminMenu.
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap s3ms-wrap">
			<?php
			AdminLayout::brand_header();
			AdminLayout::header(
				__( 'Media', 'kazcode-universal-storage' ),
				__( 'Failed attachments, bulk actions, and Media Library shortcuts.', 'kazcode-universal-storage' ),
				'images-alt2',
				array(
					admin_url( 'upload.php' ) => __( 'Media Library', 'kazcode-universal-storage' ),
				)
			);
			AdminLayout::subnav( AdminMenu::MEDIA_SLUG );
			?>
			<?php AdminLayout::tour_replay_button( 3 ); ?>

			<section class="s3ms-card"
				data-s3ms-tour-step="1"
				data-s3ms-tour-title="<?php esc_attr_e( 'S3 actions live in the Media Library', 'kazcode-universal-storage' ); ?>"
				data-s3ms-tour-text="<?php esc_attr_e( 'Open the native Media Library list — each attachment row shows its S3 status column, and the bulk actions dropdown adds offload/retry/restore for selected items. This page is just for inspecting failures in detail.', 'kazcode-universal-storage' ); ?>"
			>
				<div class="s3ms-card__body">
					<p><?php esc_html_e( 'Row and bulk S3 actions are available on the Media Library list. Use this page to inspect failures in detail.', 'kazcode-universal-storage' ); ?></p>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>"><?php esc_html_e( 'Open Media Library', 'kazcode-universal-storage' ); ?></a>
					</p>
				</div>
			</section>

			<section class="s3ms-card" id="s3ms-failed-panel"
				data-s3ms-tour-step="2"
				data-s3ms-tour-title="<?php esc_attr_e( 'Investigate and clear failures', 'kazcode-universal-storage' ); ?>"
				data-s3ms-tour-text="<?php esc_attr_e( 'Filter by why something failed (retryable vs. missing local file), select rows to ignore/unignore in bulk, or export everything to CSV for offline review. Once you have fixed the underlying issue, go to Migration → Retry failed to re-queue them.', 'kazcode-universal-storage' ); ?>"
			>
				<div class="s3ms-card__head">
					<div>
						<h2 class="s3ms-card__title"><?php esc_html_e( 'Failed items', 'kazcode-universal-storage' ); ?></h2>
						<p class="s3ms-card__intro"><?php esc_html_e( 'Inspect failures, ignore permanently missing locals, export CSV, then retry from Migration.', 'kazcode-universal-storage' ); ?></p>
					</div>
				</div>
				<div class="s3ms-card__body">
					<p>
						<label>
							<?php esc_html_e( 'Filter', 'kazcode-universal-storage' ); ?>
							<select id="s3ms-failed-filter">
								<option value="all"><?php esc_html_e( 'All failed', 'kazcode-universal-storage' ); ?></option>
								<option value="retryable"><?php esc_html_e( 'Retryable (local present)', 'kazcode-universal-storage' ); ?></option>
								<option value="missing_local"><?php esc_html_e( 'Missing local file', 'kazcode-universal-storage' ); ?></option>
								<option value="ignored"><?php esc_html_e( 'Ignored', 'kazcode-universal-storage' ); ?></option>
							</select>
						</label>
						<button type="button" class="button" id="s3ms-failed-refresh"><?php esc_html_e( 'Refresh', 'kazcode-universal-storage' ); ?></button>
						<a class="button" href="#" id="s3ms-failed-export"><?php esc_html_e( 'Export CSV', 'kazcode-universal-storage' ); ?></a>
						<button type="button" class="button" id="s3ms-failed-ignore-selected"><?php esc_html_e( 'Ignore selected', 'kazcode-universal-storage' ); ?></button>
						<button type="button" class="button" id="s3ms-failed-unignore-selected"><?php esc_html_e( 'Unignore selected', 'kazcode-universal-storage' ); ?></button>
					</p>
					<table class="widefat striped s3ms-table" id="s3ms-failed-table">
						<thead>
							<tr>
								<th style="width:28px"><input type="checkbox" id="s3ms-failed-check-all" /></th>
								<th><?php esc_html_e( 'ID', 'kazcode-universal-storage' ); ?></th>
								<th><?php esc_html_e( 'Title', 'kazcode-universal-storage' ); ?></th>
								<th><?php esc_html_e( 'File', 'kazcode-universal-storage' ); ?></th>
								<th><?php esc_html_e( 'Error', 'kazcode-universal-storage' ); ?></th>
								<th><?php esc_html_e( 'Flags', 'kazcode-universal-storage' ); ?></th>
							</tr>
						</thead>
						<tbody id="s3ms-failed-tbody">
							<tr><td colspan="6"><?php esc_html_e( 'Loading…', 'kazcode-universal-storage' ); ?></td></tr>
						</tbody>
					</table>
					<p id="s3ms-failed-meta" class="description"></p>
				</div>
			</section>
			<?php AdminLayout::footer(); ?>
		</div>
		<?php
	}
}
