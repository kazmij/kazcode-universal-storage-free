<?php
/**
 * Durable transfer session statuses.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Domain;

defined( 'ABSPATH' ) || exit;

final class TransferSessionStatus {
	public const PENDING    = 'pending';
	public const UPLOADING  = 'uploading';
	public const COMPLETING = 'completing';
	public const COMPLETED  = 'completed';
	public const FAILED     = 'failed';
	public const ABORTED    = 'aborted';
}
