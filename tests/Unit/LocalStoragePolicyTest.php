<?php
/**
 * LocalStoragePolicy unit tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Domain\LocalStoragePolicy;

final class LocalStoragePolicyTest extends TestCase {

	public function test_legacy_delete_local_maps_to_remote_only(): void {
		$this->assertSame(
			LocalStoragePolicy::REMOTE_ONLY,
			LocalStoragePolicy::from_legacy_settings(
				array(
					'delete_local_after_upload' => true,
					'keep_local_files'          => false,
				)
			)
		);
	}

	public function test_legacy_keep_maps_to_keep_all(): void {
		$this->assertSame(
			LocalStoragePolicy::KEEP_ALL,
			LocalStoragePolicy::from_legacy_settings(
				array(
					'delete_local_after_upload' => false,
					'keep_local_files'          => true,
				)
			)
		);
	}

	public function test_explicit_policy_wins(): void {
		$this->assertSame(
			LocalStoragePolicy::KEEP_ORIGINALS,
			LocalStoragePolicy::from_legacy_settings(
				array(
					'local_storage_policy'      => LocalStoragePolicy::KEEP_ORIGINALS,
					'delete_local_after_upload' => true,
				)
			)
		);
	}

	public function test_legacy_flags_for_remote_only(): void {
		$flags = LocalStoragePolicy::legacy_flags_for( LocalStoragePolicy::REMOTE_ONLY );
		$this->assertTrue( $flags['delete_local_after_upload'] );
		$this->assertFalse( $flags['keep_local_files'] );
		$this->assertTrue( $flags['verify_before_delete'] );
	}
}
