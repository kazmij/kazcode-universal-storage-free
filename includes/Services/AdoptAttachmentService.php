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
	) {
		$this->manifest_builder = $manifest_builder ?? new ManifestBuilder();
		$this->objects          = $objects ?? new ObjectRepository();
		$this->profiles         = $profiles ?? new WpdbStorageProfileRepository();
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

		$gateway = $this->gateway( $profile );
		$results = array();
		$adopted = 0;
		$missing = 0;
		$errors  = 0;

		foreach ( $manifest->items() as $item ) {
			$relative = (string) $item['relative'];
			$key      = ObjectKeyService::key_for( $profile->prefix, $relative );
			$head     = $gateway->head_key( $key );

			$row_result = array(
				'relative' => $relative,
				'key'      => $key,
				'exists'   => ! empty( $head['exists'] ),
			);

			if ( empty( $head['exists'] ) ) {
				// A HEAD failure that is not a confirmed 404/NoSuchKey (network, throttling,
				// auth) must NOT be recorded as remote_status=missing — the object may well
				// still be present, and persisting "missing" here would be a false record
				// that later drives an unnecessary repair re-upload. Skip the row instead and
				// let a future adopt/repair run re-check it once the transient error clears.
				if ( empty( $head['confirmed_missing'] ) ) {
					++$errors;
					$row_result['error'] = (string) ( $head['error'] ?? 'HEAD request failed.' );
					$results[]           = $row_result;
					continue;
				}

				++$missing;
				if ( ! $dry_run ) {
					$this->objects->upsert(
						array(
							'attachment_id'       => $attachment_id,
							'storage_profile_id'  => $profile->id,
							'local_relative_path' => $relative,
							'object_key'          => $key,
							'variant_type'        => (string) ( $item['variant_type'] ?? 'size' ),
							'remote_status'       => ObjectRemoteStatus::MISSING,
						)
					);
				}
				$results[] = $row_result;
				continue;
			}

			++$adopted;
			if ( $dry_run ) {
				$results[] = $row_result;
				continue;
			}

			$now = gmdate( 'Y-m-d H:i:s' );
			$this->objects->upsert(
				array(
					'attachment_id'       => $attachment_id,
					'storage_profile_id'  => $profile->id,
					'local_relative_path' => $relative,
					'object_key'          => $key,
					'variant_type'        => (string) ( $item['variant_type'] ?? 'size' ),
					'size_bytes'          => (int) ( $head['content_length'] ?? 0 ),
					'remote_status'       => ObjectRemoteStatus::PRESENT,
					'verified_at'         => $now,
					'offloaded_at'        => $now,
				)
			);
			$results[] = $row_result;
		}

		if ( $dry_run ) {
			return array(
				'success' => $errors === 0,
				'message' => sprintf(
					'Dry run: would adopt %d object(s); %d missing on remote%s.',
					$adopted,
					$missing,
					$errors > 0 ? sprintf( '; %d could not be checked (transient error)', $errors ) : ''
				),
				'adopted' => $adopted,
				'missing' => $missing,
				'errors'  => $errors,
				'dry_run' => true,
				'results' => $results,
			);
		}

		$rows   = $this->objects->find_by_attachment( $attachment_id );
		$status = AttachmentSyncDeriver::derive_status( $rows );
		$this->sync_attachment_meta( $attachment_id, $rows, $status );

		( new ObjectStatsAggregator( $this->objects ) )->invalidate();

		$message = sprintf( 'Adopted %d object(s).', $adopted );
		if ( $missing > 0 ) {
			$message = sprintf( 'Adopted %d object(s); %d missing on remote.', $adopted, $missing );
		}
		if ( $errors > 0 ) {
			$message .= sprintf( ' %d object(s) could not be checked (transient error) and were left unchanged.', $errors );
		}

		return array(
			'success' => $adopted > 0 && $missing === 0 && $errors === 0,
			'message' => $message,
			'adopted' => $adopted,
			'missing' => $missing,
			'errors'  => $errors,
			'status'  => $status,
			'results' => $results,
		);
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
