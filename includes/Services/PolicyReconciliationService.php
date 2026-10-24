<?php
/**
 * Integrity Center policy reconciliation (#62): dry run, then narrow repairs.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Attachment\AttachmentFileResolver;
use Kazcode\WpStorage\Core\SecretRedactor;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\LocalStoragePolicy;
use Kazcode\WpStorage\Domain\ManifestBuilder;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Infrastructure\AttachmentLeaseHandle;
use Kazcode\WpStorage\Infrastructure\AttachmentLock;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\TransferSessionRepository;
use Kazcode\WpStorage\Storage\PathGuard;

/**
 * Compares the files actually on this server with the configured local
 * storage policy, per offloaded attachment, plus site-wide conditions that
 * keep the policy from being enforced.
 *
 * The dry run reads the database and the local filesystem only. The two
 * repairs reuse existing guarded paths and are re-evaluated under the
 * attachment lease at apply time:
 * - `local_retained` → CleanupLocalFiles (the #53 eligibility gate decides);
 * - `local_missing`  → size-validated download of the exact verified object
 *   from the row's own profile.
 * Everything else is report-only.
 */
final class PolicyReconciliationService {

	public const LOCAL_RETAINED       = 'local_retained';
	public const LOCAL_MISSING        = 'local_missing';
	public const INTERRUPTED_TRANSFER = 'interrupted_transfer';
	public const CLEANUP_UNDELIVERABLE = 'cleanup_blocked_by_delivery';
	public const STALE_SESSIONS       = 'stale_transfer_sessions';

	private const INTERRUPTED_AFTER = 86400;
	private const STALE_SESSION_AFTER = 7 * 86400;

	private ObjectRepository $objects;
	private ManifestBuilder $manifests;
	private ProfileAwareObjectOperations $locations;
	private AttachmentLock $lock;
	/** @var callable(string):bool */
	private $local_exists;
	/** @var callable(int):array<string,mixed> */
	private $cleanup;
	/** @var callable():int */
	private $stale_sessions;

	/**
	 * @param (callable(string):bool)|null             $local_exists   Uploads-relative path → exists locally.
	 * @param (callable(int):array<string,mixed>)|null $cleanup        Policy-driven local cleanup for one attachment.
	 * @param (callable():int)|null                    $stale_sessions Count of stale incomplete transfer sessions.
	 */
	public function __construct(
		private Settings $settings,
		?ObjectRepository $objects = null,
		?ManifestBuilder $manifests = null,
		?ProfileAwareObjectOperations $locations = null,
		?AttachmentLock $lock = null,
		private ?AuditLog $audit = null,
		?callable $local_exists = null,
		?callable $cleanup = null,
		?callable $stale_sessions = null,
	) {
		$this->objects        = $objects ?? new ObjectRepository();
		$this->manifests      = $manifests ?? new ManifestBuilder();
		$this->locations      = $locations ?? new ProfileAwareObjectOperations( null, null, null, $settings );
		$this->lock           = $lock ?? new AttachmentLock();
		$this->local_exists   = $local_exists ?? array( DeliveryDriftService::class, 'local_file_exists' );
		$this->cleanup        = $cleanup ?? array( $this, 'policy_cleanup' );
		$this->stale_sessions = $stale_sessions ?? static fn(): int => count( ( new TransferSessionRepository() )->list_stale_incomplete( time() - self::STALE_SESSION_AFTER, 0 ) );
	}

