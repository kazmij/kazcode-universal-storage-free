<?php
/**
 * DB-first health scan over s3ms_objects rows (v2 Phase 6).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Attachment\AttachmentFileResolver;
use Kazcode\WpStorage\Domain\ObjectHealthState;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Storage\PathGuard;

/**
 * Classifies inventory from local DB + local filesystem — no S3 LIST per request.
 */
final class HealthScanService {

	public function __construct(
		private ?ObjectRepository $objects = null,
		private ?AttachmentFileResolver $files = null,
	) {
		$this->objects = $objects ?? new ObjectRepository();
		$this->files   = $files ?? new AttachmentFileResolver();
	}

	/**
	 * Scan a page of object rows and return health buckets.
	 *
	 * @return array<string, mixed>
	 */
	public function scan_page( int $limit = 500, int $after_id = 0 ): array {
		$rows     = $this->objects->scan_page( $limit, $after_id );
		$counts   = array_fill_keys( ObjectHealthState::all(), 0 );
		$samples  = array();
		$repairable = 0;
		$last_id  = $after_id;

		foreach ( $rows as $row ) {
			$last_id        = max( $last_id, (int) ( $row['id'] ?? 0 ) );
			$relative       = (string) ( $row['local_relative_path'] ?? '' );
			$local_present  = $this->local_exists( $relative );
			$health         = ObjectHealthState::classify_row( $row, $local_present );
			++$counts[ $health ];
			if ( ObjectHealthState::is_repairable( $health, $local_present ) ) {
				++$repairable;
			}
			if ( count( $samples ) < 20 && $health !== ObjectHealthState::HEALTHY ) {
				$samples[] = array(
					'object_id'      => (int) ( $row['id'] ?? 0 ),
					'attachment_id'  => (int) ( $row['attachment_id'] ?? 0 ),
					'relative'       => $relative,
					'remote_status'  => (string) ( $row['remote_status'] ?? '' ),
					'health'         => $health,
					'local_present'  => $local_present,
				);
			}
		}

		return array(
			'scanned'        => count( $rows ),
			'after_id'       => $after_id,
			'next_after_id'  => $rows === array() ? $after_id : $last_id,
			'has_more'       => count( $rows ) >= $limit,
			'health_counts'  => $counts,
			'repairable'     => $repairable,
			'samples'        => $samples,
		);
	}

	/**
	 * Repair dry-run: list repairable rows (local file present, current variant). Never writes.
	 *
	 * @return array{scanned:int,next_after_id:int,has_more:bool,items:list<array<string,mixed>>}
	 */
	public function repairable_page( int $limit = 100, int $after_id = 0 ): array {
		$rows  = $this->objects->scan_page( $limit, $after_id );
		$items = array();
		$last  = $after_id;
		foreach ( $rows as $row ) {
			$last     = max( $last, (int) ( $row['id'] ?? 0 ) );
			$relative = (string) ( $row['local_relative_path'] ?? '' );
			$local    = $this->local_exists( $relative );
			$health   = ObjectHealthState::classify_row( $row, $local );
			if ( ! ObjectHealthState::is_repairable( $health, $local ) || ! $this->is_repair_target( $row ) ) {
				continue;
			}
			$items[] = array(
				'object_id'          => (int) ( $row['id'] ?? 0 ),
				'attachment_id'      => (int) ( $row['attachment_id'] ?? 0 ),
				'storage_profile_id' => (int) ( $row['storage_profile_id'] ?? 0 ),
				'relative'           => $relative,
				'remote_status'      => (string) ( $row['remote_status'] ?? '' ),
				'health'             => $health,
			);
		}

		return array(
			'scanned'       => count( $rows ),
			'next_after_id' => $rows === array() ? $after_id : $last,
			'has_more'      => count( $rows ) >= $limit,
			'items'         => $items,
		);
	}

	/**
	 * In-flight uploads are owned by their worker; only settled failures are re-uploaded.
	 *
	 * @param array<string, mixed> $row
	 */
	private function is_repair_target( array $row ): bool {
		return in_array( (string) ( $row['remote_status'] ?? '' ), array( ObjectRemoteStatus::MISSING, ObjectRemoteStatus::FAILED, ObjectRemoteStatus::PRESENT ), true );
	}

	/**
	 * Full DB scan (batched) — safe for CLI/cron, not admin page load.
	 *
	 * @return array<string, mixed>
	 */
	public function scan_all( int $batch_size = 500 ): array {
		$totals  = array_fill_keys( ObjectHealthState::all(), 0 );
		$repairable = 0;
		$scanned = 0;
		$after   = 0;
		$samples = array();

		do {
			$page = $this->scan_page( $batch_size, $after );
			foreach ( $page['health_counts'] as $state => $count ) {
				$totals[ $state ] += (int) $count;
			}
			$repairable += (int) $page['repairable'];
			$scanned    += (int) $page['scanned'];
			$after       = (int) $page['next_after_id'];
			foreach ( $page['samples'] as $sample ) {
				if ( count( $samples ) < 50 ) {
					$samples[] = $sample;
				}
			}
		} while ( ! empty( $page['has_more'] ) && (int) $page['scanned'] > 0 );

		return array(
			'scanned'       => $scanned,
			'health_counts' => $totals,
			'repairable'    => $repairable,
			'samples'       => $samples,
			'generated_at'  => gmdate( 'c' ),
		);
	}

	private function local_exists( string $relative ): bool {
		if ( $relative === '' ) {
			return false;
		}
		try {
			$absolute = PathGuard::absolute_under_uploads( $relative );
		} catch ( \InvalidArgumentException $e ) {
			return false;
		}
		return $absolute !== null && is_file( $absolute );
	}
}
