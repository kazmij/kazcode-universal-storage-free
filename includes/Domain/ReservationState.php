<?php
/**
 * Durable storage reservation states.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Domain;

defined( 'ABSPATH' ) || exit;

final class ReservationState {
	public const HELD   = 'held';
	public const PINNED = 'pinned';
	public const OWNED  = 'owned';

	public static function is_valid( string $state ): bool {
		return in_array( $state, array( self::HELD, self::PINNED, self::OWNED ), true );
	}
}
