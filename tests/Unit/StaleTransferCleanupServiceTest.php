<?php
/**
 * Stale multipart transfer cleanup tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Domain\TransferSessionStatus;
use Kazcode\WpStorage\Services\AuditLog;
use Kazcode\WpStorage\Services\StaleTransferCleanupService;
use Kazcode\WpStorage\Storage\StorageGatewayInterface;
use Kazcode\WpStorage\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class StaleTransferCleanupServiceTest extends TestCase {

	private StaleCleanupFakeRepository $sessions;
	private StaleCleanupFakeGateway $gateway;

	protected function setUp(): void {
		WpStubs::reset();
		$this->sessions = new StaleCleanupFakeRepository();
		$this->gateway  = new StaleCleanupFakeGateway( 77 );
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_provider_confirmed_incomplete_multipart_is_aborted_and_session_marked_aborted(): void {
		$this->sessions->rows[] = $this->session( 10, 'media/stale.bin', 100, 'upload-stale', TransferSessionStatus::UPLOADING );
		$this->gateway->head_results[] = array( 'exists' => false, 'confirmed_missing' => true );
		$this->gateway->list_parts_results[] = array( 1 => array( 'etag' => 'part-one', 'size' => 50 ) );

		$result = $this->service()->run();

		$this->assertSame( 1, $result['aborted'] );
		$this->assertSame( array( array( 'key' => 'media/stale.bin', 'upload_id' => 'upload-stale' ) ), $this->gateway->aborted_uploads );
		$this->assertSame( TransferSessionStatus::ABORTED, $this->sessions->updates[10]['status'] );
		$this->assertSame( 'stale_multipart_aborted', $this->sessions->updates[10]['last_error_code'] );
		$actions = array_column( WpStubs::$options[ AuditLog::OPTION_KEY ] ?? array(), 'action' );
		$encoded = json_encode( WpStubs::$options[ AuditLog::OPTION_KEY ] ?? array() );
		$this->assertContains( 'multipart_stale_aborted', $actions );
		$this->assertStringNotContainsString( 'must-not-log-bucket', $encoded );
		$this->assertStringNotContainsString( 'upload-stale', $encoded );
	}

	public function test_completed_destination_object_marks_session_completed_without_abort(): void {
		$this->sessions->rows[] = $this->session( 11, 'media/done.bin', 123, 'upload-done', TransferSessionStatus::UPLOADING );
		$this->gateway->head_results[] = array( 'exists' => true, 'content_length' => 123 );

		$result = $this->service()->run();

		$this->assertSame( 1, $result['completed'] );
		$this->assertSame( array(), $this->gateway->list_parts_calls );
		$this->assertSame( array(), $this->gateway->aborted_uploads );
		$this->assertSame( TransferSessionStatus::COMPLETED, $this->sessions->updates[11]['status'] );
		$this->assertArrayHasKey( 'completed_at', $this->sessions->updates[11] );
	}

	public function test_provider_check_transient_failure_leaves_session_unchanged(): void {
		$this->sessions->rows[] = $this->session( 12, 'media/transient.bin', 456, 'upload-transient', TransferSessionStatus::UPLOADING );
		$this->gateway->head_errors[] = new \RuntimeException( 'temporary HEAD timeout' );

		$result = $this->service()->run();

		$this->assertSame( 1, $result['deferred'] );
		$this->assertSame( array(), $this->gateway->list_parts_calls );
		$this->assertSame( array(), $this->gateway->aborted_uploads );
		$this->assertSame( array(), $this->sessions->updates );
	}

	public function test_missing_upload_id_is_marked_aborted_locally_without_remote_delete(): void {
		$this->sessions->rows[] = $this->session( 13, 'media/no-upload.bin', 789, '', TransferSessionStatus::UPLOADING );

		$result = $this->service()->run();

		$this->assertSame( 1, $result['local_aborted'] );
		$this->assertSame( array(), $this->gateway->head_calls );
		$this->assertSame( array(), $this->gateway->aborted_uploads );
		$this->assertSame( TransferSessionStatus::ABORTED, $this->sessions->updates[13]['status'] );
		$this->assertSame( 'stale_missing_upload_id', $this->sessions->updates[13]['last_error_code'] );
	}

	private function service(): StaleTransferCleanupService {
		return new StaleTransferCleanupService(
			$this->sessions,
			fn( int $profile_id ): StorageGatewayInterface => $this->gateway,
			new AuditLog()
		);
	}

	/** @return array<string,mixed> */
	private function session( int $id, string $key, int $size, string $upload_id, string $status ): array {
		return array(
			'id'                     => $id,
			'uuid'                   => 'session-' . $id,
			'kind'                   => 'local_upload',
			'attachment_id'          => 1000 + $id,
			'destination_profile_id' => 77,
			'source_ref'             => '2026/09/source-' . $id . '.bin',
			'destination_key'        => $key,
			'size_bytes'             => $size,
			'source_fingerprint'     => 'fingerprint-' . $id,
			'multipart_upload_id'    => $upload_id,
			'part_size_bytes'        => 64 * 1024 * 1024,
			'completed_parts'        => array(),
			'status'                 => $status,
			'updated_at'             => '2026-08-20 00:00:00',
		);
	}
}

