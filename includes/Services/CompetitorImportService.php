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
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
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
		private ?AuditLog $audit = null
	) {
		$this->registry = $registry ?? ImportRegistry::defaults();
		$this->runs     = $runs ?? new ImportRunRepository();
		$this->profiles = $profiles ?? new WpdbStorageProfileRepository();
		$this->settings = $settings ?? new Settings();
		$this->audit    = $audit ?? new AuditLog();
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
		);

		foreach ( $this->group_by_attachment( $page->descriptors() ) as $attachment_id => $descriptors ) {
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
		);
		if ( $status === ImportRunStatus::COMPLETED ) {
			$update['finished_at'] = gmdate( 'Y-m-d H:i:s' );
		}
		$this->runs->update( (int) $run['id'], $update );
		$updated = $this->runs->find_by_uuid( $uuid ) ?? array_merge( $run, $update );
		if ( $status === ImportRunStatus::COMPLETED ) {
			$this->audit_event( 'import_finished', $updated );
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
	private function create_run( string $mode, string $source_type, int $profile_id, string $fingerprint ): array {
		$uuid = $this->uuid();
		$data = array(
			'uuid'                => $uuid,
			'source_type'         => $source_type,
			'profile_id'          => $profile_id,
			'mode'                => $mode,
			'status'              => ImportRunStatus::RUNNING,
			'after_attachment_id' => 0,
			'processed'           => 0,
			'verified'            => 0,
			'missing'             => 0,
			'transient_errors'    => 0,
			'conflicts'           => 0,
			'source_fingerprint'  => $fingerprint,
			'started_at'          => gmdate( 'Y-m-d H:i:s' ),
		);
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
	private function group_by_attachment( array $descriptors ): array {
		$grouped = array();
		foreach ( $descriptors as $descriptor ) {
			$grouped[ $descriptor->attachment_id() ][] = $descriptor;
		}
		return $grouped;
	}

	/**
	 * @param array<string, mixed> $run
	 * @return array<string, mixed>
	 */
	private function run_response( array $run ): array {
		return array(
			'success'            => true,
			'uuid'               => (string) $run['uuid'],
			'source_type'        => (string) $run['source_type'],
			'profile_id'         => (int) $run['profile_id'],
			'mode'               => (string) $run['mode'],
			'status'             => (string) $run['status'],
			'after_attachment_id' => (int) ( $run['after_attachment_id'] ?? 0 ),
			'processed'          => (int) ( $run['processed'] ?? 0 ),
			'verified'           => (int) ( $run['verified'] ?? 0 ),
			'missing'            => (int) ( $run['missing'] ?? 0 ),
			'transient_errors'   => (int) ( $run['transient_errors'] ?? 0 ),
			'conflicts'          => (int) ( $run['conflicts'] ?? 0 ),
		);
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
