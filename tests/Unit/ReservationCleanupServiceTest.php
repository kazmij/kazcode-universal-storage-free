<?php
declare(strict_types=1);
namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Infrastructure\ReservationRepository;
use Kazcode\WpStorage\Infrastructure\ReservationCleanupRepository;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Services\ReservationCleanupService;
use PHPUnit\Framework\TestCase;

final class ReservationCleanupServiceTest extends TestCase {
	protected function tearDown(): void {
		remove_all_filters( 'kazus_direct_upload_cleanup_retire_pre_ready_session' );
	}

	public function test_owned_release_operational_failure_never_marks_or_retires_pre_ready_session(): void {
		$binding = array( 'version' => 1, 'attachment_id' => 80, 'blog_id' => 1, 'owner_uuid' => 'session-owned', 'path_reservation_id' => 139, 'key_reservation_id' => 140, 'relative_path' => '2026/10/archive.zip', 'profile_id' => 9, 'object_key' => 'private/direct-upload/session-owned/archive.zip', 'direct_upload_session_uuid' => 'session-owned', 'direct_upload_attempt_uuid' => 'attempt-owned', 'direct_upload_attempt_generation' => 1, 'direct_upload_profile_fingerprint' => 'profile-owned' );
		$records = new class( $binding ) {
			public bool $marked = false;
			public function __construct( private array $binding ) {}
			public function pending( int $limit ): array { return array( array( 'id' => 15, 'state' => 'ready', 'binding_json' => wp_json_encode( $this->binding ), 'settlement_proof' => 'attachment_deleted:80' ) ); }
			public function require_pre_ready_retirement( int $id, array $binding, string $proof ): bool { $this->marked = true; return true; }
			public function complete( int $id ): bool { return true; }
		};
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->expects( $this->once() )->method( 'release_owned_attachment_pair' )->willReturn( new \WP_Error( 'reservation_attachment_release_failed', 'Transaction could not start.' ) );
		$reservations->expects( $this->once() )->method( 'inspect_pre_ready_pinned_attachment_pair' )->willReturn( array( 'status' => 'not_pinned' ) );
		$reservations->expects( $this->never() )->method( 'release_pre_ready_pinned_attachment_pair' );
		$retirement_calls = 0;
		add_filter( 'kazus_direct_upload_cleanup_retire_pre_ready_session', static function() use ( &$retirement_calls ): bool { ++$retirement_calls; return true; } );

		$this->assertSame( array( 'scanned' => 1, 'released' => 0, 'deferred' => 1 ), ( new ReservationCleanupService( $records, $reservations ) )->run() );
		$this->assertFalse( $records->marked );
		$this->assertSame( 0, $retirement_calls );
	}

	public function test_marker_bound_finalization_intent_is_retained_until_atomic_settlement_then_removes_only_its_exact_row(): void {
		$binding = array( 'version' => 1, 'attachment_id' => 91, 'blog_id' => 1, 'owner_uuid' => 'session-91', 'path_reservation_id' => 191, 'key_reservation_id' => 192, 'relative_path' => '2026/10/archive.zip', 'profile_id' => 9, 'object_key' => 'private/direct-upload/session-91/archive.zip', 'profile_fingerprint' => 'profile-91', 'direct_upload_finalization_marker' => 'direct-upload-91', 'direct_upload_finalization_intent_binding' => '{"owner":"session-91"}', 'direct_upload_finalization_intent_location' => '{"object":"private/direct-upload/session-91/archive.zip"}' );
		$records = new class( $binding ) {
			public bool $fail_settlement = true;
			public bool $settled = false;
			public array $intent_rows = array( 'direct-upload-91' => 'exact-91', 'unrelated-marker' => 'unrelated' );
			public array $calls = array();
			public function __construct( private array $binding ) {}
			public function pending( int $limit ): array { return $this->settled ? array() : array( array( 'id' => 91, 'state' => 'ready', 'binding_json' => wp_json_encode( $this->binding ), 'settlement_proof' => 'attachment_deleted:91' ) ); }
			public function complete( int $id ): bool { throw new \LogicException( 'Marker-bound cleanup must use atomic settlement.' ); }
			public function complete_with_finalization_intent( int $id, array $binding, string $proof ): bool {
				$this->calls[] = array( $id, $binding['direct_upload_finalization_marker'] ?? '', $proof );
				if ( $this->fail_settlement ) return false;
				unset( $this->intent_rows['direct-upload-91'] );
				$this->settled = true;
				return true;
			}
		};
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->expects( $this->exactly( 2 ) )->method( 'release_owned_attachment_pair' )->willReturn( array( 'path_reservation_id' => 191, 'key_reservation_id' => 192 ) );
		$service = new ReservationCleanupService( $records, $reservations );

		$this->assertSame( array( 'scanned' => 1, 'released' => 0, 'deferred' => 1 ), $service->run(), 'A failed exact-intent delete retains the durable cleanup retry record.' );
		$this->assertSame( array( 'direct-upload-91' => 'exact-91', 'unrelated-marker' => 'unrelated' ), $records->intent_rows, 'The finalization intent survives while cleanup remains unsettled.' );
		$records->fail_settlement = false;
		$this->assertSame( array( 'scanned' => 1, 'released' => 1, 'deferred' => 0 ), $service->run() );
		$this->assertSame( array( 'unrelated-marker' => 'unrelated' ), $records->intent_rows, 'Only the exact marker-bound finalization intent is retired.' );
		$this->assertSame( array( array( 91, 'direct-upload-91', 'attachment_deleted:91' ), array( 91, 'direct-upload-91', 'attachment_deleted:91' ) ), $records->calls );
		$this->assertSame( array( 'scanned' => 0, 'released' => 0, 'deferred' => 0 ), $service->run(), 'The settled retry is idempotent.' );
	}

