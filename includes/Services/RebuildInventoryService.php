<?php
/**
 * Rebuild Storage Inventory — §6.3 Media Integrity Center.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Attachment\AttachmentOffloader;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\AttachmentSyncDeriver;
use Kazcode\WpStorage\Domain\ManifestBuilder;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Domain\RemoteObservation;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Import\ImportRegistry;
use Kazcode\WpStorage\Import\ImportedObjectDescriptor;
use Kazcode\WpStorage\Infrastructure\AttachmentLeaseHandle;
use Kazcode\WpStorage\Infrastructure\AttachmentLock;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
use Kazcode\WpStorage\Storage\ObjectKeyService;
use Kazcode\WpStorage\Storage\ProfileStorageGateway;

/**
 * Exact-key HEAD rebuild/adopt facade. Never uploads.
 */
final class RebuildInventoryService {

	public const OUTCOME_COMPLETE = 'complete';
	public const OUTCOME_PARTIAL  = 'partial';
	public const OUTCOME_FAILED   = 'failed';

	public const CLASS_PRESENT                       = 'PRESENT';
	public const CLASS_CONFIRMED_MISSING             = 'CONFIRMED_MISSING';
	public const CLASS_SIZE_CONFLICT                 = 'SIZE_CONFLICT';
	public const CLASS_TRANSIENT                     = 'TRANSIENT';
	public const CLASS_AUTH                          = 'AUTH';
	public const CLASS_INVALID_REQUEST               = 'INVALID_REQUEST';
	public const CLASS_UNKNOWN                       = 'UNKNOWN';
	public const CLASS_EXISTS_ONLY                   = 'EXISTS_ONLY';
	public const CLASS_KEY_OWNED_BY_OTHER_ATTACHMENT = 'key_owned_by_other_attachment';
	public const CLASS_KEY_OWNED_BY_OTHER_RELATIVE   = 'key_owned_by_other_relative';
	public const CLASS_RELATIVE_KEY_CONFLICT         = 'relative_key_conflict';
	public const CLASS_AMBIGUOUS_KEY                 = 'ambiguous_key';
	public const CLASS_LOCK_CONFLICT                 = 'lock_conflict';

	/** @var list<string> */
	private const CURRENT_STATUSES = array(
		ObjectRemoteStatus::PENDING,
		ObjectRemoteStatus::UPLOADING,
		ObjectRemoteStatus::PRESENT,
		ObjectRemoteStatus::MISSING,
		ObjectRemoteStatus::FAILED,
	);

	/** @var list<string> */
	private const BLOCKING_COUNT_KEYS = array(
		'conflicts',
		'transient',
		'auth',
		'invalid_request',
		'unknown',
		'ambiguous_profile',
		'ambiguous_key',
		'explicit_profile_conflicts_surviving_rows',
		'direct_upload_location_mismatch',
		'key_owned_by_other_attachment',
		'key_owned_by_other_relative',
		'relative_key_conflict',
		'lock_conflict',
	);

	public function __construct(
		private Settings $settings,
		private ?ManifestBuilder $manifest_builder = null,
		private ?ObjectRepository $objects = null,
		private ?WpdbStorageProfileRepository $profiles = null,
		private $gateway_factory = null,
		private ?AttachmentLock $lock = null,
		private ?ImportRegistry $imports = null,
		private ?AuditLog $audit = null,
		private ?ObjectStatsAggregator $stats = null,
	) {
		$this->manifest_builder = $manifest_builder ?? new ManifestBuilder();
		$this->objects          = $objects ?? new ObjectRepository();
		$this->profiles         = $profiles ?? new WpdbStorageProfileRepository();
		$this->lock             = $lock ?? new AttachmentLock();
		$this->imports          = $imports ?? ImportRegistry::defaults();
		$this->audit            = $audit ?? new AuditLog();
		$this->stats            = $stats ?? new ObjectStatsAggregator( $this->objects );
	}

	/**
	 * Rebuild one attachment (dry-run or apply).
	 *
	 * @return array<string, mixed>
	 */
	public function rebuild_attachment( int $attachment_id, ?int $explicit_profile_id = null, bool $dry_run = true ): array {
		$counts = $this->empty_counts();
		if ( $attachment_id <= 0 ) {
			return $this->fail( 'attachment_id is required.', $counts, null, $dry_run );
		}

		$post = get_post( $attachment_id );
		if ( ! $post || $post->post_type !== 'attachment' ) {
			$result = $this->fail( 'Attachment not found.', $counts, null, $dry_run );
			if ( ! $dry_run ) {
				$this->audit_apply( $attachment_id, null, $result );
			}
			return $result;
		}

		if ( $dry_run ) {
			return $this->rebuild_attachment_unlocked( $attachment_id, $explicit_profile_id, true, $counts, null );
		}

		$lease = $this->lock->acquire_lease( $attachment_id, 'rebuild' );
		if ( ! $lease instanceof AttachmentLeaseHandle ) {
			$counts['lock_conflict'] = 1;
			$result = $this->finalize_outcome(
				array(
					'success'    => false,
					'message'    => 'Attachment is locked by another operation.',
					'reason'     => self::CLASS_LOCK_CONFLICT,
					'dry_run'    => false,
					'profile_id' => null,
					'counts'     => $counts,
					'results'    => array(
						array(
							'relative' => '',
							'key'      => '',
							'class'    => self::CLASS_LOCK_CONFLICT,
							'message'  => 'Attachment is locked by another operation.',
						),
					),
					'adopted'    => 0,
				)
			);
			$this->audit_apply( $attachment_id, null, $result );
			return $result;
		}

		try {
			$result = $this->rebuild_attachment_unlocked( $attachment_id, $explicit_profile_id, false, $counts, $lease );
			$this->audit_apply( $attachment_id, $result['profile_id'] ?? null, $result );
			return $result;
		} finally {
			$this->lock->release_lease( $lease );
		}
	}

