<?php
/**
 * Durable competitor import orchestration tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\ImportRunStatus;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Import\ImportedObjectDescriptor;
use Kazcode\WpStorage\Import\ImporterInterface;
use Kazcode\WpStorage\Import\ImportDetection;
use Kazcode\WpStorage\Import\ImportRegistry;
use Kazcode\WpStorage\Import\ImportScanPage;
use Kazcode\WpStorage\Infrastructure\ImportRunRepository;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
use Kazcode\WpStorage\Services\AdoptAttachmentService;
use Kazcode\WpStorage\Services\AuditLog;
use Kazcode\WpStorage\Services\CompetitorImportService;
use Kazcode\WpStorage\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class CompetitorImportServiceTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_dry_run_processes_descriptors_without_writing_inventory(): void {
		$importer = new CompetitorImportFakeImporter(
			'wp_offload_media',
			array(
				new ImportScanPage(
					50,
					103,
					array(
						$this->descriptor( 101, '2026/09/present.jpg', 'legacy/present.jpg' ),
						$this->descriptor( 102, '2026/09/missing.jpg', 'legacy/missing.jpg' ),
						$this->descriptor( 103, '2026/09/transient.jpg', 'legacy/transient.jpg' ),
					)
				),
				new ImportScanPage( 50, 103, array() ),
			)
		);
		$adopter  = new CompetitorImportFakeAdopter(
			array(
				101 => array( 'adopted' => 1, 'missing' => 0, 'errors' => 0, 'conflicts' => 0 ),
				102 => array( 'adopted' => 0, 'missing' => 1, 'errors' => 0, 'conflicts' => 0 ),
				103 => array( 'adopted' => 0, 'missing' => 0, 'errors' => 1, 'conflicts' => 0 ),
			)
		);
		$objects_before = array();
		$service        = $this->service( $importer, $adopter );

		$started = $service->start_dry_run( 'wp_offload_media', 12 );
		$service->process_next_page( $started['uuid'], 50 );
		$result = $service->process_next_page( $started['uuid'], 50 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( $objects_before, $adopter->inventory );
		$this->assertSame( 3, $result['processed'] );
		$this->assertSame( 1, $result['verified'] );
		$this->assertSame( 1, $result['missing'] );
		$this->assertSame( 1, $result['transient_errors'] );
		$this->assertSame( ImportRunStatus::COMPLETED, $result['status'] );
		$this->assertSame( array( true, true, true ), array_column( $adopter->calls, 'dry_run' ) );
	}

	public function test_commit_requires_matching_completed_dry_run_fingerprint(): void {
		$service = $this->service( new CompetitorImportFakeImporter( 'wp_offload_media' ) );

		$without_dry_run = $service->start_commit( 'wp_offload_media', 12 );
		$this->assertFalse( $without_dry_run['success'] );
		$this->assertSame( 'matching_dry_run_required', $without_dry_run['code'] );

		$other_profile_dry_run = $service->start_dry_run( 'wp_offload_media', 99 );
		$service->process_next_page( $other_profile_dry_run['uuid'], 50 );

		$wrong_profile = $service->start_commit( 'wp_offload_media', 12 );
		$this->assertFalse( $wrong_profile['success'] );
		$this->assertSame( 'matching_dry_run_required', $wrong_profile['code'] );
	}

	public function test_start_rejects_second_active_import_run_for_same_source_and_profile(): void {
		$runs    = new CompetitorImportFakeRunRepository();
		$service = $this->service( new CompetitorImportFakeImporter( 'wp_offload_media' ), null, null, $runs );

		$first  = $service->start_dry_run( 'wp_offload_media', 12 );
		$second = $service->start_dry_run( 'wp_offload_media', 12 );

		$this->assertTrue( $first['success'] );
		$this->assertFalse( $second['success'] );
		$this->assertSame( 'active_import_run_exists', $second['code'] );
		$this->assertSame( 1, count( $runs->rows ) );
	}

	public function test_start_rejects_second_active_import_run_for_different_source_or_profile(): void {
		$runs     = new CompetitorImportFakeRunRepository();
		$registry = new ImportRegistry(
			array(
				new CompetitorImportFakeImporter( 'wp_offload_media' ),
				new CompetitorImportFakeImporter( 'advanced-media-offloader' ),
			)
		);
		$service  = $this->service( new CompetitorImportFakeImporter( 'wp_offload_media' ), null, null, $runs, $registry );

		$first  = $service->start_dry_run( 'wp_offload_media', 12 );
		$second = $service->start_dry_run( 'advanced-media-offloader', 99 );

		$this->assertTrue( $first['success'] );
		$this->assertFalse( $second['success'] );
		$this->assertSame( 'active_import_run_exists', $second['code'] );
		$this->assertSame( 1, count( $runs->rows ) );
	}

	public function test_start_reports_conflict_when_atomic_run_claim_loses_race(): void {
		$runs                      = new CompetitorImportFakeRunRepository();
		$runs->reject_next_create  = true;
		$service                   = $this->service( new CompetitorImportFakeImporter( 'wp_offload_media' ), null, null, $runs );

		$result = $service->start_dry_run( 'wp_offload_media', 12 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'active_import_run_exists', $result['code'] );
		$this->assertSame( array(), $runs->rows );
	}

	public function test_commit_rejects_same_profile_source_when_detected_source_fingerprint_changed(): void {
		$runs     = new CompetitorImportFakeRunRepository();
		$importer = new CompetitorImportFakeImporter(
			'wp_offload_media',
			array(
				new ImportScanPage( 50, 501, array( $this->descriptor( 501, '2026/09/old.jpg', 'legacy/old.jpg' ) ) ),
				new ImportScanPage( 50, 501, array() ),
			)
		);
		$service  = $this->service( $importer, null, null, $runs );

		$dry_run = $service->start_dry_run( 'wp_offload_media', 12 );
		$service->process_next_page( $dry_run['uuid'], 50 );
		$service->process_next_page( $dry_run['uuid'], 50 );

		$importer->bucket = 'different-media-bucket';
		$commit           = $service->start_commit( 'wp_offload_media', 12 );

		$this->assertFalse( $commit['success'] );
		$this->assertSame( 'matching_dry_run_required', $commit['code'] );
	}

	public function test_cursor_advance_keeps_import_running_when_full_attachment_page_emits_fewer_descriptors(): void {
		$importer = new CompetitorImportFakeImporter(
			'wp_offload_media',
			array(
				new ImportScanPage( 3, 603, array( $this->descriptor( 601, '2026/09/valid.jpg', 'legacy/valid.jpg' ) ) ),
				new ImportScanPage( 3, 604, array( $this->descriptor( 604, '2026/09/next.jpg', 'legacy/next.jpg' ) ) ),
				new ImportScanPage( 3, 604, array() ),
			)
		);
		$service  = $this->service( $importer );

		$run    = $service->start_dry_run( 'wp_offload_media', 12 );
		$first  = $service->process_next_page( $run['uuid'], 3 );
		$second = $service->process_next_page( $run['uuid'], 3 );
		$done   = $service->process_next_page( $run['uuid'], 3 );

		$this->assertSame( ImportRunStatus::RUNNING, $first['status'] );
		$this->assertSame( 603, $first['after_attachment_id'] );
		$this->assertSame( 2, $second['processed'] );
		$this->assertSame( 604, $second['after_attachment_id'] );
		$this->assertSame( ImportRunStatus::COMPLETED, $done['status'] );
		$this->assertSame( array( 0, 603, 604 ), $importer->scan_after_attachment_ids );
	}

	public function test_commit_revalidates_and_does_not_adopt_transient_stale_dry_run_result(): void {
		$importer = new CompetitorImportFakeImporter(
			'wp_offload_media',
			array(
				new ImportScanPage( 50, 201, array( $this->descriptor( 201, '2026/09/photo.jpg', 'legacy/photo.jpg' ) ) ),
				new ImportScanPage( 50, 201, array() ),
				new ImportScanPage( 50, 201, array( $this->descriptor( 201, '2026/09/photo.jpg', 'legacy/photo.jpg' ) ) ),
			)
		);
		$adopter  = new CompetitorImportFakeAdopter(
			array(
				201 => array( 'adopted' => 1, 'missing' => 0, 'errors' => 0, 'conflicts' => 0 ),
			)
		);
		$service  = $this->service( $importer, $adopter );

		$dry_run = $service->start_dry_run( 'wp_offload_media', 12 );
		$service->process_next_page( $dry_run['uuid'], 50 );
		$service->process_next_page( $dry_run['uuid'], 50 );
		$adopter->outcomes[201] = array( 'adopted' => 0, 'missing' => 0, 'errors' => 1, 'conflicts' => 0 );

		$commit = $service->start_commit( 'wp_offload_media', 12 );
		$result = $service->process_next_page( $commit['uuid'], 50 );

		$this->assertTrue( $commit['success'] );
		$this->assertSame( 0, $result['verified'] );
		$this->assertSame( 0, $result['missing'] );
		$this->assertSame( 1, $result['transient_errors'] );
		$this->assertSame( array(), $adopter->inventory );
		$this->assertFalse( $adopter->calls[1]['dry_run'] );
	}

	public function test_pause_resume_and_completed_commit_idempotence(): void {
		$importer = new CompetitorImportFakeImporter(
			'wp_offload_media',
			array(
				new ImportScanPage( 1, 301, array( $this->descriptor( 301, '2026/09/one.jpg', 'legacy/one.jpg' ) ) ),
				new ImportScanPage( 1, 302, array( $this->descriptor( 302, '2026/09/two.jpg', 'legacy/two.jpg' ) ) ),
				new ImportScanPage( 1, 302, array() ),
				new ImportScanPage( 1, 301, array( $this->descriptor( 301, '2026/09/one.jpg', 'legacy/one.jpg' ) ) ),
				new ImportScanPage( 1, 302, array( $this->descriptor( 302, '2026/09/two.jpg', 'legacy/two.jpg' ) ) ),
				new ImportScanPage( 1, 302, array() ),
			)
		);
		$adopter  = new CompetitorImportFakeAdopter(
			array(
				301 => array( 'adopted' => 1, 'missing' => 0, 'errors' => 0, 'conflicts' => 0 ),
				302 => array( 'adopted' => 1, 'missing' => 0, 'errors' => 0, 'conflicts' => 0 ),
			)
		);
		$service  = $this->service( $importer, $adopter );
		$dry_run  = $service->start_dry_run( 'wp_offload_media', 12 );
		$service->process_next_page( $dry_run['uuid'], 1 );
		$service->process_next_page( $dry_run['uuid'], 1 );
		$service->process_next_page( $dry_run['uuid'], 1 );
		$commit = $service->start_commit( 'wp_offload_media', 12 );

		$first_page = $service->process_next_page( $commit['uuid'], 1 );
		$paused     = $service->pause( $commit['uuid'] );
		$blocked    = $service->process_next_page( $commit['uuid'], 1 );
		$resumed    = $service->resume( $commit['uuid'] );
		$second     = $service->process_next_page( $commit['uuid'], 1 );
		$completed  = $service->process_next_page( $commit['uuid'], 1 );
		$repeat     = $service->process_next_page( $commit['uuid'], 1 );

		$this->assertSame( 1, $first_page['processed'] );
		$this->assertSame( ImportRunStatus::PAUSED, $paused['status'] );
		$this->assertFalse( $blocked['success'] );
		$this->assertSame( 'import_run_paused', $blocked['code'] );
		$this->assertSame( ImportRunStatus::RUNNING, $resumed['status'] );
		$this->assertSame( 2, $second['processed'] );
		$this->assertSame( ImportRunStatus::RUNNING, $second['status'] );
		$this->assertSame( ImportRunStatus::COMPLETED, $completed['status'] );
		$this->assertSame( 2, $adopter->inventory_count() );
		$this->assertSame( 2, $repeat['processed'] );
		$this->assertSame( 2, $adopter->inventory_count() );
		$this->assertSame( array( 0, 301, 302, 0, 301, 302 ), $importer->scan_after_attachment_ids );
	}

	public function test_resume_continues_from_saved_cursor_not_first_available_page(): void {
		$importer = new CompetitorImportFakeImporter(
			'wp_offload_media',
			array(
				new ImportScanPage( 1, 701, array( $this->descriptor( 701, '2026/09/one.jpg', 'legacy/one.jpg' ) ) ),
				new ImportScanPage( 1, 702, array( $this->descriptor( 702, '2026/09/two.jpg', 'legacy/two.jpg' ) ) ),
				new ImportScanPage( 1, 702, array() ),
			)
		);
		$service  = $this->service( $importer );
		$run      = $service->start_dry_run( 'wp_offload_media', 12 );

		$first = $service->process_next_page( $run['uuid'], 1 );
		$service->pause( $run['uuid'] );
		$service->resume( $run['uuid'] );
		$second = $service->process_next_page( $run['uuid'], 1 );

		$this->assertSame( 701, $first['after_attachment_id'] );
		$this->assertSame( 702, $second['after_attachment_id'] );
		$this->assertSame( 2, $second['processed'] );
		$this->assertSame( array( 0, 701 ), $importer->scan_after_attachment_ids );
	}

	public function test_audit_events_do_not_include_secret_or_location_fields(): void {
		$service = $this->service(
			new CompetitorImportFakeImporter(
				'wp_offload_media',
				array(
					new ImportScanPage( 1, 401, array( $this->descriptor( 401, '2026/09/audit.jpg', 'legacy/audit.jpg' ) ) ),
					new ImportScanPage( 1, 401, array() ),
				)
			),
			new CompetitorImportFakeAdopter(),
			new AuditLog()
		);

		$run = $service->start_dry_run( 'wp_offload_media', 12 );
		$service->pause( $run['uuid'] );
		$service->resume( $run['uuid'] );
		$service->process_next_page( $run['uuid'], 1 );
		$service->process_next_page( $run['uuid'], 1 );

		$encoded = json_encode( WpStubs::$options[ AuditLog::OPTION_KEY ] ?? array() );
		$actions = array_column( WpStubs::$options[ AuditLog::OPTION_KEY ] ?? array(), 'action' );

		$this->assertContains( 'import_started', $actions );
		$this->assertContains( 'import_paused', $actions );
		$this->assertContains( 'import_resumed', $actions );
		$this->assertContains( 'import_finished', $actions );
		$this->assertStringContainsString( 'wp_offload_media', $encoded );
		$this->assertStringContainsString( '"profile_id":12', $encoded );
		$this->assertStringNotContainsString( 'media-bucket', $encoded );
		$this->assertStringNotContainsString( 'https://s3.example.test', $encoded );
		$this->assertStringNotContainsString( '/var/www', $encoded );
		$this->assertStringNotContainsString( 'secret', strtolower( $encoded ) );

		foreach ( WpStubs::$options[ AuditLog::OPTION_KEY ] ?? array() as $event ) {
			$this->assertSame(
				array( 'source_type', 'profile_id', 'uuid', 'processed', 'verified', 'missing', 'transient_errors', 'conflicts', 'skipped', 'rejected' ),
				array_keys( $event['context'] )
			);
		}
	}

	public function test_named_provider_fingerprint_without_endpoint_does_not_match_generic_aws_profile(): void {
		$wasabi = new ImportedObjectDescriptor(
			501,
			'2026/09/wasabi.jpg',
			'original',
			'wasabi/wasabi.jpg',
			100,
			'image/jpeg',
			'media-cloud:wasabi:media-bucket'
		);
		$aws = new ImportedObjectDescriptor(
			502,
			'2026/09/aws.jpg',
			'original',
			'aws/aws.jpg',
			100,
			'image/jpeg',
			'media-cloud:s3:media-bucket:eu-central-1'
		);
		$adopter = new CompetitorImportFakeAdopter(
			array(
				501 => array( 'adopted' => 1, 'missing' => 0, 'errors' => 0, 'conflicts' => 0 ),
				502 => array( 'adopted' => 1, 'missing' => 0, 'errors' => 0, 'conflicts' => 0 ),
			)
		);
		$service = $this->service(
			new CompetitorImportFakeImporter(
				'media_cloud',
				array(
					new ImportScanPage( 2, 502, array( $wasabi, $aws ) ),
					new ImportScanPage( 2, 502, array() ),
				)
			),
			$adopter
		);

		$run    = $service->start_dry_run( 'media_cloud', 12 );
		$result = $service->process_next_page( $run['uuid'], 50 );

		$this->assertSame( 2, $result['processed'] );
		$this->assertSame( 1, $result['verified'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 0, $result['conflicts'] );
		$this->assertCount( 1, $adopter->calls );
		$this->assertSame( 502, $adopter->calls[0]['attachment_id'] );
	}

	public function test_adoption_conflicts_do_not_fail_dry_run_as_profile_mismatch(): void {
		$matched = new ImportedObjectDescriptor(
			701,
			'2026/09/conflict.jpg',
			'original',
			'aws/conflict.jpg',
			100,
			'image/jpeg',
			'media-cloud:s3:media-bucket:eu-central-1'
		);
		$adopter = new CompetitorImportFakeAdopter(
			array(
				701 => array( 'adopted' => 0, 'missing' => 0, 'errors' => 0, 'conflicts' => 1 ),
			)
		);
		$service = $this->service(
			new CompetitorImportFakeImporter(
				'media_cloud',
				array(
					new ImportScanPage( 1, 701, array( $matched ) ),
					new ImportScanPage( 1, 701, array() ),
				)
			),
			$adopter
		);

		$run = $service->start_dry_run( 'media_cloud', 12 );
		$service->process_next_page( $run['uuid'], 50 );
		$result = $service->process_next_page( $run['uuid'], 50 );

		$this->assertSame( ImportRunStatus::COMPLETED, $result['status'] );
		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 1, $result['conflicts'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertCount( 1, $adopter->calls );
	}

	public function test_endpointless_other_s3_fingerprint_fails_closed_against_custom_profile(): void {
		$other = new ImportedObjectDescriptor(
			601,
			'2026/09/other.jpg',
			'original',
			'other/other.jpg',
			100,
			'image/jpeg',
			'media-cloud:other-s3:media-bucket:eu-central-1'
		);
		$adopter = new CompetitorImportFakeAdopter(
			array(
				601 => array( 'adopted' => 1, 'missing' => 0, 'errors' => 0, 'conflicts' => 0 ),
			)
		);
		$service = $this->service_with_profile(
			new CompetitorImportFakeImporter(
				'media_cloud',
				array(
					new ImportScanPage( 1, 601, array( $other ) ),
					new ImportScanPage( 1, 601, array() ),
				)
			),
			$adopter,
			'custom',
			'https://minio.example:9000'
		);

		$run = $service->start_dry_run( 'media_cloud', 12 );
		$service->process_next_page( $run['uuid'], 50 );
		$result = $service->process_next_page( $run['uuid'], 50 );

		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 0, $result['verified'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 0, $result['conflicts'] );
		$this->assertSame( ImportRunStatus::FAILED, $result['status'] );
		$this->assertCount( 0, $adopter->calls );
	}

	public function test_endpointless_s3_fingerprint_does_not_match_minio_or_custom_profile(): void {
		$s3 = new ImportedObjectDescriptor(
			602,
			'2026/09/s3.jpg',
			'original',
			's3/s3.jpg',
			100,
			'image/jpeg',
			'media-cloud:s3:media-bucket:eu-central-1'
		);
		$adopter = new CompetitorImportFakeAdopter(
			array(
				602 => array( 'adopted' => 1, 'missing' => 0, 'errors' => 0, 'conflicts' => 0 ),
			)
		);

		foreach ( array( 'minio', 'custom' ) as $provider_type ) {
			$service = $this->service_with_profile(
				new CompetitorImportFakeImporter(
					'media_cloud',
					array(
						new ImportScanPage( 1, 602, array( $s3 ) ),
						new ImportScanPage( 1, 602, array() ),
					)
				),
				$adopter,
				$provider_type,
				'https://s3-compatible.example'
			);
			$adopter->calls = array();

			$run = $service->start_dry_run( 'media_cloud', 12 );
			$service->process_next_page( $run['uuid'], 50 );
			$result = $service->process_next_page( $run['uuid'], 50 );

			$this->assertSame( 1, $result['processed'], $provider_type );
			$this->assertSame( 1, $result['skipped'], $provider_type );
			$this->assertSame( 0, $result['conflicts'], $provider_type );
			$this->assertSame( ImportRunStatus::FAILED, $result['status'], $provider_type );
			$this->assertCount( 0, $adopter->calls, $provider_type );
		}
	}

	public function test_other_s3_fingerprint_with_matching_endpoint_binds_to_custom_profile(): void {
		$other = new ImportedObjectDescriptor(
			603,
			'2026/09/other-ep.jpg',
			'original',
			'other/other-ep.jpg',
			100,
			'image/jpeg',
			'media-cloud:other-s3:media-bucket:eu-central-1:https://minio.example:9000'
		);
		$adopter = new CompetitorImportFakeAdopter(
			array(
				603 => array( 'adopted' => 1, 'missing' => 0, 'errors' => 0, 'conflicts' => 0 ),
			)
		);
		$service = $this->service_with_profile(
			new CompetitorImportFakeImporter(
				'media_cloud',
				array(
					new ImportScanPage( 1, 603, array( $other ) ),
					new ImportScanPage( 1, 603, array() ),
				)
			),
			$adopter,
			'custom',
			'https://minio.example:9000'
		);

		$run    = $service->start_dry_run( 'media_cloud', 12 );
		$result = $service->process_next_page( $run['uuid'], 50 );

		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 1, $result['verified'] );
		$this->assertCount( 1, $adopter->calls );
		$this->assertSame( 603, $adopter->calls[0]['attachment_id'] );
	}

	public function test_profile_mismatch_terminal_failure_returns_reasoned_error_response(): void {
		$mismatched = new ImportedObjectDescriptor(
			901,
			'2026/09/wasabi-only.jpg',
			'original',
			'wasabi/wasabi-only.jpg',
			100,
			'image/jpeg',
			'media-cloud:wasabi:media-bucket'
		);
		$service = $this->service(
			new CompetitorImportFakeImporter(
				'media_cloud',
				array(
					new ImportScanPage( 1, 901, array( $mismatched ) ),
					new ImportScanPage( 1, 901, array() ),
				)
			)
		);

		$run = $service->start_dry_run( 'media_cloud', 12 );
		$service->process_next_page( $run['uuid'], 1 );
		$result = $service->process_next_page( $run['uuid'], 1 );
		$status = $service->status( $run['uuid'] );
		$retry  = $service->process_next_page( $run['uuid'], 1 );

		foreach ( array( $result, $status, $retry ) as $response ) {
			$this->assertFalse( $response['success'] );
			$this->assertSame( 'import_run_failed', $response['code'] );
			$this->assertSame( 'No descriptors matched the selected storage profile.', $response['message'] );
			$this->assertSame( ImportRunStatus::FAILED, $response['status'] );
			$this->assertSame( 1, $response['processed'] );
			$this->assertSame( 1, $response['skipped'] );
			$this->assertSame( 0, $response['conflicts'] );
		}
	}

	public function test_keyless_scan_rejections_are_counted_without_profile_mismatch_or_adoption(): void {
		$adopter = new CompetitorImportFakeAdopter();
		$service = $this->service(
			new CompetitorImportFakeImporter(
				'media_cloud',
				array(
					new ImportScanPage( 2, 902, array(), 2 ),
					new ImportScanPage( 2, 902, array() ),
				)
			),
			$adopter
		);

		$run    = $service->start_dry_run( 'media_cloud', 12 );
		$first  = $service->process_next_page( $run['uuid'], 2 );
		$service->pause( $run['uuid'] );
		$service->resume( $run['uuid'] );
		$result = $service->process_next_page( $run['uuid'], 2 );
		$status = $service->status( $run['uuid'] );
		$retry  = $service->process_next_page( $run['uuid'], 2 );

		$this->assertSame( ImportRunStatus::RUNNING, $first['status'] );
		foreach ( array( $result, $status, $retry ) as $response ) {
			$this->assertFalse( $response['success'] );
			$this->assertSame( 'import_run_failed', $response['code'] );
			$this->assertSame( 'No importable descriptors were found in source metadata.', $response['message'] );
			$this->assertSame( ImportRunStatus::FAILED, $response['status'] );
			$this->assertSame( 2, $response['processed'] );
			$this->assertSame( 2, $response['skipped'] );
			$this->assertSame( 0, $response['conflicts'] );
		}
		$this->assertCount( 0, $adopter->calls );
	}

	public function test_mixed_scan_rejection_and_profile_mismatch_reports_profile_reason(): void {
		$mismatched = new ImportedObjectDescriptor(
			904,
			'2026/09/wasabi-only.jpg',
			'original',
			'wasabi/wasabi-only.jpg',
			100,
			'image/jpeg',
			'media-cloud:wasabi:media-bucket'
		);
		$service = $this->service(
			new CompetitorImportFakeImporter(
				'media_cloud',
				array(
					new ImportScanPage( 2, 904, array( $mismatched ), 1 ),
					new ImportScanPage( 2, 904, array() ),
				)
			)
		);

		$run = $service->start_dry_run( 'media_cloud', 12 );
		$service->process_next_page( $run['uuid'], 2 );
		$result = $service->process_next_page( $run['uuid'], 2 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'import_run_failed', $result['code'] );
		$this->assertSame( 'No descriptors matched the selected storage profile.', $result['message'] );
		$this->assertSame( 2, $result['processed'] );
		$this->assertSame( 2, $result['skipped'] );
		$this->assertSame( 1, $result['rejected'] );
	}

	public function test_mixed_scan_rejections_and_descriptors_are_counted_separately_from_conflicts(): void {
		$adopter = new CompetitorImportFakeAdopter(
			array(
				903 => array( 'adopted' => 1, 'missing' => 0, 'errors' => 0, 'conflicts' => 0 ),
			)
		);
		$service = $this->service(
			new CompetitorImportFakeImporter(
				'media_cloud',
				array(
					new ImportScanPage( 2, 903, array( $this->descriptor( 903, '2026/09/valid.jpg', 'valid.jpg' ) ), 1 ),
					new ImportScanPage( 2, 903, array() ),
				)
			),
			$adopter
		);

		$run    = $service->start_dry_run( 'media_cloud', 12 );
		$first  = $service->process_next_page( $run['uuid'], 2 );
		$result = $service->process_next_page( $run['uuid'], 2 );

		$this->assertSame( 2, $first['processed'] );
		$this->assertSame( 1, $first['verified'] );
		$this->assertSame( 1, $first['skipped'] );
		$this->assertSame( 0, $first['conflicts'] );
		$this->assertSame( ImportRunStatus::COMPLETED, $result['status'] );
		$this->assertSame( 2, $result['processed'] );
		$this->assertSame( 1, $result['verified'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 0, $result['conflicts'] );
		$this->assertCount( 1, $adopter->calls );
	}

	private function service(
		CompetitorImportFakeImporter $importer,
		?CompetitorImportFakeAdopter $adopter = null,
		?AuditLog $audit = null,
		?CompetitorImportFakeRunRepository $runs = null,
		?ImportRegistry $registry = null
	): CompetitorImportService {
		return $this->service_with_profile( $importer, $adopter, 'aws', 'https://s3.example.test', $audit, $runs, $registry );
	}

	private function service_with_profile(
		CompetitorImportFakeImporter $importer,
		?CompetitorImportFakeAdopter $adopter,
		string $provider_type,
		string $endpoint,
		?AuditLog $audit = null,
		?CompetitorImportFakeRunRepository $runs = null,
		?ImportRegistry $registry = null
	): CompetitorImportService {
		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->willReturnCallback(
			static fn( int $id ): ?StorageProfile => new StorageProfile(
				$id,
				'profile-' . $id,
				'Profile ' . $id,
				$provider_type,
				'media-bucket',
				'eu-central-1',
				$endpoint,
				false,
				'uploads/',
				'storage',
				'',
				false,
				'keys',
				'secret-option-name',
				$id === 12,
				false,
				false,
				'2026-09-01 00:00:00',
				'2026-09-01 00:00:00'
			)
		);

		return new CompetitorImportService(
			$registry ?? new ImportRegistry( array( $importer ) ),
			$runs ?? new CompetitorImportFakeRunRepository(),
			$adopter ?? new CompetitorImportFakeAdopter(),
			$profiles,
			new Settings(),
			$audit ?? $this->createMock( AuditLog::class )
		);
	}

	private function descriptor( int $attachment_id, string $relative, string $key ): ImportedObjectDescriptor {
		return new ImportedObjectDescriptor( $attachment_id, $relative, 'original', $key, 100, 'image/jpeg', 'adapter:fingerprint' );
	}
}

final class CompetitorImportFakeImporter implements ImporterInterface {
	public string $bucket = 'media-bucket';
	/** @var list<int> */
	public array $scan_after_attachment_ids = array();
	/** @param list<ImportScanPage> $pages */
	public function __construct(
		private string $id,
		private array $pages = array()
	) {
	}

	public function id(): string {
		return $this->id;
	}

	public function label(): string {
		return 'Fake Importer';
	}

	public function detect(): ImportDetection {
		return new ImportDetection(
			$this->id,
			'Fake Importer',
			'1.2.3',
			's3',
			$this->bucket,
			'eu-central-1',
			'https://s3.example.test',
			'legacy/',
			'exact',
			'https://cdn.example.test',
			true,
			1.0,
			array( 'Credential material must be re-entered.' )
		);
	}

	public function scan_page( int $limit, int $after_attachment_id = 0 ): ImportScanPage {
		$this->scan_after_attachment_ids[] = $after_attachment_id;
		foreach ( $this->pages as $index => $page ) {
			if ( $page->cursor() >= $after_attachment_id ) {
				unset( $this->pages[ $index ] );
				$this->pages = array_values( $this->pages );
				return $page;
			}
		}
		return new ImportScanPage( $limit, $after_attachment_id, array() );
	}

	public function descriptors_for_attachment( int $attachment_id ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return array();
	}
}

