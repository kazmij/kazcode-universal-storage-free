<?php
/**
 * Provider preset coverage.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\ProviderPresets;

final class ProviderPresetsTest extends TestCase {

	public function test_includes_backblaze_b2(): void {
		$all = ProviderPresets::all();
		$this->assertArrayHasKey( 'b2', $all );
		$this->assertStringContainsString( 'backblaze', strtolower( $all['b2']['endpoint'] ) );
	}

	public function test_known_slugs(): void {
		foreach ( array( 'aws', 'r2', 'spaces', 'minio', 'wasabi', 'b2', 'custom' ) as $slug ) {
			$this->assertNotNull( ProviderPresets::get( $slug ), $slug );
		}
	}
}