	/**
	 * @param array<string, int>          $counts Counts.
	 * @param AttachmentLeaseHandle|null $lease  Apply lease.
	 * @return array<string, mixed>
	 */
	private function rebuild_attachment_unlocked(
		int $attachment_id,
		?int $explicit_profile_id,
		bool $dry_run,
		array $counts,
		?AttachmentLeaseHandle $lease
	): array {
		$counts['skipped_stale'] = $this->count_skipped_stale( $attachment_id );

		$resolved = $this->resolve_profile( $attachment_id, $explicit_profile_id, $dry_run );
		if ( ! $resolved['ok'] ) {
			$reason = (string) $resolved['reason'];
			$counts[ $reason ] = 1;
			return $this->finalize_outcome(
				array(
					'success'    => false,
					'message'    => (string) $resolved['message'],
					'reason'     => $reason,
					'dry_run'    => $dry_run,
					'profile_id' => null,
					'counts'     => $counts,
					'results'    => array(),
					'adopted'    => 0,
				)
			);
		}

		/** @var StorageProfile $profile */
		$profile  = $resolved['profile'];
		$manifest = $this->manifest_builder->build( $attachment_id );
		if ( $manifest->items() === array() ) {
			return $this->fail( 'Attachment has no manifest paths.', $counts, (int) $profile->id, $dry_run );
		}

		$manifest_relatives = array();
		foreach ( $manifest->items() as $item ) {
			$manifest_relatives[] = (string) $item['relative'];
		}

		$gateway               = $this->gateway( $profile );
		$results               = array();
		$adopted               = 0;
		$inventory_mutated     = false;
		$any_importer_evidence = $this->imports->descriptors_for_attachment( $attachment_id ) !== array();
		$importer_evidence     = $this->profile_compatible_importer_descriptors( $attachment_id, $profile );

		foreach ( $manifest->items() as $item ) {
			$counts['scanned']++;
			$relative = (string) $item['relative'];
			$variant  = (string) ( $item['variant_type'] ?? 'size' );
			$key_res  = $this->resolve_key(
				$attachment_id,
				$profile,
				$relative,
				$importer_evidence,
				$any_importer_evidence
			);
			if ( ! $key_res['ok'] ) {
				$class = (string) $key_res['class'];
				$counts[ $this->count_key_for_class( $class ) ]++;
				$results[] = array(
					'relative' => $relative,
					'key'      => '',
					'class'    => $class,
					'message'  => (string) ( $key_res['message'] ?? '' ),
				);
				continue;
			}
			$key = (string) $key_res['key'];
			$direct_upload_mime = $this->matching_direct_upload_mime( $attachment_id, $profile, $relative, $key );

			$ownership = $this->ownership_conflict( $attachment_id, $profile, $relative, $key );
			if ( $ownership !== null ) {
				$class = (string) $ownership['class'];
				$counts[ $this->count_key_for_class( $class ) ]++;
				$results[] = array(
					'relative' => $relative,
					'key'      => $key,
					'class'    => $class,
					'message'  => (string) $ownership['message'],
				);
				continue;
			}

			$expected = $this->expected_size( $attachment_id, $relative, $profile, $key, $importer_evidence );
			$head     = $gateway->head_key( $key );
			$obs      = RemoteObservation::from_head_result( $head, $expected['size'] );
			$class    = $this->observation_class( $obs, $expected['has_trustworthy'] );

			$counts[ $this->count_key_for_class( $class ) ]++;
			$row_result = array(
				'relative'           => $relative,
				'key'                => $key,
				'class'              => $class,
				'verification_level' => $obs->verification_level,
				'expected_size'      => $expected['size'],
				'existence_only'     => ! $expected['has_trustworthy'] && $class === self::CLASS_EXISTS_ONLY,
			);

			if ( $dry_run ) {
				$results[] = $row_result;
				continue;
			}

			if ( $class === self::CLASS_PRESENT || $class === self::CLASS_EXISTS_ONLY ) {
				if ( ! $this->renew_lease_or_fail( $lease, $counts, $results, $row_result ) ) {
					$counts[ $this->count_key_for_class( $class ) ] = max( 0, $counts[ $this->count_key_for_class( $class ) ] - 1 );
					// Lease lost — do not rewrite attachment meta (another worker may own it).
					$this->reconcile_after_inventory_mutation(
						$attachment_id,
						$profile,
						$manifest_relatives,
						$results,
						$inventory_mutated,
						false
					);
					return $this->finalize_outcome(
						array(
							'success'         => false,
							'message'         => 'Attachment lease lost during rebuild apply.',
							'reason'          => self::CLASS_LOCK_CONFLICT,
							'dry_run'         => false,
							'profile_id'      => (int) $profile->id,
							'counts'          => $counts,
							'results'         => $results,
							'adopted'         => $adopted,
							'forced_outcome'  => self::OUTCOME_FAILED,
						)
					);
				}
				$wrote = false;
				if ( $this->is_already_tracked_present( $attachment_id, $profile, $relative, $key ) ) {
					$counts['already_tracked']++;
					$row_result['already_tracked'] = true;
					// Still refresh verified_at / size / etag after a successful size-verified HEAD.
					$write = $this->apply_present_row(
						$attachment_id,
						$profile,
						$relative,
						$key,
						$variant,
						$head,
						$class === self::CLASS_PRESENT ? $expected['size'] : null,
						$direct_upload_mime
					);
					if ( ! $write['ok'] ) {
						$fail_class = (string) $write['class'];
						$counts[ $this->count_key_for_class( $fail_class ) ]++;
						$counts[ $this->count_key_for_class( $class ) ] = max( 0, $counts[ $this->count_key_for_class( $class ) ] - 1 );
						$counts['already_tracked'] = max( 0, $counts['already_tracked'] - 1 );
						$row_result['class']   = $fail_class;
						$row_result['message'] = (string) $write['message'];
						unset( $row_result['already_tracked'] );
					} else {
						$inventory_mutated = true;
						$wrote               = true;
					}
				} else {
					$write = $this->apply_present_row(
						$attachment_id,
						$profile,
						$relative,
						$key,
						$variant,
						$head,
						$class === self::CLASS_PRESENT ? $expected['size'] : null,
						$direct_upload_mime
					);
					if ( ! $write['ok'] ) {
						$fail_class = (string) $write['class'];
						$counts[ $this->count_key_for_class( $fail_class ) ]++;
						$counts[ $this->count_key_for_class( $class ) ] = max( 0, $counts[ $this->count_key_for_class( $class ) ] - 1 );
						$row_result['class']   = $fail_class;
						$row_result['message'] = (string) $write['message'];
					} else {
						$adopted++;
						$inventory_mutated = true;
						$wrote               = true;
						if ( $class === self::CLASS_PRESENT ) {
							$counts['present_adopted']++;
						}
					}
				}
				$results[] = $row_result;
				// Do not sync serving meta after positive writes mid-scan — incomplete
				// results would falsely downgrade offloaded → partial. Definitive negatives
				// sync below while the lease is held; final sync runs after the full scan.
				continue;
			}

			if ( $class === self::CLASS_CONFIRMED_MISSING || $class === self::CLASS_SIZE_CONFLICT ) {
				// Persist definitive negatives onto an existing owned current row so a later
				// AttachmentReconciler cannot re-derive offloaded from a stale verified-present
				// inventory. Do not invent missing rows when nothing is tracked yet; AUTH/TRANSIENT
				// still never mutate inventory.
				if ( ! $this->renew_lease_or_fail( $lease, $counts, $results, $row_result ) ) {
					$counts[ $this->count_key_for_class( $class ) ] = max( 0, $counts[ $this->count_key_for_class( $class ) ] - 1 );
					$this->reconcile_after_inventory_mutation(
						$attachment_id,
						$profile,
						$manifest_relatives,
						$results,
						$inventory_mutated,
						false
					);
					return $this->finalize_outcome(
						array(
							'success'         => false,
							'message'         => 'Attachment lease lost during rebuild apply.',
							'reason'          => self::CLASS_LOCK_CONFLICT,
							'dry_run'         => false,
							'profile_id'      => (int) $profile->id,
							'counts'          => $counts,
							'results'         => $results,
							'adopted'         => $adopted,
							'forced_outcome'  => self::OUTCOME_FAILED,
						)
					);
				}
				$wrote = false;
				$write = $this->apply_definitive_negative_row(
					$attachment_id,
					$profile,
					$relative,
					$key,
					$variant,
					$class
				);
				if ( ! $write['ok'] ) {
					$fail_class = (string) $write['class'];
					$counts[ $this->count_key_for_class( $fail_class ) ]++;
					$counts[ $this->count_key_for_class( $class ) ] = max( 0, $counts[ $this->count_key_for_class( $class ) ] - 1 );
					$row_result['class']   = $fail_class;
					$row_result['message'] = (string) $write['message'];
				} elseif ( ! empty( $write['persisted'] ) ) {
					$row_result['inventory_updated'] = true;
					$inventory_mutated                 = true;
					$wrote                             = true;
				}
				$results[] = $row_result;
				if ( $wrote ) {
					$this->sync_attachment_meta( $attachment_id, $profile, $manifest_relatives, $results );
				}
				continue;
			}

			$results[] = $row_result;
		}

		if ( ! $dry_run ) {
			if ( $lease instanceof AttachmentLeaseHandle && ! $this->lock->renew( $lease ) ) {
				$counts['lock_conflict']++;
				$this->reconcile_after_inventory_mutation(
					$attachment_id,
					$profile,
					$manifest_relatives,
					$results,
					$inventory_mutated,
					false
				);
				return $this->finalize_outcome(
					array(
						'success'         => false,
						'message'         => 'Attachment lease lost before metadata sync.',
						'reason'          => self::CLASS_LOCK_CONFLICT,
						'dry_run'         => false,
						'profile_id'      => (int) $profile->id,
						'counts'          => $counts,
						'results'         => $results,
						'adopted'         => $adopted,
						'forced_outcome'  => self::OUTCOME_FAILED,
					)
				);
			}
			$this->reconcile_after_inventory_mutation(
				$attachment_id,
				$profile,
				$manifest_relatives,
				$results,
				$inventory_mutated,
				true
			);
		}

		return $this->finalize_outcome(
			array(
				'success'    => true,
				'dry_run'    => $dry_run,
				'profile_id' => (int) $profile->id,
				'counts'     => $counts,
				'results'    => $results,
				'adopted'    => $adopted,
			)
		);
	}