final class CompetitorImportFakeRunRepository extends ImportRunRepository {
	private int $next_id = 1;
	public bool $reject_next_create = false;
	/** @var array<int, array<string, mixed>> */
	public array $rows = array();

	public function create( array $data ): int {
		$id               = $this->next_id++;
		$data['id']       = $id;
		$data['processed'] = (int) ( $data['processed'] ?? 0 );
		$data['verified']  = (int) ( $data['verified'] ?? 0 );
		$data['missing']   = (int) ( $data['missing'] ?? 0 );
		$data['transient_errors'] = (int) ( $data['transient_errors'] ?? 0 );
		$data['conflicts']        = (int) ( $data['conflicts'] ?? 0 );
		$data['skipped']          = (int) ( $data['skipped'] ?? 0 );
		$data['rejected']         = (int) ( $data['rejected'] ?? 0 );
		$data['after_attachment_id'] = (int) ( $data['after_attachment_id'] ?? 0 );
		$this->rows[ $id ]       = $data;
		return $id;
	}

	public function create_if_no_active( array $data ): int {
		if ( $this->reject_next_create ) {
			$this->reject_next_create = false;
			return 0;
		}
		if ( $this->find_active() !== null ) {
			return 0;
		}
		return $this->create( $data );
	}

	public function find_by_uuid( string $uuid ): ?array {
		foreach ( $this->rows as $row ) {
			if ( $row['uuid'] === $uuid ) {
				return $row;
			}
		}
		return null;
	}

