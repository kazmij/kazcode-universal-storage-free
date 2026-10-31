<?php
/**
 * Explicit import-remediation safety tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Attachment\AttachmentFileResolver;
use Kazcode\WpStorage\Domain\ManifestBuilder;
use Kazcode\WpStorage\Domain\MediaManifest;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Infrastructure\AttachmentLeaseHandle;
use Kazcode\WpStorage\Infrastructure\AttachmentLock;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Services\ImportRemediationService;
use Kazcode\WpStorage\Storage\ProfileStorageGateway;
use Kazcode\WpStorage\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class ImportRemediationServiceTest extends TestCase {

	public function test_rejects_a_non_eligible_or_non_canonical_ledger_item_before_any_write(): void {
		$service = new ImportRemediationService( new Settings() );
		$profile = new StorageProfile( 12, 'profile-12', 'Profile', 'aws', 'bucket', 'eu-central-1', '', false, 'uploads/', 'storage', '', false, 'keys', 'secret', true, false, false, '2026-01-01 00:00:00', '2026-01-01 00:00:00' );

		$result = $service->remediate_item(
			array( 'attachment_id' => 101, 'classification' => 'verified_present', 'local_relative_path' => '2026/10/photo.jpg', 'canonical_object_key' => 'foreign/photo.jpg' ),
			$profile
		);

		$this->assertSame( 'conflict', $result['classification'] );
	}

	public function test_uploads_current_local_manifest_bytes_then_heads_before_present_inventory_and_meta_sync(): void {
		$path = tempnam( sys_get_temp_dir(), 'kazus-remediate-' );
		file_put_contents( $path, 'bytes-to-remediate' );
		$profile = $this->profile();
		$files = $this->createMock( AttachmentFileResolver::class );
		$files->method( 'existing_local_files_for_manifest_paths' )->willReturn( array( '2026/10/photo.jpg' => $path ) );
		$manifest = $this->createMock( ManifestBuilder::class );
		$manifest->method( 'build' )->willReturn( new MediaManifest( 101, array( array( 'relative' => '2026/10/photo.jpg', 'variant_type' => 'original' ) ) ) );
		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->exactly( 2 ) )->method( 'insert_owned_or_conflict' )->willReturn( array( 'ok' => true ) );
		$objects->expects( $this->once() )->method( 'find_by_attachment' )->willReturn( array( array( 'variant_type' => 'original', 'object_key' => 'uploads/2026/10/photo.jpg', 'remote_status' => 'present', 'verified_at' => '2026-10-30 00:00:00' ) ) );
		$locks = $this->createMock( AttachmentLock::class );
		$lease = new AttachmentLeaseHandle( 101, 'test-token', 1, 'import_remediation', time() + 60 );
		$locks->method( 'acquire_lease' )->willReturn( $lease );
		$locks->expects( $this->once() )->method( 'release_lease' );
		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->once() )->method( 'put_object_from_file' )->with( 'uploads/2026/10/photo.jpg', $path, 'application/octet-stream', $this->anything() );
		$gateway->expects( $this->once() )->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => filesize( $path ) ) );
		$service = new ImportRemediationService( new Settings(), $files, $manifest, $objects, $locks, static fn() => $gateway );

		$result = $service->remediate_item( array( 'attachment_id' => 101, 'classification' => 'confirmed_missing', 'local_relative_path' => '2026/10/photo.jpg', 'canonical_object_key' => 'uploads/2026/10/photo.jpg' ), $profile );

		$this->assertSame( 'uploaded', $result['classification'] );
		$this->assertSame( 'offloaded', WpStubs::$post_meta[101]['_s3ms_status'] );
		$this->assertSame( 'uploads/2026/10/photo.jpg', WpStubs::$post_meta[101]['_s3ms_original_key'] );
		unlink( $path );
	}

	public function test_changed_manifest_path_makes_no_inventory_or_provider_write(): void {
		$files = $this->createMock( AttachmentFileResolver::class );
		$files->expects( $this->never() )->method( 'existing_local_files_for_manifest_paths' );
		$manifest = $this->createMock( ManifestBuilder::class );
		$manifest->method( 'build' )->willReturn( new MediaManifest( 101, array( array( 'relative' => '2026/10/replaced.jpg', 'variant_type' => 'original' ) ) ) );
		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->never() )->method( 'insert_owned_or_conflict' );
		$locks = $this->createMock( AttachmentLock::class );
		$lease = new AttachmentLeaseHandle( 101, 'test-token', 1, 'import_remediation', time() + 60 );
		$locks->method( 'acquire_lease' )->willReturn( $lease );
		$locks->expects( $this->once() )->method( 'release_lease' );
		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'put_object_from_file' );
		$service = new ImportRemediationService( new Settings(), $files, $manifest, $objects, $locks, static fn() => $gateway );

		$result = $service->remediate_item( array( 'attachment_id' => 101, 'classification' => 'confirmed_missing', 'local_relative_path' => '2026/10/photo.jpg', 'canonical_object_key' => 'uploads/2026/10/photo.jpg' ), $this->profile() );

		$this->assertSame( 'conflict', $result['classification'] );
	}

	public function test_foreign_canonical_owner_makes_no_provider_write(): void {
		$path = tempnam( sys_get_temp_dir(), 'kazus-remediate-' );
		file_put_contents( $path, 'bytes' );
		$files = $this->createMock( AttachmentFileResolver::class );
		$files->method( 'existing_local_files_for_manifest_paths' )->willReturn( array( '2026/10/photo.jpg' => $path ) );
		$manifest = $this->createMock( ManifestBuilder::class );
		$manifest->method( 'build' )->willReturn( new MediaManifest( 101, array( array( 'relative' => '2026/10/photo.jpg', 'variant_type' => 'original' ) ) ) );
		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )->method( 'insert_owned_or_conflict' )->willReturn( array( 'ok' => false ) );
		$locks = $this->createMock( AttachmentLock::class );
		$lease = new AttachmentLeaseHandle( 101, 'test-token', 1, 'import_remediation', time() + 60 );
		$locks->method( 'acquire_lease' )->willReturn( $lease );
		$locks->expects( $this->once() )->method( 'release_lease' );
		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'put_object_from_file' );
		$service = new ImportRemediationService( new Settings(), $files, $manifest, $objects, $locks, static fn() => $gateway );

		$result = $service->remediate_item( array( 'attachment_id' => 101, 'classification' => 'confirmed_missing', 'local_relative_path' => '2026/10/photo.jpg', 'canonical_object_key' => 'uploads/2026/10/photo.jpg' ), $this->profile() );

		$this->assertSame( 'conflict', $result['classification'] );
		unlink( $path );
	}

	public function test_absent_local_file_makes_no_inventory_or_provider_write(): void {
		$files = $this->createMock( AttachmentFileResolver::class );
		$files->method( 'existing_local_files_for_manifest_paths' )->willReturn( array() );
		$manifest = $this->createMock( ManifestBuilder::class );
		$manifest->method( 'build' )->willReturn( new MediaManifest( 101, array( array( 'relative' => '2026/10/photo.jpg', 'variant_type' => 'original' ) ) ) );
		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->never() )->method( 'insert_owned_or_conflict' );
		$locks = $this->createMock( AttachmentLock::class );
		$lease = new AttachmentLeaseHandle( 101, 'test-token', 1, 'import_remediation', time() + 60 );
		$locks->method( 'acquire_lease' )->willReturn( $lease );
		$locks->expects( $this->once() )->method( 'release_lease' );
		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->never() )->method( 'put_object_from_file' );
		$service = new ImportRemediationService( new Settings(), $files, $manifest, $objects, $locks, static fn() => $gateway );

		$result = $service->remediate_item( array( 'attachment_id' => 101, 'classification' => 'size_conflict', 'local_relative_path' => '2026/10/photo.jpg', 'canonical_object_key' => 'uploads/2026/10/photo.jpg' ), $this->profile() );

		$this->assertSame( 'conflict', $result['classification'] );
	}

	public function test_provider_write_failure_never_promotes_inventory_to_present(): void {
		$path = tempnam( sys_get_temp_dir(), 'kazus-remediate-' );
		file_put_contents( $path, 'bytes' );
		$files = $this->createMock( AttachmentFileResolver::class );
		$files->method( 'existing_local_files_for_manifest_paths' )->willReturn( array( '2026/10/photo.jpg' => $path ) );
		$manifest = $this->createMock( ManifestBuilder::class );
		$manifest->method( 'build' )->willReturn( new MediaManifest( 101, array( array( 'relative' => '2026/10/photo.jpg', 'variant_type' => 'original' ) ) ) );
		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )->method( 'insert_owned_or_conflict' )->willReturn( array( 'ok' => true ) );
		$locks = $this->createMock( AttachmentLock::class );
		$lease = new AttachmentLeaseHandle( 101, 'test-token', 1, 'import_remediation', time() + 60 );
		$locks->method( 'acquire_lease' )->willReturn( $lease );
		$locks->expects( $this->once() )->method( 'release_lease' );
		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->once() )->method( 'put_object_from_file' )->willThrowException( new \RuntimeException( 'Safe Mode blocked write.' ) );
		$gateway->expects( $this->never() )->method( 'head_key' );
		$service = new ImportRemediationService( new Settings(), $files, $manifest, $objects, $locks, static fn() => $gateway );

		$result = $service->remediate_item( array( 'attachment_id' => 101, 'classification' => 'confirmed_missing', 'local_relative_path' => '2026/10/photo.jpg', 'canonical_object_key' => 'uploads/2026/10/photo.jpg' ), $this->profile() );

		$this->assertSame( 'transient_error', $result['classification'] );
		unlink( $path );
	}

	private function profile(): StorageProfile {
		return new StorageProfile( 12, 'profile-12', 'Profile', 'aws', 'bucket', 'eu-central-1', '', false, 'uploads/', 'storage', '', false, 'keys', 'secret', true, false, false, '2026-01-01 00:00:00', '2026-01-01 00:00:00' );
	}
}
