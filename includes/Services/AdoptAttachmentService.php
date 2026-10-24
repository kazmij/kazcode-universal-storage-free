<?php
/**
 * HEAD-based adoption of remote objects into s3ms_objects (v2 Phase 8).
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
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Infrastructure\AttachmentLeaseHandle;
use Kazcode\WpStorage\Infrastructure\AttachmentLock;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
use Kazcode\WpStorage\Storage\ObjectKeyService;
use Kazcode\WpStorage\Storage\ProfileStorageGateway;

/**
 * Discovers existing remote objects via HEAD — never uploads.
 */
final class AdoptAttachmentService {

	public function __construct(
		private Settings $settings,
		private ?ManifestBuilder $manifest_builder = null,
		private ?ObjectRepository $objects = null,
		private ?WpdbStorageProfileRepository $profiles = null,
		private $gateway_factory = null,
		private ?AttachmentLock $lock = null,
	) {
		$this->manifest_builder = $manifest_builder ?? new ManifestBuilder();
		$this->objects          = $objects ?? new ObjectRepository();
		$this->profiles         = $profiles ?? new WpdbStorageProfileRepository();
		$this->lock             = $lock ?? new AttachmentLock();
	}

	/**
	 * @return array{success:bool,message:string,adopted:int,missing:int,errors?:int,dry_run?:bool,status?:string,results?:list<array<string,mixed>>}
	 */
	public function adopt( int $attachment_id, ?int $profile_id = null, bool $dry_run = false ): array {
		if ( $attachment_id <= 0 ) {
			return array(
				'success' => false,
				'message' => 'attachment_id is required.',
				'adopted' => 0,
				'missing' => 0,
			);
		}

		$post = get_post( $attachment_id );
		if ( ! $post || $post->post_type !== 'attachment' ) {
			return array(
				'success' => false,
				'message' => 'Attachment not found.',
				'adopted' => 0,
				'missing' => 0,
			);
		}

		$profile = $this->resolve_profile( $profile_id );
		if ( $profile === null || $profile->id === null ) {
			return array(
				'success' => false,
				'message' => 'No storage profile configured.',
				'adopted' => 0,
				'missing' => 0,
			);
		}

		$manifest = $this->manifest_builder->build( $attachment_id );
		if ( $manifest->items() === array() ) {
			return array(
				'success' => false,
				'message' => 'Attachment has no manifest paths.',
				'adopted' => 0,
				'missing' => 0,
			);
		}

		$descriptors = array();
		foreach ( $manifest->items() as $item ) {
			$relative = (string) $item['relative'];
			$descriptors[] = array(
				'relative'     => $relative,
				'key'          => ObjectKeyService::key_for( $profile->prefix, $relative ),
				'variant_type' => (string) ( $item['variant_type'] ?? 'size' ),
			);
		}

		return $this->adopt_verified_descriptors( $attachment_id, $profile, $descriptors, $dry_run );
	}

