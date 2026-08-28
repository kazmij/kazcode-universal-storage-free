<?php
/**
 * Maps job types to handlers.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Registry for queue job handlers.
 */
final class JobHandlerRegistry {

	/** @var array<string, JobHandlerInterface> */
	private array $handlers = array();

	public function register( JobHandlerInterface $handler ): void {
		$this->handlers[ $handler->type() ] = $handler;
	}

	/**
	 * @throws \RuntimeException When no handler is registered.
	 */
	public function handle( string $job_type, array $payload ): array {
		if ( ! isset( $this->handlers[ $job_type ] ) ) {
			throw new \RuntimeException( 'No queue handler for job type: ' . $job_type );
		}
		return $this->handlers[ $job_type ]->handle( $payload );
	}

	public function supports( string $job_type ): bool {
		return isset( $this->handlers[ $job_type ] );
	}
}
