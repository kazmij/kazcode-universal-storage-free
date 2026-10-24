<?php
/**
 * Restores local files from S3 and syncs deletes.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Attachment;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\SecretRedactor;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\RemoteObservation;
use Kazcode\WpStorage\Domain\RestorePreflightResult;
use Kazcode\WpStorage\Infrastructure\AttachmentLeaseHandle;
use Kazcode\WpStorage\Infrastructure\AttachmentLock;
use Kazcode\WpStorage\Infrastructure\LeaseLostException;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\ReservationRepository;
use Kazcode\WpStorage\Infrastructure\ReservationCleanupRepository;
use Kazcode\WpStorage\Infrastructure\DirectUploadFinalizationIntentRepository;
use Kazcode\WpStorage\Infrastructure\DirectUploadMigrationFenceRepository;
use Kazcode\WpStorage\Services\AuditLog;
use Kazcode\WpStorage\Services\ProfileAwareObjectOperations;
use Kazcode\WpStorage\Services\RemoteDeleteSafetyGuard;
use Kazcode\WpStorage\Services\RestorePreflightService;
use Kazcode\WpStorage\Storage\PathGuard;
use Kazcode\WpStorage\Storage\S3Storage;

/**
 * Restore local tree from S3; delete remote objects on attachment delete.
 */
final class AttachmentRestorer {

	private Settings $settings;
	private S3Storage $storage;
	private AttachmentFileResolver $files;
	private AttachmentLock $lock;
	private ObjectRepository $objects;
	private ReservationRepository $reservations;
	private object $reservation_cleanup;
	private RemoteDeleteSafetyGuard $delete_guard;
	private AuditLog $audit;
	private ProfileAwareObjectOperations $profile_ops;
	private ?RestorePreflightService $preflight;
	private DirectUploadFinalizationIntentRepository $finalization_intents;

	public function __construct(
		Settings $settings,
		S3Storage $storage,
		?RemoteDeleteSafetyGuard $delete_guard = null,
		?AuditLog $audit = null,
		?ProfileAwareObjectOperations $profile_ops = null,
		?RestorePreflightService $preflight = null,
		?ReservationRepository $reservations = null,
		?object $reservation_cleanup = null
	) {
		$this->settings     = $settings;
		$this->storage      = $storage;
		$this->files        = new AttachmentFileResolver($storage->keys());
		$this->lock         = new AttachmentLock();
		$this->objects      = new ObjectRepository();
		$this->reservations = $reservations ?? new ReservationRepository();
		$this->reservation_cleanup = $reservation_cleanup ?? new ReservationCleanupRepository();
		$this->delete_guard = $delete_guard ?? new RemoteDeleteSafetyGuard(null, null, $this->files);
		$this->audit        = $audit ?? new AuditLog();
		$this->profile_ops  = $profile_ops ?? new ProfileAwareObjectOperations( legacy: $storage, settings: $settings );
		$this->preflight    = $preflight;
		$this->finalization_intents = new DirectUploadFinalizationIntentRepository();
	}

	/**
	 * Hook attachment deletion.
	 */
	public function register_delete_hooks(): void {
		add_action('delete_attachment', array($this, 'on_delete_attachment'), 5, 1);
	}

