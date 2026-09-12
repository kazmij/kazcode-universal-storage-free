<?php
/**
 * Durable import run statuses.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Domain;

defined( 'ABSPATH' ) || exit;

final class ImportRunStatus {
	public const PENDING   = 'pending';
	public const RUNNING   = 'running';
	public const PAUSED    = 'paused';
	public const COMPLETED = 'completed';
	public const FAILED    = 'failed';
	public const CANCELLED = 'cancelled';
}