	public function test_atomic_intent_settlement_deletes_only_the_exact_marker_bound_row_and_rolls_back_on_intent_failure(): void {
		$wpdb = new class {
			public string $prefix = 'wp_';
			public array $queries = array();
			public array $deletes = array();
			public bool $fail_intent_delete = false;
			public function prepare( string $query, mixed ...$args ): string { return $query; }
			public function query( string $query ): int|false {
				$this->queries[] = $query;
				if ( str_starts_with( $query, 'DELETE FROM wp_s3ms_du_finalization_intents' ) ) return $this->fail_intent_delete ? false : 1;
				return 1;
			}
			public function delete( string $table, array $where, array $format ): int|false { $this->deletes[] = compact( 'table', 'where', 'format' ); return 1; }
		};
		$GLOBALS['wpdb'] = $wpdb;
		$binding = array( 'attachment_id' => 92, 'direct_upload_finalization_marker' => 'marker-92', 'direct_upload_finalization_intent_binding' => '{"binding":"exact"}', 'direct_upload_finalization_intent_location' => '{"location":"exact"}' );
		$repository = new ReservationCleanupRepository();
		$this->assertTrue( $repository->complete_with_finalization_intent( 92, $binding, 'attachment_deleted:92' ) );
		$this->assertStringContainsString( 'WHERE recovery_marker = %s', $wpdb->queries[1] );
		$this->assertSame( array( array( 'table' => 'wp_s3ms_reservation_cleanup', 'where' => array( 'id' => 92 ), 'format' => array( '%d' ) ) ), $wpdb->deletes );
		$this->assertSame( 'COMMIT', $wpdb->queries[2] );

		$wpdb->queries = array();
		$wpdb->deletes = array();
		$wpdb->fail_intent_delete = true;
		$this->assertFalse( $repository->complete_with_finalization_intent( 92, $binding, 'attachment_deleted:92' ) );
		$this->assertSame( array(), $wpdb->deletes, 'A failed intent deletion must retain cleanup settlement for retry.' );
		$this->assertSame( 'ROLLBACK', $wpdb->queries[2] );
		unset( $GLOBALS['wpdb'] );
	}

	public function test_pre_ready_retirement_obligation_is_durable_across_an_idempotent_same_proof_replay(): void {
		$binding = array( 'version' => 1, 'attachment_id' => 81, 'blog_id' => 1, 'owner_uuid' => 'session-16', 'path_reservation_id' => 141, 'key_reservation_id' => 142, 'relative_path' => '2026/10/archive.zip', 'profile_id' => 9, 'object_key' => 'private/direct-upload/session-16/archive.zip', 'direct_upload_session_uuid' => 'session-16', 'direct_upload_attempt_uuid' => 'attempt-16', 'direct_upload_attempt_generation' => 1, 'direct_upload_profile_fingerprint' => 'profile-16' );
		$records = new class( $binding ) {
			public bool $requires_pre_ready_retirement = false;
			public array $completed = array();
			public array $events = array();
			public function __construct( private array $binding ) {}
			public function pending( int $limit ): array {
				return $this->completed === array() ? array( array( 'id' => 16, 'state' => 'ready', 'binding_json' => wp_json_encode( $this->binding ), 'settlement_proof' => 'attachment_deleted:81' ) ) : array();
			}
			public function require_pre_ready_retirement( int $id, array $binding, string $proof ): bool {
				if ( $id !== 16 || $proof !== 'attachment_deleted:81' || $binding !== $this->binding ) return false;
				$this->events[] = 'marker';
				$this->requires_pre_ready_retirement = true;
				$this->binding['requires_pre_ready_session_retirement'] = true;
				return true;
			}
			public function complete( int $id ): bool { $this->completed[] = $id; return true; }
		};
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->expects( $this->exactly( 2 ) )->method( 'release_owned_attachment_pair' )
			->willReturnOnConsecutiveCalls( new \WP_Error( 'still_pinned', 'Still pinned.' ), array( 'path_reservation_id' => 141, 'key_reservation_id' => 142 ) );
		$reservations->expects( $this->once() )->method( 'inspect_pre_ready_pinned_attachment_pair' )->willReturn( array( 'status' => 'pinned' ) );
		$reservations->expects( $this->once() )->method( 'release_pre_ready_pinned_attachment_pair' )
			->willReturnCallback( function () use ( $records ): array { $records->events[] = 'settle'; return array( 'path_reservation_id' => 141, 'key_reservation_id' => 142 ); } );
		$retirement_calls = 0;
		add_filter( 'kazus_direct_upload_cleanup_retire_pre_ready_session', static function( bool $retired, array $received, string $proof ) use ( &$retirement_calls ): bool {
			++$retirement_calls;
			return $received['requires_pre_ready_session_retirement'] === true && $proof === 'attachment_deleted:81' && $retirement_calls > 1;
		}, 10, 3 );

		$service = new ReservationCleanupService( $records, $reservations );
		$this->assertSame( array( 'scanned' => 1, 'released' => 0, 'deferred' => 1 ), $service->run() );
		$this->assertTrue( $records->requires_pre_ready_retirement );
		$this->assertSame( array( 'marker', 'settle' ), $records->events );
		$this->assertSame( array(), $records->completed );
		$this->assertSame( array( 'scanned' => 1, 'released' => 1, 'deferred' => 0 ), $service->run() );
		$this->assertSame( 2, $retirement_calls );
		$this->assertSame( array( 16 ), $records->completed );
	}