	public function find_latest_successful_dry_run( string $source_type, int $profile_id, string $source_fingerprint ): ?array {
		$matches = array_filter(
			$this->rows,
			static fn( array $row ): bool => $row['source_type'] === $source_type
				&& (int) $row['profile_id'] === $profile_id
				&& $row['source_fingerprint'] === $source_fingerprint
				&& $row['mode'] === 'dry_run'
				&& $row['status'] === ImportRunStatus::COMPLETED
		);
		if ( $matches === array() ) {
			return null;
		}
		usort( $matches, static fn( array $left, array $right ): int => (int) $right['id'] <=> (int) $left['id'] );
		return $matches[0];
	}

	public function find_active(): ?array {
		$matches = array_filter(
			$this->rows,
			static fn( array $row ): bool => in_array( $row['status'], array( ImportRunStatus::RUNNING, ImportRunStatus::PAUSED ), true )
		);
		if ( $matches === array() ) {
			return null;
		}
		usort( $matches, static fn( array $left, array $right ): int => (int) $right['id'] <=> (int) $left['id'] );
		return $matches[0];
	}

	public function update( int $id, array $data ): void {
		$this->rows[ $id ] = array_merge( $this->rows[ $id ], $data );
	}
}

final class CompetitorImportFakeAdopter extends AdoptAttachmentService {
	/** @var array<int, array{adopted:int,missing:int,errors:int,conflicts:int}> */
	public array $outcomes;
	/** @var list<array<string, mixed>> */
	public array $calls = array();
	/** @var array<string, array<string, mixed>> */
	public array $inventory = array();

