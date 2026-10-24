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
use Kazcode\WpStorage\Infrastructure\AttachmentLeaseHandle;
use Kazcode\WpStorage\Infrastructure\AttachmentLock;
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
		private ?AttachmentLock $lock = null,
	) {
		$this->objects = $objects ?? new ObjectRepository();
		$this->lock    = $lock ?? new AttachmentLock();
		$this->repair  = $repair ?? new RepairObjectService( $storage, $this->objects, $this->lock );
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

		$rows = $this->objects->find_by_attachment( $attachment_id );
		if ( $dry_run ) {
			return $this->repair_rows( $rows, true, null );
		}

		$lease = $this->lock->acquire_lease( $attachment_id, 'repair' );
		if ( ! $lease instanceof AttachmentLeaseHandle ) {
			return array(
				'success'  => false,
				'repaired' => 0,
				'skipped'  => 0,
				'failed'   => 1,
				'results'  => array(
					array(
						'success' => false,
						'message' => 'Attachment is locked by another operation.',
					),
				),
				'message'  => 'Attachment is locked by another operation.',
			);
		}

		try {
			// Re-read under the lease so we do not skip rows that became repairable
			// (or skip deleted ones) between the pre-lock snapshot and acquisition.
			$rows = $this->objects->find_by_attachment( $attachment_id );
			return $this->repair_rows( $rows, false, $lease );
		} finally {
			$this->lock->release_lease( $lease );
		}
	}

	/**
	 * @param list<array<string, mixed>> $rows Inventory rows.
	 * @return array{success:bool,repaired:int,skipped:int,failed:int,results:list<array<string,mixed>>,message:string}
	 */
	private function repair_rows( array $rows, bool $dry_run, ?AttachmentLeaseHandle $lease ): array {
		$results  = array();
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

			if ( ! $dry_run && $lease instanceof AttachmentLeaseHandle && ! $this->lock->renew( $lease ) ) {
				++$failed;
				$results[] = array(
					'success'   => false,
					'message'   => 'Attachment lease lost during repair.',
					'object_id' => (int) ( $row['id'] ?? 0 ),
					'relative'  => $relative,
				);
				break;
			}

			$result    = $this->repair->repair( (int) ( $row['id'] ?? 0 ), $dry_run, $lease );
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