	public function test_pre_ready_marker_failure_defers_before_settling_either_pinned_reservation(): void {
		$binding = array( 'version' => 1, 'attachment_id' => 83, 'blog_id' => 1, 'owner_uuid' => 'session-18', 'path_reservation_id' => 161, 'key_reservation_id' => 162, 'relative_path' => '2026/10/archive.zip', 'profile_id' => 9, 'object_key' => 'private/direct-upload/session-18/archive.zip', 'direct_upload_session_uuid' => 'session-18', 'direct_upload_attempt_uuid' => 'attempt-18', 'direct_upload_attempt_generation' => 1, 'direct_upload_profile_fingerprint' => 'profile-18' );
		$records = new class( $binding ) {
			public array $completed = array();
			public function __construct( private array $binding ) {}
			public function pending( int $limit ): array { return array( array( 'id' => 18, 'state' => 'ready', 'binding_json' => wp_json_encode( $this->binding ), 'settlement_proof' => 'attachment_deleted:83' ) ); }
			public function require_pre_ready_retirement( int $id, array $binding, string $proof ): bool { return false; }
			public function complete( int $id ): bool { $this->completed[] = $id; return true; }
		};
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->expects( $this->once() )->method( 'release_owned_attachment_pair' )->willReturn( new \WP_Error( 'still_pinned', 'Still pinned.' ) );
		$reservations->expects( $this->once() )->method( 'inspect_pre_ready_pinned_attachment_pair' )->willReturn( array( 'status' => 'pinned' ) );
		$reservations->expects( $this->never() )->method( 'release_pre_ready_pinned_attachment_pair' );

		$this->assertSame( array( 'scanned' => 1, 'released' => 0, 'deferred' => 1 ), ( new ReservationCleanupService( $records, $reservations ) )->run() );
		$this->assertSame( array(), $records->completed );
	}

	public function test_pre_ready_cleanup_without_a_pro_callback_stays_durable_until_reactivation_acknowledges_it(): void {
		$binding = array( 'version' => 1, 'attachment_id' => 84, 'blog_id' => 1, 'owner_uuid' => 'session-19', 'path_reservation_id' => 171, 'key_reservation_id' => 172, 'relative_path' => '2026/10/archive.zip', 'profile_id' => 9, 'object_key' => 'private/direct-upload/session-19/archive.zip', 'direct_upload_session_uuid' => 'session-19', 'direct_upload_attempt_uuid' => 'attempt-19', 'direct_upload_attempt_generation' => 1, 'direct_upload_profile_fingerprint' => 'profile-19', 'requires_pre_ready_session_retirement' => true );
		$records = new class( $binding ) {
			public array $completed = array();
			public function __construct( private array $binding ) {}
			public function pending( int $limit ): array { return $this->completed === array() ? array( array( 'id' => 19, 'state' => 'ready', 'binding_json' => wp_json_encode( $this->binding ), 'settlement_proof' => 'attachment_deleted:84' ) ) : array(); }
			public function complete( int $id ): bool { $this->completed[] = $id; return true; }
		};
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->expects( $this->exactly( 2 ) )->method( 'release_owned_attachment_pair' )->willReturn( array( 'path_reservation_id' => 171, 'key_reservation_id' => 172 ) );
		$service = new ReservationCleanupService( $records, $reservations );

		$this->assertSame( array( 'scanned' => 1, 'released' => 0, 'deferred' => 1 ), $service->run() );
		$this->assertSame( array(), $records->completed );
		$retirement_calls = 0;
		add_filter( 'kazus_direct_upload_cleanup_retire_pre_ready_session', static function( bool $retired, array $received, string $proof ) use ( &$retirement_calls ): bool { ++$retirement_calls; return $received['direct_upload_session_uuid'] === 'session-19' && $proof === 'attachment_deleted:84'; }, 10, 3 );
		$this->assertSame( array( 'scanned' => 1, 'released' => 1, 'deferred' => 0 ), $service->run() );
		$this->assertSame( 1, $retirement_calls );
		$this->assertSame( array( 19 ), $records->completed );
	}

