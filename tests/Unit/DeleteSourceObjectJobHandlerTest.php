<?php
/**
 * DeleteSourceObjectJobHandler shared-key safety tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Infrastructure\Queue\Jobs\DeleteSourceObjectJobHandler;
use PHPUnit\Framework\TestCase;

final class DeleteSourceObjectJobHandlerTest extends TestCase {

	public function test_source_delete_fails_closed_when_same_profile_key_still_has_active_reference(): void {
		$objects = new FakeDeleteSourceObjectRepository( true );
		$gateway = new FakeDeleteSourceGateway();

		$result = ( new DeleteSourceObjectJobHandler(
			$objects,
			new FakeDeleteSourceProfileRepository(),
			static fn(): FakeDeleteSourceGateway => $gateway
		) )->handle(
			array(
				'source_profile_id' => 7,
				'object_key'        => 'shared/path.jpg',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'Source object still has an active inventory reference.', $result['message'] );
		$this->assertSame( array(), $gateway->deleted_keys );
		$this->assertSame( 0, $objects->mark_deleted_calls );
	}

	public function test_source_delete_marks_stale_rows_deleted_after_remote_delete_when_unshared(): void {
		$objects = new FakeDeleteSourceObjectRepository( false );
		$gateway = new FakeDeleteSourceGateway();

		$result = ( new DeleteSourceObjectJobHandler(
			$objects,
			new FakeDeleteSourceProfileRepository(),
			static fn(): FakeDeleteSourceGateway => $gateway
		) )->handle(
			array(
				'source_profile_id' => 7,
				'object_key'        => 'unshared/path.jpg',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( array( 'unshared/path.jpg' ), $gateway->deleted_keys );
		$this->assertSame( 1, $objects->mark_deleted_calls );
	}
}

final class FakeDeleteSourceObjectRepository {
	public int $mark_deleted_calls = 0;

	public function __construct( private bool $has_active_reference ) {
	}

	public function has_active_reference_for_profile_key( int $profile_id, string $object_key ): bool {
		return $this->has_active_reference;
	}

	public function mark_deleted_by_profile_key( int $profile_id, string $object_key ): int {
		$this->mark_deleted_calls++;
		return 1;
	}
}

final class FakeDeleteSourceProfileRepository {
	public function find( int $profile_id ): ?StorageProfile {
		return new StorageProfile(
			$profile_id,
			'uuid-' . $profile_id,
			'Profile ' . $profile_id,
			'aws',
			'bucket',
			'us-east-1',
			'',
			false,
			'uploads/',
			'storage',
			'',
			false,
			'keys',
			'',
			false,
			false,
			false,
			'2026-09-01 00:00:00',
			'2026-09-01 00:00:00'
		);
	}
}

final class FakeDeleteSourceGateway {
	/** @var list<string> */
	public array $deleted_keys = array();

	public function delete_key( string $key ): void {
		$this->deleted_keys[] = $key;
	}
}
