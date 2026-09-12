<?php
/**
 * Crash-resumable local transfer engine tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Domain\TransferSessionStatus;
use Kazcode\WpStorage\Services\AuditLog;
use Kazcode\WpStorage\Services\ResumableTransferService;
use Kazcode\WpStorage\Storage\StorageGatewayInterface;
use Kazcode\WpStorage\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class ResumableTransferServiceTest extends TestCase {

	private string $uploads_dir;
	private FakeTransferSessionRepository $sessions;
	private FakeTransferGateway $gateway;
	private FakeTransferGateway $source_gateway;
	private FakeTransferGateway $destination_gateway;
	/** @var list<string> */
	private array $events = array();
	/** @var list<int> */
	private array $sleeps = array();

	protected function setUp(): void {
		parent::setUp();
		$this->uploads_dir = sys_get_temp_dir() . '/kazus-resumable-transfer-test-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->uploads_dir . '/2026/09', 0777, true );
		\Kazcode\WpStorage\Tests\Support\WpStubs::$uploads_basedir = $this->uploads_dir;
		remove_all_filters( 'kazus_multipart_threshold_bytes' );
		remove_all_filters( 'kazus_multipart_part_size_bytes' );
		remove_all_filters( 'kazus_multipart_concurrency' );
		$this->sessions = new FakeTransferSessionRepository();
		$this->gateway  = new FakeTransferGateway( 77 );
		$this->source_gateway = new FakeTransferGateway( 11 );
		$this->destination_gateway = new FakeTransferGateway( 22 );
		$this->sessions->events =& $this->events;
		$this->gateway->events  =& $this->events;
		$this->source_gateway->events =& $this->events;
		$this->destination_gateway->events =& $this->events;
	}

	protected function tearDown(): void {
		$this->delete_tree( $this->uploads_dir );
		\Kazcode\WpStorage\Tests\Support\WpStubs::$uploads_basedir = '';
		remove_all_filters( 'kazus_multipart_threshold_bytes' );
		remove_all_filters( 'kazus_multipart_part_size_bytes' );
		remove_all_filters( 'kazus_multipart_concurrency' );
		parent::tearDown();
	}

	public function test_small_local_source_uses_single_put_and_requires_matching_destination_head(): void {
		// Catches: treating below-threshold local files as multipart, or returning success before exact destination size verification.
		$this->write_upload( '2026/09/small.txt', 'small body' );
		add_filter( 'kazus_multipart_threshold_bytes', static fn(): int => 100 );
		$this->gateway->head_results[] = array( 'exists' => true, 'content_length' => 10 );

		$result = $this->service()->upload_local( 123, 77, '2026/09/small.txt', 'media/small.txt', 'text/plain', 'public, max-age=60' );

		$this->assertSame(
			array(
				array(
					'key'           => 'media/small.txt',
					'local_path'    => $this->uploads_dir . '/2026/09/small.txt',
					'content_type'  => 'text/plain',
					'cache_control' => 'public, max-age=60',
				),
			),
			$this->gateway->put_file_calls
		);
		$this->assertSame( array(), $this->gateway->created_uploads );
		$this->assertSame( array(), $this->sessions->created );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['verified_complete'] );
		$this->assertFalse( $result['resumed'] );
		$this->assertNotSame( '', $result['session_uuid'] );
	}

	public function test_local_upload_allows_double_dot_filename_but_rejects_parent_segments(): void {
		$this->write_upload( '2026/09/photo..jpg', 'safe body' );
		add_filter( 'kazus_multipart_threshold_bytes', static fn(): int => 100 );
		$this->gateway->head_results[] = array( 'exists' => true, 'content_length' => 9 );

		$result = $this->service()->upload_local( 124, 77, '2026/09/photo..jpg', 'media/photo..jpg', 'image/jpeg', '' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( $this->uploads_dir . '/2026/09/photo..jpg', $this->gateway->put_file_calls[0]['local_path'] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Relative source path is invalid.' );

		$this->service()->upload_local( 125, 77, '2026/../secret.jpg', 'media/secret.jpg', 'image/jpeg', '' );
	}

	public function test_multipart_creates_durable_uploading_session_before_first_part(): void {
		// Catches: sending part bytes before durable upload_id/fingerprint/destination metadata exists.
		$this->write_upload( '2026/09/big.bin', 'abcdefghij' );
		touch( $this->uploads_dir . '/2026/09/big.bin', 1788264000 );
		add_filter( 'kazus_multipart_threshold_bytes', static fn(): int => 5 );
		$this->gateway->created_upload_ids[] = 'provider-upload-1';
		$this->gateway->head_results[]      = array( 'exists' => true, 'content_length' => 10 );

		$this->service()->upload_local( 321, 77, '2026/09/big.bin', 'media/big.bin', 'application/octet-stream', '' );

		$this->assertSame( array( 'multipart:create', 'session:create', 'part:1' ), array_slice( $this->events, 0, 3 ) );
		$this->assertCount( 1, $this->sessions->created );
		$created = $this->sessions->created[0];
		$this->assertSame( 'local_upload', $created['kind'] );
		$this->assertSame( 321, $created['attachment_id'] );
		$this->assertSame( 77, $created['destination_profile_id'] );
		$this->assertSame( '2026/09/big.bin', $created['source_ref'] );
		$this->assertSame( 'media/big.bin', $created['destination_key'] );
		$this->assertSame( 10, $created['size_bytes'] );
		$this->assertSame( hash( 'sha256', '2026/09/big.bin|10|1788264000' ), $created['source_fingerprint'] );
		$this->assertSame( 'provider-upload-1', $created['multipart_upload_id'] );
		$this->assertSame( 64 * 1024 * 1024, $created['part_size_bytes'] );
		$this->assertSame( array(), $created['completed_parts'] );
		$this->assertSame( TransferSessionStatus::UPLOADING, $created['status'] );
	}

	public function test_resume_reconciles_provider_list_parts_before_uploading_remaining_parts(): void {
		// Catches: trusting stale DB completed_parts instead of provider ListParts after a restart.
		$this->write_sparse_upload( '2026/09/resume.bin', ( 5 * 1024 * 1024 ) + 3 );
		touch( $this->uploads_dir . '/2026/09/resume.bin', 1788267600 );
		add_filter( 'kazus_multipart_threshold_bytes', static fn(): int => 1 );
		add_filter( 'kazus_multipart_part_size_bytes', static fn(): int => 5 * 1024 * 1024 );
		$fingerprint = hash( 'sha256', '2026/09/resume.bin|' . ( ( 5 * 1024 * 1024 ) + 3 ) . '|1788267600' );
		$this->sessions->resumable = array(
			'id'                     => 41,
			'uuid'                   => 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa',
			'kind'                   => 'local_upload',
			'attachment_id'          => 555,
			'destination_profile_id' => 77,
			'source_ref'             => '2026/09/resume.bin',
			'destination_key'        => 'media/resume.bin',
			'size_bytes'             => ( 5 * 1024 * 1024 ) + 3,
			'source_fingerprint'     => $fingerprint,
			'multipart_upload_id'    => 'resume-upload-id',
			'part_size_bytes'        => 5 * 1024 * 1024,
			'completed_parts'        => array(
				1 => array( 'etag' => 'db-one', 'size' => 5 * 1024 * 1024 ),
				2 => array( 'etag' => 'db-two', 'size' => 3 ),
			),
			'status'                 => TransferSessionStatus::UPLOADING,
		);
		$this->gateway->list_parts_results[] = array(
			1 => array( 'etag' => 'provider-one', 'size' => 5 * 1024 * 1024 ),
		);
		$this->gateway->head_results[] = array( 'exists' => true, 'content_length' => ( 5 * 1024 * 1024 ) + 3 );

		$result = $this->service()->upload_local( 555, 77, '2026/09/resume.bin', 'media/resume.bin', 'application/octet-stream', '' );

		$this->assertTrue( $result['resumed'] );
		$this->assertSame( array(), $this->gateway->created_uploads );
		$this->assertSame( array( 2 ), array_column( $this->gateway->uploaded_parts, 'part_number' ) );
		$this->assertSame(
			array(
				1 => array( 'etag' => 'provider-one', 'size' => 5 * 1024 * 1024 ),
				2 => array( 'etag' => 'etag-2', 'size' => 3 ),
			),
			$this->sessions->updates[41]['completed_parts']
		);
	}

	public function test_successful_sibling_part_persists_when_another_part_exhausts_retry_budget(): void {
		// Catches: aborting or losing successfully uploaded sibling parts when another concurrent part remains retry-failing.
		$this->write_sparse_upload( '2026/09/retry.bin', ( 5 * 1024 * 1024 ) + 3 );
		add_filter( 'kazus_multipart_threshold_bytes', static fn(): int => 1 );
		add_filter( 'kazus_multipart_part_size_bytes', static fn(): int => 5 * 1024 * 1024 );
		add_filter( 'kazus_multipart_concurrency', static fn(): int => 2 );
		$this->gateway->created_upload_ids[] = 'retry-upload-id';
		$this->gateway->part_errors[2] = array(
			new FakeRetryableTransferError( 'temporary outage 1' ),
			new FakeRetryableTransferError( 'temporary outage 2' ),
			new FakeRetryableTransferError( 'temporary outage 3' ),
			new FakeRetryableTransferError( 'temporary outage 4' ),
			new FakeRetryableTransferError( 'temporary outage 5' ),
		);

		$result = $this->service()->upload_local( 777, 77, '2026/09/retry.bin', 'media/retry.bin', 'application/octet-stream', '' );

		$this->assertFalse( $result['success'] );
		$this->assertFalse( $result['verified_complete'] );
		$this->assertSame( array(), $this->gateway->aborted_uploads );
		$this->assertSame( array( 1, 2, 2, 2, 2, 2 ), array_column( $this->gateway->uploaded_parts, 'part_number' ) );
		$this->assertSame(
			array( 1 => array( 'etag' => 'etag-1', 'size' => 5 * 1024 * 1024 ) ),
			$this->sessions->updates[1]['completed_parts']
		);
		$this->assertSame( TransferSessionStatus::UPLOADING, $this->sessions->updates[1]['status'] );
		$this->assertSame( 4, count( $this->sleeps ) );
	}

	public function test_source_fingerprint_change_fails_old_session_and_starts_new_upload_attempt(): void {
		// Catches: uploading new source bytes into a multipart upload ID created for an older local file fingerprint.
		$this->write_upload( '2026/09/changed.bin', 'new-body' );
		touch( $this->uploads_dir . '/2026/09/changed.bin', 1788271200 );
		add_filter( 'kazus_multipart_threshold_bytes', static fn(): int => 1 );
		$this->sessions->resumable_for_source = array(
			'id'                     => 91,
			'uuid'                   => 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb',
			'kind'                   => 'local_upload',
			'attachment_id'          => 888,
			'destination_profile_id' => 77,
			'source_ref'             => '2026/09/changed.bin',
			'destination_key'        => 'media/changed.bin',
			'size_bytes'             => 100,
			'source_fingerprint'     => hash( 'sha256', '2026/09/changed.bin|100|1788260000' ),
			'multipart_upload_id'    => 'old-upload-id',
			'part_size_bytes'        => 64 * 1024 * 1024,
			'completed_parts'        => array(),
			'status'                 => TransferSessionStatus::UPLOADING,
		);
		$this->gateway->created_upload_ids[] = 'new-upload-id';
		$this->gateway->head_results[]      = array( 'exists' => true, 'content_length' => 8 );

		$result = $this->service()->upload_local( 888, 77, '2026/09/changed.bin', 'media/changed.bin', 'application/octet-stream', '' );

		$this->assertFalse( $result['resumed'] );
		$this->assertSame( TransferSessionStatus::FAILED, $this->sessions->updates[91]['status'] );
		$this->assertSame( 'source_changed', $this->sessions->updates[91]['last_error_code'] );
		$this->assertSame( 'new-upload-id', $this->sessions->created[0]['multipart_upload_id'] );
		$this->assertSame( array( 'new-upload-id' ), array_column( $this->gateway->uploaded_parts, 'upload_id' ) );
	}

	public function test_complete_success_with_transient_final_head_is_not_verified_or_completed(): void {
		// Catches: treating CompleteMultipartUpload success as promotable ownership without exact final HEAD verification.
		$this->write_upload( '2026/09/head-transient.bin', 'complete' );
		add_filter( 'kazus_multipart_threshold_bytes', static fn(): int => 1 );
		$this->gateway->created_upload_ids[] = 'head-transient-upload-id';
		$this->gateway->head_results[]      = array( 'exists' => false, 'confirmed_missing' => false, 'error' => 'timeout while reading HEAD' );

		$result = $this->service()->upload_local( 889, 77, '2026/09/head-transient.bin', 'media/head-transient.bin', 'application/octet-stream', '' );

		$this->assertFalse( $result['success'] );
		$this->assertFalse( $result['verified_complete'] );
		$this->assertSame( TransferSessionStatus::COMPLETING, $this->sessions->updates[1]['status'] );
		$this->assertSame( 'destination_head_unverified', $this->sessions->updates[1]['last_error_code'] );
		$this->assertArrayNotHasKey( 'completed_at', $this->sessions->updates[1] );
	}

	public function test_resume_from_completing_heads_destination_before_retrying_completion(): void {
		// Catches: retrying multipart completion after a crash even though the completed destination already verifies.
		$this->write_sparse_upload( '2026/09/completing.bin', ( 5 * 1024 * 1024 ) + 3 );
		touch( $this->uploads_dir . '/2026/09/completing.bin', 1788274800 );
		add_filter( 'kazus_multipart_threshold_bytes', static fn(): int => 1 );
		add_filter( 'kazus_multipart_part_size_bytes', static fn(): int => 5 * 1024 * 1024 );
		$fingerprint = hash( 'sha256', '2026/09/completing.bin|' . ( ( 5 * 1024 * 1024 ) + 3 ) . '|1788274800' );
		$this->sessions->resumable = array(
			'id'                     => 111,
			'uuid'                   => 'cccccccc-3333-4333-8333-cccccccccccc',
			'kind'                   => 'local_upload',
			'attachment_id'          => 999,
			'destination_profile_id' => 77,
			'source_ref'             => '2026/09/completing.bin',
			'destination_key'        => 'media/completing.bin',
			'size_bytes'             => ( 5 * 1024 * 1024 ) + 3,
			'source_fingerprint'     => $fingerprint,
			'multipart_upload_id'    => 'completing-upload-id',
			'part_size_bytes'        => 5 * 1024 * 1024,
			'completed_parts'        => array(
				1 => array( 'etag' => 'one', 'size' => 5 * 1024 * 1024 ),
				2 => array( 'etag' => 'two', 'size' => 3 ),
			),
			'status'                 => TransferSessionStatus::COMPLETING,
		);
		$this->gateway->head_results[] = array( 'exists' => true, 'content_length' => ( 5 * 1024 * 1024 ) + 3 );

		$result = $this->service()->upload_local( 999, 77, '2026/09/completing.bin', 'media/completing.bin', 'application/octet-stream', '' );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['verified_complete'] );
		$this->assertTrue( $result['resumed'] );
		$this->assertSame( array( 'head' ), $this->events );
		$this->assertSame( array(), $this->gateway->uploaded_parts );
		$this->assertSame( array(), $this->gateway->complete_calls );
		$this->assertSame( TransferSessionStatus::COMPLETED, $this->sessions->updates[111]['status'] );
		$this->assertArrayHasKey( 'completed_at', $this->sessions->updates[111] );
	}

	public function test_remote_copy_uses_exact_source_and_destination_profiles_and_keys(): void {
		// Catches: deriving remote-copy keys from relative paths or using one
		// profile gateway for both sides of a provider-to-provider transfer.
		add_filter( 'kazus_multipart_threshold_bytes', static fn(): int => 100 );
		$this->source_gateway->head_results[] = array( 'exists' => true, 'content_length' => 11, 'etag' => 'source-etag' );
		$this->source_gateway->range_bodies['source-prefix/exact photo.jpg'] = 'remote body';
		$this->destination_gateway->head_results[] = array( 'exists' => true, 'content_length' => 11 );

		$result = $this->remote_service()->copy_remote(
			123,
			11,
			'source-prefix/exact photo.jpg',
			22,
			'dest-prefix/exact photo.jpg',
			'image/jpeg',
			'public, max-age=60',
			11
		);

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['verified_complete'] );
		$this->assertSame(
			array(
				array(
					'key'   => 'source-prefix/exact photo.jpg',
					'start' => 0,
					'end'   => 10,
				),
			),
			$this->source_gateway->range_calls
		);
		$this->assertSame(
			array(
				array(
					'key'           => 'dest-prefix/exact photo.jpg',
					'contents'      => 'remote body',
					'content_type'  => 'image/jpeg',
					'cache_control' => 'public, max-age=60',
				),
			),
			$this->destination_gateway->put_file_contents_calls
		);
		$this->assertSame( array( 'head', 'remote_copy:single', 'head' ), $this->events );
		$this->assertSame( array(), $this->sessions->created );
	}

	public function test_remote_copy_with_transient_destination_head_is_not_verified_complete(): void {
		// Catches: allowing a provider-to-provider transfer caller to promote
		// ownership after copy bytes were sent but final destination HEAD did not verify.
		add_filter( 'kazus_multipart_threshold_bytes', static fn(): int => 100 );
		$this->source_gateway->head_results[] = array( 'exists' => true, 'content_length' => 11, 'etag' => 'source-etag' );
		$this->source_gateway->range_bodies['source-key'] = 'remote body';
		$this->destination_gateway->head_results[] = array( 'exists' => false, 'confirmed_missing' => false, 'error' => 'timeout while reading HEAD' );

		$result = $this->remote_service()->copy_remote(
			321,
			11,
			'source-key',
			22,
			'dest-key',
			'application/octet-stream',
			'',
			11
		);

		$this->assertFalse( $result['success'] );
		$this->assertFalse( $result['verified_complete'] );
		$this->assertSame( 'Destination verification failed.', $result['message'] );
	}

	public function test_remote_copy_source_etag_change_fails_old_session_and_starts_new_upload(): void {
		// Catches: reusing completed parts from a previous multipart copy when
		// the source object was overwritten with different same-size bytes.
		add_filter( 'kazus_multipart_threshold_bytes', static fn(): int => 1 );
		add_filter( 'kazus_multipart_part_size_bytes', static fn(): int => 5 );
		$this->source_gateway->head_results[] = array( 'exists' => true, 'content_length' => 10, 'etag' => 'new-source-etag' );
		$this->source_gateway->range_bodies['source-key'] = 'abcdefghij';
		$this->destination_gateway->created_upload_ids[] = 'new-upload';
		$this->destination_gateway->head_results[] = array( 'exists' => true, 'content_length' => 10 );
		$old_fingerprint = hash( 'sha256', '11|source-key|10|old-source-etag' );
		$this->sessions->resumable_for_source = array(
			'id'                     => 166,
			'uuid'                   => 'eeeeeeee-5555-4555-8555-eeeeeeeeeeee',
			'kind'                   => 'remote_copy',
			'attachment_id'          => 906,
			'source_profile_id'      => 11,
			'destination_profile_id' => 22,
			'source_ref'             => 'source-key',
			'destination_key'        => 'dest-key',
			'size_bytes'             => 10,
			'source_fingerprint'     => $old_fingerprint,
			'multipart_upload_id'    => 'old-upload',
			'part_size_bytes'        => 5,
			'completed_parts'        => array( 1 => array( 'etag' => 'old-part-1', 'size' => 5 ) ),
			'status'                 => TransferSessionStatus::UPLOADING,
		);

		$result = $this->remote_service()->copy_remote( 906, 11, 'source-key', 22, 'dest-key', 'application/octet-stream', '', 10 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( TransferSessionStatus::FAILED, $this->sessions->updates[166]['status'] );
		$this->assertSame( 'source_changed', $this->sessions->updates[166]['last_error_code'] );
		$this->assertSame( 'new-upload', $this->destination_gateway->uploaded_parts[0]['upload_id'] );
		$this->assertNotContains( 'list', $this->events );
	}

	public function test_multipart_audit_events_do_not_include_provider_or_local_source_details(): void {
		$this->write_upload( '2026/09/audit.bin', 'audit-body' );
		add_filter( 'kazus_multipart_threshold_bytes', static fn(): int => 1 );
		$this->gateway->created_upload_ids[] = 'audit-upload-id';
		$this->gateway->head_results[]      = array( 'exists' => true, 'content_length' => 10 );

		$this->service( new AuditLog() )->upload_local( 904, 77, '2026/09/audit.bin', 'media/audit.bin', 'application/octet-stream', '' );

		$encoded = json_encode( WpStubs::$options[ AuditLog::OPTION_KEY ] ?? array() );
		$actions = array_column( WpStubs::$options[ AuditLog::OPTION_KEY ] ?? array(), 'action' );

		$this->assertContains( 'multipart_started', $actions );
		$this->assertContains( 'multipart_completed', $actions );
		$this->assertStringContainsString( '"profile_id":77', $encoded );
		$this->assertStringNotContainsString( 'test-bucket', $encoded );
		$this->assertStringNotContainsString( $this->uploads_dir, $encoded );
		$this->assertStringNotContainsString( 'audit-upload-id', $encoded );
		$this->assertStringNotContainsString( 'secret', strtolower( $encoded ) );
	}

	public function test_multipart_resume_and_fail_audit_events_are_sanitized(): void {
		$this->write_sparse_upload( '2026/09/audit-resume.bin', ( 5 * 1024 * 1024 ) + 3 );
		touch( $this->uploads_dir . '/2026/09/audit-resume.bin', 1788278400 );
		add_filter( 'kazus_multipart_threshold_bytes', static fn(): int => 1 );
		add_filter( 'kazus_multipart_part_size_bytes', static fn(): int => 5 * 1024 * 1024 );
		$fingerprint = hash( 'sha256', '2026/09/audit-resume.bin|' . ( ( 5 * 1024 * 1024 ) + 3 ) . '|1788278400' );
		$this->sessions->resumable = array(
			'id'                     => 141,
			'uuid'                   => 'dddddddd-4444-4444-8444-dddddddddddd',
			'kind'                   => 'local_upload',
			'attachment_id'          => 905,
			'destination_profile_id' => 77,
			'source_ref'             => '2026/09/audit-resume.bin',
			'destination_key'        => 'media/audit-resume.bin',
			'size_bytes'             => ( 5 * 1024 * 1024 ) + 3,
			'source_fingerprint'     => $fingerprint,
			'multipart_upload_id'    => 'resume-secret-upload-id',
			'part_size_bytes'        => 5 * 1024 * 1024,
			'completed_parts'        => array(),
			'status'                 => TransferSessionStatus::UPLOADING,
		);
		$this->gateway->part_errors[1] = array(
			new FakeRetryableTransferError( 'temporary outage 1' ),
			new FakeRetryableTransferError( 'temporary outage 2' ),
			new FakeRetryableTransferError( 'temporary outage 3' ),
			new FakeRetryableTransferError( 'temporary outage 4' ),
			new FakeRetryableTransferError( 'temporary outage 5' ),
		);

		$this->service( new AuditLog() )->upload_local( 905, 77, '2026/09/audit-resume.bin', 'media/audit-resume.bin', 'application/octet-stream', '' );

		$encoded = json_encode( WpStubs::$options[ AuditLog::OPTION_KEY ] ?? array() );
		$actions = array_column( WpStubs::$options[ AuditLog::OPTION_KEY ] ?? array(), 'action' );

		$this->assertContains( 'multipart_resumed', $actions );
		$this->assertContains( 'multipart_failed', $actions );
		$this->assertStringNotContainsString( 'test-bucket', $encoded );
		$this->assertStringNotContainsString( $this->uploads_dir, $encoded );
		$this->assertStringNotContainsString( 'resume-secret-upload-id', $encoded );
		$this->assertStringNotContainsString( 'secret', strtolower( $encoded ) );
	}

	private function service( ?AuditLog $audit = null ): ResumableTransferService {
		return new ResumableTransferService(
			$this->sessions,
			fn( int $profile_id ): StorageGatewayInterface => $this->gateway,
			function ( int $milliseconds ): void {
				$this->sleeps[] = $milliseconds;
			},
			null,
			$audit
		);
	}

	private function remote_service(): ResumableTransferService {
		return new ResumableTransferService(
			$this->sessions,
			function ( int $profile_id ): StorageGatewayInterface {
				return match ( $profile_id ) {
					22 => $this->destination_gateway,
					default => throw new \RuntimeException( 'Unexpected destination profile.' ),
				};
			},
			function ( int $milliseconds ): void {
				$this->sleeps[] = $milliseconds;
			},
			function ( int $profile_id ): StorageGatewayInterface {
				return match ( $profile_id ) {
					11 => $this->source_gateway,
					default => throw new \RuntimeException( 'Unexpected source profile.' ),
				};
			}
		);
	}

	private function write_upload( string $relative, string $contents ): void {
		file_put_contents( $this->uploads_dir . '/' . $relative, $contents );
	}

	private function write_sparse_upload( string $relative, int $size ): void {
		$handle = fopen( $this->uploads_dir . '/' . $relative, 'wb' );
		if ( $handle === false ) {
			throw new \RuntimeException( 'Unable to create sparse test upload.' );
		}
		ftruncate( $handle, $size );
		fclose( $handle );
	}

	private function delete_tree( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}
		$items = scandir( $path );
		if ( $items === false ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( $item === '.' || $item === '..' ) {
				continue;
			}
			$child = $path . '/' . $item;
			if ( is_dir( $child ) ) {
				$this->delete_tree( $child );
			} else {
				unlink( $child );
			}
		}
		rmdir( $path );
	}
}

