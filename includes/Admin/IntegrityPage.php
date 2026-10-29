<?php
/**
 * Integrity Center admin shell (#27).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Admin;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\Features;
use Kazcode\WpStorage\Services\DeliveryDriftService;
use Kazcode\WpStorage\Services\FailedItemsService;
use Kazcode\WpStorage\Services\IntegrityVerifyService;
use Kazcode\WpStorage\Services\ObjectStatsAggregator;

/**
 * Single Free recovery entry point (docs/architecture/MEDIA-INTEGRITY-CENTER.md §4).
 *
 * Opening the page only reads existing summaries (ObjectStatsAggregator,
 * FailedItemsService) and makes no remote call. Verify and Repair (#28) run
 * through IntegrityActions: a POSTed dry run first, then an apply limited to
 * that dry run's rows. Rebuild / Restore still point at today's entry points.
 */
final class IntegrityPage {

	public const TABS = array( 'overview', 'verify', 'repair', 'drift', 'policy', 'rebuild', 'restore', 'orphans', 'history' );

	/** @var callable():array<string,mixed> */
	private $stats;
	/** @var callable():int */
	private $needs_attention;
	private IntegrityActions $actions;

	/**
	 * @param (callable():array<string,mixed>)|null $stats           Cached object stats.
	 * @param (callable():int)|null                 $needs_attention Failed/partial attachment count.
	 */
	public function __construct( ?callable $stats = null, ?callable $needs_attention = null, ?IntegrityActions $actions = null ) {
		$this->actions         = $actions ?? new IntegrityActions();
		$this->stats           = $stats ?? static fn(): array => ( new ObjectStatsAggregator() )->get();
		$this->needs_attention = $needs_attention ?? static fn(): int => (int) ( ( new FailedItemsService() )->list( 1, 1, 'all' )['total'] ?? 0 );
	}

	public static function url( string $tab = 'overview' ): string {
		return admin_url( 'admin.php?page=' . AdminMenu::INTEGRITY_SLUG . ( $tab !== 'overview' ? '&tab=' . rawurlencode( $tab ) : '' ) );
	}