	/**
	 * When an attachment is permanently deleted, remove S3 objects if configured.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function on_delete_attachment(int $attachment_id): void {
		if (!$this->settings->is_enabled() || !$this->settings->should_delete_remote()) {
			return;
		}
		$status = (string) get_post_meta($attachment_id, '_s3ms_status', true);
		$key    = (string) get_post_meta($attachment_id, '_s3ms_original_key', true);
		$binding_metadata = (string) get_post_meta( $attachment_id, '_s3ms_direct_upload_reservation_binding', true );
		$location_metadata = (string) get_post_meta( $attachment_id, '_s3ms_direct_upload_location', true );
		$has_direct_upload_cleanup_metadata = $binding_metadata !== '' || $location_metadata !== '';
		$cleanup = null;
		if ( $binding_metadata === '' || $location_metadata === '' ) {
			// The post is already visible to this deletion hook but its postmeta may
			// be only partly published. Recover the proof that Core required before
			// wp_insert_attachment(), but reject a partial field bound differently.
			$intent = $this->finalization_intents->evidence_for_attachment( $attachment_id );
			if ( is_wp_error( $intent ) ) {
				throw new \RuntimeException( 'Direct Upload finalization intent lookup failed; attachment deletion is unsafe.' );
			}
			if ( is_array( $intent ) ) {
				$binding = $intent['binding'];
				unset( $binding['attachment_id'], $binding['profile_fingerprint'], $binding['cleanup_profile_id'], $binding['cleanup_object_key'], $binding['cleanup_profile_fingerprint'] );
				$expected_binding = wp_json_encode( $binding );
				$expected_location = wp_json_encode( $intent['location'] );
				if ( is_string( $expected_binding ) && $binding_metadata !== '' && ! hash_equals( $expected_binding, $binding_metadata ) ) {
					throw new \RuntimeException( 'Direct Upload cleanup metadata conflicts with durable finalization intent; attachment deletion is unsafe.' );
				}
				if ( is_string( $expected_location ) && $location_metadata !== '' && ! hash_equals( $expected_location, $location_metadata ) ) {
					throw new \RuntimeException( 'Direct Upload cleanup metadata conflicts with durable finalization intent; attachment deletion is unsafe.' );
				}
				if ( is_string( $expected_binding ) && is_string( $expected_location )
					&& ( $binding_metadata === '' || hash_equals( $expected_binding, $binding_metadata ) )
					&& ( $location_metadata === '' || hash_equals( $expected_location, $location_metadata ) ) ) {
					$binding_published = $binding_metadata !== '' || add_post_meta( $attachment_id, '_s3ms_direct_upload_reservation_binding', $expected_binding, true );
					$location_published = $location_metadata !== '' || add_post_meta( $attachment_id, '_s3ms_direct_upload_location', $expected_location, true );
					// A false unique insert can be either a storage write failure or a
					// concurrent publisher. Re-read before using the intent fallback: a
					// conflicting value is never deletion authority.
					if ( ! $binding_published ) {
						$current_binding = (string) get_post_meta( $attachment_id, '_s3ms_direct_upload_reservation_binding', true );
						if ( $current_binding !== '' && ! hash_equals( $expected_binding, $current_binding ) ) {
							throw new \RuntimeException( 'Direct Upload cleanup persistence failed; attachment deletion is unsafe.' );
						}
						$binding_published = $current_binding !== '';
					}
					if ( ! $location_published ) {
						$current_location = (string) get_post_meta( $attachment_id, '_s3ms_direct_upload_location', true );
						if ( $current_location !== '' && ! hash_equals( $expected_location, $current_location ) ) {
							throw new \RuntimeException( 'Direct Upload cleanup persistence failed; attachment deletion is unsafe.' );
						}
						$location_published = $current_location !== '';
					}
					if ( $binding_published && $location_published ) {
						$has_direct_upload_cleanup_metadata = true;
					} else {
						// WordPress will remove the post and its remaining metadata after this
						// hook. The immutable intent has already passed the same identity
						// checks, so preserve its cleanup proof even if postmeta recovery fails.
						try {
							$cleanup = $this->prepare_direct_upload_reservation_cleanup( $attachment_id, $intent );
							$has_direct_upload_cleanup_metadata = $cleanup !== null;
						} catch ( \Throwable $error ) {
							throw new \RuntimeException( 'Direct Upload cleanup persistence failed; attachment deletion is unsafe.', 0, $error );
						}
					}
				}
			}
		}
		// A completed Direct Upload retains immutable location and reservation proof
		// even after local restore clears ordinary offload markers and inventory
		// rows. Valid evidence is its sole deletion authority in either state.
		$direct_upload_cleanup = $cleanup !== null || $this->has_direct_upload_cleanup_evidence( $attachment_id );
		if ( $has_direct_upload_cleanup_metadata && ! $direct_upload_cleanup ) {
			// Both fields are present, so this is claimed Direct Upload authority.
			// Returning would let WordPress erase the only recovery evidence.
			throw new \RuntimeException( 'Direct Upload cleanup evidence is invalid; attachment deletion is unsafe.' );
		}
		if ( $direct_upload_cleanup ) {
			try {
				// WordPress proceeds with deletion after this hook returns. Persist the
				// proof-bound intent before attempting the attachment lease so a busy
				// operation cannot destroy the only cleanup evidence.
				$cleanup = $cleanup ?? $this->prepare_direct_upload_reservation_cleanup( $attachment_id );
			} catch ( \Throwable $error ) {
				throw new \RuntimeException( 'Direct Upload cleanup persistence failed; attachment deletion is unsafe.', 0, $error );
			}
		}
		// Direct Upload deletion is bound to a persisted profile location. Its
		// fingerprint is validated by the exact-profile operation below, so it
		// must not be disabled merely because unrelated legacy AWS settings are
		// empty. Every ordinary attachment retains the legacy-settings gate.
		if ( ! $direct_upload_cleanup && ! $this->settings->is_aws_configured() ) {
			return;
		}
		if ($status === '' && $key === '' && ! $direct_upload_cleanup) {
			return;
		}

		if ( ! $direct_upload_cleanup && $this->files->relative_paths($attachment_id) === array() ) {
			return;
		}

		$lease = $this->lock->acquire_lease($attachment_id, 'delete');
		if (!$lease instanceof AttachmentLeaseHandle) {
			$this->audit->record(
				'remote_delete_skipped',
				array(
					'attachment_id' => $attachment_id,
					'status'        => RemoteDeleteSafetyGuard::UNKNOWN,
					'reason'        => 'attachment_lock_busy',
				)
			);
			if (defined('WP_DEBUG') && WP_DEBUG) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log('KAZCODE Universal Storage: skipped remote delete for locked attachment ' . $attachment_id);
			}
			return;
		}

		try {
			if ( $direct_upload_cleanup ) {
				if ( $cleanup === null ) {
					throw new \RuntimeException( 'Direct Upload cleanup evidence disappeared before deletion.' );
				}
				$this->renew_or_abort( $lease, 'direct upload remote delete' );
				$binding = $cleanup['binding'];
				if ( ! $this->profile_ops->delete_exact_profile_key( (int) $binding['cleanup_profile_id'], (string) $binding['cleanup_object_key'], (string) $binding['cleanup_profile_fingerprint'] ) ) {
					throw new \RuntimeException( 'Direct Upload remote location could not be safely deleted.' );
				}
				$this->delete_migration_cleanup_location( $binding );
				if ( ! $this->reservation_cleanup->remote_deleted( $cleanup['id'] ) ) {
					throw new \RuntimeException( 'Direct Upload reservation cleanup could not record remote deletion.' );
				}
				$this->renew_or_abort( $lease, 'direct upload inventory cleanup' );
				$this->objects->delete_by_attachment($attachment_id);
				$this->release_direct_upload_reservations( $cleanup );
				return;
			}

			$decision = $this->delete_guard->evaluate($attachment_id);
			if ((string) ($decision['status'] ?? '') !== RemoteDeleteSafetyGuard::SAFE_TO_DELETE) {
				$this->audit->record(
					'remote_delete_skipped',
					array(
						'attachment_id' => $attachment_id,
						'status'        => (string) ($decision['status'] ?? RemoteDeleteSafetyGuard::UNKNOWN),
						'reason'        => (string) ($decision['reason'] ?? 'unknown'),
					)
				);
				return;
			}

			$locations = $decision['locations'] ?? array();
			if (!is_array($locations) || $locations === array()) {
				$this->audit->record(
					'remote_delete_skipped',
					array(
						'attachment_id' => $attachment_id,
						'status'        => RemoteDeleteSafetyGuard::UNKNOWN,
						'reason'        => 'no_guard_locations',
					)
				);
				return;
			}

			$this->renew_or_abort($lease, 'remote delete');
			$this->profile_ops->delete_locations($locations);
			// Only once the remote objects are actually gone: drop this attachment's
			// s3ms_objects rows too. wp_posts has no FK/cascade into that table, so
			// skipping this leaves permanently orphaned "present" rows behind — which
			// both poison Health/stats with objects that no longer exist and, for the
			// default profile, keep count_by_profile() > 0 forever, permanently
			// blocking LegacyProfileMigrator::sync_default_profile_from_settings()
			// from ever syncing a later bucket/region/endpoint change again.
			$this->renew_or_abort($lease, 'remote delete inventory cleanup');
			$this->objects->delete_by_attachment($attachment_id);
		} catch (LeaseLostException $e) {
			$this->audit->record(
				'remote_delete_skipped',
				array(
					'attachment_id' => $attachment_id,
					'status'        => RemoteDeleteSafetyGuard::UNKNOWN,
					'reason'        => 'lease_lost',
				)
			);
		} catch (\Kazcode\WpStorage\Core\SafeModeException $e) {
			// The attachment is still deleted from WordPress; its remote objects and
			// inventory rows are kept because this site may not own them (#61).
			$this->audit->record(
				'remote_delete_skipped',
				array(
					'attachment_id' => $attachment_id,
					'status'        => RemoteDeleteSafetyGuard::UNKNOWN,
					'reason'        => 'safe_mode:' . $e->reason,
				)
			);
		} catch (\Throwable $e) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log('KAZCODE Universal Storage: failed deleting remote objects for attachment ' . $attachment_id);
			}
		} finally {
			$this->lock->release_lease($lease);
		}
	}

	private function has_direct_upload_cleanup_evidence( int $attachment_id ): bool {
		try {
			$binding = json_decode( (string) get_post_meta( $attachment_id, '_s3ms_direct_upload_reservation_binding', true ), true, 16, JSON_THROW_ON_ERROR );
			$location = json_decode( (string) get_post_meta( $attachment_id, '_s3ms_direct_upload_location', true ), true, 16, JSON_THROW_ON_ERROR );
		} catch ( \Throwable $error ) {
			return false;
		}
		if ( ! is_array( $binding ) || ! is_array( $location ) ) {
			return false;
		}
		return (int) ( $binding['version'] ?? 0 ) === 1
			&& (int) ( $binding['blog_id'] ?? 0 ) > 0
			&& (string) ( $binding['owner_uuid'] ?? '' ) !== ''
			&& (int) ( $binding['path_reservation_id'] ?? 0 ) > 0
			&& (int) ( $binding['key_reservation_id'] ?? 0 ) > 0
			&& (int) ( $binding['path_reservation_id'] ?? 0 ) !== (int) ( $binding['key_reservation_id'] ?? 0 )
			&& (string) ( $binding['relative_path'] ?? '' ) !== ''
			&& (int) ( $binding['profile_id'] ?? 0 ) > 0
			&& (string) ( $binding['object_key'] ?? '' ) !== ''
			&& (int) ( $location['version'] ?? 0 ) === 2
			&& (int) ( $location['profile_id'] ?? 0 ) > 0
			&& (string) ( $location['object_key'] ?? '' ) !== ''
			&& (string) ( $location['location_fingerprint'] ?? '' ) !== ''
			&& hash_equals( (string) ( $binding['relative_path'] ?? '' ), (string) ( $location['relative_path'] ?? '' ) );
	}

	/**
	 * Release a Direct Upload's final OWNED reservation pair only after the
	 * normal remote delete and inventory cleanup have both completed.
	 */
	/** @return array{id:int,binding:array<string,mixed>,proof:string}|null */
	private function prepare_direct_upload_reservation_cleanup( int $attachment_id, ?array $intent = null ): ?array {
		if ( $intent !== null ) {
			$binding = $intent['binding'] ?? null;
			$location = $intent['location'] ?? null;
		} else {
			$raw = (string) get_post_meta( $attachment_id, '_s3ms_direct_upload_reservation_binding', true );
			if ( $raw === '' ) {
				return null;
			}
			try {
				$binding = json_decode( $raw, true, 16, JSON_THROW_ON_ERROR );
			} catch ( \Throwable $error ) {
				throw new \RuntimeException( 'Direct Upload reservation binding is invalid.', 0, $error );
			}
			$raw_location = (string) get_post_meta( $attachment_id, '_s3ms_direct_upload_location', true );
			try {
				$location = json_decode( $raw_location, true, 16, JSON_THROW_ON_ERROR );
			} catch ( \Throwable $error ) {
				$location = null;
			}
		}
		if ( ! is_array( $binding ) || (int) ( $binding['version'] ?? 0 ) !== 1 ) {
			throw new \RuntimeException( 'Direct Upload reservation binding is invalid.' );
		}
		$intent_binding = $binding;
		unset( $intent_binding['attachment_id'], $intent_binding['profile_fingerprint'], $intent_binding['cleanup_profile_id'], $intent_binding['cleanup_object_key'], $intent_binding['cleanup_profile_fingerprint'] );
		$intent_binding_json = wp_json_encode( $intent_binding );
		$post = function_exists( 'get_post' ) ? get_post( $attachment_id ) : null;
		$marker = is_object( $post ) && (string) ( $post->post_type ?? '' ) === 'attachment' ? (string) ( $post->post_name ?? '' ) : '';
		$intent_row = $marker !== '' ? $this->finalization_intents->find( $marker ) : null;
		if ( is_wp_error( $intent_row ) ) {
			throw new \RuntimeException( 'Direct Upload finalization intent lookup failed; attachment deletion is unsafe.' );
		}
		$has_exact_finalization_intent = is_array( $intent_row )
			&& is_string( $intent_binding_json )
			&& hash_equals( $intent_binding_json, (string) ( $intent_row['binding_json'] ?? '' ) );
		$fingerprint = is_array( $location ) ? (string) ( $location['location_fingerprint'] ?? '' ) : '';
		// The reservation pair remains immutable at its original Direct Upload
		// locator. A verified profile migration may legitimately advance only the
		// delivery location, which is separately persisted below for remote and
		// inventory cleanup. Both records must still agree on the attachment path.
		if ( ! is_array( $location ) || (int) ( $location['version'] ?? 0 ) !== 2 || (int) ( $location['profile_id'] ?? 0 ) < 1 || (string) ( $location['object_key'] ?? '' ) === '' || ! hash_equals( (string) ( $location['relative_path'] ?? '' ), (string) ( $binding['relative_path'] ?? '' ) ) || $fingerprint === '' ) {
			$this->audit->record( 'remote_delete_skipped', array( 'attachment_id' => $attachment_id, 'status' => RemoteDeleteSafetyGuard::UNKNOWN, 'reason' => 'direct_upload_cleanup_snapshot_missing_or_mismatched' ) );
			throw new \RuntimeException( 'Direct Upload cleanup cannot safely persist its immutable location snapshot.' );
		}
		$binding['attachment_id'] = $attachment_id;
		$binding['profile_fingerprint'] = $fingerprint;
		$binding['cleanup_profile_id'] = (int) $location['profile_id'];
		$binding['cleanup_object_key'] = (string) $location['object_key'];
		$binding['cleanup_profile_fingerprint'] = $fingerprint;
		if ( $has_exact_finalization_intent ) {
			$binding['direct_upload_finalization_marker'] = $marker;
			$binding['direct_upload_finalization_intent_binding'] = $intent_binding_json;
			// The durable intent identifies the original Direct Upload reservation
			// pair. A verified migration legitimately advances the attachment's live
			// delivery locator, so retain the intent's original locator for its exact
			// eventual deletion instead of comparing it to the migrated locator.
			$binding['direct_upload_finalization_intent_location'] = (string) ( $intent_row['location_json'] ?? '' );
		}
		$proof = 'attachment_deleted:' . $attachment_id;
		// This must exist before the attachment's postmeta is destroyed. A failed
		// release is therefore retryable without reading the soon-to-be-deleted
		// attachment, and contains every locator required for foreign-safe release.
		$cleanup = $this->reservation_cleanup->record( $binding, $proof );
		if ( is_wp_error( $cleanup ) || (int) ( $cleanup['id'] ?? 0 ) < 1 ) {
			throw new \RuntimeException( 'Direct Upload reservation cleanup could not be persisted.' );
		}
		try { $stored_binding = json_decode( (string) ( $cleanup['binding_json'] ?? '' ), true, 16, JSON_THROW_ON_ERROR ); } catch ( \Throwable $error ) { $stored_binding = null; }
		return array( 'id' => (int) $cleanup['id'], 'binding' => is_array( $stored_binding ) ? $stored_binding : $binding, 'proof' => $proof );
	}

