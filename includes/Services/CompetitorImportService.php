<?php
/**
 * Durable analyze/commit orchestration for competitor imports.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\ImportRunStatus;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Import\ImportedObjectDescriptor;
use Kazcode\WpStorage\Import\ImporterInterface;
use Kazcode\WpStorage\Import\ImportRegistry;
use Kazcode\WpStorage\Infrastructure\ImportRunRepository;
use Kazcode\WpStorage\Infrastructure\ImportRunItemRepository;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
use Kazcode\WpStorage\Storage\ObjectKeyService;
use Kazcode\WpStorage\Storage\ProfileStorageGateway;

/**
 * Coordinates durable dry-run and commit import runs.
 */
final class CompetitorImportService {

	public function __construct(
		private ?ImportRegistry $registry = null,
		private ?ImportRunRepository $runs = null,
		private ?AdoptAttachmentService $adopter = null,
		private ?WpdbStorageProfileRepository $profiles = null,
		private ?Settings $settings = null,
		private ?AuditLog $audit = null,
		private ?ImportRunItemRepository $items = null,
		private ?ImportRemediationService $remediator = null
	) {
		$this->registry = $registry ?? ImportRegistry::defaults();
		$this->runs     = $runs ?? new ImportRunRepository();
		$this->profiles = $profiles ?? new WpdbStorageProfileRepository();
		$this->settings = $settings ?? new Settings();
		$this->audit    = $audit ?? new AuditLog();
		$this->items    = $items ?? new ImportRunItemRepository();
		$this->remediator = $remediator ?? new ImportRemediationService( $this->settings );
		if ( $this->adopter === null ) {
			$settings = $this->settings;
			$this->adopter = new AdoptAttachmentService(
				$this->settings,
				null,
				null,
				$this->profiles,
				static function ( StorageProfile $profile ) use ( $settings ): ProfileStorageGateway {
					return new ProfileStorageGateway( $profile, $settings );
				}
			);
		}
	}

	/** @return array<string, array<string, mixed>> */
	public function detect(): array {
		$detected = array();
		foreach ( $this->registry->all() as $id => $importer ) {
			$detected[ $id ] = $importer->detect()->to_array();
		}
		return $detected;
	}

	/** @return array<string, mixed> */
	public function start_dry_run( string $source_type, int $profile_id ): array {
		return $this->start( 'dry_run', $source_type, $profile_id );
	}

	/** @return array<string, mixed> */
	public function start_commit( string $source_type, int $profile_id ): array {
		$importer = $this->importer( $source_type );
		if ( $importer === null ) {
			return $this->error( 'unknown_source_type', 'Unknown import source type.' );
		}
		$profile = $this->profiles->find( $profile_id );
		if ( $profile === null || $profile->id === null ) {
			return $this->error( 'profile_not_found', 'Storage profile not found.' );
		}

		$fingerprint = $this->source_fingerprint( $importer, $profile );
		$dry_run     = $this->runs->find_latest_successful_dry_run( $source_type, $profile_id, $fingerprint );
		if ( $dry_run === null ) {
			return $this->error( 'matching_dry_run_required', 'Commit requires a completed dry run for the same source/profile fingerprint.' );
		}

		$active = $this->runs->find_active();
		if ( $active !== null ) {
			return $this->error( 'active_import_run_exists', 'An import run is already active for this source/profile.', $active );
		}

		return $this->create_run( 'commit', $source_type, $profile_id, $fingerprint );
	}