	public function test_pre_ready_recovery_keeps_the_durable_record_when_the_proof_bound_retirement_port_refuses_the_cleanup(): void {
		$binding = array( 'version' => 1, 'attachment_id' => 79, 'blog_id' => 1, 'owner_uuid' => 'session-14', 'path_reservation_id' => 121, 'key_reservation_id' => 122, 'relative_path' => '2026/10/archive.zip', 'profile_id' => 9, 'object_key' => 'private/direct-upload/session-14/archive.zip', 'direct_upload_session_uuid' => 'session-14', 'direct_upload_attempt_uuid' => 'attempt-14', 'direct_upload_attempt_generation' => 1, 'direct_upload_profile_fingerprint' => 'profile-14' );
		$records = new class( $binding ) {
			public array $completed = array();
			public function __construct( private array $binding ) {}
			public function pending( int $limit ): array { return array( array( 'id' => 14, 'state' => 'ready', 'binding_json' => wp_json_encode( $this->binding ), 'settlement_proof' => 'attachment_deleted:79' ) ); }
			public function require_pre_ready_retirement( int $id, array $binding, string $proof ): bool { return $id === 14 && $proof === 'attachment_deleted:79' && $binding === $this->binding; }
			public function complete( int $id ): bool { $this->completed[] = $id; return true; }
		};
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->expects( $this->once() )->method( 'release_owned_attachment_pair' )->willReturn( new \WP_Error( 'still_pinned', 'Still pinned.' ) );
		$reservations->expects( $this->once() )->method( 'inspect_pre_ready_pinned_attachment_pair' )->willReturn( array( 'status' => 'pinned' ) );
		$reservations->expects( $this->once() )->method( 'release_pre_ready_pinned_attachment_pair' )->willReturn( array( 'path_reservation_id' => 121 ) );
		add_filter( 'kazus_direct_upload_cleanup_retire_pre_ready_session', static fn( bool $retired, array $received, string $proof ): bool => $retired && $received === $binding && $proof === 'attachment_deleted:79' ? false : false, 10, 3 );

		$result = ( new ReservationCleanupService( $records, $reservations ) )->run();

		$this->assertSame( array( 'scanned' => 1, 'released' => 0, 'deferred' => 1 ), $result );
		$this->assertSame( array(), $records->completed );
	}
	public function test_recovery_releases_only_the_exact_durably_recorded_reservation_pair(): void {
		$binding = array( 'version' => 1, 'attachment_id' => 74, 'blog_id' => 1, 'owner_uuid' => 'session-9', 'path_reservation_id' => 71, 'key_reservation_id' => 72, 'relative_path' => '2026/10/archive.zip', 'profile_id' => 9, 'object_key' => 'private/direct-upload/session-9/archive.zip' );
		$records = new class( $binding ) {
			public array $completed = array();
			public function __construct( private array $binding ) {}
			public function pending( int $limit ): array { return array( array( 'id' => 5, 'state' => 'ready', 'binding_json' => wp_json_encode( $this->binding ), 'settlement_proof' => 'attachment_deleted:74' ) ); }
			public function complete( int $id ): bool { $this->completed[] = $id; return true; }
		};
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->expects( $this->once() )->method( 'release_owned_attachment_pair' )->with( 1, 'session-9', 71, 72, '2026/10/archive.zip', 9, 'private/direct-upload/session-9/archive.zip', 'attachment_deleted:74' )->willReturn( array( 'path_reservation_id' => 71 ) );

		$result = ( new ReservationCleanupService( $records, $reservations ) )->run();

		$this->assertSame( array( 'scanned' => 1, 'released' => 1, 'deferred' => 0 ), $result );
		$this->assertSame( array( 5 ), $records->completed );
	}