	/**
	 * @param array<string, int>            $counts Counts.
	 * @param list<array<string, mixed>>    $results Results so far.
	 * @param array<string, mixed>          $row_result Current row.
	 */
	private function renew_lease_or_fail(
		?AttachmentLeaseHandle $lease,
		array &$counts,
		array &$results,
		array $row_result
	): bool {
		if ( ! $lease instanceof AttachmentLeaseHandle ) {
			return true;
		}
		if ( $this->lock->renew( $lease ) ) {
			return true;
		}
		$counts['lock_conflict']++;
		$row_result['class']   = self::CLASS_LOCK_CONFLICT;
		$row_result['message'] = 'Attachment lease lost during rebuild apply.';
		$results[]             = $row_result;
		return false;
	}

	/**
	 * @return array{ok:bool,profile?:StorageProfile,reason?:string,message?:string}
	 */
	private function resolve_profile( int $attachment_id, ?int $explicit_profile_id, bool $dry_run = true ): array {
		if ( $explicit_profile_id !== null && $explicit_profile_id <= 0 ) {
			return array(
				'ok'      => false,
				'reason'  => 'invalid_request',
				'message' => 'Explicit profile ID must be a positive integer.',
			);
		}

		$current     = $this->current_rows( $attachment_id );
		$direct_upload_evidence = $this->direct_upload_recovery_evidence( $attachment_id );
		if ( (string) get_post_meta( $attachment_id, '_s3ms_direct_upload_location', true ) !== '' && $direct_upload_evidence === null ) {
			return array( 'ok' => false, 'reason' => 'direct_upload_location_mismatch', 'message' => 'Direct Upload recovery evidence is incomplete or does not match the attachment.' );
		}
		$profile_ids = array();
		foreach ( $current as $row ) {
			$pid = (int) ( $row['storage_profile_id'] ?? 0 );
			if ( $pid > 0 ) {
				$profile_ids[ $pid ] = true;
			}
		}
		$distinct = array_map( 'intval', array_keys( $profile_ids ) );

		if ( $explicit_profile_id !== null && $explicit_profile_id > 0 ) {
			if ( $direct_upload_evidence !== null && (int) $direct_upload_evidence['profile_id'] !== $explicit_profile_id ) {
				return array( 'ok' => false, 'reason' => 'explicit_profile_conflicts_surviving_rows', 'message' => 'Explicit profile conflicts with durable Direct Upload recovery evidence.' );
			}
			if ( count( $distinct ) === 1 && $distinct[0] !== $explicit_profile_id ) {
				return array(
					'ok'      => false,
					'reason'  => 'explicit_profile_conflicts_surviving_rows',
					'message' => 'Explicit profile conflicts with surviving inventory bindings.',
				);
			}
			if ( count( $distinct ) > 1 ) {
				return array(
					'ok'      => false,
					'reason'  => 'ambiguous_profile',
					'message' => 'Surviving inventory rows disagree on storage profile.',
				);
			}
			$profile = $this->profiles->find( $explicit_profile_id );
			if ( $profile === null || $profile->id === null ) {
				return array(
					'ok'      => false,
					'reason'  => 'ambiguous_profile',
					'message' => 'Explicit storage profile not found.',
				);
			}
			if ( $direct_upload_evidence !== null && ! $this->direct_upload_evidence_matches_profile( $direct_upload_evidence, $profile ) ) {
				return array( 'ok' => false, 'reason' => 'direct_upload_location_mismatch', 'message' => 'Direct Upload recovery evidence does not match the selected storage location.' );
			}
			return array( 'ok' => true, 'profile' => $profile );
		}

		if ( count( $distinct ) > 1 ) {
			return array(
				'ok'      => false,
				'reason'  => 'ambiguous_profile',
				'message' => 'Surviving inventory rows disagree on storage profile.',
			);
		}
		if ( count( $distinct ) === 1 ) {
			$profile = $this->profiles->find( $distinct[0] );
			if ( $profile === null || $profile->id === null ) {
				return array(
					'ok'      => false,
					'reason'  => 'ambiguous_profile',
					'message' => 'Surviving storage profile is missing.',
				);
			}
			if ( $direct_upload_evidence !== null && ! $this->direct_upload_evidence_matches_profile( $direct_upload_evidence, $profile ) ) {
				return array( 'ok' => false, 'reason' => 'direct_upload_location_mismatch', 'message' => 'Direct Upload recovery evidence does not match the surviving storage location.' );
			}
			return array( 'ok' => true, 'profile' => $profile );
		}
		if ( $direct_upload_evidence !== null ) {
			$profile = $this->profiles->find( (int) $direct_upload_evidence['profile_id'] );
			if ( $profile === null || $profile->id === null ) {
				return array( 'ok' => false, 'reason' => 'ambiguous_profile', 'message' => 'Direct Upload recovery profile is missing.' );
			}
			if ( ! $this->direct_upload_evidence_matches_profile( $direct_upload_evidence, $profile ) ) {
				return array( 'ok' => false, 'reason' => 'direct_upload_location_mismatch', 'message' => 'Direct Upload recovery evidence does not match its storage location.' );
			}
			return array( 'ok' => true, 'profile' => $profile );
		}

		// Only superseded (stale/deleted) bindings remain — never silently fall back to default.
		if ( $this->superseded_profile_ids( $attachment_id ) !== array() ) {
			return array(
				'ok'      => false,
				'reason'  => 'ambiguous_profile',
				'message' => 'Only superseded inventory bindings remain; provide an explicit profile ID.',
			);
		}

		$profile = $this->profiles->find_default_upload_target();
		if ( $profile === null && ! $dry_run ) {
			$profile = ( new LegacyProfileMigrator( $this->settings, $this->profiles ) )->ensure_legacy_profile();
		}
		if ( $profile === null || $profile->id === null ) {
			return array(
				'ok'      => false,
				'reason'  => 'ambiguous_profile',
				'message' => $dry_run
					? 'No storage profile configured (dry-run will not create a legacy profile).'
					: 'No storage profile configured.',
			);
		}
		return array( 'ok' => true, 'profile' => $profile );
	}

	/**
	 * @param list<ImportedObjectDescriptor> $importer_evidence Compatible descriptors.
	 * @return array{ok:bool,key?:string,class?:string,message?:string}
	 */
	private function resolve_key(
		int $attachment_id,
		StorageProfile $profile,
		string $relative,
		array $importer_evidence,
		bool $any_importer_evidence
	): array {
		$pid           = (int) $profile->id;
		$inventory_keys = array();
		foreach ( $this->current_rows( $attachment_id ) as $row ) {
			if ( (string) ( $row['local_relative_path'] ?? '' ) !== $relative ) {
				continue;
			}
			if ( (int) ( $row['storage_profile_id'] ?? 0 ) !== $pid ) {
				continue;
			}
			$key = (string) ( $row['object_key'] ?? '' );
			if ( $key !== '' ) {
				$inventory_keys[ $key ] = true;
			}
		}
		if ( count( $inventory_keys ) > 1 ) {
			return array(
				'ok'      => false,
				'class'   => self::CLASS_RELATIVE_KEY_CONFLICT,
				'message' => 'Multiple current object keys exist for this relative path.',
			);
		}
		if ( count( $inventory_keys ) === 1 ) {
			return array( 'ok' => true, 'key' => (string) array_key_first( $inventory_keys ) );
		}

		$direct_upload_evidence = $this->direct_upload_recovery_evidence( $attachment_id );
		if ( $direct_upload_evidence !== null && (int) $direct_upload_evidence['profile_id'] === $pid && (string) $direct_upload_evidence['relative_path'] === $relative ) {
			return array( 'ok' => true, 'key' => (string) $direct_upload_evidence['object_key'] );
		}

		$importer_keys = array();
		foreach ( $importer_evidence as $descriptor ) {
			if ( $descriptor->wp_relative_path() !== $relative ) {
				continue;
			}
			$key = $descriptor->source_object_key();
			if ( $key !== '' ) {
				$importer_keys[ $key ] = true;
			}
		}
		if ( count( $importer_keys ) === 1 ) {
			return array( 'ok' => true, 'key' => (string) array_key_first( $importer_keys ) );
		}
		if ( count( $importer_keys ) > 1 ) {
			return array(
				'ok'      => false,
				'class'   => self::CLASS_AMBIGUOUS_KEY,
				'message' => 'Importer evidence disagrees on exact object key for this variant.',
			);
		}

		$synthesized = ObjectKeyService::key_for( $profile->prefix, $relative );
		$retained    = $this->retained_original_key_for_profile( $attachment_id, $relative, $profile, $importer_evidence );
		if ( $retained !== null ) {
			if ( $retained === '' ) {
				return array(
					'ok'      => false,
					'class'   => self::CLASS_AMBIGUOUS_KEY,
					'message' => 'Retained original key is not proven for the selected profile.',
				);
			}
			return array( 'ok' => true, 'key' => $retained );
		}

		// Exact-key evidence exists (compatible incomplete, or incompatible location) — never synthesize.
		if ( $importer_evidence !== array() || $any_importer_evidence ) {
			return array(
				'ok'      => false,
				'class'   => self::CLASS_AMBIGUOUS_KEY,
				'message' => 'Custom/exact key evidence is incomplete or not proven for the selected profile.',
			);
		}

		return array(
			'ok'  => true,
			'key' => $synthesized,
		);
	}