	/** Start an explicit upload-only remediation for one completed, unchanged dry run. */
	public function start_remediation_upload( string $dry_run_uuid ): array {
		$dry_run = $this->runs->find_by_uuid( $dry_run_uuid );
		if ( $dry_run === null || (string) ( $dry_run['mode'] ?? '' ) !== 'dry_run' || (string) ( $dry_run['status'] ?? '' ) !== ImportRunStatus::COMPLETED ) {
			return $this->error( 'matching_dry_run_required', 'Remediation requires a completed dry run.' );
		}
		$importer = $this->importer( (string) $dry_run['source_type'] );
		$profile  = $this->profiles->find( (int) $dry_run['profile_id'] );
		if ( $importer === null || ! $profile instanceof StorageProfile || $profile->id === null || ! hash_equals( (string) $dry_run['source_fingerprint'], $this->source_fingerprint( $importer, $profile ) ) ) {
			return $this->error( 'matching_dry_run_required', 'Remediation requires unchanged source and profile configuration.' );
		}
		if ( $this->runs->find_active() !== null ) {
			return $this->error( 'active_import_run_exists', 'An import run is already active for this source/profile.', $this->runs->find_active() );
		}
		return $this->create_run( 'remediate_upload', (string) $dry_run['source_type'], (int) $dry_run['profile_id'], (string) $dry_run['source_fingerprint'], (int) $dry_run['id'] );
	}

	/** @return array<string, mixed> */
	public function process_next_page( string $uuid, int $limit = 50 ): array {
		$run = $this->runs->find_by_uuid( $uuid );
		if ( $run === null ) {
			return $this->error( 'import_run_not_found', 'Import run not found.' );
		}
		if ( (string) $run['status'] === ImportRunStatus::PAUSED ) {
			return $this->error( 'import_run_paused', 'Import run is paused.', $run );
		}
		if ( in_array( (string) $run['status'], array( ImportRunStatus::COMPLETED, ImportRunStatus::CANCELLED, ImportRunStatus::FAILED ), true ) ) {
			return $this->run_response( $run );
		}
		if ( (string) $run['mode'] === 'remediate_upload' ) {
			return $this->process_remediation_page( $run, $limit );
		}

		$importer = $this->importer( (string) $run['source_type'] );
		if ( $importer === null ) {
			$this->runs->update( (int) $run['id'], array( 'status' => ImportRunStatus::FAILED, 'last_error_message' => 'Unknown import source type.' ) );
			return $this->error( 'unknown_source_type', 'Unknown import source type.', $this->runs->find_by_uuid( $uuid ) ?? $run );
		}

		$limit               = max( 1, $limit );
		$after_attachment_id = (int) ( $run['after_attachment_id'] ?? 0 );
		$page                = $importer->scan_page( $limit, $after_attachment_id );
		$counts = array(
			'processed'        => 0,
			'verified'         => 0,
			'missing'          => 0,
			'transient_errors' => 0,
			'conflicts'        => 0,
			'skipped'          => 0,
			'rejected'         => $page->rejected_attachment_count(),
		);

		$profile = $this->profiles->find( (int) $run['profile_id'] );
		if ( ! $profile instanceof StorageProfile ) {
			$this->runs->update( (int) $run['id'], array( 'status' => ImportRunStatus::FAILED, 'last_error_message' => 'Storage profile not found.' ) );
			return $this->error( 'profile_not_found', 'Storage profile not found.', $this->runs->find_by_uuid( $uuid ) ?? $run );
		}
		$counts['processed'] += $counts['rejected'];
		$counts['skipped']   += $counts['rejected'];

		$compatible = array();
		foreach ( $page->descriptors() as $descriptor ) {
			if ( $this->descriptor_matches_profile( $descriptor, $profile ) ) {
				$compatible[] = $descriptor;
				continue;
			}
			// Profile-mismatched retained locations must be visible — never silently drop.
			++$counts['skipped'];
			++$counts['processed'];
		}

		foreach ( $this->group_by_attachment( $compatible ) as $attachment_id => $descriptors ) {
			$result = $this->adopter->adopt_descriptors(
				(int) $attachment_id,
				(int) $run['profile_id'],
				$descriptors,
				(string) $run['mode'] === 'dry_run'
			);
			$counts['processed']        += count( $descriptors );
			$counts['verified']         += (int) ( $result['adopted'] ?? 0 );
			$counts['missing']          += (int) ( $result['missing'] ?? 0 );
			$counts['transient_errors'] += (int) ( $result['errors'] ?? 0 );
			$counts['conflicts']        += (int) ( $result['conflicts'] ?? 0 );
			$this->record_descriptor_outcomes( (int) $run['id'], $profile, $descriptors, $result );
		}

		$status = $page->cursor() <= $after_attachment_id ? ImportRunStatus::COMPLETED : ImportRunStatus::RUNNING;
		$update = array(
			'status'              => $status,
			'after_attachment_id' => $page->cursor(),
			'processed'           => (int) ( $run['processed'] ?? 0 ) + $counts['processed'],
			'verified'            => (int) ( $run['verified'] ?? 0 ) + $counts['verified'],
			'missing'             => (int) ( $run['missing'] ?? 0 ) + $counts['missing'],
			'transient_errors'    => (int) ( $run['transient_errors'] ?? 0 ) + $counts['transient_errors'],
			'conflicts'           => (int) ( $run['conflicts'] ?? 0 ) + $counts['conflicts'],
			'skipped'             => (int) ( $run['skipped'] ?? 0 ) + $counts['skipped'],
			'rejected'            => (int) ( $run['rejected'] ?? 0 ) + $counts['rejected'],
		);
		// Dry run that never submitted a profile-compatible descriptor must not unlock commit.
		if (
			$status === ImportRunStatus::COMPLETED
			&& (string) $run['mode'] === 'dry_run'
			&& (int) $update['verified'] === 0
			&& (int) $update['missing'] === 0
			&& (int) $update['transient_errors'] === 0
			&& (int) $update['skipped'] > 0
			&& (int) $update['processed'] === (int) $update['skipped']
		) {
			$update['status']             = ImportRunStatus::FAILED;
			$update['last_error_message'] = (int) $update['rejected'] === (int) $update['processed']
				? 'No importable descriptors were found in source metadata.'
				: 'No descriptors matched the selected storage profile.';
			$status                       = ImportRunStatus::FAILED;
		}
		if ( $status === ImportRunStatus::COMPLETED || $status === ImportRunStatus::FAILED ) {
			$update['finished_at'] = gmdate( 'Y-m-d H:i:s' );
		}
		$this->runs->update( (int) $run['id'], $update );
		$updated = $this->runs->find_by_uuid( $uuid ) ?? array_merge( $run, $update );
		if ( $status === ImportRunStatus::COMPLETED ) {
			$this->audit_event( 'import_finished', $updated );
		}
		if ( $status === ImportRunStatus::FAILED ) {
			$message = (string) ( $updated['last_error_message'] ?? 'Import run failed.' );
			return $this->error( 'import_run_failed', $message, $updated );
		}
		return $this->run_response( $updated );
	}

