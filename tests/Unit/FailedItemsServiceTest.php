<?php
/**
 * FailedItemsService::clear() — this plugin's own bookkeeping only.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Services\FailedItemsService;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class FailedItemsServiceTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_clear_removes_bookkeeping_meta_but_nothing_else(): void {
		$id = 42;
		WpStubs::set_meta( $id, '_s3ms_status', 'failed' );
		WpStubs::set_meta( $id, '_s3ms_last_error', 'Some error' );
		WpStubs::set_meta( $id, '_s3ms_offloaded_at', '2026-01-01T00:00:00Z' );
		WpStubs::set_meta( $id, '_s3ms_verified_at', '2026-01-01T00:00:00Z' );
		WpStubs::set_meta( $id, '_s3ms_original_key', 'uploads/2026/01/file.jpg' );
		WpStubs::set_meta( $id, FailedItemsService::META_IGNORED, '1' );
		// Never touched by clear() — a different plugin/core meta key.
		WpStubs::set_meta( $id, '_wp_attached_file', '2026/01/file.jpg' );

		$cleared = ( new FailedItemsService() )->clear( array( $id ) );

		$this->assertSame( 1, $cleared );
		$this->assertSame( '', get_post_meta( $id, '_s3ms_status', true ) );
		$this->assertSame( '', get_post_meta( $id, '_s3ms_last_error', true ) );
		$this->assertSame( '', get_post_meta( $id, '_s3ms_offloaded_at', true ) );
		$this->assertSame( '', get_post_meta( $id, '_s3ms_verified_at', true ) );
		$this->assertSame( '', get_post_meta( $id, '_s3ms_original_key', true ) );
		$this->assertSame( '', get_post_meta( $id, FailedItemsService::META_IGNORED, true ) );
		$this->assertSame( '2026/01/file.jpg', get_post_meta( $id, '_wp_attached_file', true ) );
	}

	public function test_clear_ignores_non_positive_ids(): void {
		$cleared = ( new FailedItemsService() )->clear( array( 0, -1 ) );

		$this->assertSame( 0, $cleared );
	}

	public function test_clear_counts_each_valid_id_once(): void {
		WpStubs::set_meta( 1, '_s3ms_status', 'failed' );
		WpStubs::set_meta( 2, '_s3ms_status', 'failed' );

		$cleared = ( new FailedItemsService() )->clear( array( 1, 2, 0 ) );

		$this->assertSame( 2, $cleared );
	}
}
