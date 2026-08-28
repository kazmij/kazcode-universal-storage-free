<?php
/**
 * Repair all repairable objects for one attachment (v2 Phase 6).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Domain\ObjectHealthState;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Storage\PathGuard;
use Kazcode\WpStorage\Storage\S3Storage;

/**
 * Orchestrates per-object repair for an attachment.
 */
final class RepairAttachmentService {

	public function __construct(
		private S3Storage $storage,
		private ?ObjectRepository $objects = null,
		private ?RepairObjectService $repair = null,
	) {
		$this->objects = $objects ?? new ObjectRepository();
		$this->repair  = $repair ?? new RepairObjectService( $storage, $this->objects );
	}

	/**
	 * @return array{success:bool,repaired:int,skipped:int,failed:int,results:list<array<string,mixed>>,message:string}
	 */
	public function repair( int $attachment_id, bool $dry_run = false ): array {
		if ( $attachment_id <= 0 ) {
			return array(
				'success'  => false,
				'repaired' => 0,
				'skipped'  => 0,
				'failed'   => 0,
				'results'  => array(),
				'message'  => 'attachment_id is required.',
			);
		}

		$rows    = $this->objects->find_by_attachment( $attachment_id );
		$results = array();
		$repaired = 0;
		$skipped  = 0;
		$failed   = 0;

		foreach ( $rows as $row ) {
			$relative      = (string) ( $row['local_relative_path'] ?? '' );
			$local_present = $this->local_exists( $relative );
			$health        = ObjectHealthState::classify_row( $row, $local_present );
			if ( ! ObjectHealthState::is_repairable( $health, $local_present ) ) {
				++$skipped;
				continue;
			}

			$result = $this->repair->repair( (int) ( $row['id'] ?? 0 ), $dry_run );
			$results[] = array_merge(
				$result,
				array(
					'object_id' => (int) ( $row['id'] ?? 0 ),
					'relative'  => $relative,
				)
			);
			if ( ! empty( $result['success'] ) ) {
				++$repaired;
			} else {
				++$failed;
			}
		}

		$success = $failed === 0 && ( $repaired > 0 || $skipped === count( $rows ) );
		$message = $dry_run
			? sprintf( 'Dry run: would repair %d object(s).', $repaired )
			: sprintf( 'Repaired %d object(s); failed=%d skipped=%d.', $repaired, $failed, $skipped );

		return array(
			'success'  => $success,
			'repaired' => $repaired,
			'skipped'  => $skipped,
			'failed'   => $failed,
			'results'  => $results,
			'message'  => $message,
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