	/** @return array<string, mixed> */
	public function pause( string $uuid ): array {
		return $this->transition( $uuid, ImportRunStatus::PAUSED, 'import_paused' );
	}

	/** @return array<string, mixed> */
	public function resume( string $uuid ): array {
		return $this->transition( $uuid, ImportRunStatus::RUNNING, 'import_resumed', array( ImportRunStatus::PAUSED ) );
	}

	/** @return array<string, mixed> */
	public function cancel( string $uuid ): array {
		return $this->transition( $uuid, ImportRunStatus::CANCELLED, null );
	}

	/** @return array<string, mixed> */
	public function status( string $uuid ): array {
		$run = $this->runs->find_by_uuid( $uuid );
		if ( $run === null ) {
			return $this->error( 'import_run_not_found', 'Import run not found.' );
		}
		return $this->run_response( $run );
	}

	/** @return array<string, mixed> */
	private function start( string $mode, string $source_type, int $profile_id ): array {
		$importer = $this->importer( $source_type );
		if ( $importer === null ) {
			return $this->error( 'unknown_source_type', 'Unknown import source type.' );
		}
		$profile = $this->profiles->find( $profile_id );
		if ( $profile === null || $profile->id === null ) {
			return $this->error( 'profile_not_found', 'Storage profile not found.' );
		}
		$active = $this->runs->find_active();
		if ( $active !== null ) {
			return $this->error( 'active_import_run_exists', 'An import run is already active for this source/profile.', $active );
		}
		return $this->create_run( $mode, $source_type, $profile_id, $this->source_fingerprint( $importer, $profile ) );
	}