	/** @param array<string,mixed> $binding */
	private function delete_migration_cleanup_location( array $binding ): void {
		$location = $binding['migration_cleanup_location'] ?? null;
		if ( ! is_array( $location ) ) return;
		$upload_id = (string) ( $binding['migration_multipart_upload_id'] ?? '' );
		if ( $upload_id !== '' ) {
			if ( ! $this->profile_ops->settle_exact_multipart_cleanup( (int) ( $location['profile_id'] ?? 0 ), (string) ( $location['object_key'] ?? '' ), (string) ( $location['profile_fingerprint'] ?? '' ), $upload_id ) ) {
				throw new \RuntimeException( 'Direct Upload migration multipart cleanup could not be safely settled.' );
			}
			return;
		}
		if ( ! $this->profile_ops->delete_exact_profile_key( (int) ( $location['profile_id'] ?? 0 ), (string) ( $location['object_key'] ?? '' ), (string) ( $location['profile_fingerprint'] ?? '' ) ) ) throw new \RuntimeException( 'Direct Upload migration destination could not be safely deleted.' );
	}

	/** @param array{id:int,binding:array<string,mixed>,proof:string}|null $cleanup */
	private function release_direct_upload_reservations( ?array $cleanup ): void {
		if ( $cleanup === null ) return;
		if ( ! $this->reservation_cleanup->ready( $cleanup['id'] ) ) {
			throw new \RuntimeException( 'Direct Upload reservation cleanup could not be marked ready.' );
		}
		$binding = $cleanup['binding'];
		$released = $this->reservations->release_owned_attachment_pair(
			(int) ( $binding['blog_id'] ?? 0 ),
			(string) ( $binding['owner_uuid'] ?? '' ),
			(int) ( $binding['path_reservation_id'] ?? 0 ),
			(int) ( $binding['key_reservation_id'] ?? 0 ),
			(string) ( $binding['relative_path'] ?? '' ),
			(int) ( $binding['profile_id'] ?? 0 ),
			(string) ( $binding['object_key'] ?? '' ),
			$cleanup['proof']
		);
		// A failure here can be the deliberate pre-READY case: the attachment was
		// durably created but finalization did not reach mark_ready(), leaving both
		// exact reservations PINNED. Remote deletion and exact cleanup proof have
		// already been recorded above, so attempt only the dedicated PINNED path.
		$requires_pre_ready_retirement = ( $binding['requires_pre_ready_session_retirement'] ?? false ) === true;
		if ( is_wp_error( $released ) ) {
			// Cleanup and reservation state have separate transactions. Record the
			// immutable retirement obligation first: a marker write failure must
			// leave the exact PINNED pair untouched for a durable retry.
			if ( ! $requires_pre_ready_retirement ) {
				$inspection = $this->reservations->inspect_pre_ready_pinned_attachment_pair(
					(int) ( $binding['blog_id'] ?? 0 ),
					(string) ( $binding['owner_uuid'] ?? '' ),
					(int) ( $binding['path_reservation_id'] ?? 0 ),
					(int) ( $binding['key_reservation_id'] ?? 0 ),
					(string) ( $binding['relative_path'] ?? '' ),
					(int) ( $binding['profile_id'] ?? 0 ),
					(string) ( $binding['object_key'] ?? '' ),
					$cleanup['proof']
				);
				if ( is_wp_error( $inspection ) || ! is_array( $inspection ) || ( $inspection['status'] ?? '' ) !== 'pinned' ) {
					throw new \RuntimeException( 'Direct Upload reservation state could not be verified as pre-READY.' );
				}
				if ( ! $this->reservation_cleanup->require_pre_ready_retirement( $cleanup['id'], $binding, $cleanup['proof'] ) ) {
					throw new \RuntimeException( 'Direct Upload pre-READY retirement requirement could not be persisted.' );
				}
				$binding['requires_pre_ready_session_retirement'] = true;
				$requires_pre_ready_retirement = true;
			}
			$released = $this->reservations->release_pre_ready_pinned_attachment_pair(
				(int) ( $binding['blog_id'] ?? 0 ),
				(string) ( $binding['owner_uuid'] ?? '' ),
				(int) ( $binding['path_reservation_id'] ?? 0 ),
				(int) ( $binding['key_reservation_id'] ?? 0 ),
				(string) ( $binding['relative_path'] ?? '' ),
				(int) ( $binding['profile_id'] ?? 0 ),
				(string) ( $binding['object_key'] ?? '' ),
				$cleanup['proof']
			);
		}
		if ( is_wp_error( $released ) || ! is_array( $released ) ) {
			throw new \RuntimeException( 'Direct Upload reservation ownership could not be released.' );
		}
		if ( $requires_pre_ready_retirement && ! apply_filters( 'kazus_direct_upload_cleanup_retire_pre_ready_session', false, $binding, $cleanup['proof'] ) ) {
			throw new \RuntimeException( 'Direct Upload pre-READY session retirement could not be confirmed.' );
		}
		// Keep the durable record until the matching fence is removed. The record
		// is the retry authority if that final database operation fails.
		if ( ! ( new DirectUploadMigrationFenceRepository() )->complete_cleanup( $binding ) ) {
			throw new \RuntimeException( 'Direct Upload migration fence could not be completed.' );
		}
		if ( ! $this->complete_direct_upload_cleanup( $cleanup ) ) {
			throw new \RuntimeException( 'Direct Upload reservation cleanup could not be completed.' );
		}
	}