	public function test_pre_ready_recovery_releases_the_exact_pinned_pair_only_after_remote_deletion_and_inventory_cleanup(): void {
		$binding = array( 'version' => 1, 'attachment_id' => 79, 'blog_id' => 1, 'owner_uuid' => 'session-14', 'path_reservation_id' => 121, 'key_reservation_id' => 122, 'relative_path' => '2026/10/archive.zip', 'profile_id' => 9, 'object_key' => 'private/direct-upload/session-14/archive.zip', 'direct_upload_session_uuid' => 'session-14', 'direct_upload_attempt_uuid' => 'attempt-14', 'direct_upload_attempt_generation' => 1, 'direct_upload_profile_fingerprint' => 'profile-14' );
		$records = new class( $binding ) {
			public array $completed = array();
			public array $ready = array();
			public function __construct( private array $binding ) {}
			public function pending( int $limit ): array { return array( array( 'id' => 14, 'state' => 'remote_deleted', 'binding_json' => wp_json_encode( $this->binding ), 'settlement_proof' => 'attachment_deleted:79' ) ); }
			public function ready( int $id ): bool { $this->ready[] = $id; return true; }
			public function require_pre_ready_retirement( int $id, array $binding, string $proof ): bool { return $id === 14 && $proof === 'attachment_deleted:79' && $binding === $this->binding; }
			public function complete( int $id ): bool { $this->completed[] = $id; return true; }
		};
		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )->method( 'delete_by_attachment' )->with( 79 );
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->expects( $this->once() )->method( 'release_owned_attachment_pair' )
			->with( 1, 'session-14', 121, 122, '2026/10/archive.zip', 9, 'private/direct-upload/session-14/archive.zip', 'attachment_deleted:79' )
			->willReturn( new \WP_Error( 'reservation_attachment_release_lost', 'Still pinned.' ) );
		$reservations->expects( $this->once() )->method( 'inspect_pre_ready_pinned_attachment_pair' )->willReturn( array( 'status' => 'pinned' ) );
		$reservations->expects( $this->once() )->method( 'release_pre_ready_pinned_attachment_pair' )
			->with( 1, 'session-14', 121, 122, '2026/10/archive.zip', 9, 'private/direct-upload/session-14/archive.zip', 'attachment_deleted:79' )
			->willReturn( array( 'path_reservation_id' => 121 ) );
		add_filter( 'kazus_direct_upload_cleanup_retire_pre_ready_session', static fn( bool $retired, array $received, string $proof ): bool => $received['direct_upload_session_uuid'] === 'session-14' && $proof === 'attachment_deleted:79', 10, 3 );

		$result = ( new ReservationCleanupService( $records, $reservations, $objects ) )->run();