final class FakeTransferSessionRepository {
	/** @var list<array<string,mixed>> */
	public array $created = array();
	/** @var list<string> */
	public array $events = array();
	/** @var array<string,mixed>|null */
	public ?array $resumable = null;
	/** @var array<string,mixed>|null */
	public ?array $resumable_for_source = null;
	/** @var array<int,array<string,mixed>> */
	public array $updates = array();

	public function find_resumable( string $kind, int $destination_profile_id, string $destination_key, string $source_fingerprint ): ?array {
		if (
			$this->resumable !== null
			&& $this->resumable['kind'] === $kind
			&& (int) $this->resumable['destination_profile_id'] === $destination_profile_id
			&& $this->resumable['destination_key'] === $destination_key
			&& $this->resumable['source_fingerprint'] === $source_fingerprint
			&& $this->is_resumable_status( (string) $this->resumable['status'] )
		) {
			return $this->resumable;
		}
		return null;
	}

	public function find_resumable_by_source( string $kind, int $destination_profile_id, string $destination_key, string $source_ref ): ?array {
		if (
			$this->resumable_for_source !== null
			&& $this->resumable_for_source['kind'] === $kind
			&& (int) $this->resumable_for_source['destination_profile_id'] === $destination_profile_id
			&& $this->resumable_for_source['destination_key'] === $destination_key
			&& $this->resumable_for_source['source_ref'] === $source_ref
			&& $this->is_resumable_status( (string) $this->resumable_for_source['status'] )
		) {
			return $this->resumable_for_source;
		}
		return null;
	}

