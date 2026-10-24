<?php
/**
 * Deterministic multipart transfer sizing plan.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Storage;

defined( 'ABSPATH' ) || exit;

final class MultipartPlan {

	public const MIN_PART_BYTES = 5 * 1024 * 1024;
	public const MAX_PARTS      = 10000;

	private const MIB_BYTES = 1024 * 1024;

	private bool $uses_multipart;
	private int $part_size_bytes;
	private int $part_count;

	private function __construct( bool $uses_multipart, int $part_size_bytes, int $part_count ) {
		$this->uses_multipart  = $uses_multipart;
		$this->part_size_bytes = $part_size_bytes;
		$this->part_count      = $part_count;
	}

	public static function for_size( int $size_bytes, int $threshold_bytes, int $requested_part_size ): self {
		if ( $size_bytes < $threshold_bytes ) {
			return new self( false, 0, 1 );
		}

		return self::multipart_for_size( $size_bytes, $requested_part_size );
	}

	/**
	 * Build a multipart plan even when the normal threshold would select a single PUT.
	 */
	public static function for_forced_multipart_size( int $size_bytes, int $threshold_bytes, int $requested_part_size ): self {
		if ( $size_bytes <= 0 ) {
			throw new \InvalidArgumentException( 'Forced multipart requires a non-empty source.' );
		}

		return self::multipart_for_size( $size_bytes, $requested_part_size );
	}

	private static function multipart_for_size( int $size_bytes, int $requested_part_size ): self {
		$minimum_for_max_parts = intdiv( $size_bytes + self::MAX_PARTS - 1, self::MAX_PARTS );
		$part_size            = max( $requested_part_size, $minimum_for_max_parts, self::MIN_PART_BYTES );
		$part_size            = self::round_up_to_mib( $part_size );
		$part_count           = intdiv( $size_bytes + $part_size - 1, $part_size );

		return new self( true, $part_size, $part_count );
	}

	public function uses_multipart(): bool {
		return $this->uses_multipart;
	}

	public function part_size_bytes(): int {
		return $this->part_size_bytes;
	}

	public function part_count(): int {
		return $this->part_count;
	}

	private static function round_up_to_mib( int $bytes ): int {
		return intdiv( $bytes + self::MIB_BYTES - 1, self::MIB_BYTES ) * self::MIB_BYTES;
	}
}