	/**
	 * Adopt exact remote keys supplied by importer-compatible descriptors.
	 *
	 * @param array<int, mixed> $descriptors Descriptor arrays or DTO-like objects.
	 *
	 * @return array{success:bool,message:string,adopted:int,missing:int,errors:int,conflicts:int,dry_run?:bool,status?:string,results:list<array<string,mixed>>}
	 */
	public function adopt_descriptors( int $attachment_id, int $profile_id, array $descriptors, bool $dry_run = false ): array {
		if ( $attachment_id <= 0 ) {
			return array(
				'success'   => false,
				'message'   => 'attachment_id is required.',
				'adopted'   => 0,
				'missing'   => 0,
				'errors'    => 0,
				'conflicts' => 0,
				'results'   => array(),
			);
		}

		$post = get_post( $attachment_id );
		if ( ! $post || $post->post_type !== 'attachment' ) {
			return array(
				'success'   => false,
				'message'   => 'Attachment not found.',
				'adopted'   => 0,
				'missing'   => 0,
				'errors'    => 0,
				'conflicts' => 0,
				'results'   => array(),
			);
		}

		$profile = $this->resolve_profile( $profile_id );
		if ( $profile === null || $profile->id === null ) {
			return array(
				'success'   => false,
				'message'   => 'No storage profile configured.',
				'adopted'   => 0,
				'missing'   => 0,
				'errors'    => 0,
				'conflicts' => 0,
				'results'   => array(),
			);
		}

		$prepared = array();
		foreach ( $descriptors as $descriptor ) {
			$relative = $this->descriptor_value( $descriptor, array( 'wp_relative_path', 'relative', 'relative_path', 'local_relative_path' ) );
			$key      = $this->descriptor_raw_value( $descriptor, array( 'source_key', 'source_object_key', 'object_key', 'key' ) );
			if ( $relative === '' || trim( $key ) === '' ) {
				$prepared[] = array(
					'relative' => $relative,
					'key'      => $key,
					'skipped'  => true,
				);
				continue;
			}

			$prepared[] = array_filter(
				array(
					'relative'     => $relative,
					'key'          => $key,
					'variant_type' => $this->descriptor_value( $descriptor, array( 'variant_type' ) ) ?: 'size',
					'size_bytes'   => $this->descriptor_int_or_null( $descriptor, array( 'size_bytes', 'source_size', 'source_size_bytes' ) ),
					'mime_type'    => $this->descriptor_value( $descriptor, array( 'mime_type', 'source_mime', 'content_type' ) ),
					'etag'         => $this->descriptor_value( $descriptor, array( 'etag', 'source_etag' ) ),
				),
				static fn( $value ): bool => $value !== null && $value !== ''
			);
		}

		if ( $prepared === array() ) {
			return array(
				'success'   => false,
				'message'   => 'No descriptors supplied.',
				'adopted'   => 0,
				'missing'   => 0,
				'errors'    => 0,
				'conflicts' => 0,
				'results'   => array(),
			);
		}

		return $this->adopt_verified_descriptors( $attachment_id, $profile, $prepared, $dry_run );
	}

	/**
	 * @param list<array<string, mixed>> $descriptors Prepared descriptors with exact keys.
	 *
	 * @return array{success:bool,message:string,adopted:int,missing:int,errors:int,conflicts:int,dry_run?:bool,status?:string,results:list<array<string,mixed>>}
	 */
	private function adopt_verified_descriptors( int $attachment_id, StorageProfile $profile, array $descriptors, bool $dry_run ): array {
		if ( $dry_run ) {
			return $this->adopt_verified_descriptors_unlocked( $attachment_id, $profile, $descriptors, true );
		}

		$lease = $this->lock->acquire_lease( $attachment_id, 'adopt' );
		if ( ! $lease instanceof AttachmentLeaseHandle ) {
			return array(
				'success'   => false,
				'message'   => 'Attachment is locked by another operation.',
				'adopted'   => 0,
				'missing'   => 0,
				'errors'    => 0,
				'conflicts' => 1,
				'results'   => array(
					array(
						'relative'       => '',
						'key'            => '',
						'exists'         => false,
						'classification' => 'conflict',
						'error'          => 'Attachment is locked by another operation.',
					),
				),
			);
		}

		try {
			return $this->adopt_verified_descriptors_unlocked( $attachment_id, $profile, $descriptors, false, $lease );
		} finally {
			$this->lock->release_lease( $lease );
		}
	}

