<?php
/**
 * Background migration via WP-Cron tick loop.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Plugin;

/**
 * Persists a job cursor and advances it on cron.
 */
final class BackgroundMigrator {

	public const OPTION_KEY      = 's3ms_background_job';
	public const CRON_HOOK       = 'kazus_background_tick';
	public const TICK_LEASE_KEY  = 'kazus_background_tick_lease';
	private const TICK_LEASE_TTL = 120;

	private Settings $settings;
	private AuditLog $audit;

	public function __construct( Settings $settings, AuditLog $audit ) {
		$this->settings = $settings;
		$this->audit    = $audit;
	}

	/**
	 * Register cron handler.
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'schedules' ) );
	}

	/**
	 * @param array<string, array{interval:int,display:string}> $schedules Schedules.
	 * @return array<string, array{interval:int,display:string}>
	 */
	public function schedules( array $schedules ): array {
		if ( ! isset( $schedules['s3ms_minute'] ) ) {
			$schedules['s3ms_minute'] = array(
				'interval' => 60,
				'display'  => __( 'Every minute (KAZCODE Universal Storage)', 'kazcode-universal-storage' ),
			);
		}
		return $schedules;
	}

	/**
	 * Current job state.
	 *
	 * @return array<string, mixed>
	 */
	public function status(): array {
		$job = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $job ) ) {
			$job = array();
		}
		return array_merge(
			array(
				'running'        => false,
				'action'         => '',
				'after_id'       => 0,
				'processed'      => 0,
				'success'        => 0,
				'failed'         => 0,
				'started_at'     => '',
				'updated_at'     => '',
				'last_error'     => '',
				'finished_at'    => '',
			),
			$job
		);
	}

	/**
	 * Start a background job.
	 *
	 * @param string $action migrate|verify|retry|restore.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function start( string $action ) {
		$action = sanitize_key( $action );
		if ( ! in_array( $action, array( 'migrate', 'verify', 'retry', 'restore' ), true ) ) {
			return new \WP_Error( 's3ms_action', __( 'Invalid background action.', 'kazcode-universal-storage' ) );
		}

		$current = $this->status();
		if ( ! empty( $current['running'] ) ) {
			return new \WP_Error( 's3ms_busy', __( 'A background job is already running. Stop it first.', 'kazcode-universal-storage' ) );
		}

		$job = array(
			'running'     => true,
			'action'      => $action,
			'after_id'    => 0,
			'processed'   => 0,
			'success'     => 0,
			'failed'      => 0,
			'started_at'  => gmdate( 'c' ),
			'updated_at'  => gmdate( 'c' ),
			'last_error'  => '',
			'finished_at' => '',
		);
		update_option( self::OPTION_KEY, $job, false );
		$this->ensure_scheduled();
		$this->audit->record( 'background_start', array( 'action' => $action ) );
		// Run one tick soon.
		wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		spawn_cron();
		return $job;
	}

	/**
	 * Stop job.
	 */
	public function stop(): array {
		$job = $this->status();
		$job['running']     = false;
		$job['finished_at'] = gmdate( 'c' );
		$job['updated_at']  = gmdate( 'c' );
		update_option( self::OPTION_KEY, $job, false );
		$this->audit->record( 'background_stop', array( 'action' => $job['action'] ?? '' ) );
		return $job;
	}

	/**
	 * Cron tick — process one batch (legacy entry; prefer QueueGateway::run_next).
	 */
	public function tick(): void {
		$this->process_batch();
	}

	/**
	 * Advance running bulk job by one batch.
	 *
	 * @return bool True when work was performed.
	 */
	public function process_batch(): bool {
		if ( ! $this->acquire_tick_lease() ) {
			return false;
		}

		try {
			return $this->process_batch_with_lease();
		} finally {
			$this->release_tick_lease();
		}
	}

	/**
	 * Advance running bulk job by one batch (caller holds tick lease).
	 *
	 * @return bool True when work was performed.
	 */
	private function process_batch_with_lease(): bool {
		$job = $this->status();
		if ( empty( $job['running'] ) ) {
			return false;
		}

		$batch = max( 1, min( 50, (int) $this->settings->get( 'background_batch_size', 20 ) ) );
		$action = (string) ( $job['action'] ?? 'migrate' );
		$after  = (int) ( $job['after_id'] ?? 0 );
		$migration = Plugin::instance()->migration_service();

		try {
			if ( $action === 'migrate' ) {
				$result = $migration->migrate_batch( $batch, false, false, null, null, $after );
			} elseif ( $action === 'retry' ) {
				$result = $migration->migrate_batch( $batch, false, true, null, null, $after );
			} elseif ( $action === 'verify' ) {
				$result = $migration->verify_batch( $batch, null, $after );
				$result['success'] = (int) ( $result['processed'] ?? 0 );
				$result['failed']  = 0;
			} else {
				$result = $migration->restore_batch( $batch, null, $after );
			}
		} catch ( \Throwable $e ) {
			$job['last_error'] = $e->getMessage();
			$job['updated_at'] = gmdate( 'c' );
			update_option( self::OPTION_KEY, $job, false );
			$this->ensure_scheduled();
			return true;
		}

		$job['processed'] = (int) $job['processed'] + (int) ( $result['processed'] ?? 0 );
		$job['success']   = (int) $job['success'] + (int) ( $result['success'] ?? 0 );
		$job['failed']    = (int) $job['failed'] + (int) ( $result['failed'] ?? 0 );
		$job['after_id']  = (int) ( $result['next_after_id'] ?? $after );
		$job['updated_at'] = gmdate( 'c' );

		$processed_now = (int) ( $result['processed'] ?? 0 );
		if ( $processed_now === 0 ) {
			$job['running']     = false;
			$job['finished_at'] = gmdate( 'c' );
			$this->audit->record(
				'background_finished',
				array(
					'action'    => $action,
					'processed' => $job['processed'],
					'success'   => $job['success'],
					'failed'    => $job['failed'],
				)
			);
		}

		update_option( self::OPTION_KEY, $job, false );

		if ( ! empty( $job['running'] ) ) {
			$this->ensure_scheduled();
			wp_schedule_single_event( time() + 15, self::CRON_HOOK );
		}

		return $processed_now > 0;
	}

	/**
	 * Ensure recurring schedule exists while jobs may run.
	 */
	private function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 's3ms_minute', self::CRON_HOOK );
		}
	}

	private function acquire_tick_lease(): bool {
		$now  = time();
		$data = array(
			'at'      => $now,
			'expires' => $now + self::TICK_LEASE_TTL,
		);

		$existing = get_option( self::TICK_LEASE_KEY, null );
		if ( is_array( $existing ) && (int) ( $existing['expires'] ?? 0 ) > $now ) {
			return false;
		}
		if ( is_array( $existing ) ) {
			delete_option( self::TICK_LEASE_KEY );
		}

		if ( add_option( self::TICK_LEASE_KEY, $data, '', false ) ) {
			return true;
		}

		$existing = get_option( self::TICK_LEASE_KEY, null );
		if ( is_array( $existing ) && (int) ( $existing['expires'] ?? 0 ) > $now ) {
			return false;
		}

		delete_option( self::TICK_LEASE_KEY );
		return (bool) add_option( self::TICK_LEASE_KEY, $data, '', false );
	}

	private function release_tick_lease(): void {
		delete_option( self::TICK_LEASE_KEY );
	}
}
