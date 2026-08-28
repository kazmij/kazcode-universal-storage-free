<?php
/**
 * ManifestDiff unit tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Domain\ManifestDiff;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;

final class ManifestDiffTest extends TestCase {

	public function test_detects_added_removed_and_unchanged(): void {
		$diff = ManifestDiff::compare(
			array(
				'2026/08/photo.jpg',
				'2026/08/photo-300.jpg',
			),
			array(
				array(
					'local_relative_path' => '2026/08/photo.jpg',
					'remote_status'       => ObjectRemoteStatus::PRESENT,
				),
				array(
					'local_relative_path' => '2026/08/photo-150.jpg',
					'remote_status'       => ObjectRemoteStatus::PRESENT,
				),
			)
		);

		$this->assertSame( array( '2026/08/photo-300.jpg' ), $diff->added );
		$this->assertSame( array( '2026/08/photo-150.jpg' ), $diff->removed );
		$this->assertSame( array( '2026/08/photo.jpg' ), $diff->unchanged );
	}

	public function test_failed_remote_counts_as_added(): void {
		$diff = ManifestDiff::compare(
			array( '2026/08/photo.jpg' ),
			array(
				array(
					'local_relative_path' => '2026/08/photo.jpg',
					'remote_status'       => ObjectRemoteStatus::FAILED,
				),
			)
		);

		$this->assertSame( array( '2026/08/photo.jpg' ), $diff->added );
		$this->assertSame( array(), $diff->removed );
	}

	public function test_already_stale_rows_are_not_re_removed(): void {
		$diff = ManifestDiff::compare(
			array( '2026/08/photo.jpg' ),
			array(
				array(
					'local_relative_path' => '2026/08/photo-old.jpg',
					'remote_status'       => ObjectRemoteStatus::STALE,
				),
			)
		);

		$this->assertSame( array( '2026/08/photo.jpg' ), $diff->added );
		$this->assertSame( array(), $diff->removed );
	}
}
