<?php
/**
 * Handles one queue job type.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Idempotent job handler contract.
 */
interface JobHandlerInterface {

	/**
	 * Job type slug this handler supports.
	 */
	public function type(): string;

	/**
	 * Execute job. Must be safe to re-run.
	 *
	 * @param array<string, mixed> $payload Job payload.
	 * @return array{success:bool,message:string}
	 */
	public function handle( array $payload ): array;
}
