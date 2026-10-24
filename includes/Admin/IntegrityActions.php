<?php
/**
 * Integrity Center Verify / Repair actions (#28): dry-run first, apply second.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Admin;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\SecretRedactor;
use Kazcode\WpStorage\Services\AuditLog;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Services\DeliveryDriftService;
use Kazcode\WpStorage\Services\PolicyReconciliationService;
use Kazcode\WpStorage\Services\HealthScanService;
use Kazcode\WpStorage\Services\IntegrityVerifyService;
use Kazcode\WpStorage\Services\RepairObjectService;

/**
 * Apply only ever acts on the rows listed by this user's own, recent dry-run,
 * stored server-side — never on ids posted by the browser — and the stored
 * dry-run is consumed by the apply, so each apply needs a fresh dry-run.
 */
final class IntegrityActions {

	public const VERIFY_ACTION = 'kazus_integrity_verify';
	public const REPAIR_ACTION = 'kazus_integrity_repair';
	public const DRIFT_ACTION  = 'kazus_integrity_drift';
	public const DRIFT_PAGE    = 50;
	public const POLICY_ACTION = 'kazus_integrity_policy';
	public const POLICY_PAGE   = 50;
	public const VERIFY_PAGE   = 50;
	public const REPAIR_PAGE   = 100;
	private const TTL          = 3600;

	/** @var callable():IntegrityVerifyService */
	private $verify;
	/** @var callable():HealthScanService */
	private $health;
	/** @var callable():RepairObjectService */
	private $repair;
	/** @var callable():DeliveryDriftService */
	private $drift;
	/** @var callable():PolicyReconciliationService */
	private $policy;

	public function __construct( ?callable $verify = null, ?callable $health = null, ?callable $repair = null, private ?AuditLog $audit = null, ?callable $drift = null, ?callable $policy = null ) {
		$this->policy = $policy ?? static fn(): PolicyReconciliationService => new PolicyReconciliationService( new Settings() );
		$this->drift  = $drift ?? static fn(): DeliveryDriftService => new DeliveryDriftService();
		$this->verify = $verify ?? static fn(): IntegrityVerifyService => new IntegrityVerifyService();
		$this->health = $health ?? static fn(): HealthScanService => new HealthScanService();
		$this->repair = $repair ?? static fn(): RepairObjectService => new RepairObjectService( \Kazcode\WpStorage\Plugin::instance()->storage() );
	}

	public function register(): void {
		add_action( 'admin_post_' . self::VERIFY_ACTION, array( $this, 'handle_verify' ) );
		add_action( 'admin_post_' . self::REPAIR_ACTION, array( $this, 'handle_repair' ) );
		add_action( 'admin_post_' . self::DRIFT_ACTION, array( $this, 'handle_drift' ) );
		add_action( 'admin_post_' . self::POLICY_ACTION, array( $this, 'handle_policy' ) );
	}

	public function handle_verify(): void {
		$this->guard( self::VERIFY_ACTION );
		$this->is_apply() ? $this->apply_verify( get_current_user_id() ) : $this->dry_run_verify( get_current_user_id(), $this->after_id() );
		$this->redirect( 'verify' );
	}

	public function handle_repair(): void {
		$this->guard( self::REPAIR_ACTION );
		$this->is_apply() ? $this->apply_repair( get_current_user_id() ) : $this->dry_run_repair( get_current_user_id(), $this->after_id() );
		$this->redirect( 'repair' );
	}

	/**
	 * Delivery drift is report-only (#55): there is no apply.
	 */
	public function handle_drift(): void {
		$this->guard( self::DRIFT_ACTION );
		$this->scan_drift( get_current_user_id(), $this->after_id() );
		$this->redirect( 'drift' );
	}

	public function scan_drift( int $user_id, int $after_id ): void {
		$report = ( $this->drift )()->scan_page( self::DRIFT_PAGE, $after_id );
		$this->store( 'drift', $user_id, array( 'report' => $report, 'after_id' => $after_id ) );
	}

	public function handle_policy(): void {
		$this->guard( self::POLICY_ACTION );
		$this->is_apply() ? $this->apply_policy( get_current_user_id() ) : $this->dry_run_policy( get_current_user_id(), $this->after_id() );
		$this->redirect( 'policy' );
	}

