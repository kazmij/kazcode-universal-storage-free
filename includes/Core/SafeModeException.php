<?php
/**
 * Raised when Safe Mode refuses a storage write (#61).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Core;

defined( 'ABSPATH' ) || exit;

final class SafeModeException extends \RuntimeException {

	public function __construct( public readonly string $reason, string $message ) {
		parent::__construct( $message );
	}
}
