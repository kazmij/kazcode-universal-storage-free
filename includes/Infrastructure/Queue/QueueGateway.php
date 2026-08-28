<?php
/**
 * Durable job queue abstraction (v2 Phase 3).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueue, cancel, inspect, and run background work.
 */
interface QueueGateway {

	/**
	 * Queue driver id (cron, action_scheduler, …).
	 */
	public function driver(): string;

	/**
	 * Register WP hooks for asynchronous execution.
	 */
	public function register(): void;

	/**
	 * Enqueue a job.
	 *
	 * @param string               $job_type Job type slug.
	 * @param array<string, mixed> $payload  Job payload.
	 * @return string|\WP_Error Job id on success.
	 */
	public function enqueue( string $job_type, array $payload = array() );

	/**
	 * Cancel a job (bulk jobs use empty id).
	 *
	 * @param string $job_id Job id or empty for active bulk job.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function cancel( string $job_id = '' );

	/**
	 * Job / queue status snapshot.
	 *
	 * @param string $job_id Optional specific job id.
	 * @return array<string, mixed>
	 */
	public function status( string $job_id = '' ): array;

	/**
	 * Process the next unit of queued work (one attachment job and/or one bulk batch).
	 *
	 * @param string|null $job_id Optional specific pending job id.
	 */
	public function run_next( ?string $job_id = null ): bool;
}