	/** @param array{id:int,binding:array<string,mixed>,proof:string} $cleanup */
	private function complete_direct_upload_cleanup( array $cleanup ): bool {
		$binding = $cleanup['binding'];
		if ( isset( $binding['direct_upload_finalization_marker'] ) ) {
			return method_exists( $this->reservation_cleanup, 'complete_with_finalization_intent' )
				&& $this->reservation_cleanup->complete_with_finalization_intent( $cleanup['id'], $binding, $cleanup['proof'] );
		}
		return $this->reservation_cleanup->complete( $cleanup['id'] );
	}

	/**
	 * Restore all known files for an attachment from S3 to local uploads.
	 *
	 * When a RestorePreflightService is injected (production Plugin wiring), restore
	 * is gated: BLOCKED always refuses; WARNING requires acknowledge_warning=true.
	 * Downloads use the preflight restorable plan (resolved profile/key), not a bare re-locate().
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $options       Optional. acknowledge_warning (bool).
	 * @return array{success:bool,message:string,files?:int,status?:string,preflight?:array<string,mixed>}
	 */
	public function restore(int $attachment_id, array $options = array()): array {
		if ( ! $this->is_restore_ready( $attachment_id ) ) {
			return array(
				'success' => false,
				'message' => 'AWS is not configured.',
			);
		}

		$acknowledge = self::explicit_true( $options['acknowledge_warning'] ?? false );
		$preflight   = null;
		$lock_token  = null;

		// Lease first so preflight's plan cannot race with concurrent offload/rebuild/adopt.
		$lease = $this->lock->acquire_lease( $attachment_id, 'restore' );
		if ( ! $lease instanceof AttachmentLeaseHandle ) {
			return array(
				'success' => false,
				'message' => 'Attachment is locked by another operation.',
			);
		}

		try {
			if ( $this->preflight instanceof RestorePreflightService ) {
				$this->renew_or_abort( $lease, 'restore preflight' );
				$preflight = $this->preflight->preflight_attachment(
					$attachment_id,
					function () use ( $lease ): void {
						$this->renew_or_abort( $lease, 'restore preflight heartbeat' );
					}
				);
				$this->renew_or_abort( $lease, 'after restore preflight' );
				$outcome   = (string) ( $preflight['outcome'] ?? '' );

				if ( $outcome === RestorePreflightResult::OUTCOME_BLOCKED ) {
					return array(
						'success'   => false,
						'message'   => 'Restore blocked by preflight: ' . implode( ', ', (array) ( $preflight['reasons'] ?? array() ) ),
						'status'    => 'blocked',
						'preflight' => $preflight,
						'files'     => 0,
					);
				}

				if ( $outcome === RestorePreflightResult::OUTCOME_WARNING && ! $acknowledge ) {
					return array(
						'success'   => false,
						'message'   => 'Restore preflight WARNING requires acknowledge_warning.',
						'status'    => 'warning_requires_acknowledge',
						'preflight' => $preflight,
						'files'     => 0,
					);
				}

				if ( (int) ( $preflight['restorable_count'] ?? 0 ) > 0 ) {
					$uploads  = wp_upload_dir();
					$basedir  = rtrim( str_replace( '\\', '/', (string) ( $uploads['basedir'] ?? '' ) ), '/' );
					$lock_ttl = RestorePreflightService::ttl_for_expected_bytes(
						isset( $preflight['required_bytes'] ) ? (int) $preflight['required_bytes'] : null
					);
					$lock_token = $this->preflight->acquire_capacity_lock(
						$basedir !== '' ? $basedir : '/',
						$lock_ttl
					);
					if ( $lock_token === null ) {
						return array(
							'success'   => false,
							'message'   => 'Restore capacity lock unavailable; retry later.',
							'status'    => 'blocked',
							'preflight' => $preflight,
							'files'     => 0,
						);
					}
					$recheck = $this->preflight->recheck_capacity_under_lock( $preflight );
					if ( empty( $recheck['ok'] ) ) {
						$this->preflight->release_capacity_lock( $lock_token );
						$lock_token = null;
						return array(
							'success'   => false,
							'message'   => 'Restore blocked by capacity recheck: ' . implode( ', ', (array) ( $recheck['reasons'] ?? array() ) ),
							'status'    => 'blocked',
							'preflight' => $preflight,
							'files'     => 0,
						);
					}
				}

				return $this->restore_from_preflight_plan( $attachment_id, $preflight, $lease, $lock_token );
			}

			return $this->restore_legacy( $attachment_id, $lease );
		} catch ( LeaseLostException $e ) {
			return array(
				'success' => false,
				'message' => 'Operation ownership lost; retry required.',
				'files'   => 0,
				'status'  => 'unknown',
			);
		} catch ( \Throwable $e ) {
			return array(
				'success' => false,
				'message' => SecretRedactor::redact( $e ),
			);
		} finally {
			$this->lock->release_lease( $lease );
			if ( is_string( $lock_token ) && $this->preflight instanceof RestorePreflightService ) {
				$this->preflight->release_capacity_lock( $lock_token );
			}
		}
	}

