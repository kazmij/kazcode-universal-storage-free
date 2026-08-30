<?php
/**
 * Raised when a state-mutating worker no longer owns its attachment lease.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class LeaseLostException extends \RuntimeException {
}