	/** @return array<string, mixed> */
	private function create_run( string $mode, string $source_type, int $profile_id, string $fingerprint, ?int $source_run_id = null ): array {
		$uuid = $this->uuid();
		$data = array(
			'uuid'                => $uuid,
			'source_type'         => $source_type,
			'profile_id'          => $profile_id,
			'mode'                => $mode,
			'status'              => ImportRunStatus::RUNNING,
			'after_attachment_id' => 0,
			'after_item_id'       => 0,
			'processed'           => 0,
			'verified'            => 0,
			'missing'             => 0,
			'transient_errors'    => 0,
			'conflicts'           => 0,
			'skipped'             => 0,
			'rejected'            => 0,
			'source_fingerprint'  => $fingerprint,
			'started_at'          => gmdate( 'Y-m-d H:i:s' ),
		);
		if ( $source_run_id !== null ) $data['source_run_id'] = $source_run_id;
		$id = $this->runs->create_if_no_active( $data );
		if ( $id <= 0 ) {
			return $this->error( 'active_import_run_exists', 'An import run is already active for this source/profile.', $this->runs->find_active() );
		}
		$run = array_merge( $data, array( 'id' => $id ) );
		$this->audit_event( 'import_started', $run );
		return $this->run_response( $run );
	}

	private function importer( string $source_type ): ?ImporterInterface {
		return $this->registry->get( $source_type );
	}

	private function source_fingerprint( ImporterInterface $importer, StorageProfile $profile ): string {
		$detection = $importer->detect()->to_array();
		unset( $detection['source_label'], $detection['warnings'] );
		$identity = array(
			'source_adapter_id' => $importer->id(),
			'detected_config'   => $detection,
			'profile'           => array(
				'id'                  => $profile->id,
				'uuid'                => $profile->uuid,
				'provider_type'       => $profile->provider_type,
				'bucket'              => $profile->bucket,
				'region'              => $profile->region,
				'endpoint'            => $profile->endpoint,
				'path_style'          => $profile->path_style,
				'prefix'              => $profile->prefix,
				'delivery_type'       => $profile->delivery_type,
				'delivery_base_url'   => $profile->delivery_base_url,
				'cdn_includes_prefix' => $profile->cdn_includes_prefix,
				'credential_mode'     => $profile->credential_mode,
			),
		);
		return hash( 'sha256', json_encode( $identity, JSON_UNESCAPED_SLASHES ) ?: '' );
	}

	/**
	 * @param list<ImportedObjectDescriptor> $descriptors
	 * @return array<int, list<ImportedObjectDescriptor>>
	 */

	private function descriptor_matches_profile( ImportedObjectDescriptor $descriptor, StorageProfile $profile ): bool {
		$fingerprint = $descriptor->source_fingerprint();
		$location    = $this->parse_descriptor_fingerprint_location( $fingerprint );
		if ( $location === null ) {
			// Built-in incomplete fingerprints (e.g. media-cloud:s3:unknown-bucket) must not
			// fail open as if they were ad-hoc non-location test fingerprints.
			foreach ( array( 'wp-offload-media:', 'advanced-media-offloader:', 'media-cloud:' ) as $prefix ) {
				if ( str_starts_with( $fingerprint, $prefix ) ) {
					return false;
				}
			}
			return true;
		}
		return $this->fingerprint_location_matches_profile( $location, $profile );
	}