	/**
	 * Importer descriptors whose retained source location matches the selected profile.
	 *
	 * Prefers per-descriptor fingerprint (row-level provider/bucket/region) over the
	 * importer's current global detect() settings, so a settings change cannot make
	 * an older as3cf_items location look compatible.
	 *
	 * @return list<ImportedObjectDescriptor>
	 */
	private function profile_compatible_importer_descriptors( int $attachment_id, StorageProfile $profile ): array {
		$out = array();
		foreach ( $this->imports->all() as $importer ) {
			$detection = $importer->detect()->to_array();
			$detect_ok = $this->detection_matches_profile( $detection, $profile );
			foreach ( $importer->descriptors_for_attachment( $attachment_id ) as $descriptor ) {
				$location = $this->parse_fingerprint_location( $descriptor->source_fingerprint() );
				if ( $location !== null ) {
					if ( ! $this->location_matches_profile( $location, $profile ) ) {
						continue;
					}
					// Most built-in fingerprints require retained endpoint evidence. Media Cloud
					// is the exception when its provider slug itself identifies a configured
					// profile preset (for example endpointless s3 => AWS).
					if (
						$location['endpoint'] === null
						&& ! $this->endpointless_media_cloud_matches_profile(
							$descriptor->source_fingerprint(),
							$location,
							$profile
						)
					) {
						continue;
					}
					$out[] = $descriptor;
					continue;
				}
				// Built-in location fingerprint format that failed to parse (e.g. unknown-
				// provider/bucket placeholders) must not fall back to current global detect.
				if ( $this->is_builtin_location_fingerprint_format( $descriptor->source_fingerprint() ) ) {
					continue;
				}
				// Truly non-location fingerprints (tests / other importers): fall back to detect().
				if ( $detect_ok ) {
					$out[] = $descriptor;
				}
			}
		}
		return $out;
	}