	public function current_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection.
		return in_array( $tab, self::TABS, true ) ? $tab : 'overview';
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab = $this->current_tab();
		?>
		<div class="wrap s3ms-wrap" data-integrity-center>
			<?php
			AdminLayout::brand_header();
			AdminLayout::header(
				__( 'Integrity', 'kazcode-universal-storage' ),
				__( 'One place to check that every media file is where WordPress expects it, and to recover safely when it is not. Every recovery starts with a dry run.', 'kazcode-universal-storage' ),
				'shield'
			);
			AdminLayout::subnav( AdminMenu::INTEGRITY_SLUG );
			?>
			<?php AdminLayout::tour_replay_button( 2 ); ?>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Integrity sections', 'kazcode-universal-storage' ); ?>">
				<?php foreach ( $this->tab_labels() as $key => $label ) : ?>
					<a class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::url( $key ) ); ?>" data-integrity-tab="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<section class="s3ms-card" data-integrity-panel="<?php echo esc_attr( $tab ); ?>"
				data-s3ms-tour-step="1"
				data-s3ms-tour-title="<?php esc_attr_e( 'Check and recover your media safely', 'kazcode-universal-storage' ); ?>"
				data-s3ms-tour-text="<?php esc_attr_e( 'Use these sections to verify stored objects, review delivery drift, and start recovery from a dry run. Opening this page never changes storage.', 'kazcode-universal-storage' ); ?>">
				<?php
				if ( $tab === 'overview' ) {
					$this->render_overview();
				} elseif ( $tab === 'verify' ) {
					$this->render_verify();
				} elseif ( $tab === 'repair' ) {
					$this->render_repair();
				} elseif ( $tab === 'drift' ) {
					$this->render_drift();
				} elseif ( $tab === 'policy' ) {
					$this->render_policy();
				} else {
					$this->render_panel( $tab );
				}
				?>
			</section>
			<?php AdminLayout::footer(); ?>
		</div>
		<?php
	}

	/**
	 * @return array<string,string>
	 */
	private function tab_labels(): array {
		return array(
			'overview' => __( 'Overview', 'kazcode-universal-storage' ),
			'verify'   => __( 'Verify', 'kazcode-universal-storage' ),
			'repair'   => __( 'Repair / Retry', 'kazcode-universal-storage' ),
			'drift'    => __( 'Delivery drift', 'kazcode-universal-storage' ),
			'policy'   => __( 'Policy', 'kazcode-universal-storage' ),
			'rebuild'  => __( 'Rebuild inventory', 'kazcode-universal-storage' ),
			'restore'  => __( 'Restore preflight', 'kazcode-universal-storage' ),
			'orphans'  => __( 'Orphans', 'kazcode-universal-storage' ),
			'history'  => __( 'History', 'kazcode-universal-storage' ),
		);
	}

	private function render_overview(): void {
		$stats     = ( $this->stats )();
		$attention = ( $this->needs_attention )();
		$cards     = array(
			'total_objects' => __( 'Tracked files', 'kazcode-universal-storage' ),
			'present'       => __( 'In storage', 'kazcode-universal-storage' ),
			'pending'       => __( 'Uploading / pending', 'kazcode-universal-storage' ),
			'failed'        => __( 'Failed', 'kazcode-universal-storage' ),
			'missing'       => __( 'Confirmed missing', 'kazcode-universal-storage' ),
			'stale'         => __( 'Superseded (stale)', 'kazcode-universal-storage' ),
		);
		?>
		<div class="s3ms-card__head"><div>
			<h2 class="s3ms-card__title"><?php esc_html_e( 'Overview', 'kazcode-universal-storage' ); ?></h2>
			<p class="s3ms-card__intro"><?php esc_html_e( 'Counts come from the existing object inventory summary; nothing is contacted or changed by opening this page.', 'kazcode-universal-storage' ); ?></p>
		</div></div>
		<div class="s3ms-card__body">
			<div class="s3ms-status-grid s3ms-status-grid--compact">
				<?php foreach ( $cards as $key => $label ) : ?>
					<div class="s3ms-stat is-info" data-integrity-stat="<?php echo esc_attr( $key ); ?>">
						<span class="s3ms-stat__label"><?php echo esc_html( $label ); ?></span>
						<strong class="s3ms-stat__value"><?php echo esc_html( (string) (int) ( $stats[ $key ] ?? 0 ) ); ?></strong>
					</div>
				<?php endforeach; ?>
			</div>
			<h3><?php esc_html_e( 'Needs attention', 'kazcode-universal-storage' ); ?></h3>
			<p data-integrity-needs-attention="<?php echo esc_attr( (string) $attention ); ?>">
				<?php
				if ( $attention > 0 ) {
					printf(
						/* translators: %d: number of attachments */
						esc_html( _n( '%d attachment failed or is only partially in storage.', '%d attachments failed or are only partially in storage.', $attention, 'kazcode-universal-storage' ) ),
						(int) $attention
					);
					echo ' <a href="' . esc_url( admin_url( 'admin.php?page=' . AdminMenu::MEDIA_SLUG ) ) . '">' . esc_html__( 'Review failed items', 'kazcode-universal-storage' ) . '</a>';
				} else {
					esc_html_e( 'Nothing needs attention right now.', 'kazcode-universal-storage' );
				}
				?>
			</p>
		</div>
		<?php
	}

	private function render_panel( string $tab ): void {
		$panels = array(
			'rebuild' => array(
				__( 'Rebuild the object inventory from what storage actually holds (HEAD only, never re-uploads). Use after restoring a database backup or adopting an existing bucket.', 'kazcode-universal-storage' ),
				'wp universal-storage rebuild-inventory --attachment-id=<id> --dry-run',
				AdminMenu::MIGRATION_SLUG,
			),
			'restore' => array(
				__( 'Before bringing files back to this server, check disk space and remote state. The preflight returns SAFE, WARNING or BLOCKED and changes nothing.', 'kazcode-universal-storage' ),
				'wp universal-storage restore --preflight',
				AdminMenu::MIGRATION_SLUG,
			),
			'history' => array(
				__( 'Recovery runs are recorded in the activity log.', 'kazcode-universal-storage' ),
				'',
				AdminMenu::LOGS_SLUG,
			),
		);
		$labels = $this->tab_labels();
		?>
		<div class="s3ms-card__head"><div>
			<h2 class="s3ms-card__title"><?php echo esc_html( $labels[ $tab ] ); ?></h2>
		</div></div>
		<div class="s3ms-card__body">
			<?php if ( $tab === 'orphans' ) : ?>
				<?php if ( Features::enabled( 'orphan_scan' ) ) : ?>
					<p><?php esc_html_e( 'Report-only: lists objects in your bucket prefix that no attachment owns. Nothing is ever deleted automatically.', 'kazcode-universal-storage' ); ?></p>
					<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminMenu::HEALTH_SLUG ) ); ?>"><?php esc_html_e( 'Run the orphan scan in Health', 'kazcode-universal-storage' ); ?></a></p>
				<?php else : ?>
					<p data-integrity-pro-gated><?php esc_html_e( 'Orphan reporting is part of Universal Storage Pro. Free keeps every other Integrity check.', 'kazcode-universal-storage' ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<?php [ $intro, $cli, $where ] = $panels[ $tab ]; ?>
				<p><?php echo esc_html( $intro ); ?></p>
				<p><?php esc_html_e( 'This panel is being moved here; until then use:', 'kazcode-universal-storage' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $where ) ); ?>"><?php echo esc_html( $this->page_label( $where ) ); ?></a>
					<?php if ( $cli !== '' ) : ?>
						<?php esc_html_e( 'or WP-CLI', 'kazcode-universal-storage' ); ?> <code><?php echo esc_html( $cli ); ?></code>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_verify(): void {
		$state = $this->actions->load( 'verify', get_current_user_id() );
		?>
		<div class="s3ms-card__head"><div>
			<h2 class="s3ms-card__title"><?php esc_html_e( 'Verify', 'kazcode-universal-storage' ); ?></h2>
			<p class="s3ms-card__intro"><?php esc_html_e( 'Checks each inventoried file on its own storage profile and compares the remote size with the local file (or, when the file is offloaded only, with the recorded size). The dry run changes nothing. Apply records only confirmed results — a timeout or permission error is never recorded as missing, and recorded sizes are never overwritten.', 'kazcode-universal-storage' ); ?></p>
		</div></div>
		<div class="s3ms-card__body">
			<?php $this->render_state_notices( $state ); ?>
			<?php if ( isset( $state['applied'] ) ) : ?>
				<p class="notice notice-success inline" data-integrity-applied="verify">
					<?php
					printf(
						/* translators: 1: applied rows, 2: unchanged rows, 3: skipped rows */
						esc_html__( 'Applied %1$d change(s); %2$d unchanged on re-check; %3$d skipped.', 'kazcode-universal-storage' ),
						(int) $state['applied']['applied'],
						(int) $state['applied']['unchanged'],
						(int) $state['applied']['skipped']
					);
					?>
				</p>
			<?php endif; ?>
			<?php $this->action_form( IntegrityActions::VERIFY_ACTION, 'dry_run', 0, __( 'Run verify dry run', 'kazcode-universal-storage' ), 'button-secondary' ); ?>
			<?php
			if ( isset( $state['report'] ) ) {
				$report  = $state['report'];
				$changes = count( array_filter( (array) $report['items'], static fn( array $item ): bool => ! empty( $item['change'] ) ) );
				?>
				<div data-integrity-report="verify" data-integrity-changes="<?php echo esc_attr( (string) $changes ); ?>">
					<h3><?php esc_html_e( 'Dry run result', 'kazcode-universal-storage' ); ?></h3>
					<ul class="s3ms-inline-list">
						<?php foreach ( (array) $report['counts'] as $outcome => $count ) : ?>
							<li data-integrity-outcome-count="<?php echo esc_attr( (string) $outcome ); ?>"><?php echo esc_html( $this->outcome_label( (string) $outcome ) . ': ' . (int) $count ); ?></li>
						<?php endforeach; ?>
					</ul>
					<?php
					$this->render_rows(
						(array) $report['items'],
						array(
							'outcome' => __( 'Result', 'kazcode-universal-storage' ),
							'change'  => __( 'Apply would', 'kazcode-universal-storage' ),
						)
					);
					if ( ! empty( $report['has_more'] ) ) {
						$this->action_form( IntegrityActions::VERIFY_ACTION, 'dry_run', (int) $report['next_after_id'], __( 'Dry run the next page', 'kazcode-universal-storage' ), 'button-secondary' );
					}
					if ( $changes > 0 ) {
						/* translators: %d: number of inventory rows */
						$this->action_form( IntegrityActions::VERIFY_ACTION, 'apply', 0, sprintf( _n( 'Apply %d confirmed change', 'Apply %d confirmed changes', $changes, 'kazcode-universal-storage' ), $changes ), 'button-primary' );
					} else {
						echo '<p data-integrity-nothing-to-apply>' . esc_html__( 'Nothing to apply: inventory already matches every confirmed result.', 'kazcode-universal-storage' ) . '</p>';
					}
					?>
				</div>
				<?php
			}
			?>
		</div>
		<?php
	}

	private function render_repair(): void {
		$state = $this->actions->load( 'repair', get_current_user_id() );
		?>
		<div class="s3ms-card__head"><div>
			<h2 class="s3ms-card__title"><?php esc_html_e( 'Repair / Retry', 'kazcode-universal-storage' ); ?></h2>
			<p class="s3ms-card__intro"><?php esc_html_e( 'Re-uploads files that failed, are recorded as missing or were never verified — only when the local copy still exists, to the storage profile each file belongs to, and only marks them present after a size-verified check. Nothing is deleted.', 'kazcode-universal-storage' ); ?></p>
		</div></div>
		<div class="s3ms-card__body">
			<?php $this->render_state_notices( $state ); ?>
			<?php if ( isset( $state['applied'] ) ) : ?>
				<p class="notice notice-success inline" data-integrity-applied="repair">
					<?php
					printf(
						/* translators: 1: repaired files, 2: failed files */
						esc_html__( 'Repaired %1$d file(s); %2$d failed.', 'kazcode-universal-storage' ),
						(int) $state['applied']['repaired'],
						(int) $state['applied']['failed']
					);
					?>
				</p>
				<?php $this->render_rows( (array) $state['applied']['results'], array( 'message' => __( 'Result', 'kazcode-universal-storage' ) ) ); ?>
			<?php endif; ?>
			<?php $this->action_form( IntegrityActions::REPAIR_ACTION, 'dry_run', 0, __( 'Find repairable files (dry run)', 'kazcode-universal-storage' ), 'button-secondary' ); ?>
			<?php
			if ( isset( $state['report'] ) ) {
				$report = $state['report'];
				$count  = count( (array) $report['items'] );
				?>
				<div data-integrity-report="repair" data-integrity-changes="<?php echo esc_attr( (string) $count ); ?>">
					<h3><?php esc_html_e( 'Dry run result', 'kazcode-universal-storage' ); ?></h3>
					<?php
					$this->render_rows( (array) $report['items'], array( 'health' => __( 'State', 'kazcode-universal-storage' ) ) );
					if ( ! empty( $report['has_more'] ) ) {
						$this->action_form( IntegrityActions::REPAIR_ACTION, 'dry_run', (int) $report['next_after_id'], __( 'Dry run the next page', 'kazcode-universal-storage' ), 'button-secondary' );
					}
					if ( $count > 0 ) {
						/* translators: %d: number of files */
						$this->action_form( IntegrityActions::REPAIR_ACTION, 'apply', 0, sprintf( _n( 'Repair %d file', 'Repair %d files', $count, 'kazcode-universal-storage' ), $count ), 'button-primary' );
					} else {
						echo '<p data-integrity-nothing-to-apply>' . esc_html__( 'No repairable files on this page.', 'kazcode-universal-storage' ) . '</p>';
					}
					?>
				</div>
				<?php
			}
			?>
		</div>
		<?php
	}

	private function render_drift(): void {
		$state = $this->actions->load( 'drift', get_current_user_id() );
		?>
		<div class="s3ms-card__head"><div>
			<h2 class="s3ms-card__title"><?php esc_html_e( 'Delivery drift', 'kazcode-universal-storage' ); ?></h2>
			<p class="s3ms-card__intro"><?php esc_html_e( 'Compares what WordPress references (attachment metadata and post content) with the inventory, the files on this server and where delivery points. Report only: it contacts no storage, changes nothing and never rewrites content.', 'kazcode-universal-storage' ); ?></p>
		</div></div>
		<div class="s3ms-card__body">
			<?php $this->action_form( IntegrityActions::DRIFT_ACTION, 'dry_run', 0, __( 'Scan for delivery drift', 'kazcode-universal-storage' ), 'button-secondary' ); ?>
			<?php
			if ( isset( $state['report'] ) ) {
				$report   = $state['report'];
				$findings = (array) $report['findings'];
				?>
				<div data-integrity-report="drift" data-integrity-findings="<?php echo esc_attr( (string) count( $findings ) ); ?>">
					<h3><?php esc_html_e( 'Scan result', 'kazcode-universal-storage' ); ?></h3>
					<p>
						<?php
						printf(
							/* translators: 1: attachments scanned, 2: storage findings, 3: delivery findings, 4: reference findings */
							esc_html__( '%1$d attachment(s) scanned — storage: %2$d, delivery: %3$d, content references: %4$d.', 'kazcode-universal-storage' ),
							(int) $report['scanned'],
							(int) ( $report['counts']['storage'] ?? 0 ),
							(int) ( $report['counts']['delivery'] ?? 0 ),
							(int) ( $report['counts']['reference'] ?? 0 )
						);
						?>
					</p>
					<?php if ( $findings === array() ) : ?>
						<p data-integrity-nothing-to-apply><?php esc_html_e( 'No drift found on this page.', 'kazcode-universal-storage' ); ?></p>
					<?php else : ?>
						<div class="s3ms-table-wrap"><table class="widefat striped">
							<thead><tr>
								<th><?php esc_html_e( 'Attachment', 'kazcode-universal-storage' ); ?></th>
								<th><?php esc_html_e( 'File', 'kazcode-universal-storage' ); ?></th>
								<th><?php esc_html_e( 'Finding', 'kazcode-universal-storage' ); ?></th>
								<th><?php esc_html_e( 'Details', 'kazcode-universal-storage' ); ?></th>
								<th><?php esc_html_e( 'Next step', 'kazcode-universal-storage' ); ?></th>
							</tr></thead>
							<tbody>
								<?php foreach ( array_slice( $findings, 0, 200 ) as $finding ) : ?>
									<tr data-drift-finding="<?php echo esc_attr( (string) $finding['finding'] ); ?>" data-drift-attachment="<?php echo esc_attr( (string) (int) $finding['attachment_id'] ); ?>">
										<td><a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $finding['attachment_id'] . '&action=edit' ) ); ?>">#<?php echo esc_html( (string) (int) $finding['attachment_id'] ); ?></a></td>
										<td><code><?php echo esc_html( (string) $finding['relative'] ); ?></code>
											<?php if ( ! empty( $finding['storage_profile_id'] ) ) : ?>
												<br><small><?php echo esc_html( sprintf( /* translators: %d: storage profile id */ __( 'profile %d', 'kazcode-universal-storage' ), (int) $finding['storage_profile_id'] ) ); ?></small>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html( $this->drift_label( (string) $finding['finding'] ) ); ?><br><small data-drift-category><?php echo esc_html( (string) $finding['category'] ); ?></small></td>
										<td><?php echo esc_html( (string) $finding['detail'] ); ?>
											<?php foreach ( (array) $finding['post_ids'] as $post_id ) : ?>
												<a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' ) ); ?>" data-drift-post="<?php echo esc_attr( (string) (int) $post_id ); ?>">#<?php echo esc_html( (string) (int) $post_id ); ?></a>
											<?php endforeach; ?>
										</td>
										<td><?php echo esc_html( (string) $finding['next_action'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table></div>
					<?php endif; ?>
					<?php
					if ( ! empty( $report['has_more'] ) ) {
						$this->action_form( IntegrityActions::DRIFT_ACTION, 'dry_run', (int) $report['next_after_id'], __( 'Scan the next page', 'kazcode-universal-storage' ), 'button-secondary' );
					}
					?>
				</div>
				<?php
			}
			?>
		</div>
		<?php
	}

	private function render_policy(): void {
		$state = $this->actions->load( 'policy', get_current_user_id() );
		?>
		<div class="s3ms-card__head"><div>
			<h2 class="s3ms-card__title"><?php esc_html_e( 'Policy', 'kazcode-universal-storage' ); ?></h2>
			<p class="s3ms-card__intro"><?php esc_html_e( 'Compares the files on this server with your local file policy (Settings). The dry run reads the database and the server only. Apply is limited to the rows that dry run marked repairable: it downloads a missing file back from its own storage profile (size-checked), or runs the guarded local cleanup, which still deletes only after every current file is verified and delivered from storage.', 'kazcode-universal-storage' ); ?></p>
		</div></div>
		<div class="s3ms-card__body">
			<?php $this->render_state_notices( $state ); ?>
			<?php if ( isset( $state['applied'] ) ) : ?>
				<p class="notice notice-success inline" data-integrity-applied="policy">
					<?php
					printf(
						/* translators: 1: applied repairs, 2: skipped, 3: failed */
						esc_html__( 'Applied %1$d repair(s); %2$d skipped; %3$d failed.', 'kazcode-universal-storage' ),
						(int) $state['applied']['applied'],
						(int) $state['applied']['skipped'],
						(int) $state['applied']['failed']
					);
					?>
				</p>
				<?php $this->render_rows( array_map( static fn( array $r ): array => array( 'object_id' => (int) $r['attachment_id'], 'relative' => (string) $r['relative'], 'message' => (string) $r['message'], 'success' => $r['result'] === 'applied' ), (array) $state['applied']['results'] ), array( 'message' => __( 'Result', 'kazcode-universal-storage' ) ) ); ?>
			<?php endif; ?>
			<?php $this->action_form( IntegrityActions::POLICY_ACTION, 'dry_run', 0, __( 'Check policy (dry run)', 'kazcode-universal-storage' ), 'button-secondary' ); ?>
			<?php
			if ( isset( $state['report'] ) ) {
				$report     = $state['report'];
				$findings   = (array) $report['findings'];
				$repairable = count( array_filter( $findings, static fn( array $f ): bool => ! empty( $f['repairable'] ) ) );
				?>
				<div data-integrity-report="policy" data-integrity-changes="<?php echo esc_attr( (string) $repairable ); ?>">
					<h3><?php esc_html_e( 'Dry run result', 'kazcode-universal-storage' ); ?></h3>
					<p data-policy-name="<?php echo esc_attr( (string) $report['policy'] ); ?>">
						<?php
						printf(
							/* translators: 1: policy key, 2: attachments scanned */
							esc_html__( 'Policy: %1$s — %2$d attachment(s) checked.', 'kazcode-universal-storage' ),
							esc_html( (string) $report['policy'] ),
							(int) $report['scanned']
						);
						?>
					</p>
					<?php foreach ( (array) $report['site'] as $site ) : ?>
						<p class="notice notice-warning inline" data-policy-site="<?php echo esc_attr( (string) $site['finding'] ); ?>"><?php echo esc_html( (string) $site['detail'] . ' ' . (string) $site['next_action'] ); ?></p>
					<?php endforeach; ?>
					<?php if ( $findings === array() ) : ?>
						<p data-integrity-nothing-to-apply><?php esc_html_e( 'Files on this page match the policy.', 'kazcode-universal-storage' ); ?></p>
					<?php else : ?>
						<div class="s3ms-table-wrap"><table class="widefat striped">
							<thead><tr>
								<th><?php esc_html_e( 'Attachment', 'kazcode-universal-storage' ); ?></th>
								<th><?php esc_html_e( 'File', 'kazcode-universal-storage' ); ?></th>
								<th><?php esc_html_e( 'Finding', 'kazcode-universal-storage' ); ?></th>
								<th><?php esc_html_e( 'Next step', 'kazcode-universal-storage' ); ?></th>
							</tr></thead>
							<tbody>
								<?php foreach ( array_slice( $findings, 0, 200 ) as $finding ) : ?>
									<tr data-policy-finding="<?php echo esc_attr( (string) $finding['finding'] ); ?>" data-policy-attachment="<?php echo esc_attr( (string) (int) $finding['attachment_id'] ); ?>" data-policy-repairable="<?php echo ! empty( $finding['repairable'] ) ? '1' : '0'; ?>">
										<td>#<?php echo esc_html( (string) (int) $finding['attachment_id'] ); ?></td>
										<td><code><?php echo esc_html( (string) $finding['relative'] ); ?></code></td>
										<td><?php echo esc_html( (string) $finding['detail'] ); ?></td>
										<td><?php echo esc_html( (string) $finding['next_action'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table></div>
					<?php endif; ?>
					<?php
					if ( ! empty( $report['has_more'] ) ) {
						$this->action_form( IntegrityActions::POLICY_ACTION, 'dry_run', (int) $report['next_after_id'], __( 'Dry run the next page', 'kazcode-universal-storage' ), 'button-secondary' );
					}
					if ( $repairable > 0 ) {
						/* translators: %d: number of repairable findings */
						$this->action_form( IntegrityActions::POLICY_ACTION, 'apply', 0, sprintf( _n( 'Apply %d repair', 'Apply %d repairs', $repairable, 'kazcode-universal-storage' ), $repairable ), 'button-primary' );
					}
					?>
				</div>
				<?php
			}
			?>
		</div>
		<?php
	}

	private function drift_label( string $finding ): string {
		return match ( $finding ) {
			DeliveryDriftService::ARTIFACT_UNAVAILABLE      => __( 'File unavailable', 'kazcode-universal-storage' ),
			DeliveryDriftService::LOCAL_404_RISK            => __( 'Delivered from a missing local file', 'kazcode-universal-storage' ),
			DeliveryDriftService::OWNERSHIP_AMBIGUOUS       => __( 'Claimed by two profiles', 'kazcode-universal-storage' ),
			DeliveryDriftService::DELIVERED_FROM_SUPERSEDED => __( 'Delivered from a superseded record', 'kazcode-universal-storage' ),
			DeliveryDriftService::STALE_VARIANT_REFERENCED  => __( 'Old version still used in content', 'kazcode-universal-storage' ),
			DeliveryDriftService::LOCAL_URL_IN_CONTENT      => __( 'Content links a file no longer on this server', 'kazcode-universal-storage' ),
			default                                         => $finding,
		};
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function render_state_notices( array $state ): void {
		if ( isset( $state['error'] ) ) {
			echo '<p class="notice notice-warning inline" data-integrity-error>' . esc_html( (string) $state['error'] ) . '</p>';
		}
	}

	private function action_form( string $action, string $mode, int $after_id, string $label, string $class ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="s3ms-inline-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="mode" value="<?php echo esc_attr( $mode ); ?>">
			<input type="hidden" name="after_id" value="<?php echo esc_attr( (string) $after_id ); ?>">
			<?php wp_nonce_field( $action ); ?>
			<p><button type="submit" class="button <?php echo esc_attr( $class ); ?>" data-integrity-<?php echo esc_attr( $mode === 'apply' ? 'apply' : 'dry-run' ); ?>><?php echo esc_html( $label ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @param array<string, string>      $extra Column key => heading.
	 */
	private function render_rows( array $rows, array $extra ): void {
		if ( $rows === array() ) {
			return;
		}
		?>
		<div class="s3ms-table-wrap"><table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Object', 'kazcode-universal-storage' ); ?></th>
				<th><?php esc_html_e( 'File', 'kazcode-universal-storage' ); ?></th>
				<?php foreach ( $extra as $heading ) : ?>
					<th><?php echo esc_html( $heading ); ?></th>
				<?php endforeach; ?>
			</tr></thead>
			<tbody>
				<?php foreach ( array_slice( $rows, 0, 200 ) as $row ) : ?>
					<tr data-integrity-row="<?php echo esc_attr( (string) (int) ( $row['object_id'] ?? 0 ) ); ?>">
						<td>#<?php echo esc_html( (string) (int) ( $row['object_id'] ?? 0 ) ); ?></td>
						<td><code><?php echo esc_html( (string) ( $row['relative'] ?? '' ) ); ?></code></td>
						<?php foreach ( array_keys( $extra ) as $key ) : ?>
							<td data-integrity-col="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $this->cell( $key, $row ) ); ?></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table></div>
		<?php
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function cell( string $key, array $row ): string {
		$value = (string) ( $row[ $key ] ?? '' );
		return match ( $key ) {
			'outcome' => $this->outcome_label( $value ) . ( ! empty( $row['error_class'] ) ? ' (' . (string) $row['error_class'] . ')' : '' ),
			'change'  => match ( $value ) {
				IntegrityVerifyService::CHANGE_MARK_PRESENT => __( 'mark present', 'kazcode-universal-storage' ),
				IntegrityVerifyService::CHANGE_MARK_MISSING => __( 'mark missing', 'kazcode-universal-storage' ),
				default                                     => '—',
			},
			'message' => ( ! empty( $row['success'] ) ? __( 'Repaired', 'kazcode-universal-storage' ) : __( 'Failed', 'kazcode-universal-storage' ) ) . ( $value !== '' ? ': ' . $value : '' ),
			default   => $value,
		};
	}

	private function outcome_label( string $outcome ): string {
		return match ( $outcome ) {
			IntegrityVerifyService::VERIFIED      => __( 'Verified (size matches)', 'kazcode-universal-storage' ),
			IntegrityVerifyService::EXISTS_ONLY   => __( 'Exists (size unknown)', 'kazcode-universal-storage' ),
			IntegrityVerifyService::SIZE_CONFLICT => __( 'Size conflict', 'kazcode-universal-storage' ),
			IntegrityVerifyService::MISSING       => __( 'Missing (404)', 'kazcode-universal-storage' ),
			IntegrityVerifyService::UNKNOWN       => __( 'Could not check', 'kazcode-universal-storage' ),
			IntegrityVerifyService::UNRESOLVED    => __( 'Profile unresolved', 'kazcode-universal-storage' ),
			default                               => $outcome,
		};
	}

	private function page_label( string $slug ): string {
		return match ( $slug ) {
			AdminMenu::MEDIA_SLUG     => __( 'Universal Storage → Media', 'kazcode-universal-storage' ),
			AdminMenu::MIGRATION_SLUG => __( 'Universal Storage → Migration', 'kazcode-universal-storage' ),
			AdminMenu::LOGS_SLUG      => __( 'Universal Storage → Logs', 'kazcode-universal-storage' ),
			default                   => __( 'Universal Storage', 'kazcode-universal-storage' ),
		};
	}
}
