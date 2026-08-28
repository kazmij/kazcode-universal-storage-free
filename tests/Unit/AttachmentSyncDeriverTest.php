<?php
/**
 * AttachmentSyncDeriver unit tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Attachment\AttachmentOffloader;
use Kazcode\WpStorage\Domain\AttachmentSyncDeriver;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;

final class AttachmentSyncDeriverTest extends TestCase {

	public function test_empty_rows_is_failed(): void {
		$this->assertSame( AttachmentOffloader::STATUS_FAILED, AttachmentSyncDeriver::derive_status( array() ) );
	}

	public function test_all_present_is_offloaded(): void {
		$rows = array(
			array( 'remote_status' => ObjectRemoteStatus::PRESENT, 'object_key' => 'uploads/a.jpg', 'variant_type' => 'original' ),
			array( 'remote_status' => ObjectRemoteStatus::PRESENT, 'object_key' => 'uploads/a-150.jpg', 'variant_type' => 'size' ),
		);
		$this->assertSame( AttachmentOffloader::STATUS_OFFLOADED, AttachmentSyncDeriver::derive_status( $rows ) );
	}

	public function test_mixed_present_and_failed_is_partial(): void {
		$rows = array(
			array( 'remote_status' => ObjectRemoteStatus::PRESENT, 'object_key' => 'uploads/a.jpg', 'variant_type' => 'original' ),
			array( 'remote_status' => ObjectRemoteStatus::FAILED, 'last_error_message' => 'boom', 'variant_type' => 'size' ),
		);
		$this->assertSame( AttachmentOffloader::STATUS_PARTIAL, AttachmentSyncDeriver::derive_status( $rows ) );
	}

	public function test_mixed_present_and_pending_is_partial(): void {
		$rows = array(
			array( 'remote_status' => ObjectRemoteStatus::PRESENT, 'object_key' => 'uploads/a.jpg' ),
			array( 'remote_status' => ObjectRemoteStatus::UPLOADING ),
		);
		$this->assertSame( AttachmentOffloader::STATUS_PARTIAL, AttachmentSyncDeriver::derive_status( $rows ) );
	}

	public function test_stale_variant_is_excluded_from_roll_up(): void {
		// A crop/regenerate/migration superseded one variant (now `stale`); every
		// currently-active row is still `present`, so the attachment must still
		// derive as fully offloaded — not `failed` (regression for the bug where
		// stale/deleted rows silently counted against the present/total ratio).
		$rows = array(
			array( 'remote_status' => ObjectRemoteStatus::PRESENT, 'object_key' => 'uploads/a.jpg', 'variant_type' => 'original' ),
			array( 'remote_status' => ObjectRemoteStatus::PRESENT, 'object_key' => 'uploads/a-150x150.jpg', 'variant_type' => 'size' ),
			array( 'remote_status' => ObjectRemoteStatus::STALE, 'object_key' => 'uploads/a-old-150x150.jpg', 'variant_type' => 'size' ),
		);
		$this->assertSame( AttachmentOffloader::STATUS_OFFLOADED, AttachmentSyncDeriver::derive_status( $rows ) );
	}

	public function test_deleted_variant_is_excluded_from_roll_up(): void {
		$rows = array(
			array( 'remote_status' => ObjectRemoteStatus::PRESENT, 'object_key' => 'uploads/a.jpg', 'variant_type' => 'original' ),
			array( 'remote_status' => ObjectRemoteStatus::DELETED, 'object_key' => 'uploads/a-150x150.jpg', 'variant_type' => 'size' ),
		);
		$this->assertSame( AttachmentOffloader::STATUS_OFFLOADED, AttachmentSyncDeriver::derive_status( $rows ) );
	}

	public function test_all_rows_stale_is_failed(): void {
		$rows = array(
			array( 'remote_status' => ObjectRemoteStatus::STALE, 'object_key' => 'uploads/a.jpg' ),
		);
		$this->assertSame( AttachmentOffloader::STATUS_FAILED, AttachmentSyncDeriver::derive_status( $rows ) );
	}

	public function test_stale_variant_alongside_real_failure_is_still_partial(): void {
		$rows = array(
			array( 'remote_status' => ObjectRemoteStatus::PRESENT, 'object_key' => 'uploads/a.jpg', 'variant_type' => 'original' ),
			array( 'remote_status' => ObjectRemoteStatus::FAILED, 'last_error_message' => 'boom', 'variant_type' => 'size' ),
			array( 'remote_status' => ObjectRemoteStatus::STALE, 'object_key' => 'uploads/a-old-150x150.jpg', 'variant_type' => 'size' ),
		);
		$this->assertSame( AttachmentOffloader::STATUS_PARTIAL, AttachmentSyncDeriver::derive_status( $rows ) );
	}

	public function test_all_failed_is_failed(): void {
		$rows = array(
			array( 'remote_status' => ObjectRemoteStatus::FAILED ),
			array( 'remote_status' => ObjectRemoteStatus::FAILED ),
		);
		$this->assertSame( AttachmentOffloader::STATUS_FAILED, AttachmentSyncDeriver::derive_status( $rows ) );
	}

	public function test_original_key_prefers_original_variant(): void {
		$rows = array(
			array( 'remote_status' => ObjectRemoteStatus::PRESENT, 'object_key' => 'uploads/size.jpg', 'variant_type' => 'size' ),
			array( 'remote_status' => ObjectRemoteStatus::PRESENT, 'object_key' => 'uploads/original.jpg', 'variant_type' => 'original' ),
		);
		$this->assertSame( 'uploads/original.jpg', AttachmentSyncDeriver::original_key( $rows ) );
	}

	public function test_last_error_returns_first_message(): void {
		$rows = array(
			array( 'last_error_message' => 'first' ),
			array( 'last_error_message' => 'second' ),
		);
		$this->assertSame( 'first', AttachmentSyncDeriver::last_error( $rows ) );
	}
}