	/**
	 * @return array{provider:string,bucket:string,region:?string,endpoint:?string}|null
	 */
	private function parse_descriptor_fingerprint_location( string $fingerprint ): ?array {
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
			$endpoint = null;
			if ( count( $bits ) > 3 ) {
				$raw_endpoint = implode( ':', array_slice( $bits, 3 ) );
				if ( $raw_endpoint !== '' && ! str_starts_with( $raw_endpoint, 'unknown-' ) ) {
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
	 * @param array{provider:string,bucket:string,region:?string,endpoint:?string} $location Location.
	 */
	private function fingerprint_location_matches_profile( array $location, StorageProfile $profile ): bool {
		if ( $location['bucket'] !== $profile->bucket ) {
			return false;
		}
		$family = match ( $location['provider'] ) {
			'aws', 's3', 'r2', 'wasabi', 'b2',
			'aws_s3', 'cloudflare_r2', 'minio', 'backblaze_b2',
			'other-s3', 'cloudflare', 'backblaze-s3' => 's3',
			'do', 'spaces', 'digitalocean'           => 'digitalocean',
			'gcp'                                    => 'gcs',
			default                                  => null,
		};
		$profile_family = match ( $profile->provider_type ) {
			'spaces', 'digitalocean' => 'digitalocean',
			default => 's3',
		};
		if ( $family === null || $family !== $profile_family ) {
			return false;
		}
		// Named provider without endpoint evidence must match the profile preset — otherwise a
		// Wasabi/R2/B2 fingerprint can falsely bind to any S3-family profile with the same bucket.
		if ( $location['endpoint'] === null && ! $this->named_provider_matches_profile_preset( $location['provider'], $profile->provider_type ) ) {
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

	/**
	 * When endpoint evidence is absent, require profile.provider_type to agree with a named
	 * competitor provider identity. Never HEAD a potentially different S3-compatible store
	 * based only on shared bucket+region:
	 * - named presets (Wasabi/R2/B2/MinIO/Spaces) bind only to that preset
	 * - endpointless aws/s3 bind only to unambiguous AWS identity
	 * - endpointless other-s3 fails closed (needs retained endpoint evidence)
	 */
	private function named_provider_matches_profile_preset( string $fingerprint_provider, string $profile_type ): bool {
		$expected = match ( $fingerprint_provider ) {
			'wasabi' => array( 'wasabi' ),
			'cloudflare', 'r2', 'cloudflare_r2' => array( 'r2' ),
			'backblaze-s3', 'b2', 'backblaze_b2' => array( 'b2' ),
			'minio' => array( 'minio' ),
			'do', 'spaces', 'digitalocean' => array( 'spaces', 'digitalocean' ),
			// Unambiguous AWS labels only — not custom/minio without endpoint evidence.
			'aws', 's3', 'aws_s3' => array( 'aws', 's3' ),
			// Generic Media Cloud "other-s3" with no endpoint cannot identify the store.
			'other-s3' => array(),
			default => null,
		};
		if ( $expected === null || $expected === array() ) {
			return false;
		}
		return in_array( $profile_type, $expected, true );
	}

	private function group_by_attachment( array $descriptors ): array {
		$grouped = array();
		foreach ( $descriptors as $descriptor ) {
			$grouped[ $descriptor->attachment_id() ][] = $descriptor;
		}
		return $grouped;
	}

	/** @param array<string,mixed> $run @return array<string,mixed> */
	private function process_remediation_page( array $run, int $limit ): array {
		$source_run_id = (int) ( $run['source_run_id'] ?? 0 );
		$source_run = $source_run_id > 0 ? $this->runs->find_by_id( $source_run_id ) : null;
		if ( $source_run === null || (string) ( $source_run['mode'] ?? '' ) !== 'dry_run' || (string) ( $source_run['status'] ?? '' ) !== ImportRunStatus::COMPLETED ) {
			$this->runs->update( (int) $run['id'], array( 'status' => ImportRunStatus::FAILED, 'last_error_message' => 'The remediation source dry run is unavailable.' ) );
			return $this->error( 'matching_dry_run_required', 'The remediation source dry run is unavailable.', $this->runs->find_by_uuid( (string) $run['uuid'] ) ?? $run );
		}
		$profile = $this->profiles->find( (int) $run['profile_id'] );
		if ( ! $profile instanceof StorageProfile ) {
			return $this->error( 'profile_not_found', 'Storage profile not found.', $run );
		}
		$limit = max( 1, min( 200, $limit ) );
		$page = $this->items->eligible_page( $source_run_id, (int) ( $run['after_item_id'] ?? 0 ), $limit );
		$counts = array( 'processed' => 0, 'verified' => 0, 'missing' => 0, 'transient_errors' => 0, 'conflicts' => 0 );
		$after = (int) ( $run['after_item_id'] ?? 0 );
		foreach ( $page as $item ) {
			$result = $this->remediator->remediate_item( $item, $profile );
			++$counts['processed'];
			$after = max( $after, (int) ( $item['id'] ?? 0 ) );
			if ( ( $result['classification'] ?? '' ) === 'uploaded' ) ++$counts['verified'];
			elseif ( ( $result['classification'] ?? '' ) === 'conflict' ) ++$counts['conflicts'];
			else ++$counts['transient_errors'];
		}
		$status = count( $page ) < $limit ? ImportRunStatus::COMPLETED : ImportRunStatus::RUNNING;
		$update = array( 'status' => $status, 'after_item_id' => $after, 'processed' => (int) $run['processed'] + $counts['processed'], 'verified' => (int) $run['verified'] + $counts['verified'], 'missing' => (int) $run['missing'], 'transient_errors' => (int) $run['transient_errors'] + $counts['transient_errors'], 'conflicts' => (int) $run['conflicts'] + $counts['conflicts'] );
		if ( $status === ImportRunStatus::COMPLETED ) $update['finished_at'] = gmdate( 'Y-m-d H:i:s' );
		$this->runs->update( (int) $run['id'], $update );
		return $this->run_response( $this->runs->find_by_uuid( (string) $run['uuid'] ) ?? array_merge( $run, $update ) );
	}

	/** @param list<ImportedObjectDescriptor> $descriptors @param array<string,mixed> $result */
	private function record_descriptor_outcomes( int $run_id, StorageProfile $profile, array $descriptors, array $result ): void {
		$by_identity = array();
		foreach ( (array) ( $result['results'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$relative = (string) ( $row['relative'] ?? '' );
			$key      = (string) ( $row['key'] ?? '' );
			$class    = (string) ( $row['classification'] ?? '' );
			if ( $relative !== '' && $key !== '' && in_array( $class, array( 'verified_present', 'confirmed_missing', 'transient_error', 'conflict' ), true ) ) {
				$by_identity[ $relative . "\0" . $key ] = $class;
			}
		}
		$remaining = array(
			'verified_present' => (int) ( $result['adopted'] ?? 0 ),
			'confirmed_missing' => (int) ( $result['missing'] ?? 0 ),
			'transient_error' => (int) ( $result['errors'] ?? 0 ),
			'conflict' => (int) ( $result['conflicts'] ?? 0 ),
		);
		foreach ( $descriptors as $descriptor ) {
			$identity       = $descriptor->wp_relative_path() . "\0" . $descriptor->source_object_key();
			$classification = $by_identity[ $identity ] ?? '';
			if ( $classification === '' ) {
				$classification = 'conflict';
				foreach ( array( 'verified_present', 'confirmed_missing', 'transient_error', 'conflict' ) as $candidate ) {
					if ( $remaining[ $candidate ] > 0 ) {
						$classification = $candidate;
						--$remaining[ $candidate ];
						break;
					}
				}
			}
			$this->items->record( $run_id, $descriptor, $classification, null, ObjectKeyService::key_for( $profile->prefix, $descriptor->wp_relative_path() ) );
		}
	}

	/**
	 * @param array<string, mixed> $run
	 * @return array<string, mixed>
	 */
	private function run_response( array $run ): array {
		$failed   = (string) $run['status'] === ImportRunStatus::FAILED;
		$response = array(
			'success'            => ! $failed,
			'uuid'               => (string) $run['uuid'],
			'source_type'        => (string) $run['source_type'],
			'profile_id'         => (int) $run['profile_id'],
			'mode'               => (string) $run['mode'],
			'status'             => (string) $run['status'],
			'after_attachment_id' => (int) ( $run['after_attachment_id'] ?? 0 ),
			'after_item_id'       => (int) ( $run['after_item_id'] ?? 0 ),
			'processed'          => (int) ( $run['processed'] ?? 0 ),
			'verified'           => (int) ( $run['verified'] ?? 0 ),
			'missing'            => (int) ( $run['missing'] ?? 0 ),
			'transient_errors'   => (int) ( $run['transient_errors'] ?? 0 ),
			'conflicts'          => (int) ( $run['conflicts'] ?? 0 ),
			'skipped'            => (int) ( $run['skipped'] ?? 0 ),
			'rejected'           => (int) ( $run['rejected'] ?? 0 ),
			'objects_checked'    => (int) ( $run['verified'] ?? 0 ) + (int) ( $run['missing'] ?? 0 ) + (int) ( $run['transient_errors'] ?? 0 ) + (int) ( $run['conflicts'] ?? 0 ),
			'elapsed_seconds'    => max( 0, time() - (int) strtotime( (string) ( $run['started_at'] ?? 'now' ) ) ),
			'estimated_remaining_seconds' => null,
		);
		if ( $failed ) {
			$response['code']    = 'import_run_failed';
			$response['message'] = (string) ( $run['last_error_message'] ?? 'Import run failed.' );
		}
		return $response;
	}

	/** @param array<string, mixed>|null $run @return array<string, mixed> */
	private function error( string $code, string $message, ?array $run = null ): array {
		$response = array(
			'success' => false,
			'code'    => $code,
			'message' => $message,
		);
		return $run === null ? $response : array_merge( $this->run_response( $run ), $response );
	}

	/**
	 * @param list<string>|null $from
	 * @return array<string, mixed>
	 */
	private function transition( string $uuid, string $status, ?string $audit_action, ?array $from = null ): array {
		$run = $this->runs->find_by_uuid( $uuid );
		if ( $run === null ) {
			return $this->error( 'import_run_not_found', 'Import run not found.' );
		}
		if ( $from !== null && ! in_array( (string) $run['status'], $from, true ) ) {
			return $this->run_response( $run );
		}
		if ( in_array( (string) $run['status'], array( ImportRunStatus::COMPLETED, ImportRunStatus::CANCELLED, ImportRunStatus::FAILED ), true ) ) {
			return $this->run_response( $run );
		}
		$this->runs->update( (int) $run['id'], array( 'status' => $status ) );
		$updated = $this->runs->find_by_uuid( $uuid ) ?? array_merge( $run, array( 'status' => $status ) );
		if ( $audit_action !== null ) {
			$this->audit_event( $audit_action, $updated );
		}
		return $this->run_response( $updated );
	}

	/** @param array<string, mixed> $run */
	private function audit_event( string $action, array $run ): void {
		$this->audit->record(
			$action,
			array(
				'source_type'      => (string) $run['source_type'],
				'profile_id'       => (int) $run['profile_id'],
				'uuid'             => (string) $run['uuid'],
				'processed'        => (int) ( $run['processed'] ?? 0 ),
				'verified'         => (int) ( $run['verified'] ?? 0 ),
				'missing'          => (int) ( $run['missing'] ?? 0 ),
				'transient_errors' => (int) ( $run['transient_errors'] ?? 0 ),
				'conflicts'        => (int) ( $run['conflicts'] ?? 0 ),
				'skipped'          => (int) ( $run['skipped'] ?? 0 ),
				'rejected'         => (int) ( $run['rejected'] ?? 0 ),
			)
		);
	}

	private function uuid(): string {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		$hex      = bin2hex( $bytes );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 );
	}
}