	/**
	 * True when fingerprint uses a built-in importer location encoding, even if incomplete.
	 */
	private function is_builtin_location_fingerprint_format( string $fingerprint ): bool {
		foreach ( array( 'wp-offload-media:', 'advanced-media-offloader:', 'media-cloud:' ) as $prefix ) {
			if ( str_starts_with( $fingerprint, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parse importer fingerprints that encode provider/bucket[/region[/endpoint]].
	 *
	 * @return array{provider:string,bucket:string,region:?string,endpoint:?string}|null
	 */
	private function parse_fingerprint_location( string $fingerprint ): ?array {
		foreach ( array( 'wp-offload-media', 'advanced-media-offloader', 'media-cloud' ) as $source ) {
			$prefix = $source . ':';
			if ( ! str_starts_with( $fingerprint, $prefix ) ) {
				continue;
			}
			$bits = explode( ':', substr( $fingerprint, strlen( $prefix ) ) );
			if ( count( $bits ) < 2 ) {
				return null;
			}
			$provider = (string) $bits[0];
			$bucket   = (string) $bits[1];
			if ( $provider === '' || $bucket === '' || str_starts_with( $provider, 'unknown-' ) || str_starts_with( $bucket, 'unknown-' ) ) {
				return null;
			}
			$region = isset( $bits[2] ) && $bits[2] !== '' && ! str_starts_with( (string) $bits[2], 'unknown-' )
				? (string) $bits[2]
				: null;
			// Endpoint may itself contain colons (http://host:9000) — rejoin the tail.
			$endpoint = null;
			if ( count( $bits ) > 3 ) {
				$raw_endpoint = implode( ':', array_slice( $bits, 3 ) );
				if ( $raw_endpoint !== '' && ! str_starts_with( $raw_endpoint, 'unknown-' ) ) {
					// Sentinel for retained "no custom endpoint" evidence (classic AWS).
					$endpoint = ( $raw_endpoint === '-' || $raw_endpoint === '.' )
						? ''
						: untrailingslashit( $raw_endpoint );
				}
			}
			return array(
				'provider' => $provider,
				'bucket'   => $bucket,
				'region'   => $region,
				'endpoint' => $endpoint,
			);
		}
		return null;
	}

	/**
	 * @param array{provider:string,bucket:string,region:?string,endpoint:?string} $location Fingerprint location.
	 */
	private function location_matches_profile( array $location, StorageProfile $profile ): bool {
		if ( $location['bucket'] !== $profile->bucket ) {
			return false;
		}
		$family = $this->fingerprint_provider_family( $location['provider'] );
		if ( $family === null || $family !== $this->profile_provider_family( $profile ) ) {
			return false;
		}
		if ( $location['region'] !== null && $profile->region !== '' && $location['region'] !== $profile->region ) {
			return false;
		}
		if ( $location['endpoint'] !== null ) {
			$profile_endpoint = untrailingslashit( (string) $profile->endpoint );
			if ( $location['endpoint'] !== $profile_endpoint ) {
				return false;
			}
		}
		return true;
	}

	private function fingerprint_provider_family( string $provider ): ?string {
		return match ( $provider ) {
			'aws', 's3', 'r2', 'wasabi', 'b2',
			'aws_s3', 'cloudflare_r2', 'minio', 'backblaze_b2',
			'other-s3', 'cloudflare', 'backblaze-s3' => 's3',
			'do', 'spaces', 'digitalocean'           => 'digitalocean',
			'gcp'                                    => 'gcs',
			default                                  => null,
		};
	}

	/**
	 * Media Cloud stores normal AWS as media-cloud:s3:<bucket>:<region> with no
	 * custom endpoint. In that format the provider slug is retained identity.
	 *
	 * @param array{provider:string,bucket:string,region:?string,endpoint:?string} $location
	 */
	private function endpointless_media_cloud_matches_profile(
		string $fingerprint,
		array $location,
		StorageProfile $profile
	): bool {
		if ( ! str_starts_with( $fingerprint, 'media-cloud:' ) || $location['endpoint'] !== null ) {
			return false;
		}
		$expected = match ( $location['provider'] ) {
			'aws', 's3', 'aws_s3' => array( 'aws', 's3' ),
			'wasabi' => array( 'wasabi' ),
			'cloudflare', 'r2', 'cloudflare_r2' => array( 'r2' ),
			'backblaze-s3', 'b2', 'backblaze_b2' => array( 'b2' ),
			'minio' => array( 'minio' ),
			'do', 'spaces', 'digitalocean' => array( 'spaces', 'digitalocean' ),
			default => array(),
		};
		return in_array( $profile->provider_type, $expected, true );
	}

	/**
	 * @param array<string, mixed> $detection ImportDetection::to_array().
	 */
	private function detection_matches_profile( array $detection, StorageProfile $profile ): bool {
		$bucket = (string) ( $detection['bucket'] ?? '' );
		if ( $bucket === '' || $bucket !== $profile->bucket ) {
			return false;
		}

		$family = (string) ( $detection['provider_family'] ?? '' );
		if ( $family === '' || $family !== $this->profile_provider_family( $profile ) ) {
			return false;
		}

		$region = (string) ( $detection['region'] ?? '' );
		if ( $region !== '' || $profile->region !== '' ) {
			if ( $region !== $profile->region ) {
				return false;
			}
		}

		$endpoint = untrailingslashit( (string) ( $detection['endpoint'] ?? '' ) );
		$profile_endpoint = untrailingslashit( (string) $profile->endpoint );
		if ( $endpoint !== '' || $profile_endpoint !== '' ) {
			if ( $endpoint !== $profile_endpoint ) {
				return false;
			}
		}

		return true;
	}

	private function profile_provider_family( StorageProfile $profile ): string {
		return match ( $profile->provider_type ) {
			'spaces', 'digitalocean' => 'digitalocean',
			default => 's3',
		};
	}

	/**
	 * Direct Upload records an exact key that intentionally does not follow the
	 * profile-prefix + relative-path convention.  The value is add-only at
	 * finalization; require every identity field to match the attachment before
	 * allowing inventory recovery to trust it.
	 *
	 * @return array{profile_id:int,object_key:string,relative_path:string,size_bytes:int,mime_type:string}|null
	 */
	private function direct_upload_recovery_evidence( int $attachment_id ): ?array {
		$raw = (string) get_post_meta( $attachment_id, '_s3ms_direct_upload_location', true );
		if ( $raw === '' ) {
			return null;
		}
		try {
			$value = json_decode( $raw, true, 16, JSON_THROW_ON_ERROR );
		} catch ( \Throwable $error ) {
			return null;
		}
		if ( ! is_array( $value ) || (int) ( $value['version'] ?? 0 ) !== 2 ) {
			return null;
		}
		$profile_id = (int) ( $value['profile_id'] ?? 0 );
		$key = (string) ( $value['object_key'] ?? '' );
		$relative = (string) ( $value['relative_path'] ?? '' );
		$fingerprint = (string) ( $value['location_fingerprint'] ?? '' );
		if ( $profile_id < 1 || $key === '' || $relative === '' || $fingerprint === '' || $relative !== (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) ) {
			return null;
		}
		return array( 'profile_id' => $profile_id, 'object_key' => $key, 'relative_path' => $relative, 'size_bytes' => max( 0, (int) ( $value['size_bytes'] ?? 0 ) ), 'mime_type' => (string) ( $value['mime_type'] ?? '' ), 'location_fingerprint' => $fingerprint );
	}

	/** @param array{profile_id:int,location_fingerprint:string} $evidence */
	private function direct_upload_evidence_matches_profile( array $evidence, StorageProfile $profile ): bool {
		$values = array( 'profile_id' => $profile->id, 'provider_type' => $profile->provider_type, 'bucket' => $profile->bucket, 'region' => $profile->region, 'endpoint' => $profile->endpoint, 'path_style' => $profile->path_style ? 1 : 0, 'prefix' => ObjectKeyService::normalize_prefix( $profile->prefix ), 'credentials_ref' => $profile->credentials_ref );
		return (int) $evidence['profile_id'] === (int) $profile->id && hash_equals( (string) $evidence['location_fingerprint'], hash( 'sha256', json_encode( $values, JSON_THROW_ON_ERROR ) ) );
	}

	/**
	 * @param list<ImportedObjectDescriptor> $importer_evidence Compatible descriptors.
	 * @return string|null null = no retained key; '' = ambiguous foreign retained key.
	 */
	private function retained_original_key_for_profile(
		int $attachment_id,
		string $relative,
		StorageProfile $profile,
		array $importer_evidence
	): ?string {
		$attached = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( $attached === '' || $attached !== $relative ) {
			return null;
		}
		$key = (string) get_post_meta( $attachment_id, '_s3ms_original_key', true );
		if ( $key === '' ) {
			return null;
		}

		$synthesized = ObjectKeyService::key_for( $profile->prefix, $relative );
		if ( $key === $synthesized ) {
			// Postmeta has no profile identity. If any importer evidence for this path is
			// not location-compatible with the selected profile, textual equality is not proof.
			if ( $this->has_incompatible_importer_evidence_for_relative( $attachment_id, $relative, $importer_evidence ) ) {
				return '';
			}
			return $key;
		}
		foreach ( $importer_evidence as $descriptor ) {
			if ( $descriptor->wp_relative_path() === $relative && $descriptor->source_object_key() === $key ) {
				return $key;
			}
		}

		// Shared textual prefix is not proof of bucket/provider identity.
		return '';
	}

	/**
	 * True when some importer descriptor for this relative was not accepted as profile-compatible.
	 *
	 * @param list<ImportedObjectDescriptor> $compatible Compatible descriptors.
	 */
	private function has_incompatible_importer_evidence_for_relative(
		int $attachment_id,
		string $relative,
		array $compatible
	): bool {
		$compatible_keys = array();
		foreach ( $compatible as $descriptor ) {
			if ( $descriptor->wp_relative_path() !== $relative ) {
				continue;
			}
			$k = $descriptor->source_object_key();
			if ( $k !== '' ) {
				$compatible_keys[ $k ] = true;
			}
		}
		foreach ( $this->imports->descriptors_for_attachment( $attachment_id ) as $descriptor ) {
			if ( $descriptor->wp_relative_path() !== $relative ) {
				continue;
			}
			$k = $descriptor->source_object_key();
			if ( $k === '' ) {
				continue;
			}
			if ( ! isset( $compatible_keys[ $k ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array{class:string,message:string}|null
	 */
	private function ownership_conflict( int $attachment_id, StorageProfile $profile, string $relative, string $key ): ?array {
		$existing = $this->objects->find_by_profile_and_key( (int) $profile->id, $key );
		if ( $existing === null ) {
			return null;
		}
		$status = (string) ( $existing['remote_status'] ?? '' );
		if ( in_array( $status, array( ObjectRemoteStatus::STALE, ObjectRemoteStatus::DELETED ), true ) ) {
			return null;
		}

		$owner = (int) ( $existing['attachment_id'] ?? 0 );
		$path  = (string) ( $existing['local_relative_path'] ?? '' );
		if ( $owner !== $attachment_id ) {
			return array(
				'class'   => self::CLASS_KEY_OWNED_BY_OTHER_ATTACHMENT,
				'message' => 'Profile+key owned by another attachment.',
			);
		}
		if ( $path !== '' && $path !== $relative ) {
			return array(
				'class'   => self::CLASS_KEY_OWNED_BY_OTHER_RELATIVE,
				'message' => 'Profile+key owned under a different relative path.',
			);
		}

		// Same attachment+relative already on this key — idempotent path.
		return null;
	}

	/**
	 * @param list<ImportedObjectDescriptor> $importer_evidence Compatible descriptors.
	 * @return array{size:?int,has_trustworthy:bool}
	 */
	private function expected_size(
		int $attachment_id,
		string $relative,
		StorageProfile $profile,
		string $object_key,
		array $importer_evidence
	): array {
		$uploads = wp_upload_dir();
		$basedir = (string) ( $uploads['basedir'] ?? '' );
		if ( $basedir !== '' ) {
			$abs = trailingslashit( $basedir ) . ltrim( $relative, '/' );
			if ( is_file( $abs ) ) {
				$size = filesize( $abs );
				if ( $size !== false && $size >= 0 ) {
					return array( 'size' => (int) $size, 'has_trustworthy' => true );
				}
			}
		}

		foreach ( $this->current_rows( $attachment_id ) as $row ) {
			if ( (string) ( $row['local_relative_path'] ?? '' ) !== $relative ) {
				continue;
			}
			if ( (int) ( $row['storage_profile_id'] ?? 0 ) !== (int) $profile->id ) {
				continue;
			}
			if ( ! array_key_exists( 'size_bytes', $row ) || $row['size_bytes'] === null ) {
				continue;
			}
			$size = (int) $row['size_bytes'];
			// Zero-byte objects are legitimate; treat stored 0 as trustworthy (matches write path).
			if ( $size >= 0 ) {
				return array( 'size' => $size, 'has_trustworthy' => true );
			}
		}

		$direct_upload_evidence = $this->direct_upload_recovery_evidence( $attachment_id );
		if ( $direct_upload_evidence !== null
			&& $this->direct_upload_evidence_matches_profile( $direct_upload_evidence, $profile )
			&& (string) $direct_upload_evidence['relative_path'] === $relative
			&& (string) $direct_upload_evidence['object_key'] === $object_key ) {
			return array( 'size' => (int) $direct_upload_evidence['size_bytes'], 'has_trustworthy' => true );
		}

		$retained_sizes = array();
		foreach ( $importer_evidence as $descriptor ) {
			if ( ! $descriptor instanceof ImportedObjectDescriptor ) {
				continue;
			}
			if ( $descriptor->wp_relative_path() !== $relative ) {
				continue;
			}
			if ( $descriptor->source_object_key() !== $object_key ) {
				continue;
			}
			$size = $descriptor->source_size();
			if ( $size !== null && $size >= 0 ) {
				$retained_sizes[ $size ] = true;
			}
		}
		if ( count( $retained_sizes ) === 1 ) {
			return array( 'size' => (int) array_key_first( $retained_sizes ), 'has_trustworthy' => true );
		}

		return array( 'size' => null, 'has_trustworthy' => false );
	}

	private function matching_direct_upload_mime( int $attachment_id, StorageProfile $profile, string $relative, string $object_key ): ?string {
		$evidence = $this->direct_upload_recovery_evidence( $attachment_id );
		if ( $evidence === null
			|| ! $this->direct_upload_evidence_matches_profile( $evidence, $profile )
			|| (string) $evidence['relative_path'] !== $relative
			|| (string) $evidence['object_key'] !== $object_key
			|| (string) $evidence['mime_type'] === '' ) {
			return null;
		}
		return (string) $evidence['mime_type'];
	}

	private function observation_class( RemoteObservation $obs, bool $has_trustworthy_expected ): string {
		if ( $obs->is_confirmed_missing() ) {
			return self::CLASS_CONFIRMED_MISSING;
		}
		if ( $obs->status === RemoteObservation::REMOTE_PRESENT ) {
			if ( $obs->verification_level === RemoteObservation::SIZE_MISMATCH ) {
				return self::CLASS_SIZE_CONFLICT;
			}
			if ( $obs->verification_level === RemoteObservation::SIZE_VERIFIED ) {
				return self::CLASS_PRESENT;
			}
			// Existence-only when no trustworthy expected size.
			return $has_trustworthy_expected ? self::CLASS_PRESENT : self::CLASS_EXISTS_ONLY;
		}

		$error = (string) ( $obs->error_class ?? RemoteObservation::ERROR_UNKNOWN );
		return match ( $error ) {
			RemoteObservation::ERROR_AUTH => self::CLASS_AUTH,
			RemoteObservation::ERROR_INVALID_REQUEST => self::CLASS_INVALID_REQUEST,
			RemoteObservation::ERROR_THROTTLED,
			RemoteObservation::ERROR_TIMEOUT,
			RemoteObservation::ERROR_NETWORK,
			RemoteObservation::ERROR_TLS,
			RemoteObservation::ERROR_DNS,
			RemoteObservation::ERROR_PROVIDER => self::CLASS_TRANSIENT,
			default => self::CLASS_UNKNOWN,
		};
	}

	/**
	 * @param array<string, mixed> $head HEAD result.
	 * @return array{ok:bool,class?:string,message?:string}
	 */
	private function apply_present_row(
		int $attachment_id,
		StorageProfile $profile,
		string $relative,
		string $key,
		string $variant,
		array $head,
		?int $trusted_size,
		?string $validated_mime_type = null
	): array {
		// Same relative already bound to a different current key → conflict.
		foreach ( $this->current_rows( $attachment_id ) as $row ) {
			if ( (string) ( $row['local_relative_path'] ?? '' ) !== $relative ) {
				continue;
			}
			if ( (int) ( $row['storage_profile_id'] ?? 0 ) !== (int) $profile->id ) {
				continue;
			}
			$existing_key = (string) ( $row['object_key'] ?? '' );
			if ( $existing_key !== '' && $existing_key !== $key ) {
				return array(
					'ok'      => false,
					'class'   => self::CLASS_RELATIVE_KEY_CONFLICT,
					'message' => 'Relative path already bound to a different object key.',
				);
			}
		}

		$data = array(
			'attachment_id'       => $attachment_id,
			'storage_profile_id'  => (int) $profile->id,
			'local_relative_path' => $relative,
			'object_key'          => $key,
			'variant_type'        => $variant,
			'remote_status'       => ObjectRemoteStatus::PRESENT,
			'local_status'        => $this->local_file_status( $relative ),
			'attempt_count'       => 0,
			'last_error_code'     => '',
			'last_error_message'  => '',
		);
		if ( $trusted_size !== null && $trusted_size >= 0 ) {
			$data['size_bytes']  = $trusted_size;
			$data['verified_at'] = gmdate( 'Y-m-d H:i:s' );
		} else {
			// Existence-only: explicitly clear inherited size/verification (e.g. reclaim).
			$data['size_bytes']  = null;
			$data['verified_at'] = null;
		}
		if ( ! empty( $head['etag'] ) ) {
			$data['etag'] = (string) $head['etag'];
		}
		if ( $validated_mime_type !== null ) {
			$data['mime_type'] = $validated_mime_type;
		}

		$result = $this->objects->insert_owned_or_conflict( $data );
		if ( empty( $result['ok'] ) ) {
			return array(
				'ok'      => false,
				'class'   => (string) ( $result['reason'] ?? self::CLASS_KEY_OWNED_BY_OTHER_ATTACHMENT ),
				'message' => 'Owner-preserving inventory write refused.',
			);
		}
		return array( 'ok' => true );
	}

	/**
	 * Persist CONFIRMED_MISSING / SIZE_CONFLICT onto an existing owned current inventory row.
	 *
	 * Report-only when no current owned row exists (do not invent missing inventory).
	 * SIZE_CONFLICT keeps the prior expected size_bytes and never writes remote Content-Length.
	 *
	 * @return array{ok:bool,persisted?:bool,class?:string,message?:string}
	 */
	private function apply_definitive_negative_row(
		int $attachment_id,
		StorageProfile $profile,
		string $relative,
		string $key,
		string $variant,
		string $class
	): array {
		$existing = $this->objects->find_by_profile_and_key( (int) $profile->id, $key );
		if ( $existing === null ) {
			return array(
				'ok'        => true,
				'persisted' => false,
			);
		}

		$status = (string) ( $existing['remote_status'] ?? '' );
		if ( in_array( $status, array( ObjectRemoteStatus::STALE, ObjectRemoteStatus::DELETED ), true ) ) {
			return array(
				'ok'        => true,
				'persisted' => false,
			);
		}

		$same_owner = (int) ( $existing['attachment_id'] ?? 0 ) === $attachment_id
			&& (string) ( $existing['local_relative_path'] ?? '' ) === $relative;
		if ( ! $same_owner ) {
			$conflict_class = (int) ( $existing['attachment_id'] ?? 0 ) !== $attachment_id
				? self::CLASS_KEY_OWNED_BY_OTHER_ATTACHMENT
				: self::CLASS_KEY_OWNED_BY_OTHER_RELATIVE;
			return array(
				'ok'      => false,
				'class'   => $conflict_class,
				'message' => 'Owner-preserving inventory write refused.',
			);
		}

		$remote_status = $class === self::CLASS_CONFIRMED_MISSING
			? ObjectRemoteStatus::MISSING
			: ObjectRemoteStatus::FAILED;
		$error_code    = $class === self::CLASS_CONFIRMED_MISSING
			? 'confirmed_missing'
			: 'size_conflict';
		$error_message = $class === self::CLASS_CONFIRMED_MISSING
			? 'Rebuild confirmed remote object is missing.'
			: 'Rebuild observed remote size conflict with expected inventory size.';

		$data = array(
			'attachment_id'       => $attachment_id,
			'storage_profile_id'  => (int) $profile->id,
			'local_relative_path' => $relative,
			'object_key'          => $key,
			'variant_type'        => $variant !== '' ? $variant : (string) ( $existing['variant_type'] ?? 'size' ),
			'remote_status'       => $remote_status,
			'local_status'        => $this->local_file_status( $relative ),
			'verified_at'         => null,
			'last_error_code'     => $error_code,
			'last_error_message'  => $error_message,
		);
		// Preserve expected size on conflict — never replace with remote Content-Length.
		if ( array_key_exists( 'size_bytes', $existing ) ) {
			$data['size_bytes'] = $existing['size_bytes'];
		}

		$result = $this->objects->insert_owned_or_conflict( $data );
		if ( empty( $result['ok'] ) ) {
			return array(
				'ok'      => false,
				'class'   => (string) ( $result['reason'] ?? self::CLASS_KEY_OWNED_BY_OTHER_ATTACHMENT ),
				'message' => 'Owner-preserving inventory write refused.',
			);
		}
		return array(
			'ok'        => true,
			'persisted' => true,
		);
	}

	/**
	 * Keep attachment cache / stats coherent after inventory writes.
	 *
	 * Metadata sync must only run while this worker still holds the attachment lease.
	 * After lease loss, only invalidate the global stats cache — never rewrite
	 * `_s3ms_*` for an attachment another operation may already own.
	 *
	 * @param list<string>               $manifest_relatives Manifest relatives.
	 * @param list<array<string, mixed>> $results            Per-variant results so far.
	 */
	private function reconcile_after_inventory_mutation(
		int $attachment_id,
		StorageProfile $profile,
		array $manifest_relatives,
		array $results,
		bool $inventory_mutated,
		bool $sync_meta
	): void {
		if ( $sync_meta ) {
			$this->sync_attachment_meta( $attachment_id, $profile, $manifest_relatives, $results );
		}
		if ( $inventory_mutated ) {
			$this->stats->invalidate();
		}
	}

	/**
	 * @param list<string>               $manifest_relatives Expected current relatives.
	 * @param list<array<string, mixed>> $results            Per-variant observation results.
	 */
	private function sync_attachment_meta(
		int $attachment_id,
		StorageProfile $profile,
		array $manifest_relatives,
		array $results
	): void {
		$rows      = $this->objects->find_by_attachment( $attachment_id );
		$proposed  = $this->status_for_manifest( $manifest_relatives, $results );
		$uncertain = $this->has_uncertain_observations( $results );
		$status    = $proposed;
		if (
			$uncertain
			&& ! $this->has_definitive_negative_observations( $results )
			&& $this->inventory_covers_manifest( $rows, $profile, $manifest_relatives )
		) {
			$current = (string) get_post_meta( $attachment_id, '_s3ms_status', true );
			// Preserve serving state only when inventory already covers every current
			// manifest path and every unsuccessful HEAD is uncertain.
			if (
				$current === AttachmentOffloader::STATUS_OFFLOADED
				&& ( $proposed === AttachmentOffloader::STATUS_FAILED || $proposed === AttachmentOffloader::STATUS_PARTIAL )
			) {
				$status = $current;
			}
		}
		update_post_meta( $attachment_id, '_s3ms_status', $status );

		$original_relative = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$original          = $this->present_original_object_key( $rows, $profile, $original_relative );
		if ( $original !== '' ) {
			update_post_meta( $attachment_id, '_s3ms_original_key', $original );
		}

		if ( $status === AttachmentOffloader::STATUS_OFFLOADED && ! $uncertain ) {
			$now = gmdate( 'c' );
			update_post_meta( $attachment_id, '_s3ms_offloaded_at', $now );
			update_post_meta( $attachment_id, '_s3ms_verified_at', $now );
			delete_post_meta( $attachment_id, '_s3ms_last_error' );
		} elseif ( $status === AttachmentOffloader::STATUS_PARTIAL || $status === AttachmentOffloader::STATUS_FAILED ) {
			$error = AttachmentSyncDeriver::last_error( $rows );
			if ( $error !== '' ) {
				update_post_meta( $attachment_id, '_s3ms_last_error', $error );
			}
		}
	}

	/**
	 * AUTH/TRANSIENT/UNKNOWN/INVALID_REQUEST leave inventory untouched — do not
	 * derive attachment cache solely from those observations.
	 *
	 * @param list<array<string, mixed>> $results Per-variant results.
	 */
	private function has_uncertain_observations( array $results ): bool {
		foreach ( $results as $row ) {
			$class = (string) ( $row['class'] ?? '' );
			if ( in_array(
				$class,
				array(
					self::CLASS_AUTH,
					self::CLASS_TRANSIENT,
					self::CLASS_UNKNOWN,
					self::CLASS_INVALID_REQUEST,
				),
				true
			) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Definitive negatives that must flip attachment cache even if other HEADs are uncertain.
	 *
	 * @param list<array<string, mixed>> $results Per-variant results.
	 */
	private function has_definitive_negative_observations( array $results ): bool {
		foreach ( $results as $row ) {
			$class = (string) ( $row['class'] ?? '' );
			if ( in_array(
				$class,
				array(
					self::CLASS_CONFIRMED_MISSING,
					self::CLASS_SIZE_CONFLICT,
					self::CLASS_KEY_OWNED_BY_OTHER_ATTACHMENT,
					self::CLASS_KEY_OWNED_BY_OTHER_RELATIVE,
					self::CLASS_RELATIVE_KEY_CONFLICT,
					self::CLASS_AMBIGUOUS_KEY,
				),
				true
			) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Only size-verified PRESENT paths count toward offloaded; EXISTS_ONLY is not a size-verified pass.
	 *
	 * @param list<string>               $manifest_relatives Relatives.
	 * @param list<array<string, mixed>> $results Observation results.
	 */
	private function status_for_manifest( array $manifest_relatives, array $results ): string {
		$size_verified = array();
		$exists_only   = array();
		foreach ( $results as $row ) {
			$class = (string) ( $row['class'] ?? '' );
			$path  = (string) ( $row['relative'] ?? '' );
			if ( $path === '' ) {
				continue;
			}
			if ( $class === self::CLASS_PRESENT ) {
				$size_verified[ $path ] = true;
			} elseif ( $class === self::CLASS_EXISTS_ONLY ) {
				$exists_only[ $path ] = true;
			}
		}

		$required = array_values( array_unique( $manifest_relatives ) );
		if ( $required === array() ) {
			return AttachmentOffloader::STATUS_FAILED;
		}

		$missing_verified = 0;
		$verified         = 0;
		$existence        = 0;
		foreach ( $required as $relative ) {
			if ( isset( $size_verified[ $relative ] ) ) {
				++$verified;
			} elseif ( isset( $exists_only[ $relative ] ) ) {
				++$existence;
			} else {
				++$missing_verified;
			}
		}

		if ( $missing_verified === 0 && $existence === 0 ) {
			return AttachmentOffloader::STATUS_OFFLOADED;
		}
		if ( $verified > 0 || $existence > 0 ) {
			return AttachmentOffloader::STATUS_PARTIAL;
		}
		return AttachmentOffloader::STATUS_FAILED;
	}

	/**
	 * Only a present original-variant key for the selected profile + current attached file
	 * may refresh `_s3ms_original_key`.
	 *
	 * @param list<array<string, mixed>> $rows Inventory rows.
	 */
	private function present_original_object_key( array $rows, StorageProfile $profile, string $current_original_relative ): string {
		if ( $current_original_relative === '' ) {
			return '';
		}
		foreach ( $rows as $row ) {
			if ( (int) ( $row['storage_profile_id'] ?? 0 ) !== (int) $profile->id ) {
				continue;
			}
			if ( (string) ( $row['variant_type'] ?? '' ) !== 'original' ) {
				continue;
			}
			if ( (string) ( $row['remote_status'] ?? '' ) !== ObjectRemoteStatus::PRESENT ) {
				continue;
			}
			if ( (string) ( $row['local_relative_path'] ?? '' ) !== $current_original_relative ) {
				continue;
			}
			$key = (string) ( $row['object_key'] ?? '' );
			if ( $key !== '' ) {
				return $key;
			}
		}
		return '';
	}

	/**
	 * Derive local_status from the filesystem (present|missing|unknown).
	 */
	private function local_file_status( string $relative ): string {
		$uploads = wp_upload_dir();
		$basedir = (string) ( $uploads['basedir'] ?? '' );
		if ( $basedir === '' ) {
			return 'unknown';
		}
		$abs = trailingslashit( $basedir ) . ltrim( $relative, '/' );
		return is_file( $abs ) ? 'present' : 'missing';
	}

	private function is_already_tracked_present(
		int $attachment_id,
		StorageProfile $profile,
		string $relative,
		string $key
	): bool {
		$existing = $this->objects->find_by_profile_and_key( (int) $profile->id, $key );
		if ( $existing === null ) {
			return false;
		}
		return (int) ( $existing['attachment_id'] ?? 0 ) === $attachment_id
			&& (string) ( $existing['local_relative_path'] ?? '' ) === $relative
			&& (string) ( $existing['remote_status'] ?? '' ) === ObjectRemoteStatus::PRESENT;
	}

	/**
	 * @param array<string, mixed> $result Result.
	 */
	private function audit_apply( int $attachment_id, mixed $profile_id, array $result ): void {
		$this->audit->record(
			'rebuild_inventory_apply',
			array(
				'attachment_id' => $attachment_id,
				'profile_id'    => $profile_id,
				'outcome'       => (string) ( $result['outcome'] ?? '' ),
				'adopted'       => (int) ( $result['adopted'] ?? 0 ),
				'counts'        => is_array( $result['counts'] ?? null ) ? $result['counts'] : array(),
				'message'       => (string) ( $result['message'] ?? '' ),
			)
		);
	}

	/**
	 * True when every current manifest relative has a present inventory row on the profile.
	 *
	 * @param list<array<string, mixed>> $rows Inventory rows.
	 * @param list<string>               $manifest_relatives Relatives.
	 */
	private function inventory_covers_manifest( array $rows, StorageProfile $profile, array $manifest_relatives ): bool {
		if ( $manifest_relatives === array() ) {
			return false;
		}
		$present = array();
		foreach ( $rows as $row ) {
			if ( (int) ( $row['storage_profile_id'] ?? 0 ) !== (int) $profile->id ) {
				continue;
			}
			if ( (string) ( $row['remote_status'] ?? '' ) !== ObjectRemoteStatus::PRESENT ) {
				continue;
			}
			if ( empty( $row['verified_at'] ) ) {
				continue;
			}
			$path = (string) ( $row['local_relative_path'] ?? '' );
			if ( $path !== '' ) {
				$present[ $path ] = true;
			}
		}
		foreach ( $manifest_relatives as $relative ) {
			if ( ! isset( $present[ $relative ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Profile IDs referenced only by stale/deleted inventory rows.
	 *
	 * @return list<int>
	 */
	private function superseded_profile_ids( int $attachment_id ): array {
		$ids = array();
		foreach ( $this->objects->find_by_attachment( $attachment_id ) as $row ) {
			$status = (string) ( $row['remote_status'] ?? '' );
			if ( ! in_array( $status, array( ObjectRemoteStatus::STALE, ObjectRemoteStatus::DELETED ), true ) ) {
				continue;
			}
			$pid = (int) ( $row['storage_profile_id'] ?? 0 );
			if ( $pid > 0 ) {
				$ids[ $pid ] = true;
			}
		}
		return array_map( 'intval', array_keys( $ids ) );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function current_rows( int $attachment_id ): array {
		$rows = array();
		foreach ( $this->objects->find_by_attachment( $attachment_id ) as $row ) {
			$status = (string) ( $row['remote_status'] ?? '' );
			if ( in_array( $status, array( ObjectRemoteStatus::STALE, ObjectRemoteStatus::DELETED ), true ) ) {
				continue;
			}
			if ( $status === '' || in_array( $status, self::CURRENT_STATUSES, true ) ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * Count superseded inventory rows excluded from rebuild decisions.
	 */
	private function count_skipped_stale( int $attachment_id ): int {
		$n = 0;
		foreach ( $this->objects->find_by_attachment( $attachment_id ) as $row ) {
			$status = (string) ( $row['remote_status'] ?? '' );
			if ( in_array( $status, array( ObjectRemoteStatus::STALE, ObjectRemoteStatus::DELETED ), true ) ) {
				++$n;
			}
		}
		return $n;
	}

	private function gateway( StorageProfile $profile ): ProfileStorageGateway {
		if ( is_callable( $this->gateway_factory ) ) {
			return ( $this->gateway_factory )( $profile );
		}
		return new ProfileStorageGateway( $profile, $this->settings );
	}

	/**
	 * @return array<string, int>
	 */
	private function empty_counts(): array {
		return array(
			'scanned'                                   => 0,
			'present'                                   => 0,
			'present_adopted'                           => 0,
			'exists_only'                               => 0,
			'confirmed_missing'                         => 0,
			'conflicts'                                 => 0,
			'transient'                                 => 0,
			'auth'                                      => 0,
			'invalid_request'                           => 0,
			'unknown'                                   => 0,
			'ambiguous_profile'                         => 0,
			'ambiguous_key'                             => 0,
			'explicit_profile_conflicts_surviving_rows' => 0,
			'key_owned_by_other_attachment'             => 0,
			'key_owned_by_other_relative'               => 0,
			'relative_key_conflict'                     => 0,
			'lock_conflict'                             => 0,
			'already_tracked'                           => 0,
			'skipped_stale'                             => 0,
		);
	}

	private function count_key_for_class( string $class ): string {
		return match ( $class ) {
			self::CLASS_PRESENT => 'present',
			self::CLASS_EXISTS_ONLY => 'exists_only',
			self::CLASS_CONFIRMED_MISSING => 'confirmed_missing',
			self::CLASS_SIZE_CONFLICT => 'conflicts',
			self::CLASS_TRANSIENT => 'transient',
			self::CLASS_AUTH => 'auth',
			self::CLASS_INVALID_REQUEST => 'invalid_request',
			self::CLASS_UNKNOWN => 'unknown',
			self::CLASS_KEY_OWNED_BY_OTHER_ATTACHMENT => 'key_owned_by_other_attachment',
			self::CLASS_KEY_OWNED_BY_OTHER_RELATIVE => 'key_owned_by_other_relative',
			self::CLASS_RELATIVE_KEY_CONFLICT => 'relative_key_conflict',
			self::CLASS_AMBIGUOUS_KEY => 'ambiguous_key',
			self::CLASS_LOCK_CONFLICT => 'lock_conflict',
			'ambiguous_profile' => 'ambiguous_profile',
			'explicit_profile_conflicts_surviving_rows' => 'explicit_profile_conflicts_surviving_rows',
			default => 'unknown',
		};
	}

	/**
	 * @param array<string, mixed> $result Result.
	 * @return array<string, mixed>
	 */
	private function finalize_outcome( array $result ): array {
		$counts  = is_array( $result['counts'] ?? null ) ? $result['counts'] : $this->empty_counts();
		$dry_run = ! empty( $result['dry_run'] );
		$forced  = isset( $result['forced_outcome'] ) ? (string) $result['forced_outcome'] : '';
		$outcome = $forced !== ''
			? $forced
			: $this->derive_outcome( $counts, (array) ( $result['results'] ?? array() ) );

		$message = match ( $outcome ) {
			self::OUTCOME_COMPLETE => $dry_run ? 'Rebuild dry-run complete.' : 'Rebuild apply complete.',
			self::OUTCOME_PARTIAL => $dry_run ? 'Rebuild dry-run partially complete.' : 'Rebuild apply partially complete.',
			default => $dry_run ? 'Rebuild dry-run failed.' : 'Rebuild apply failed.',
		};
		// Prefer explicit failure messages (lock/not-found/forced). Optimistic "complete" is no longer passed in.
		if ( $outcome === self::OUTCOME_FAILED && ( $result['message'] ?? '' ) !== '' ) {
			$message = (string) $result['message'];
		}

		unset( $result['forced_outcome'] );
		$result['counts']  = $counts;
		$result['outcome'] = $outcome;
		$result['success'] = $outcome === self::OUTCOME_COMPLETE;
		$result['message'] = $message;
		return $result;
	}

	/**
	 * @param array<string, int>       $counts Counts.
	 * @param list<array<string,mixed>> $results Per-row results.
	 */
	private function derive_outcome( array $counts, array $results ): string {
		$blocking = 0;
		foreach ( self::BLOCKING_COUNT_KEYS as $key ) {
			$blocking += (int) ( $counts[ $key ] ?? 0 );
		}

		// EXISTS_ONLY is a resolved observation, but never counted as size-verified present.
		$resolved = (int) ( $counts['present'] ?? 0 )
			+ (int) ( $counts['exists_only'] ?? 0 )
			+ (int) ( $counts['confirmed_missing'] ?? 0 );

		if ( $blocking === 0 ) {
			return self::OUTCOME_COMPLETE;
		}
		if ( $resolved > 0 || (int) ( $counts['present_adopted'] ?? 0 ) > 0 ) {
			return self::OUTCOME_PARTIAL;
		}
		if ( $results === array() && $blocking > 0 ) {
			return self::OUTCOME_FAILED;
		}
		return self::OUTCOME_FAILED;
	}

	/**
	 * @param array<string, int> $counts Counts.
	 * @return array<string, mixed>
	 */
	private function fail( string $message, array $counts, ?int $profile_id = null, bool $dry_run = true ): array {
		return $this->finalize_outcome(
			array(
				'success'         => false,
				'message'         => $message,
				'dry_run'         => $dry_run,
				'profile_id'      => $profile_id,
				'counts'          => $counts,
				'results'         => array(),
				'adopted'         => 0,
				'forced_outcome'  => self::OUTCOME_FAILED,
			)
		);
	}
}
