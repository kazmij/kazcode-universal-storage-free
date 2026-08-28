<?php
/**
 * WP-Cron + option-backed queue adapter (default v2 driver).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure\Queue;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Services\BackgroundMigrator;

/**
 * Bulk jobs reuse BackgroundMigrator; attachment jobs live in s3ms_pending_jobs.
 */
final class CronQueueAdapter implements QueueGateway {

	public const PENDING_OPTION = 's3ms_pending_jobs';

	public function __construct(
		private BackgroundMigrator $migrator,
		private JobHandlerRegistry $handlers,
	) {
	}

	public function driver(): string {
		return 'cron';
	}

	public function register(): void {
		add_action( BackgroundMigrator::CRON_HOOK, array( $this, 'run_next' ), 5 );
		add_action( 'kazus_queue_job', array( $this, 'dispatch_job' ), 10, 2 );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function dispatch_job( string $job_type, array $payload ): void {
		if ( ! $this->handlers->supports( $job_type ) ) {
			return;
		}
		$this->handlers->handle( $job_type, $payload );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function enqueue( string $job_type, array $payload = array() ): string|\WP_Error {
		if ( in_array( $job_type, QueueJobType::bulk_actions(), true ) ) {
			$result = $this->migrator->start( $job_type );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return 'bulk:' . $job_type;
		}

		if ( ! $this->handlers->supports( $job_type ) ) {
			return new \WP_Error( 's3ms_job', __( 'Unknown queue job type.', 'kazcode-universal-storage' ) );
		}

		$job_id = $job_type . ':' . wp_generate_password( 12, false, false );
		$jobs   = $this->pending_jobs();
		$jobs[] = array(
			'id'         => $job_id,
			'type'       => $job_type,
			'payload'    => $payload,
			'created_at' => gmdate( 'c' ),
		);
		$this->save_pending_jobs( $jobs );
		$this->schedule_tick();
		return $job_id;
	}

	public function cancel( string $job_id = '' ): array|\WP_Error {
		if ( $job_id === '' || str_starts_with( $job_id, 'bulk:' ) ) {
			return $this->migrator->stop();
		}

		$jobs = array();
		foreach ( $this->pending_jobs() as $job ) {
			if ( (string) ( $job['id'] ?? '' ) !== $job_id ) {
				$jobs[] = $job;
			}
		}
		$this->save_pending_jobs( $jobs );
		return array(
			'cancelled' => $job_id,
		);
	}

	public function status( string $job_id = '' ): array {
		if ( $job_id !== '' && ! str_starts_with( $job_id, 'bulk:' ) ) {
			foreach ( $this->pending_jobs() as $job ) {
				if ( (string) ( $job['id'] ?? '' ) === $job_id ) {
					return array_merge(
						$job,
						array(
							'driver'  => $this->driver(),
							'pending' => true,
						)
					);
				}
			}
		}

		$bulk = $this->migrator->status();
		return array(
			'driver'        => $this->driver(),
			'bulk'          => $bulk,
			'pending_count' => count( $this->pending_jobs() ),
			'pending_jobs'  => $this->pending_jobs(),
		);
	}

	public function run_next( ?string $job_id = null ): bool {
		$ran = false;

		if ( $this->process_pending_jobs( 1, $job_id ) ) {
			$ran = true;
		}

		if ( $this->migrator->process_batch() ) {
			$ran = true;
		}

		if ( $this->pending_jobs() !== array() || ! empty( $this->migrator->status()['running'] ) ) {
			$this->schedule_tick();
		}

		return $ran;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function pending_jobs(): array {
		$jobs = get_option( self::PENDING_OPTION, array() );
		return is_array( $jobs ) ? array_values( $jobs ) : array();
	}

	/**
	 * @param list<array<string, mixed>> $jobs Jobs.
	 */
	private function save_pending_jobs( array $jobs ): void {
		update_option( self::PENDING_OPTION, array_values( $jobs ), false );
	}

	private function schedule_tick(): void {
		if ( ! wp_next_scheduled( BackgroundMigrator::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, BackgroundMigrator::CRON_HOOK );
		}
	}

	private function process_pending_jobs( int $limit, ?string $only_id = null ): bool {
		$jobs    = $this->pending_jobs();
		$ran     = false;
		$remain  = array();
		$handled = 0;

		foreach ( $jobs as $job ) {
			$id = (string) ( $job['id'] ?? '' );
			if ( $only_id !== null && $id !== $only_id ) {
				$remain[] = $job;
				continue;
			}

			if ( $handled >= $limit && ( $only_id === null || $id !== $only_id ) ) {
				$remain[] = $job;
				continue;
			}

			$type    = (string) ( $job['type'] ?? '' );
			$payload = is_array( $job['payload'] ?? null ) ? $job['payload'] : array();

			if ( $this->handlers->supports( $type ) ) {
				$this->handlers->handle( $type, $payload );
				$ran = true;
				++$handled;
				continue;
			}

			$remain[] = $job;
		}

		if ( $handled > 0 ) {
			$this->save_pending_jobs( $remain );
		}

		return $ran;
	}
}