final class StaleCleanupFakeRepository {
	/** @var list<array<string,mixed>> */
	public array $rows = array();
	/** @var array<int,array<string,mixed>> */
	public array $updates = array();

	public function list_stale_incomplete( int $before_timestamp, int $after_id ): array {
		return array_values(
			array_filter(
				$this->rows,
				static fn( array $row ): bool => (int) $row['id'] > $after_id
			)
		);
	}

	/** @param array<string,mixed> $data */
	public function update( int $id, array $data ): void {
		$this->updates[ $id ] = array_merge( $this->updates[ $id ] ?? array(), $data );
	}
}

final class StaleCleanupFakeGateway implements StorageGatewayInterface {
	/** @var list<array<string,mixed>> */
	public array $head_results = array();
	/** @var list<\Throwable> */
	public array $head_errors = array();
	/** @var list<array{key:string}> */
	public array $head_calls = array();
	/** @var list<array<int,array{etag:string,size:int}>> */
	public array $list_parts_results = array();
	/** @var list<array{key:string,upload_id:string}> */
	public array $list_parts_calls = array();
	/** @var list<array{key:string,upload_id:string}> */
	public array $aborted_uploads = array();

	public function __construct( private int $profile_id ) {
	}

	public function profile_id(): int {
		return $this->profile_id;
	}

	public function bucket(): string {
		return 'must-not-log-bucket';
	}

	public function head_key( string $key ): array {
		$this->head_calls[] = compact( 'key' );
		if ( $this->head_errors !== array() ) {
			throw array_shift( $this->head_errors );
		}
		return array_shift( $this->head_results ) ?? array( 'exists' => false, 'confirmed_missing' => true );
	}

	public function put_object_from_file( string $key, string $local_path, string $content_type, string $cache_control ): void {
		throw new \LogicException( 'Cleanup must not write objects.' );
	}

	public function create_multipart_upload( string $key, string $content_type, string $cache_control ): string {
		throw new \LogicException( 'Cleanup must not create multipart uploads.' );
	}

	public function upload_part( string $key, string $upload_id, int $part_number, $body, int $content_length ): string {
		throw new \LogicException( 'Cleanup must not upload parts.' );
	}

	public function list_parts( string $key, string $upload_id ): array {
		$this->list_parts_calls[] = compact( 'key', 'upload_id' );
		return array_shift( $this->list_parts_results ) ?? array();
	}

	public function complete_multipart_upload( string $key, string $upload_id, array $parts ): void {
		throw new \LogicException( 'Cleanup must not complete multipart uploads.' );
	}

	public function abort_multipart_upload( string $key, string $upload_id ): void {
		$this->aborted_uploads[] = compact( 'key', 'upload_id' );
	}

	public function get_range( string $key, int $start, int $end ) {
		throw new \LogicException( 'Cleanup must not read ranges.' );
	}
}
