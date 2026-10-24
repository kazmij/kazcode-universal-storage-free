<?php
/** Executes durable, exact-pair reservation cleanup retries. */
declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Infrastructure\ReservationCleanupRepository;
use Kazcode\WpStorage\Infrastructure\ReservationRepository;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\AttachmentLeaseHandle;
use Kazcode\WpStorage\Infrastructure\AttachmentLock;
use Kazcode\WpStorage\Infrastructure\DirectUploadMigrationFenceRepository;

final class ReservationCleanupService {
	/** @param object $records ReservationCleanupRepository-compatible persistence. */
	public function __construct( private object $records, private ReservationRepository $reservations, private ?ObjectRepository $objects = null, private ?object $remote_objects = null, private ?AttachmentLock $lock = null ) {
		$this->objects ??= new ObjectRepository();
		$this->remote_objects ??= new ProfileAwareObjectOperations();
		$this->lock ??= new AttachmentLock();
	}
	/** @return array{scanned:int,released:int,deferred:int} */
	public function run( int $limit = 50 ): array {
		$summary = array( 'scanned' => 0, 'released' => 0, 'deferred' => 0 );
		foreach ( $this->records->pending( $limit ) as $row ) {
			++$summary['scanned'];
			try { $binding = json_decode( (string) ( $row['binding_json'] ?? '' ), true, 16, JSON_THROW_ON_ERROR ); } catch ( \Throwable $error ) { ++$summary['deferred']; continue; }
			if ( ! is_array( $binding ) ) { ++$summary['deferred']; continue; }
			$attachment_id = (int) ( $binding['attachment_id'] ?? 0 );
			$lease = $attachment_id > 0 ? $this->lock->acquire_lease( $attachment_id, 'reservation_cleanup' ) : null;
			if ( ! $lease instanceof AttachmentLeaseHandle ) { ++$summary['deferred']; continue; }
			try {
				$binding = $this->refresh_migration_authority( $row, $binding );
				if ( ! is_array( $binding ) ) { ++$summary['deferred']; continue; }
				if ( ! $this->settle_migration_multipart( $binding ) ) { ++$summary['deferred']; continue; }
				$state = (string) ( $row['state'] ?? '' );
				if ( $state === 'intent' ) {
					if ( ! $this->renew_or_defer( $lease ) || ! $this->resume_intent( $row, $binding ) || ! $this->renew_or_defer( $lease ) || ! $this->finish_post_remote_cleanup( $row, $binding ) ) { ++$summary['deferred']; continue; }
				} elseif ( $state === 'remote_deleted' ) {
					if ( ! $this->renew_or_defer( $lease ) || ! $this->finish_post_remote_cleanup( $row, $binding ) ) { ++$summary['deferred']; continue; }
				} elseif ( $state !== 'ready' ) { ++$summary['deferred']; continue; }
				if ( ! $this->renew_or_defer( $lease ) ) { ++$summary['deferred']; continue; }
				$requires_pre_ready_retirement = ( $binding['requires_pre_ready_session_retirement'] ?? false ) === true;
				$result = $this->reservations->release_owned_attachment_pair( (int) ( $binding['blog_id'] ?? 0 ), (string) ( $binding['owner_uuid'] ?? '' ), (int) ( $binding['path_reservation_id'] ?? 0 ), (int) ( $binding['key_reservation_id'] ?? 0 ), (string) ( $binding['relative_path'] ?? '' ), (int) ( $binding['profile_id'] ?? 0 ), (string) ( $binding['object_key'] ?? '' ), (string) ( $row['settlement_proof'] ?? '' ) );
				if ( is_wp_error( $result ) ) {
					// The durable marker crosses the cleanup/reservation transaction
					// boundary before the exact PINNED pair is settled. If this write
					// fails, retain the ready row and leave both reservations PINNED.
					if ( ! $requires_pre_ready_retirement ) {
						$inspection = $this->reservations->inspect_pre_ready_pinned_attachment_pair( (int) ( $binding['blog_id'] ?? 0 ), (string) ( $binding['owner_uuid'] ?? '' ), (int) ( $binding['path_reservation_id'] ?? 0 ), (int) ( $binding['key_reservation_id'] ?? 0 ), (string) ( $binding['relative_path'] ?? '' ), (int) ( $binding['profile_id'] ?? 0 ), (string) ( $binding['object_key'] ?? '' ), (string) ( $row['settlement_proof'] ?? '' ) );
						if ( is_wp_error( $inspection ) || ! is_array( $inspection ) || ( $inspection['status'] ?? '' ) !== 'pinned' ) { ++$summary['deferred']; continue; }
						if ( ! method_exists( $this->records, 'require_pre_ready_retirement' ) || ! $this->records->require_pre_ready_retirement( (int) ( $row['id'] ?? 0 ), $binding, (string) ( $row['settlement_proof'] ?? '' ) ) ) { ++$summary['deferred']; continue; }
						$binding['requires_pre_ready_session_retirement'] = true;
						$requires_pre_ready_retirement = true;
					}
					$result = $this->reservations->release_pre_ready_pinned_attachment_pair( (int) ( $binding['blog_id'] ?? 0 ), (string) ( $binding['owner_uuid'] ?? '' ), (int) ( $binding['path_reservation_id'] ?? 0 ), (int) ( $binding['key_reservation_id'] ?? 0 ), (string) ( $binding['relative_path'] ?? '' ), (int) ( $binding['profile_id'] ?? 0 ), (string) ( $binding['object_key'] ?? '' ), (string) ( $row['settlement_proof'] ?? '' ) );
				}
				// Core deliberately knows no Pro session internals. Pro may refuse this
				// proof-bound port; retaining the row then makes retirement retryable.
				if ( $requires_pre_ready_retirement && ! apply_filters( 'kazus_direct_upload_cleanup_retire_pre_ready_session', false, $binding, (string) ( $row['settlement_proof'] ?? '' ) ) ) { ++$summary['deferred']; continue; }
				if ( is_wp_error( $result ) || ! is_array( $result ) || ! ( new DirectUploadMigrationFenceRepository() )->complete_cleanup( $binding ) || ! $this->complete_cleanup( (int) ( $row['id'] ?? 0 ), $binding, (string) ( $row['settlement_proof'] ?? '' ) ) ) { ++$summary['deferred']; continue; }
				++$summary['released'];
			} finally {
				$this->lock->release_lease( $lease );
			}
		}
		return $summary;
	}