		$this->assertSame( array( 'scanned' => 1, 'released' => 1, 'deferred' => 0 ), $result );
		$this->assertSame( array( 14 ), $records->ready );
		$this->assertSame( array( 14 ), $records->completed );
	}

	public function test_intent_is_retained_without_releasing_until_remote_and_inventory_cleanup_prove_ready(): void {
		$records = new class {
			public array $completed = array();
			public function pending( int $limit ): array { return array( array( 'id' => 6, 'state' => 'intent', 'binding_json' => wp_json_encode( array( 'version' => 1, 'blog_id' => 1, 'owner_uuid' => 'session-10', 'path_reservation_id' => 81, 'key_reservation_id' => 82, 'relative_path' => '2026/10/archive.zip', 'profile_id' => 9, 'object_key' => 'private/direct-upload/session-10/archive.zip' ) ), 'settlement_proof' => 'attachment_deleted:75' ) ); }
			public function complete( int $id ): bool { $this->completed[] = $id; return true; }
		};
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->expects( $this->never() )->method( 'release_owned_attachment_pair' );

		$result = ( new ReservationCleanupService( $records, $reservations ) )->run();

		$this->assertSame( array( 'scanned' => 1, 'released' => 0, 'deferred' => 1 ), $result );
		$this->assertSame( array(), $records->completed );
	}

	public function test_intent_replay_retries_the_persisted_exact_remote_binding_before_inventory_cleanup_and_exact_pair_release(): void {
		$binding = array( 'version' => 1, 'attachment_id' => 77, 'blog_id' => 1, 'owner_uuid' => 'session-12', 'path_reservation_id' => 101, 'key_reservation_id' => 102, 'relative_path' => '2026/10/archive.zip', 'profile_id' => 9, 'object_key' => 'private/direct-upload/session-12/archive.zip', 'profile_fingerprint' => 'profile-fingerprint-12' );
		$records = new class( $binding ) {
			public string $state = 'intent';
			public array $completed = array();
			public int $remote_transition_attempts = 0;
			public function __construct( private array $binding ) {}
			public function pending( int $limit ): array { return array( array( 'id' => 8, 'state' => $this->state, 'binding_json' => wp_json_encode( $this->binding ), 'settlement_proof' => 'attachment_deleted:77' ) ); }
			public function remote_deleted( int $id ): bool { ++$this->remote_transition_attempts; if ( $this->remote_transition_attempts === 1 ) return false; $this->state = 'remote_deleted'; return true; }
			public function ready( int $id ): bool { $this->state = 'ready'; return true; }
			public function complete( int $id ): bool { $this->completed[] = $id; return true; }
		};
		$remote = new class {
			public array $calls = array();
			public function delete_exact_profile_key( int $profile_id, string $object_key, string $fingerprint ): bool {
				$this->calls[] = array( $profile_id, $object_key, $fingerprint );
				return count( $this->calls ) > 1;
			}
		};
		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )->method( 'delete_by_attachment' )->with( 77 );
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->expects( $this->once() )->method( 'release_owned_attachment_pair' )
			->with( 1, 'session-12', 101, 102, '2026/10/archive.zip', 9, 'private/direct-upload/session-12/archive.zip', 'attachment_deleted:77' )
			->willReturn( array( 'path_reservation_id' => 101 ) );

		$service = new ReservationCleanupService( $records, $reservations, $objects, $remote );
		$this->assertSame( array( 'scanned' => 1, 'released' => 0, 'deferred' => 1 ), $service->run() );
		$this->assertSame( 'intent', $records->state );
		$this->assertSame( array(), $records->completed );

		$this->assertSame( array( 'scanned' => 1, 'released' => 0, 'deferred' => 1 ), $service->run() );
		$this->assertSame( 'intent', $records->state );
		$this->assertSame( array(), $records->completed );

		$this->assertSame( array( 'scanned' => 1, 'released' => 1, 'deferred' => 0 ), $service->run() );
		$this->assertSame( array( 8 ), $records->completed );
		$this->assertSame( array( array( 9, 'private/direct-upload/session-12/archive.zip', 'profile-fingerprint-12' ), array( 9, 'private/direct-upload/session-12/archive.zip', 'profile-fingerprint-12' ), array( 9, 'private/direct-upload/session-12/archive.zip', 'profile-fingerprint-12' ) ), $remote->calls );
	}

	public function test_post_remote_replay_finishes_inventory_then_releases_only_the_bound_pair(): void {
		$binding = array( 'version' => 1, 'attachment_id' => 76, 'blog_id' => 1, 'owner_uuid' => 'session-11', 'path_reservation_id' => 91, 'key_reservation_id' => 92, 'relative_path' => '2026/10/archive.zip', 'profile_id' => 9, 'object_key' => 'private/direct-upload/session-11/archive.zip' );
		$records = new class( $binding ) {
			public array $completed = array();
			public array $ready = array();
			public function __construct( private array $binding ) {}
			public function pending( int $limit ): array { return array( array( 'id' => 7, 'state' => 'remote_deleted', 'binding_json' => wp_json_encode( $this->binding ), 'settlement_proof' => 'attachment_deleted:76' ) ); }
			public function ready( int $id ): bool { $this->ready[] = $id; return true; }
			public function complete( int $id ): bool { $this->completed[] = $id; return true; }
		};
		$objects = $this->createMock( \Kazcode\WpStorage\Infrastructure\ObjectRepository::class );
		$objects->expects( $this->once() )->method( 'delete_by_attachment' )->with( 76 );
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->expects( $this->once() )->method( 'release_owned_attachment_pair' )->with( 1, 'session-11', 91, 92, '2026/10/archive.zip', 9, 'private/direct-upload/session-11/archive.zip', 'attachment_deleted:76' )->willReturn( array( 'path_reservation_id' => 91 ) );

		$result = ( new ReservationCleanupService( $records, $reservations, $objects ) )->run();

		$this->assertSame( array( 'scanned' => 1, 'released' => 1, 'deferred' => 0 ), $result );
		$this->assertSame( array( 7 ), $records->ready );
		$this->assertSame( array( 7 ), $records->completed );
	}

	public function test_migrated_replay_removes_every_attachment_inventory_row_only_after_remote_cleanup_is_settled(): void {
		$previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$inventory = new ReplayInventoryWpdbFake( array(
			array( 'attachment_id' => 176, 'storage_profile_id' => 12, 'object_key' => 'destination/archive.zip', 'remote_status' => 'present' ),
			array( 'attachment_id' => 176, 'storage_profile_id' => 9, 'object_key' => 'source-stale/archive.zip', 'remote_status' => 'stale' ),
			array( 'attachment_id' => 176, 'storage_profile_id' => 9, 'object_key' => 'source-deleted/archive.zip', 'remote_status' => 'deleted' ),
		) );
		$GLOBALS['wpdb'] = $inventory;
		$binding = array( 'version' => 1, 'attachment_id' => 176, 'blog_id' => 1, 'owner_uuid' => 'session-176', 'path_reservation_id' => 291, 'key_reservation_id' => 292, 'relative_path' => '2026/10/archive.zip', 'profile_id' => 9, 'object_key' => 'source/archive.zip', 'cleanup_profile_id' => 12, 'cleanup_object_key' => 'destination/archive.zip', 'cleanup_profile_fingerprint' => str_repeat( 'd', 64 ), 'migration_cleanup_location' => array( 'profile_id' => 12, 'object_key' => 'destination/archive.zip', 'profile_fingerprint' => str_repeat( 'd', 64 ) ) );
		$records = new class( $binding ) {
			public string $state = 'intent'; public array $completed = array();
			public function __construct( private array $binding ) {}
			public function pending( int $limit ): array { return array( array( 'id' => 176, 'state' => $this->state, 'binding_json' => wp_json_encode( $this->binding ), 'settlement_proof' => 'attachment_deleted:176' ) ); }
			public function remote_deleted( int $id ): bool { $this->state = 'remote_deleted'; return true; }
			public function ready( int $id ): bool { $this->state = 'ready'; return true; }
			public function complete( int $id ): bool { $this->completed[] = $id; return true; }
		};
		$remote = new class { public bool $settled = false; public function delete_exact_profile_key( int $profile_id, string $object_key, string $fingerprint ): bool { return $this->settled; } };
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->expects( $this->once() )->method( 'release_owned_attachment_pair' )->willReturn( array( 'path_reservation_id' => 291 ) );
		$service = new ReservationCleanupService( $records, $reservations, new ObjectRepository(), $remote );

		try {
			$this->assertSame( array( 'scanned' => 1, 'released' => 0, 'deferred' => 1 ), $service->run() );
			$this->assertCount( 3, $inventory->rows, 'Unsettled remote cleanup retains destination plus stale/deleted source inventory rows.' );
			$remote->settled = true;
			$this->assertSame( array( 'scanned' => 1, 'released' => 1, 'deferred' => 0 ), $service->run() );
			$this->assertSame( array(), $inventory->rows, 'Settled replay removes every inventory row for the permanently deleted attachment.' );
			$this->assertSame( array( 176 ), $records->completed );
		} finally { if ( $previous_wpdb === null ) unset( $GLOBALS['wpdb'] ); else $GLOBALS['wpdb'] = $previous_wpdb; }
	}

	public function test_migrated_delivery_replay_cleans_the_current_location_but_releases_only_the_original_owned_pair(): void {
		$binding = array(
			'version' => 1,
			'attachment_id' => 78,
			'blog_id' => 1,
			'owner_uuid' => 'session-13',
			'path_reservation_id' => 111,
			'key_reservation_id' => 112,
			'relative_path' => '2026/10/archive.zip',
			'profile_id' => 9,
			'object_key' => 'private/direct-upload/session-13/archive.zip',
			'cleanup_profile_id' => 12,
			'cleanup_object_key' => 'migrated/direct-upload/session-13/archive.zip',
			'cleanup_profile_fingerprint' => 'destination-fingerprint-13',
		);
		$records = new class( $binding ) {
			public string $state = 'intent';
			public array $completed = array();
			public function __construct( private array $binding ) {}
			public function pending( int $limit ): array { return array( array( 'id' => 13, 'state' => $this->state, 'binding_json' => wp_json_encode( $this->binding ), 'settlement_proof' => 'attachment_deleted:78' ) ); }
			public function remote_deleted( int $id ): bool { $this->state = 'remote_deleted'; return true; }
			public function ready( int $id ): bool { $this->state = 'ready'; return true; }
			public function complete( int $id ): bool { $this->completed[] = $id; return true; }
		};
		$remote = new class {
			public array $calls = array();
			public function delete_exact_profile_key( int $profile_id, string $object_key, string $fingerprint ): bool { $this->calls[] = array( $profile_id, $object_key, $fingerprint ); return true; }
		};
		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )->method( 'delete_by_attachment' )->with( 78 );
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->expects( $this->once() )->method( 'release_owned_attachment_pair' )
			->with( 1, 'session-13', 111, 112, '2026/10/archive.zip', 9, 'private/direct-upload/session-13/archive.zip', 'attachment_deleted:78' )
			->willReturn( array( 'path_reservation_id' => 111 ) );

		$result = ( new ReservationCleanupService( $records, $reservations, $objects, $remote ) )->run();

		$this->assertSame( array( 'scanned' => 1, 'released' => 1, 'deferred' => 0 ), $result );
		$this->assertSame( array( array( 12, 'migrated/direct-upload/session-13/archive.zip', 'destination-fingerprint-13' ) ), $remote->calls );
		$this->assertSame( array( 13 ), $records->completed );
	}

	public function test_migration_destination_cleanup_replay_retains_the_record_until_the_matching_fence_is_removed_after_settlement(): void {
		$previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new MigrationCleanupFenceWpdbFake();
		$binding = array( 'version' => 1, 'attachment_id' => 91, 'blog_id' => 1, 'owner_uuid' => 'session-91', 'path_reservation_id' => 191, 'key_reservation_id' => 192, 'relative_path' => '2026/10/archive.zip', 'profile_id' => 9, 'object_key' => 'source/archive.zip', 'profile_fingerprint' => str_repeat( 'a', 64 ), 'migration_cleanup_location' => array( 'profile_id' => 12, 'object_key' => 'destination/archive.zip', 'profile_fingerprint' => str_repeat( 'b', 64 ) ) );
		$records = new class( $binding ) {
			public string $state = 'intent'; public array $completed = array();
			public function __construct( private array $binding ) {}
			public function pending( int $limit ): array { return $this->state === 'complete' ? array() : array( array( 'id' => 91, 'state' => $this->state, 'binding_json' => wp_json_encode( $this->binding ), 'settlement_proof' => 'attachment_deleted:91' ) ); }
			public function remote_deleted( int $id ): bool { $this->state = 'remote_deleted'; return true; }
			public function ready( int $id ): bool { $this->state = 'ready'; return true; }
			public function complete( int $id ): bool { $this->completed[] = $id; $this->state = 'complete'; return true; }
		};
		$remote = new class { public array $calls = array(); public function delete_exact_profile_key( int $profile_id, string $object_key, string $fingerprint ): bool { $this->calls[] = array( $profile_id, $object_key, $fingerprint ); return true; } };
		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )->method( 'delete_by_attachment' )->with( 91 );
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->expects( $this->once() )->method( 'release_owned_attachment_pair' )->willReturn( array( 'path_reservation_id' => 191 ) );
		try {
			$result = ( new ReservationCleanupService( $records, $reservations, $objects, $remote ) )->run();
			$this->assertSame( array( 'scanned' => 1, 'released' => 1, 'deferred' => 0 ), $result );
			$this->assertSame( array( array( 9, 'source/archive.zip', str_repeat( 'a', 64 ) ), array( 12, 'destination/archive.zip', str_repeat( 'b', 64 ) ) ), $remote->calls );
			$this->assertSame( array( 91 ), $GLOBALS['wpdb']->fence_deletes, 'The exact matching fence must be removed before the durable record is completed.' );
			$this->assertSame( array( 91 ), $records->completed );
		} finally { if ( $previous_wpdb === null ) unset( $GLOBALS['wpdb'] ); else $GLOBALS['wpdb'] = $previous_wpdb; }
	}

	public function test_migration_fence_delete_failure_keeps_settled_cleanup_retryable_until_a_later_replay_removes_it(): void {
		$previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new MigrationCleanupFenceWpdbFake();
		$GLOBALS['wpdb']->fail_next_fence_delete = true;
		$binding = array( 'version' => 1, 'attachment_id' => 92, 'blog_id' => 1, 'owner_uuid' => 'session-92', 'path_reservation_id' => 193, 'key_reservation_id' => 194, 'relative_path' => '2026/10/archive.zip', 'profile_id' => 9, 'object_key' => 'source/archive.zip', 'profile_fingerprint' => str_repeat( 'a', 64 ), 'migration_cleanup_location' => array( 'profile_id' => 12, 'object_key' => 'destination/archive.zip', 'profile_fingerprint' => str_repeat( 'b', 64 ) ) );
		$records = new class( $binding ) {
			public string $state = 'ready'; public array $completed = array();
			public function __construct( private array $binding ) {}
			public function pending( int $limit ): array { return $this->state === 'complete' ? array() : array( array( 'id' => 92, 'state' => $this->state, 'binding_json' => wp_json_encode( $this->binding ), 'settlement_proof' => 'attachment_deleted:92' ) ); }
			public function complete( int $id ): bool { $this->completed[] = $id; $this->state = 'complete'; return true; }
		};
		$reservations = $this->createMock( ReservationRepository::class );
		$reservations->expects( $this->exactly( 2 ) )->method( 'release_owned_attachment_pair' )->willReturn( array( 'path_reservation_id' => 193 ) );
		try {
			$service = new ReservationCleanupService( $records, $reservations );
			$this->assertSame( array( 'scanned' => 1, 'released' => 0, 'deferred' => 1 ), $service->run() );
			$this->assertSame( array(), $records->completed, 'Fence deletion failure must retain the durable cleanup row.' );
			$this->assertSame( array( 'scanned' => 1, 'released' => 1, 'deferred' => 0 ), $service->run() );
			$this->assertSame( array( 92 ), $records->completed );
		} finally { if ( $previous_wpdb === null ) unset( $GLOBALS['wpdb'] ); else $GLOBALS['wpdb'] = $previous_wpdb; }
	}
}

