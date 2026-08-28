<?php
/**
 * Cached SQL aggregates from s3ms_objects (v2 Phase 6).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Infrastructure\ObjectRepository;

/**
 * Dashboard counters without full bucket LIST on every admin request.
 */
final class ObjectStatsAggregator {

	public const CACHE_OPTION = 's3ms_object_stats_cache';
	private const CACHE_TTL   = 3600;

	public function __construct(
		private ?ObjectRepository $objects = null,
	) {
		$this->objects = $objects ?? new ObjectRepository();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get( bool $refresh = false ): array {
		if ( ! $refresh ) {
			$cached = get_option( self::CACHE_OPTION, array() );
			if ( is_array( $cached ) && $this->is_fresh( $cached ) ) {
				return $cached;
			}
		}

		$stats = $this->build();
		update_option( self::CACHE_OPTION, $stats, false );
		return $stats;
	}

	public function invalidate(): void {
		delete_option( self::CACHE_OPTION );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build(): array {
		$remote = $this->objects->aggregate_remote_status();
		$total  = $this->objects->total_count();

		return array(
			'generated_at'    => gmdate( 'c' ),
			'expires_at'      => gmdate( 'c', time() + self::CACHE_TTL ),
			'total_objects'   => $total,
			'remote_status'   => $remote,
			'present'         => (int) ( $remote[ \Kazcode\WpStorage\Domain\ObjectRemoteStatus::PRESENT ] ?? 0 ),
			'failed'          => (int) ( $remote[ \Kazcode\WpStorage\Domain\ObjectRemoteStatus::FAILED ] ?? 0 ),
			'missing'         => (int) ( $remote[ \Kazcode\WpStorage\Domain\ObjectRemoteStatus::MISSING ] ?? 0 ),
			'stale'           => (int) ( $remote[ \Kazcode\WpStorage\Domain\ObjectRemoteStatus::STALE ] ?? 0 ),
			'pending'         => (int) ( $remote[ \Kazcode\WpStorage\Domain\ObjectRemoteStatus::PENDING ] ?? 0 ) +
				(int) ( $remote[ \Kazcode\WpStorage\Domain\ObjectRemoteStatus::UPLOADING ] ?? 0 ),
		);
	}

	/**
	 * @param array<string, mixed> $cached Cached payload.
	 */
	private function is_fresh( array $cached ): bool {
		$expires = (string) ( $cached['expires_at'] ?? '' );
		if ( $expires === '' ) {
			return false;
		}
		$ts = strtotime( $expires );
		return $ts !== false && $ts > time();
	}
}