	/**
	 * @param list<array<string, mixed>> $descriptors Verified descriptor payloads.
	 * @return array{success:bool,message:string,adopted:int,missing:int,errors:int,conflicts:int,dry_run?:bool,status?:string,results:list<array<string,mixed>>}
	 */
	private function adopt_verified_descriptors_unlocked(
		int $attachment_id,
		StorageProfile $profile,
		array $descriptors,
		bool $dry_run,
		?AttachmentLeaseHandle $lease = null
	): array {
		$gateway       = $this->gateway( $profile );
		$existing_rows = $this->objects->find_by_attachment( $attachment_id );
		$results       = array();
		$adopted       = 0;
		$missing       = 0;
		$errors        = 0;
		$conflicts     = 0;
		$skipped       = 0;
		$mutated       = false;

		foreach ( $descriptors as $descriptor ) {
			$relative = (string) ( $descriptor['relative'] ?? '' );
			$key      = (string) ( $descriptor['key'] ?? '' );

			$row_result = array(
				'relative' => $relative,
				'key'      => $key,
				'exists'   => false,
			);

			if ( ! empty( $descriptor['skipped'] ) || $relative === '' || $key === '' ) {
				++$skipped;
				$row_result['classification'] = 'skipped';
				$row_result['error']          = 'Descriptor relative path and source key are required.';
				$results[]                    = $row_result;
				continue;
			}

			$conflict = $this->active_conflict_for_descriptor( $existing_rows, $profile, $relative, $key );
			if ( $conflict !== null ) {
				++$conflicts;
				$row_result['classification'] = 'conflict';
				$row_result['error']          = 'Attachment already has an active object row for a different profile/key.';
				$row_result['conflict']       = array(
					'storage_profile_id' => (int) ( $conflict['storage_profile_id'] ?? 0 ),
					'object_key'         => (string) ( $conflict['object_key'] ?? '' ),
					'remote_status'      => (string) ( $conflict['remote_status'] ?? '' ),
				);
				$results[]                    = $row_result;
				continue;
			}

			$conflict = $this->active_profile_key_conflict( $attachment_id, $profile, $relative, $key );
			if ( $conflict !== null ) {
				++$conflicts;
				$row_result['classification'] = 'conflict';
				$row_result['error']          = 'Remote object key already has an active KAZCODE owner.';
				$row_result['conflict']       = array(
					'attachment_id'      => (int) ( $conflict['attachment_id'] ?? 0 ),
					'storage_profile_id' => (int) ( $conflict['storage_profile_id'] ?? 0 ),
					'object_key'         => (string) ( $conflict['object_key'] ?? '' ),
					'remote_status'      => (string) ( $conflict['remote_status'] ?? '' ),
				);
				$results[]                    = $row_result;
				continue;
			}

			$head                 = $gateway->head_key( $key );
			$row_result['exists'] = ! empty( $head['exists'] );

			if ( empty( $head['exists'] ) ) {
				// A HEAD failure that is not a confirmed 404/NoSuchKey (network, throttling,
				// auth) must NOT be recorded as remote_status=missing — the object may well
				// still be present, and persisting "missing" here would be a false record
				// that later drives an unnecessary repair re-upload. Skip the row instead and
				// let a future adopt/repair run re-check it once the transient error clears.
				if ( empty( $head['confirmed_missing'] ) ) {
					++$errors;
					$row_result['classification'] = 'transient_error';
					$row_result['error']          = (string) ( $head['error'] ?? 'HEAD request failed.' );
					$results[]                    = $row_result;
					continue;
				}

				++$missing;
				$row_result['classification'] = 'confirmed_missing';
				if ( ! $dry_run ) {
					if ( ! $this->renew_lease_or_fail( $lease ) ) {
						++$conflicts;
						$row_result['classification'] = 'conflict';
						$row_result['error']          = 'Attachment lease lost during adopt.';
						$results[]                    = $row_result;
						return $this->adopt_finalize( $attachment_id, $existing_rows, $results, $adopted, $missing, $errors, $conflicts, $skipped, $mutated, false );
					}
					$write = $this->objects->insert_owned_or_conflict(
						array(
							'attachment_id'       => $attachment_id,
							'storage_profile_id'  => $profile->id,
							'local_relative_path' => $relative,
							'object_key'          => $key,
							'variant_type'        => (string) ( $descriptor['variant_type'] ?? 'size' ),
							'remote_status'       => ObjectRemoteStatus::MISSING,
						)
					);
					if ( empty( $write['ok'] ) ) {
						--$missing;
						++$conflicts;
						$row_result['classification'] = 'conflict';
						$row_result['error']          = 'Owner-preserving inventory write refused.';
						$results[]                    = $row_result;
						continue;
					}
					$mutated = true;
					$this->sync_meta_from_inventory( $attachment_id );
				}
				$results[] = $row_result;
				continue;
			}

			if ( ! $this->head_matches_expected_size( $head, $descriptor ) ) {
				++$conflicts;
				$row_result['classification'] = 'conflict';
				$row_result['error']          = 'Remote object size does not match descriptor.';
				$results[]                    = $row_result;
				continue;
			}

			++$adopted;
			$row_result['classification'] = 'verified_present';
			if ( $dry_run ) {
				$results[] = $row_result;
				continue;
			}

			if ( ! $this->renew_lease_or_fail( $lease ) ) {
				--$adopted;
				++$conflicts;
				$row_result['classification'] = 'conflict';
				$row_result['error']          = 'Attachment lease lost during adopt.';
				$results[]                    = $row_result;
				return $this->adopt_finalize( $attachment_id, $existing_rows, $results, $adopted, $missing, $errors, $conflicts, $skipped, $mutated, false );
			}

			$now  = gmdate( 'Y-m-d H:i:s' );
			$data = array(
				'attachment_id'       => $attachment_id,
				'storage_profile_id'  => $profile->id,
				'local_relative_path' => $relative,
				'object_key'          => $key,
				'variant_type'        => (string) ( $descriptor['variant_type'] ?? 'size' ),
				'size_bytes'          => (int) ( $head['content_length'] ?? ( $descriptor['size_bytes'] ?? 0 ) ),
				'remote_status'       => ObjectRemoteStatus::PRESENT,
				'verified_at'         => $now,
				'offloaded_at'        => $now,
			);
			$mime = (string) ( $head['content_type'] ?? ( $descriptor['mime_type'] ?? '' ) );
			if ( $mime !== '' ) {
				$data['mime_type'] = $mime;
			}
			if ( ! empty( $descriptor['etag'] ) ) {
				$data['etag'] = (string) $descriptor['etag'];
			}
			$write = $this->objects->insert_owned_or_conflict( $data );
			if ( empty( $write['ok'] ) ) {
				--$adopted;
				++$conflicts;
				$row_result['classification'] = 'conflict';
				$row_result['error']          = 'Owner-preserving inventory write refused.';
				$results[]                    = $row_result;
				continue;
			}
			$mutated   = true;
			// Do not promote serving meta to offloaded after a single PRESENT write —
			// later descriptors may still fail or the lease may expire mid-scan.
			$results[] = $row_result;
		}

		if ( $dry_run ) {
			return array(
				'success'   => $errors === 0 && $conflicts === 0,
				'message'   => sprintf(
					'Dry run: would adopt %d object(s); %d missing on remote%s%s.',
					$adopted,
					$missing,
					$errors > 0 ? sprintf( '; %d could not be checked (transient error)', $errors ) : '',
					$conflicts > 0 ? sprintf( '; %d conflict(s)', $conflicts ) : ''
				),
				'adopted'   => $adopted,
				'missing'   => $missing,
				'errors'    => $errors,
				'conflicts' => $conflicts,
				'skipped'   => $skipped,
				'dry_run'   => true,
				'results'   => $results,
			);
		}

		if ( $mutated && ! $this->renew_lease_or_fail( $lease ) ) {
			++$conflicts;
			$results[] = array(
				'relative'       => '',
				'key'            => '',
				'exists'         => false,
				'classification' => 'conflict',
				'error'          => 'Attachment lease lost before adopt metadata sync.',
			);
			return $this->adopt_finalize( $attachment_id, $existing_rows, $results, $adopted, $missing, $errors, $conflicts, $skipped, true, false );
		}

		// Positive serving promotion only when every descriptor finished without
		// transient/conflict/skip gaps — otherwise a lone present row would derive offloaded.
		$allow_meta_sync = $errors === 0 && $conflicts === 0 && $skipped === 0;
		return $this->adopt_finalize( $attachment_id, $existing_rows, $results, $adopted, $missing, $errors, $conflicts, $skipped, $mutated, $allow_meta_sync );
	}