	/** @param array<int, array{adopted:int,missing:int,errors:int,conflicts:int}> $outcomes */
	public function __construct( array $outcomes = array() ) {
		$this->outcomes = $outcomes;
	}

	public function adopt_descriptors( int $attachment_id, int $profile_id, array $descriptors, bool $dry_run = false ): array {
		$outcome       = $this->outcomes[ $attachment_id ] ?? array( 'adopted' => 0, 'missing' => 0, 'errors' => 0, 'conflicts' => 0 );
		$this->calls[] = compact( 'attachment_id', 'profile_id', 'descriptors', 'dry_run' );
		if ( ! $dry_run && $outcome['adopted'] > 0 ) {
			foreach ( $descriptors as $descriptor ) {
				$this->inventory[ $profile_id . ':' . $descriptor->source_object_key() ] = $descriptor->to_array();
			}
		}
		return array(
			'success'   => $outcome['errors'] === 0 && $outcome['conflicts'] === 0,
			'message'   => 'fake',
			'adopted'   => $outcome['adopted'],
			'missing'   => $outcome['missing'],
			'errors'    => $outcome['errors'],
			'conflicts' => $outcome['conflicts'],
			'results'   => array(),
		);
	}

	public function inventory_count(): int {
		return count( $this->inventory );
	}
}