	/**
	 * Fail-closed truthy parse: only explicit true / 1 / "1" / "true" / "yes".
	 */
	public static function explicit_true( mixed $value ): bool {
		if ( $value === true || $value === 1 ) {
			return true;
		}
		if ( is_string( $value ) ) {
			$normalized = strtolower( trim( $value ) );
			return $normalized === '1' || $normalized === 'true' || $normalized === 'yes';
		}
		return false;
	}

	/**
	 * @param array<string, mixed> $preflight Preflight result array.
	 * @return array{success:bool,message:string,files?:int,status?:string,preflight?:array<string,mixed>}
	 */
	private function restore_from_preflight_plan( int $attachment_id, array $preflight, AttachmentLeaseHandle $lease, ?string $lock_token ): array {
		$plan       = is_array( $preflight['plan'] ?? null ) ? $preflight['plan'] : array();
		$restorable = ( new RestorePreflightResult(
			(string) ( $preflight['outcome'] ?? RestorePreflightResult::OUTCOME_SAFE ),
			(int) ( $preflight['attachment_count'] ?? 1 ),
			(int) ( $preflight['required_download_count'] ?? 0 ),
			(int) ( $preflight['restorable_count'] ?? 0 ),
			(int) ( $preflight['required_bytes'] ?? 0 ),
			$preflight['free_bytes'] ?? null,
			(int) ( $preflight['reserve_bytes'] ?? 0 ),
			is_array( $preflight['reasons'] ?? null ) ? $preflight['reasons'] : array(),
			is_array( $preflight['counts'] ?? null ) ? $preflight['counts'] : array(),
			$plan
		) )->restorable_plan();

		$confirmed_missing = 0;
		foreach ( $plan as $entry ) {
			if ( (string) ( $entry['class'] ?? '' ) === RestorePreflightResult::CLASS_CONFIRMED_MISSING ) {
				++$confirmed_missing;
			}
		}

		$count   = 0;
		$unknown = 0;
		foreach ( $restorable as $entry ) {
			$expected = array_key_exists( 'expected_size', $entry ) && is_int( $entry['expected_size'] )
				? (int) $entry['expected_size']
				: null;
			$ttl = RestorePreflightService::ttl_for_expected_bytes( $expected );
			// Size the attachment lease to cover this blocking download so a
			// concurrent offload/rebuild cannot steal it mid-transfer.
			$this->renew_or_abort( $lease, 'restore download', $ttl );
			if ( is_string( $lock_token ) && $this->preflight instanceof RestorePreflightService ) {
				if ( ! $this->preflight->renew_capacity_lock( $lock_token, $ttl ) ) {
					return array(
						'success'   => false,
						'message'   => 'Restore capacity lock lost during download.',
						'files'     => $count,
						'status'    => 'blocked',
						'preflight' => $preflight,
					);
				}
			}
			$relative = (string) ( $entry['relative'] ?? '' );
			$absolute = PathGuard::absolute_under_uploads( $relative );
			if ( $absolute === null ) {
				++$unknown;
				continue;
			}
			// Paths still in the restorable plan must be redownloaded even when
			// is_file() is true (truncated / untrustworthy leftovers).
			$download = $this->profile_ops->download_resolved_to_local(
				$attachment_id,
				$relative,
				$absolute,
				isset( $entry['profile_id'] ) ? (int) $entry['profile_id'] : null,
				(string) ( $entry['object_key'] ?? '' ),
				! empty( $entry['legacy_fallback'] ),
				$expected
			);
			if ( empty( $download['success'] ) ) {
				++$unknown;
				continue;
			}
			++$count;
		}

		if ( $unknown > 0 && $count === 0 && $confirmed_missing === 0 ) {
			return array(
				'success'   => false,
				'message'   => 'Nothing restored; remote objects could not be verified.',
				'files'     => 0,
				'status'    => 'unknown',
				'preflight' => $preflight,
			);
		}

		if ( $unknown > 0 || $confirmed_missing > 0 ) {
			return array(
				'success'   => false,
				'message'   => 'Restore incomplete; some remote objects could not be restored.',
				'files'     => $count,
				'status'    => 'partial',
				'preflight' => $preflight,
			);
		}

		$this->renew_or_abort( $lease, 'restore finalization' );
		$this->mark_attachment_local( $attachment_id );

		return array(
			'success'   => true,
			'message'   => 'Restore completed.',
			'files'     => $count,
			'preflight' => $preflight,
		);
	}