final class MigrationCleanupFenceWpdbFake {
	public string $prefix = 'wp_';
	public array $fence_deletes = array();
	public bool $fail_next_fence_delete = false;
	public function prepare( string $query, mixed ...$args ): string { foreach ( $args as $arg ) $query = preg_replace( '/%[ds]/', is_int( $arg ) ? (string) $arg : "'" . (string) $arg . "'", $query, 1 ) ?? $query; return $query; }
	public function query( string $query ): int|false { if ( str_contains( $query, 's3ms_direct_upload_migration_fences' ) ) { if ( $this->fail_next_fence_delete ) { $this->fail_next_fence_delete = false; return false; } $this->fence_deletes[] = str_contains( $query, '92' ) ? 92 : 91; } return 1; }
}

final class ReplayInventoryWpdbFake {
	public string $prefix = 'wp_';
	public string $last_error = '';
	/** @var list<array{attachment_id:int,storage_profile_id:int,object_key:string,remote_status:string}> */
	public array $rows;
	/** @param list<array{attachment_id:int,storage_profile_id:int,object_key:string,remote_status:string}> $rows */
	public function __construct( array $rows ) { $this->rows = $rows; }
	public function prepare( string $query, mixed ...$args ): string { foreach ( $args as $arg ) $query = preg_replace( '/%[ds]/', is_int( $arg ) ? (string) $arg : "'" . (string) $arg . "'", $query, 1 ) ?? $query; return $query; }
	public function get_row( string $query, mixed $output = null ): ?array { unset( $query, $output ); return null; }
	public function query( string $query ): int|false { unset( $query ); return 1; }
	public function delete( string $table, array $where, array $format = array() ): int|false { unset( $format ); if ( str_contains( $table, 's3ms_objects' ) ) { $before = count( $this->rows ); $this->rows = array_values( array_filter( $this->rows, static fn( array $row ): bool => $row['attachment_id'] !== (int) $where['attachment_id'] ) ); return $before - count( $this->rows ); } return 1; }
}
