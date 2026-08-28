<?php
/**
 * SameProviderCopyStrategy unit tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Domain\SameProviderCopyStrategy;
use Kazcode\WpStorage\Domain\StorageProfile;

final class SameProviderCopyStrategyTest extends TestCase {

	public function test_allows_copy_within_same_bucket_and_identity(): void {
		$a = $this->profile( 1, 'shared-bucket', '', 'uploads/' );
		$b = $this->profile( 2, 'shared-bucket', '', 'archive/' );
		$this->assertTrue( SameProviderCopyStrategy::can_copy_within_bucket( $a, $b ) );
	}

	public function test_requires_stream_for_different_buckets(): void {
		$aws = $this->profile( 1, 'aws-bucket', '', 'uploads/' );
		$minio = $this->profile( 2, 'minio-bucket', 'http://127.0.0.1:9000', 'uploads/', 'minio' );
		$this->assertFalse( SameProviderCopyStrategy::can_copy_within_bucket( $aws, $minio ) );
	}

	private function profile(
		int $id,
		string $bucket,
		string $endpoint = '',
		string $prefix = 'uploads/',
		string $provider = 'aws',
	): StorageProfile {
		$now = gmdate( 'Y-m-d H:i:s' );
		return new StorageProfile(
			$id,
			'uuid-' . $id,
			'Profile ' . $id,
			$provider,
			$bucket,
			'us-east-1',
			$endpoint,
			$endpoint !== '',
			$prefix,
			'storage',
			'',
			false,
			'keys',
			'legacy_default',
			false,
			false,
			false,
			$now,
			$now
		);
	}
}