	/**
	 * Legacy ungated restore (unit tests / callers without preflight service).
	 *
	 * @return array{success:bool,message:string,files?:int,status?:string}
	 */
	private function restore_legacy( int $attachment_id, AttachmentLeaseHandle $lease ): array {
		$relatives = $this->files->relative_paths($attachment_id);
		if ($relatives === array()) {
			throw new \RuntimeException('No file metadata to restore.');
		}

		$count   = 0;
		$missing = 0;
		$unknown = 0;
		foreach ($relatives as $relative) {
			$absolute = PathGuard::absolute_under_uploads($relative);
			if ($absolute === null) {
				++$missing;
				continue;
			}
			if (is_file($absolute)) {
				continue;
			}
			$head = $this->profile_ops->head_attachment_relative($attachment_id, $relative);
			if (empty($head['exists'])) {
				$observation = RemoteObservation::from_head_result( $head );
				if ( $observation->is_confirmed_missing() ) {
					++$missing;
				} else {
					++$unknown;
				}
				continue;
			}
			$download = $this->profile_ops->download_attachment_relative_to_local($attachment_id, $relative, $absolute);
			if (empty($download['success'])) {
				++$unknown;
				continue;
			}
			++$count;
		}

		if ($unknown > 0 && $count === 0 && $missing === 0) {
			return array(
				'success' => false,
				'message' => 'Nothing restored; remote objects could not be verified.',
				'files'   => 0,
				'status'  => 'unknown',
			);
		}

		if ($unknown > 0 || $missing > 0) {
			return array(
				'success' => false,
				'message' => 'Restore incomplete; some remote objects could not be restored.',
				'files'   => $count,
				'status'  => 'partial',
			);
		}

		if ($count === 0 && $missing > 0) {
			return array(
				'success' => false,
				'message' => 'Nothing restored; remote objects missing or paths invalid.',
				'files'   => 0,
			);
		}

		$this->renew_or_abort($lease, 'restore finalization');
		$this->mark_attachment_local( $attachment_id );

		return array(
			'success' => true,
			'message' => 'Restore completed.',
			'files'   => $count,
		);
	}