	private function is_resumable_status( string $status ): bool {
		return in_array( $status, array( TransferSessionStatus::PENDING, TransferSessionStatus::UPLOADING, TransferSessionStatus::COMPLETING ), true );
	}

	/** @param array<string,mixed> $data */
	public function create( array $data ): int {
		$this->events[] = 'session:create';
		$this->created[] = $data;
		return count( $this->created );
	}

	/** @param array<string,mixed> $data */
	public function update( int $id, array $data ): void {
		$this->updates[ $id ] = array_merge( $this->updates[ $id ] ?? array(), $data );
		if ( $this->resumable !== null && (int) $this->resumable['id'] === $id ) {
			$this->resumable = array_merge( $this->resumable, $data );
		}
		if ( $this->resumable_for_source !== null && (int) $this->resumable_for_source['id'] === $id ) {
			$this->resumable_for_source = array_merge( $this->resumable_for_source, $data );
		}
	}
}

final class FakeTransferGateway implements StorageGatewayInterface {
	/** @var list<array<string,mixed>> */
	public array $put_file_calls = array();
	/** @var list<array<string,mixed>> */
	public array $created_uploads = array();
	/** @var list<string> */
	public array $created_upload_ids = array();
	/** @var list<array<string,mixed>> */
	public array $head_results = array();
	/** @var list<array<string,mixed>> */
	public array $uploaded_parts = array();
	/** @var list<array<string,mixed>> */
	public array $complete_calls = array();
	/** @var list<array<int,array{etag:string,size:int}>> */
	public array $list_parts_results = array();
	/** @var array<int,list<\Throwable>> */
	public array $part_errors = array();
	/** @var list<array{key:string,upload_id:string}> */
	public array $aborted_uploads = array();
	/** @var list<string> */
	public array $events = array();
	/** @var array<string,string> */
	public array $range_bodies = array();
	/** @var list<array{key:string,start:int,end:int}> */
	public array $range_calls = array();
	/** @var list<array{key:string,contents:string,content_type:string,cache_control:string}> */
	public array $put_file_contents_calls = array();