	/**
	 * @param list<array<string, mixed>> $existing_rows Pre-adopt rows.
	 * @param list<array<string, mixed>> $results       Per-descriptor results.
	 * @return array{success:bool,message:string,adopted:int,missing:int,errors:int,conflicts:int,status?:string,results:list<array<string,mixed>>}
	 */
	private function adopt_finalize(
		int $attachment_id,
		array $existing_rows,
		array $results,
		int $adopted,
		int $missing,
		int $errors,
		int $conflicts,
		int $skipped,
		bool $mutated,
		bool $allow_meta_sync
	): array {
		$status = AttachmentSyncDeriver::derive_status( $existing_rows );
		if ( $mutated && $allow_meta_sync ) {
			$rows   = $this->objects->find_by_attachment( $attachment_id );
			$status = AttachmentSyncDeriver::derive_status( $rows );
			$this->sync_attachment_meta( $attachment_id, $rows, $status );
		}
		if ( $mutated ) {
			( new ObjectStatsAggregator( $this->objects ) )->invalidate();
		}

		$message = sprintf( 'Adopted %d object(s).', $adopted );
		if ( $missing > 0 ) {
			$message = sprintf( 'Adopted %d object(s); %d missing on remote.', $adopted, $missing );
		}
		if ( $errors > 0 ) {
			$message .= sprintf( ' %d object(s) could not be checked (transient error) and were left unchanged.', $errors );
		}
		if ( $conflicts > 0 ) {
			$message .= sprintf( ' %d conflict(s) were left unchanged.', $conflicts );
		}

		return array(
			'success'   => $adopted > 0 && $missing === 0 && $errors === 0 && $conflicts === 0,
			'message'   => $message,
			'adopted'   => $adopted,
			'missing'   => $missing,
			'errors'    => $errors,
			'conflicts' => $conflicts,
			'skipped'   => $skipped,
			'status'    => $status,
			'results'   => $results,
		);
	}