	private function renew_or_abort( AttachmentLeaseHandle $lease, string $stage, ?int $ttl_seconds = null ): void {
		unset( $stage );
		if ( ! $this->lock->renew( $lease, $ttl_seconds ) ) {
			throw new LeaseLostException( 'Attachment operation ownership lost before a guarded commit.' );
		}
	}

	/**
	 * Clear offload meta and object inventory so URLs serve locally again.
	 */
	private function mark_attachment_local( int $attachment_id ): void {
		foreach ( array(
			'_s3ms_status',
			'_s3ms_original_key',
			'_s3ms_offloaded_at',
			'_s3ms_verified_at',
			'_s3ms_last_error',
		) as $key ) {
			delete_post_meta( $attachment_id, $key );
		}
		$this->objects->delete_by_attachment( $attachment_id );
	}

	/**
	 * Restore readiness is profile-aware: site Settings credentials unlock the
	 * legacy path; inventory rows or retained importer evidence unlock
	 * profile-credentialed restores even when site-wide AWS Settings are empty.
	 */
	private function is_restore_ready( int $attachment_id ): bool {
		if ( $this->settings->is_aws_configured() ) {
			return true;
		}

		foreach ( $this->objects->find_by_attachment( $attachment_id ) as $row ) {
			if ( (int) ( $row['storage_profile_id'] ?? 0 ) > 0 ) {
				return true;
			}
		}

		// No inventory yet, but importer exact-key evidence may still resolve a profile.
		$imports = \Kazcode\WpStorage\Import\ImportRegistry::defaults()->descriptors_for_attachment( $attachment_id );
		return $imports !== array();
	}
}