	/** @param array<string,mixed> $row @param array<string,mixed> $binding */
	private function finish_post_remote_cleanup( array $row, array $binding ): bool {
		$attachment_id = (int) ( $binding['attachment_id'] ?? 0 );
		$proof = (string) ( $row['settlement_proof'] ?? '' );
		if ( $attachment_id < 1 || ! hash_equals( 'attachment_deleted:' . $attachment_id, $proof ) ) return false;
		try {
			// Remote settlement is proof that the attachment is permanently gone.
			// Delete all inventory rows, including a migrated source row left STALE
			// or DELETED while the current destination was being cleaned up.
			$this->objects->delete_by_attachment( $attachment_id );
		} catch ( \Throwable $error ) {
			return false;
		}
		return $this->records->ready( (int) ( $row['id'] ?? 0 ) );
	}

	/** @param array<string,mixed> $row @param array<string,mixed> $binding */
	private function resume_intent( array $row, array $binding ): bool {
		$attachment_id = (int) ( $binding['attachment_id'] ?? 0 );
		$proof = (string) ( $row['settlement_proof'] ?? '' );
		$profile_id = $this->cleanup_profile_id( $binding );
		$object_key = $this->cleanup_object_key( $binding );
		$fingerprint = $this->cleanup_profile_fingerprint( $binding );
		if ( $attachment_id < 1 || ! hash_equals( 'attachment_deleted:' . $attachment_id, $proof ) || $profile_id < 1 || $object_key === '' || $fingerprint === '' || ! method_exists( $this->remote_objects, 'delete_exact_profile_key' ) ) {
			return false;
		}
		try {
			$extra = $binding['migration_cleanup_location'] ?? null;
			$upload_id = (string) ( $binding['migration_multipart_upload_id'] ?? '' );
			if ( ! $this->remote_objects->delete_exact_profile_key( $profile_id, $object_key, $fingerprint ) ) {
				return false;
			}
			if ( is_array( $extra ) && $upload_id === '' && ! $this->remote_objects->delete_exact_profile_key( (int) ( $extra['profile_id'] ?? 0 ), (string) ( $extra['object_key'] ?? '' ), (string) ( $extra['profile_fingerprint'] ?? '' ) ) ) return false;
		} catch ( \Throwable $error ) {
			return false;
		}
		return $this->records->remote_deleted( (int) ( $row['id'] ?? 0 ) );
	}

	/** @param array<string,mixed> $row @param array<string,mixed> $binding */
	private function refresh_migration_authority( array $row, array $binding ): ?array {
		$fences = new DirectUploadMigrationFenceRepository();
		$next = $fences->enrich_cleanup_binding( $binding );
		if ( ! is_array( $next ) ) return null;
		if ( $next === $binding ) return $binding;
		if ( ! method_exists( $this->records, 'replace_binding' ) || ! $this->records->replace_binding( (int) ( $row['id'] ?? 0 ), $next, (string) ( $row['settlement_proof'] ?? '' ) ) ) return null;
		return $next;
	}

	/** @param array<string,mixed> $binding */
	private function settle_migration_multipart( array $binding ): bool {
		$extra = $binding['migration_cleanup_location'] ?? null;
		$upload_id = (string) ( $binding['migration_multipart_upload_id'] ?? '' );
		if ( ! is_array( $extra ) || $upload_id === '' ) return true;
		return method_exists( $this->remote_objects, 'settle_exact_multipart_cleanup' )
			&& $this->remote_objects->settle_exact_multipart_cleanup( (int) ( $extra['profile_id'] ?? 0 ), (string) ( $extra['object_key'] ?? '' ), (string) ( $extra['profile_fingerprint'] ?? '' ), $upload_id );
	}

	/** @param array<string,mixed> $binding */
	private function cleanup_profile_id( array $binding ): int {
		return (int) ( $binding['cleanup_profile_id'] ?? $binding['profile_id'] ?? 0 );
	}

	/** @param array<string,mixed> $binding */
	private function cleanup_object_key( array $binding ): string {
		return (string) ( $binding['cleanup_object_key'] ?? $binding['object_key'] ?? '' );
	}

	/** @param array<string,mixed> $binding */
	private function cleanup_profile_fingerprint( array $binding ): string {
		return (string) ( $binding['cleanup_profile_fingerprint'] ?? $binding['profile_fingerprint'] ?? '' );
	}

	private function renew_or_defer( AttachmentLeaseHandle $lease ): bool {
		return $this->lock->renew( $lease );
	}

	/** @param array<string,mixed> $binding */
	private function complete_cleanup( int $id, array $binding, string $proof ): bool {
		if ( isset( $binding['direct_upload_finalization_marker'] ) ) {
			return method_exists( $this->records, 'complete_with_finalization_intent' )
				&& $this->records->complete_with_finalization_intent( $id, $binding, $proof );
		}
		return $this->records->complete( $id );
	}
}