	private function renew_lease_or_fail( ?AttachmentLeaseHandle $lease ): bool {
		if ( ! $lease instanceof AttachmentLeaseHandle ) {
			return true;
		}
		return $this->lock->renew( $lease );
	}

	/**
	 * Refresh attachment cache from current inventory while this worker still holds the lease.
	 */
	private function sync_meta_from_inventory( int $attachment_id ): void {
		$rows   = $this->objects->find_by_attachment( $attachment_id );
		$status = AttachmentSyncDeriver::derive_status( $rows );
		$this->sync_attachment_meta( $attachment_id, $rows, $status );
	}

	/**
	 * @param mixed $descriptor Descriptor array or DTO-like object.
	 * @param list<string> $keys Candidate field names.
	 */
	private function descriptor_value( mixed $descriptor, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( is_array( $descriptor ) && array_key_exists( $key, $descriptor ) ) {
				return trim( (string) $descriptor[ $key ] );
			}
			if ( is_object( $descriptor ) && isset( $descriptor->{$key} ) ) {
				return trim( (string) $descriptor->{$key} );
			}
			if ( is_object( $descriptor ) && is_callable( array( $descriptor, $key ) ) ) {
				return trim( (string) $descriptor->{$key}() );
			}
		}
		return '';
	}

	/**
	 * @param mixed $descriptor Descriptor array or DTO-like object.
	 * @param list<string> $keys Candidate field names.
	 */
	private function descriptor_raw_value( mixed $descriptor, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( is_array( $descriptor ) && array_key_exists( $key, $descriptor ) ) {
				return (string) $descriptor[ $key ];
			}
			if ( is_object( $descriptor ) && isset( $descriptor->{$key} ) ) {
				return (string) $descriptor->{$key};
			}
			if ( is_object( $descriptor ) && is_callable( array( $descriptor, $key ) ) ) {
				return (string) $descriptor->{$key}();
			}
		}
		return '';
	}

	/**
	 * @param mixed $descriptor Descriptor array or DTO-like object.
	 * @param list<string> $keys Candidate field names.
	 */
	private function descriptor_int_or_null( mixed $descriptor, array $keys ): ?int {
		$value = $this->descriptor_value( $descriptor, $keys );
		if ( $value === '' ) {
			return null;
		}
		return max( 0, (int) $value );
	}

	/**
	 * @param list<array<string, mixed>> $rows Existing object rows for the attachment.
	 *
	 * @return array<string, mixed>|null
	 */
	private function active_conflict_for_descriptor( array $rows, StorageProfile $profile, string $relative, string $key ): ?array {
		foreach ( $rows as $row ) {
			$status = (string) ( $row['remote_status'] ?? '' );
			if ( ! in_array( $status, array( ObjectRemoteStatus::PRESENT, ObjectRemoteStatus::UPLOADING ), true ) ) {
				continue;
			}
			if ( (string) ( $row['local_relative_path'] ?? '' ) !== $relative ) {
				continue;
			}
			if ( (int) ( $row['storage_profile_id'] ?? 0 ) === (int) $profile->id && (string) ( $row['object_key'] ?? '' ) === $key ) {
				continue;
			}
			return $row;
		}
		return null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function active_profile_key_conflict( int $attachment_id, StorageProfile $profile, string $relative, string $key ): ?array {
		$row = $this->objects->find_by_profile_and_key( (int) $profile->id, $key );
		if ( $row === null ) {
			return null;
		}

		$status = (string) ( $row['remote_status'] ?? '' );
		if ( ! in_array( $status, array( ObjectRemoteStatus::PRESENT, ObjectRemoteStatus::UPLOADING ), true ) ) {
			return null;
		}

		if ( (int) ( $row['attachment_id'] ?? 0 ) === $attachment_id && (string) ( $row['local_relative_path'] ?? '' ) === $relative ) {
			return null;
		}

		return $row;
	}

	/**
	 * @param array<string, mixed> $head HEAD response.
	 * @param array<string, mixed> $descriptor Prepared descriptor.
	 */
	private function head_matches_expected_size( array $head, array $descriptor ): bool {
		if ( ! array_key_exists( 'size_bytes', $descriptor ) ) {
			return true;
		}
		if ( ! array_key_exists( 'content_length', $head ) ) {
			return false;
		}
		return (int) $head['content_length'] === (int) $descriptor['size_bytes'];
	}

	private function resolve_profile( ?int $profile_id ): ?StorageProfile {
		if ( $profile_id !== null && $profile_id > 0 ) {
			return $this->profiles->find( $profile_id );
		}
		$profile = $this->profiles->find_default_upload_target();
		if ( $profile !== null ) {
			return $profile;
		}
		return ( new LegacyProfileMigrator( $this->settings, $this->profiles ) )->ensure_legacy_profile();
	}

	private function gateway( StorageProfile $profile ): ProfileStorageGateway {
		if ( is_callable( $this->gateway_factory ) ) {
			$gateway = ( $this->gateway_factory )( $profile );
			if ( $gateway instanceof ProfileStorageGateway ) {
				return $gateway;
			}
		}
		return new ProfileStorageGateway( $profile, $this->settings );
	}

	/**
	 * @param list<array<string, mixed>> $rows Object rows.
	 */
	private function sync_attachment_meta( int $attachment_id, array $rows, string $status ): void {
		update_post_meta( $attachment_id, '_s3ms_status', $status );

		$original = AttachmentSyncDeriver::original_key( $rows );
		if ( $original !== '' ) {
			update_post_meta( $attachment_id, '_s3ms_original_key', $original );
		}

		if ( $status === AttachmentOffloader::STATUS_OFFLOADED ) {
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
}