	public function dry_run_policy( int $user_id, int $after_id ): void {
		$report = ( $this->policy )()->scan_page( self::POLICY_PAGE, $after_id );
		$this->store( 'policy', $user_id, array( 'report' => $report, 'after_id' => $after_id ) );
	}

	public function apply_policy( int $user_id ): void {
		$state = $this->load( 'policy', $user_id );
		if ( ! isset( $state['report'] ) ) {
			$this->store( 'policy', $user_id, array( 'error' => 'Run a dry run before applying.' ) );
			return;
		}
		$result = ( $this->policy )()->apply( (array) $state['report']['findings'] );
		$this->store( 'policy', $user_id, array( 'applied' => $result ) );
	}

	public function dry_run_verify( int $user_id, int $after_id ): void {
		$report = ( $this->verify )()->observe_page( self::VERIFY_PAGE, $after_id );
		$this->store( 'verify', $user_id, array( 'report' => $report, 'after_id' => $after_id ) );
	}

	public function apply_verify( int $user_id ): void {
		$state = $this->load( 'verify', $user_id );
		if ( ! isset( $state['report'] ) ) {
			$this->store( 'verify', $user_id, array( 'error' => 'Run a dry run before applying.' ) );
			return;
		}
		$ids = array();
		foreach ( (array) $state['report']['items'] as $item ) {
			if ( ! empty( $item['change'] ) ) {
				$ids[] = (int) $item['object_id'];
			}
		}
		$result = ( $this->verify )()->apply( $ids );
		$this->store( 'verify', $user_id, array( 'applied' => $result ) );
	}

	public function dry_run_repair( int $user_id, int $after_id ): void {
		$report = ( $this->health )()->repairable_page( self::REPAIR_PAGE, $after_id );
		$this->store( 'repair', $user_id, array( 'report' => $report, 'after_id' => $after_id ) );
	}

	public function apply_repair( int $user_id ): void {
		$state = $this->load( 'repair', $user_id );
		if ( ! isset( $state['report'] ) ) {
			$this->store( 'repair', $user_id, array( 'error' => 'Run a dry run before applying.' ) );
			return;
		}
		$service = ( $this->repair )();
		$summary = array( 'repaired' => 0, 'failed' => 0, 'results' => array() );
		foreach ( (array) $state['report']['items'] as $item ) {
			$object_id = (int) $item['object_id'];
			try {
				$result = $service->repair( $object_id, false );
			} catch ( \Throwable $e ) {
				$result = array( 'success' => false, 'message' => $e->getMessage() );
			}
			$ok = ! empty( $result['success'] );
			++$summary[ $ok ? 'repaired' : 'failed' ];
			$summary['results'][] = array(
				'object_id' => $object_id,
				'relative'  => (string) ( $item['relative'] ?? '' ),
				'success'   => $ok,
				'message'   => SecretRedactor::redact( (string) ( $result['message'] ?? '' ), 200 ),
			);
		}
		( $this->audit ?? new AuditLog() )->record(
			'integrity_repair_apply',
			array(
				'requested' => count( $summary['results'] ),
				'repaired'  => $summary['repaired'],
				'failed'    => $summary['failed'],
			)
		);
		$this->store( 'repair', $user_id, array( 'applied' => $summary ) );
	}

	/**
	 * Last dry-run / apply state for the Integrity panels.
	 *
	 * @return array<string, mixed>
	 */
	public function load( string $kind, int $user_id ): array {
		$state = get_option( self::option( $kind, $user_id ), array() );
		if ( ! is_array( $state ) || (int) ( $state['at'] ?? 0 ) < time() - self::TTL ) {
			return array();
		}
		return $state;
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function store( string $kind, int $user_id, array $state ): void {
		$state['at'] = time();
		update_option( self::option( $kind, $user_id ), $state, false );
	}

	private static function option( string $kind, int $user_id ): string {
		return 'kazus_integrity_' . $kind . '_' . $user_id;
	}

	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to run integrity actions.', 'kazcode-universal-storage' ), 403 );
		}
		check_admin_referer( $action );
	}

	private function is_apply(): bool {
		return isset( $_POST['mode'] ) && sanitize_key( wp_unslash( (string) $_POST['mode'] ) ) === 'apply'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
	}

	private function after_id(): int {
		return isset( $_POST['after_id'] ) ? absint( wp_unslash( $_POST['after_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
	}

	private function redirect( string $tab ): void {
		wp_safe_redirect( IntegrityPage::url( $tab ) );
		exit;
	}
}