	/**
	 * Dry run over one page of offloaded attachments. Never writes.
	 *
	 * @return array{policy:string,scanned:int,next_after_id:int,has_more:bool,site:list<array<string,mixed>>,findings:list<array<string,mixed>>}
	 */
	public function scan_page( int $limit = 50, int $after_attachment_id = 0 ): array {
		$ids      = $this->objects->attachment_ids_page( $limit, $after_attachment_id );
		$findings = array();
		foreach ( $ids as $attachment_id ) {
			foreach ( $this->analyze( $attachment_id ) as $finding ) {
				$findings[] = $finding;
			}
		}

		return array(
			'policy'        => $this->policy(),
			'scanned'       => count( $ids ),
			'next_after_id' => $ids === array() ? $after_attachment_id : max( $ids ),
			'has_more'      => count( $ids ) >= max( 1, min( 500, $limit ) ),
			'site'          => $after_attachment_id === 0 ? $this->site_findings() : array(),
			'findings'      => $findings,
		);
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function analyze( int $attachment_id ): array {
		$policy   = $this->policy();
		$manifest = $this->manifests->build( $attachment_id )->items();
		$rows     = $this->objects->find_by_attachment( $attachment_id );
		$findings = array();

		foreach ( $manifest as $item ) {
			$relative = (string) $item['relative'];
			$keep     = $policy === LocalStoragePolicy::KEEP_ALL
				|| ( $policy === LocalStoragePolicy::KEEP_ORIGINALS && ( $item['variant_type'] ?? '' ) === 'original' );
			$local    = ( $this->local_exists )( $relative );
			$verified = $this->verified_row( $rows, $relative );

			if ( $keep && ! $local ) {
				$findings[] = $this->finding(
					$attachment_id,
					$relative,
					self::LOCAL_MISSING,
					$verified,
					$verified !== null,
					$verified !== null
						? 'The policy keeps this file on the server, but it is missing; a verified remote copy exists.'
						: 'The policy keeps this file on the server, but it is missing and no verified remote copy is recorded.',
					$verified !== null
						? 'Apply downloads it back from its own storage profile (size-checked).'
						: 'Run Integrity → Verify; if the remote copy is gone, restore from a backup.'
				);
			} elseif ( ! $keep && $local ) {
				$findings[] = $this->finding(
					$attachment_id,
					$relative,
					self::LOCAL_RETAINED,
					$verified,
					$verified !== null,
					'The policy removes this file from the server after offload, but it is still here.',
					$verified !== null
						? 'Apply runs the guarded local cleanup; it only deletes after every current file is verified and delivered from storage.'
						: 'Run Integrity → Verify or Repair first; cleanup needs a verified remote copy.'
				);
			}
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::INTERRUPTED_AFTER );
		foreach ( $rows as $row ) {
			$status = (string) ( $row['remote_status'] ?? '' );
			if ( in_array( $status, array( ObjectRemoteStatus::PENDING, ObjectRemoteStatus::UPLOADING ), true ) && (string) ( $row['updated_at'] ?? '' ) !== '' && (string) $row['updated_at'] < $cutoff ) {
				$findings[] = $this->finding( $attachment_id, (string) ( $row['local_relative_path'] ?? '' ), self::INTERRUPTED_TRANSFER, $row, false, 'An upload or migration of this file stopped more than a day ago (' . $status . ').', 'Retry it from Integrity → Repair or the Migration screen.' );
			}
		}

		return $findings;
	}

	/**
	 * Apply the repairable findings of a dry run, re-evaluated under the lease.
	 *
	 * @param list<array<string,mixed>> $findings Findings from this user's dry run.
	 * @return array{applied:int,skipped:int,failed:int,results:list<array<string,mixed>>}
	 */
	public function apply( array $findings ): array {
		$by_attachment = array();
		foreach ( $findings as $finding ) {
			if ( ! empty( $finding['repairable'] ) && in_array( $finding['finding'] ?? '', array( self::LOCAL_RETAINED, self::LOCAL_MISSING ), true ) ) {
				$by_attachment[ (int) $finding['attachment_id'] ][ (string) $finding['finding'] ][] = (string) $finding['relative'];
			}
		}

		$summary = array( 'applied' => 0, 'skipped' => 0, 'failed' => 0, 'results' => array() );
		foreach ( $by_attachment as $attachment_id => $wanted ) {
			foreach ( $this->apply_attachment( $attachment_id, $wanted ) as $result ) {
				++$summary[ $result['result'] ];
				$summary['results'][] = $result;
			}
		}

		( $this->audit ?? new AuditLog() )->record(
			'integrity_policy_apply',
			array(
				'policy'      => $this->policy(),
				'attachments' => count( $by_attachment ),
				'applied'     => $summary['applied'],
				'skipped'     => $summary['skipped'],
				'failed'      => $summary['failed'],
			)
		);
		return $summary;
	}

	/**
	 * @param array<string,list<string>> $wanted finding => relatives from the dry run.
	 * @return list<array<string,mixed>>
	 */
	private function apply_attachment( int $attachment_id, array $wanted ): array {
		$lease = $this->lock->acquire_lease( $attachment_id, 'policy_reconcile' );
		if ( ! $lease instanceof AttachmentLeaseHandle ) {
			return array( $this->result( 'skipped', $attachment_id, '', 'Attachment is locked by another operation.' ) );
		}

		try {
			$current = array();
			foreach ( $this->analyze( $attachment_id ) as $finding ) {
				if ( ! empty( $finding['repairable'] ) ) {
					$current[ $finding['finding'] ][ $finding['relative'] ] = $finding;
				}
			}

			$results = array();
			foreach ( $wanted[ self::LOCAL_MISSING ] ?? array() as $relative ) {
				$finding = $current[ self::LOCAL_MISSING ][ $relative ] ?? null;
				if ( $finding === null ) {
					$results[] = $this->result( 'skipped', $attachment_id, $relative, 'No longer applies; run the dry run again.' );
					continue;
				}
				if ( ! $this->lock->renew( $lease ) ) {
					$results[] = $this->result( 'skipped', $attachment_id, $relative, 'Attachment lease lost.' );
					break;
				}
				$results[] = $this->download( $attachment_id, $finding );
			}

			if ( isset( $wanted[ self::LOCAL_RETAINED ] ) ) {
				if ( ! isset( $current[ self::LOCAL_RETAINED ] ) ) {
					$results[] = $this->result( 'skipped', $attachment_id, '', 'No longer applies; run the dry run again.' );
				} elseif ( ! $this->lock->renew( $lease ) ) {
					$results[] = $this->result( 'skipped', $attachment_id, '', 'Attachment lease lost.' );
				} else {
					$cleanup   = ( $this->cleanup )( $attachment_id );
					$deleted   = (int) ( $cleanup['deleted'] ?? 0 );
					$results[] = $this->result(
						$deleted > 0 ? 'applied' : 'skipped',
						$attachment_id,
						'',
						$deleted > 0 ? 'Removed ' . $deleted . ' local file(s).' : 'Cleanup kept the files: ' . (string) ( $cleanup['blocked_reason'] ?? $cleanup['message'] ?? 'not eligible' )
					);
				}
			}
			return $results;
		} finally {
			$this->lock->release_lease( $lease );
		}
	}

	/**
	 * @param array<string,mixed> $finding
	 * @return array<string,mixed>
	 */
	private function download( int $attachment_id, array $finding ): array {
		$relative = (string) $finding['relative'];
		$absolute = PathGuard::absolute_under_uploads( $relative );
		if ( $absolute === null ) {
			return $this->result( 'failed', $attachment_id, $relative, 'Path is outside the uploads directory.' );
		}
		$size     = (int) ( $finding['size_bytes'] ?? 0 );
		$download = $this->locations->download_resolved_to_local(
			$attachment_id,
			$relative,
			$absolute,
			(int) $finding['storage_profile_id'],
			(string) $finding['object_key'],
			false,
			$size > 0 ? $size : null
		);
		return empty( $download['success'] )
			? $this->result( 'failed', $attachment_id, $relative, 'Download failed: ' . SecretRedactor::redact( (string) ( $download['error'] ?? 'unknown' ), 200 ) )
			: $this->result( 'applied', $attachment_id, $relative, 'Downloaded back from storage.' );
	}

	/**
	 * Site-wide conditions that keep the policy from being enforced.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function site_findings(): array {
		$out = array();
		if ( LocalStoragePolicy::deletes_any_local( $this->policy() ) && ( ! $this->settings->is_serve_enabled() || ! $this->settings->has_public_url_config() ) ) {
			$out[] = array(
				'finding'     => self::CLEANUP_UNDELIVERABLE,
				'detail'      => 'The local file policy removes files after offload, but media is not delivered from storage, so every cleanup is refused to avoid broken images.',
				'next_action' => 'Enable Serve from storage (and a delivery URL) in Settings, or switch the policy to keep all files.',
			);
		}
		$stale = ( $this->stale_sessions )();
		if ( $stale > 0 ) {
			$out[] = array(
				'finding'     => self::STALE_SESSIONS,
				'detail'      => $stale . ' interrupted large-file transfer(s) have been idle for over a week.',
				'next_action' => 'They are cleaned up automatically by the daily stale-transfer task; no action is needed unless the count keeps growing.',
			);
		}
		return $out;
	}

	/**
	 * Policy-driven cleanup for one attachment via the guarded CleanupLocalFiles path.
	 *
	 * @return array<string,mixed>
	 */
	public function policy_cleanup( int $attachment_id ): array {
		$plugin = \Kazcode\WpStorage\Plugin::instance();
		$files  = new AttachmentFileResolver( $plugin->key_resolver() );
		$local  = $files->existing_local_files( $attachment_id );
		if ( $local === array() ) {
			return array( 'deleted' => 0, 'message' => 'No local files.' );
		}
		return ( new CleanupLocalFiles( $this->settings, $plugin->storage() ) )->maybe_cleanup(
			$attachment_id,
			$local,
			( new ManifestBuilder( $files ) )->build( $attachment_id )->items()
		);
	}

	private function policy(): string {
		return $this->settings->local_storage_policy();
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @return array<string,mixed>|null
	 */
	private function verified_row( array $rows, string $relative ): ?array {
		foreach ( $rows as $row ) {
			if ( trim( str_replace( '\\', '/', (string) ( $row['local_relative_path'] ?? '' ) ), '/' ) === $relative
				&& ( $row['remote_status'] ?? '' ) === ObjectRemoteStatus::PRESENT
				&& ! empty( $row['verified_at'] ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed>|null $row
	 * @return array<string,mixed>
	 */
	private function finding( int $attachment_id, string $relative, string $type, ?array $row, bool $repairable, string $detail, string $next ): array {
		return array(
			'attachment_id'      => $attachment_id,
			'relative'           => $relative,
			'finding'            => $type,
			'repairable'         => $repairable,
			'storage_profile_id' => $row !== null ? (int) ( $row['storage_profile_id'] ?? 0 ) : null,
			'object_key'         => $row !== null ? (string) ( $row['object_key'] ?? '' ) : null,
			'size_bytes'         => $row !== null && isset( $row['size_bytes'] ) ? (int) $row['size_bytes'] : null,
			'detail'             => $detail,
			'next_action'        => $next,
		);
	}

	/**
	 * @return array{result:string,attachment_id:int,relative:string,message:string}
	 */
	private function result( string $result, int $attachment_id, string $relative, string $message ): array {
		return array( 'result' => $result, 'attachment_id' => $attachment_id, 'relative' => $relative, 'message' => $message );
	}
}
