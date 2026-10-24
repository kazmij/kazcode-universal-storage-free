<?php
/**
 * Safe Mode admin notice and explicit operator acknowledgements (#61).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Admin;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\SafeMode;
use Kazcode\WpStorage\Core\SiteIdentity;
use Kazcode\WpStorage\Services\AuditLog;

/**
 * Explains why storage writes are paused and offers the only three ways out,
 * each audited: rebind (same site, moved), become independent (a separate
 * copy, which must then use its own prefix), or take over a namespace whose
 * previous owner no longer writes to it.
 */
final class SafeModeNotice {

	public const ACTION = 'kazus_safe_mode';

	public function __construct( private ?AuditLog $audit = null ) {
	}

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$block = SafeMode::last_block();
		if ( $block === null ) {
			return;
		}
		$identity = in_array( $block['reason'], array( 'site_url_changed', 'environment_changed' ), true );
		?>
		<div class="notice notice-error" data-safe-mode="<?php echo esc_attr( $block['reason'] ); ?>">
			<p><strong><?php esc_html_e( 'Universal Storage Safe Mode: writes to remote storage are paused.', 'kazcode-universal-storage' ); ?></strong></p>
			<p><?php echo esc_html( $block['detail'] ); ?></p>
			<p><?php esc_html_e( 'Media already in storage keeps being delivered. Uploads stay on this server, and nothing in storage is overwritten or deleted, until you confirm what this site is.', 'kazcode-universal-storage' ); ?></p>
			<?php if ( $identity ) : ?>
				<?php $this->form( 'rebind', __( 'This is the same site (moved or renamed) — resume', 'kazcode-universal-storage' ), 'button-primary' ); ?>
				<?php $this->form( 'independent', __( 'This is a separate copy — keep it apart', 'kazcode-universal-storage' ), 'button-secondary' ); ?>
				<p class="description"><?php esc_html_e( 'A separate copy (staging, test) gets its own identity and must use its own bucket prefix before it can write.', 'kazcode-universal-storage' ); ?></p>
			<?php elseif ( $block['reason'] === 'namespace_owned_elsewhere' ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<input type="hidden" name="decision" value="takeover">
					<input type="hidden" name="namespace" value="<?php echo esc_attr( (string) ( $block['namespace'] ?? '' ) ); ?>">
					<?php wp_nonce_field( self::ACTION ); ?>
					<p><label><input type="checkbox" name="confirm" value="1" required data-safe-mode-confirm> <?php esc_html_e( 'The other installation no longer writes to this bucket prefix.', 'kazcode-universal-storage' ); ?></label></p>
					<p><button type="submit" class="button" data-safe-mode-action="takeover"><?php esc_html_e( 'Take ownership of this prefix', 'kazcode-universal-storage' ); ?></button>
					<?php esc_html_e( 'Otherwise, set a different prefix for this site in Settings.', 'kazcode-universal-storage' ); ?></p>
				</form>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Check the storage credentials and permissions; writes resume automatically once the ownership marker can be read.', 'kazcode-universal-storage' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change Safe Mode.', 'kazcode-universal-storage' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( (string) $_POST['decision'] ) ) : '';
		$this->apply(
			$decision,
			isset( $_POST['namespace'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['namespace'] ) ) : '',
			! empty( $_POST['confirm'] )
		);
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=' . AdminMenu::DASHBOARD_SLUG ) );
		exit;
	}

	/**
	 * @return bool Whether the decision was applied.
	 */
	public function apply( string $decision, string $namespace, bool $confirmed ): bool {
		$before = SafeMode::last_block();
		switch ( $decision ) {
			case 'rebind':
				SiteIdentity::rebind();
				break;
			case 'independent':
				SiteIdentity::become_independent();
				break;
			case 'takeover':
				if ( ! $confirmed || $namespace === '' ) {
					return false;
				}
				SafeMode::allow_takeover( $namespace );
				break;
			default:
				return false;
		}
		SafeMode::clear_block();
		( $this->audit ?? new AuditLog() )->record(
			'safe_mode_' . $decision,
			array(
				'reason'    => (string) ( $before['reason'] ?? '' ),
				'namespace' => $decision === 'takeover' ? $namespace : '',
				'home'      => SiteIdentity::current_home(),
			)
		);
		return true;
	}

	private function form( string $decision, string $label, string $class ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="decision" value="<?php echo esc_attr( $decision ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<button type="submit" class="button <?php echo esc_attr( $class ); ?>" data-safe-mode-action="<?php echo esc_attr( $decision ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}
}
