<?php
/**
 * ObjectHealthState classification tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Domain\ObjectHealthState;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;

final class ObjectHealthStateTest extends TestCase {

	public function test_classify_present_verified_with_local(): void {
		$health = ObjectHealthState::classify_row(
			array(
				'remote_status' => ObjectRemoteStatus::PRESENT,
				'verified_at'   => '2026-08-25 12:00:00',
			),
			true
		);
		$this->assertSame( ObjectHealthState::HEALTHY, $health );
	}

	public function test_classify_missing_is_remote_missing(): void {
		$health = ObjectHealthState::classify_row(
			array(
				'remote_status' => ObjectRemoteStatus::MISSING,
			),
			true
		);
		$this->assertSame( ObjectHealthState::REMOTE_MISSING, $health );
		$this->assertTrue( ObjectHealthState::is_repairable( $health, true ) );
	}

	public function test_remote_missing_not_repairable_without_local(): void {
		$health = ObjectHealthState::REMOTE_MISSING;
		$this->assertFalse( ObjectHealthState::is_repairable( $health, false ) );
	}
}