	public function __construct( private int $profile_id ) {
	}

	public function profile_id(): int {
		return $this->profile_id;
	}

	public function bucket(): string {
		return 'test-bucket';
	}

	/** @return array<string,mixed> */
	public function head_key( string $key ): array {
		$this->events[] = 'head';
		return array_shift( $this->head_results ) ?? array( 'exists' => false, 'confirmed_missing' => false, 'error' => 'not configured' );
	}

	public function put_object_from_file( string $key, string $local_path, string $content_type, string $cache_control ): void {
		if ( $this->profile_id === 22 ) {
			$this->events[] = 'remote_copy:single';
		}
		$this->put_file_calls[] = compact( 'key', 'local_path', 'content_type', 'cache_control' );
		$contents = file_get_contents( $local_path );
		if ( $contents !== false ) {
			$this->put_file_contents_calls[] = compact( 'key', 'contents', 'content_type', 'cache_control' );
		}
	}

	public function create_multipart_upload( string $key, string $content_type, string $cache_control ): string {
		$this->events[] = 'multipart:create';
		$this->created_uploads[] = compact( 'key', 'content_type', 'cache_control' );
		return array_shift( $this->created_upload_ids ) ?? 'upload-' . count( $this->created_uploads );
	}

	public function upload_part( string $key, string $upload_id, int $part_number, $body, int $content_length ): string {
		$this->events[] = 'part:' . $part_number;
		$this->uploaded_parts[] = compact( 'key', 'upload_id', 'part_number', 'content_length' );
		if ( ! empty( $this->part_errors[ $part_number ] ) ) {
			throw array_shift( $this->part_errors[ $part_number ] );
		}
		return 'etag-' . $part_number;
	}

	/** @return array<int,array{etag:string,size:int}> */
	public function list_parts( string $key, string $upload_id ): array {
		$this->events[] = 'list';
		return array_shift( $this->list_parts_results ) ?? array();
	}

	public function complete_multipart_upload( string $key, string $upload_id, array $parts ): void {
		$this->events[] = 'complete';
		$this->complete_calls[] = compact( 'key', 'upload_id', 'parts' );
	}

	public function abort_multipart_upload( string $key, string $upload_id ): void {
		$this->aborted_uploads[] = compact( 'key', 'upload_id' );
	}

	public function get_range( string $key, int $start, int $end ) {
		$this->range_calls[] = compact( 'key', 'start', 'end' );
		$body = fopen( 'php://temp', 'w+b' );
		if ( $body === false ) {
			throw new \RuntimeException( 'Unable to create range stream.' );
		}
		fwrite( $body, substr( $this->range_bodies[ $key ] ?? '', $start, $end - $start + 1 ) );
		rewind( $body );
		return $body;
	}
}

final class FakeRetryableTransferError extends \RuntimeException {
	public function getStatusCode(): int {
		return 503;
	}
}
