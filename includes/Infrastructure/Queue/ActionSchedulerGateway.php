<?php
/**
 * Action Scheduler queue driver with cron fallback.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Uses WooCommerce Action Scheduler when available; otherwise delegates to cron adapter.
 */
final class ActionSchedulerGateway implements QueueGateway {

	public const AS_HOOK = 'kazus_queue_job';

	public function __construct(
		private CronQueueAdapter $fallback,
		private JobHandlerRegistry $handlers,
	) {
	}

	public function driver(): string {
		return function_exists( 'as_enqueue_async_action' ) ? 'action_scheduler' : $this->fallback->driver();
	}

	public function register(): void {
		$this->fallback->register();
		add_action( self::AS_HOOK, array( $this, 'run_action_scheduler_job' ), 10, 2 );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function run_action_scheduler_job( string $job_type, array $payload ): void {
		if ( $this->handlers->supports( $job_type ) ) {
			$this->handlers->handle( $job_type, $payload );
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function enqueue( string $job_type, array $payload = array() ): string|\WP_Error {
		if ( in_array( $job_type, QueueJobType::bulk_actions(), true ) || ! function_exists( 'as_enqueue_async_action' ) ) {
			return $this->fallback->enqueue( $job_type, $payload );
		}

		if ( ! $this->handlers->supports( $job_type ) ) {
			return new \WP_Error( 's3ms_job', __( 'Unknown queue job type.', 'kazcode-universal-storage' ) );
		}

		$job_id = $job_type . ':' . wp_generate_password( 12, false, false );
		as_enqueue_async_action( self::AS_HOOK, array( $job_type, $payload ), 's3ms' );
		return $job_id;
	}

	public function cancel( string $job_id = '' ): array|\WP_Error {
		return $this->fallback->cancel( $job_id );
	}

	public function status( string $job_id = '' ): array {
		$status = $this->fallback->status( $job_id );
		$status['driver'] = $this->driver();
		return $status;
	}

	public function run_next( ?string $job_id = null ): bool {
		return $this->fallback->run_next( $job_id );
	}
}
