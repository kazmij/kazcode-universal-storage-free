<?php
/**
 * Delivery-drift diagnostics (#55): taxonomy, report-only.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Domain\ManifestBuilder;
use Kazcode\WpStorage\Domain\MediaManifest;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Services\CleanupDeliveryProbe;
use Kazcode\WpStorage\Services\DeliveryDriftService;
use Kazcode\WpStorage\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class DeliveryDriftServiceTest extends TestCase {

	private const ORIGINAL = '2026/08/photo.jpg';
	private const THUMB    = '2026/08/photo-150x150.jpg';

	protected function setUp(): void {
		WpStubs::reset();
	}

	public function test_healthy_cloud_only_attachment_has_no_findings(): void {
		$service = $this->service(
			array( $this->row( 1, self::ORIGINAL, ObjectRemoteStatus::PRESENT ), $this->row( 1, self::THUMB, ObjectRemoteStatus::PRESENT ) ),
			local: array(),
			remote: true
		);

		$this->assertSame( array(), $service->analyze( 42 ) );
	}

	public function test_unverified_remote_without_local_is_unavailable_but_not_called_missing(): void {
		$service = $this->service(
			array( $this->row( 1, self::ORIGINAL, ObjectRemoteStatus::PRESENT ), $this->row( 1, self::THUMB, ObjectRemoteStatus::FAILED ) ),
			local: array(),
			remote: true
		);

		$finding = $this->only( $service->analyze( 42 ) );
		$this->assertSame( DeliveryDriftService::ARTIFACT_UNAVAILABLE, $finding['finding'] );
		$this->assertSame( DeliveryDriftService::CATEGORY_STORAGE, $finding['category'] );
		$this->assertSame( self::THUMB, $finding['relative'] );
		$this->assertStringContainsString( 'not confirmed (failed)', $finding['detail'] );
		$this->assertStringNotContainsString( 'confirmed missing', $finding['detail'] );
	}

	public function test_confirmed_missing_remote_without_local_says_restore_from_backup(): void {
		$service = $this->service(
			array( $this->row( 1, self::ORIGINAL, ObjectRemoteStatus::PRESENT ), $this->row( 1, self::THUMB, ObjectRemoteStatus::MISSING ) ),
			local: array(),
			remote: true
		);

		$finding = $this->only( $service->analyze( 42 ) );
		$this->assertStringContainsString( 'confirmed missing (404)', $finding['detail'] );
		$this->assertStringContainsString( 'backup', $finding['next_action'] );
	}

	public function test_verified_remote_delivered_locally_without_local_file_is_a_404_risk(): void {
		$service = $this->service(
			array( $this->row( 1, self::ORIGINAL, ObjectRemoteStatus::PRESENT ), $this->row( 1, self::THUMB, ObjectRemoteStatus::PRESENT ) ),
			local: array( self::ORIGINAL ),
			remote: false
		);

		$finding = $this->only( $service->analyze( 42 ) );
		$this->assertSame( DeliveryDriftService::LOCAL_404_RISK, $finding['finding'] );
		$this->assertSame( DeliveryDriftService::CATEGORY_DELIVERY, $finding['category'] );
		$this->assertSame( self::THUMB, $finding['relative'] );
	}

	public function test_local_delivery_with_local_files_is_not_drift(): void {
		$service = $this->service(
			array( $this->row( 1, self::ORIGINAL, ObjectRemoteStatus::PRESENT ), $this->row( 1, self::THUMB, ObjectRemoteStatus::PRESENT ) ),
			local: array( self::ORIGINAL, self::THUMB ),
			remote: false
		);

		$this->assertSame( array(), $service->analyze( 42 ) );
	}

	public function test_two_verified_profiles_for_one_artifact_are_ambiguous(): void {
		$service = $this->service(
			array(
				$this->row( 1, self::ORIGINAL, ObjectRemoteStatus::PRESENT ),
				$this->row( 2, self::ORIGINAL, ObjectRemoteStatus::PRESENT ),
				$this->row( 2, self::THUMB, ObjectRemoteStatus::PRESENT ),
			),
			local: array(),
			remote: true
		);

		$finding = $this->only( $service->analyze( 42 ) );
		$this->assertSame( DeliveryDriftService::OWNERSHIP_AMBIGUOUS, $finding['finding'] );
		$this->assertStringContainsString( 'profiles 1, 2', $finding['detail'] );
	}

	public function test_migrated_attachment_with_stale_source_rows_is_not_drift(): void {
		$service = $this->service(
			array(
				$this->row( 1, self::ORIGINAL, ObjectRemoteStatus::STALE ),
				$this->row( 1, self::THUMB, ObjectRemoteStatus::STALE ),
				$this->row( 2, self::ORIGINAL, ObjectRemoteStatus::PRESENT ),
				$this->row( 2, self::THUMB, ObjectRemoteStatus::PRESENT ),
			),
			local: array(),
			remote: true
		);

		$this->assertSame( array(), $service->analyze( 42 ) );
	}

	public function test_current_artifact_delivered_from_a_superseded_row(): void {
		$service = $this->service(
			array( $this->row( 1, self::ORIGINAL, ObjectRemoteStatus::PRESENT ), $this->row( 1, self::THUMB, ObjectRemoteStatus::STALE ) ),
			local: array( self::THUMB ),
			remote: true
		);

		$finding = $this->only( $service->analyze( 42 ) );
		$this->assertSame( DeliveryDriftService::DELIVERED_FROM_SUPERSEDED, $finding['finding'] );
		$this->assertSame( ObjectRemoteStatus::STALE, $finding['remote_status'] );
	}

	public function test_image_editor_backup_with_superseded_row_is_not_drift(): void {
		WpStubs::$post_meta[42]['_wp_attached_file']           = '2026/08/photo-e123.jpg';
		WpStubs::$post_meta[42]['_wp_attachment_backup_sizes'] = array( 'thumbnail-orig' => array( 'file' => 'photo-150x150.jpg' ) );
		$service = $this->service(
			array( $this->row( 1, self::ORIGINAL, ObjectRemoteStatus::PRESENT ), $this->row( 1, self::THUMB, ObjectRemoteStatus::STALE ) ),
			local: array( self::THUMB ),
			remote: true
		);

		$this->assertSame( array(), $service->analyze( 42 ) );
	}

	public function test_stale_variant_still_referenced_in_content(): void {
		$old     = '2026/08/photo-e1700000000-150x150.jpg';
		$service = $this->service(
			array(
				$this->row( 1, self::ORIGINAL, ObjectRemoteStatus::PRESENT ),
				$this->row( 1, self::THUMB, ObjectRemoteStatus::PRESENT ),
				$this->row( 1, $old, ObjectRemoteStatus::STALE ),
			),
			local: array(),
			remote: true,
			refs: array( $old => array( 7, 9 ) )
		);

		$finding = $this->only( $service->analyze( 42 ) );
		$this->assertSame( DeliveryDriftService::STALE_VARIANT_REFERENCED, $finding['finding'] );
		$this->assertSame( DeliveryDriftService::CATEGORY_REFERENCE, $finding['category'] );
		$this->assertSame( array( 7, 9 ), $finding['post_ids'] );
		$this->assertStringContainsString( 'never rewritten', $finding['next_action'] );
	}

	public function test_content_reference_to_a_cloud_only_file_is_reported(): void {
		$service = $this->service(
			array( $this->row( 1, self::ORIGINAL, ObjectRemoteStatus::PRESENT ), $this->row( 1, self::THUMB, ObjectRemoteStatus::PRESENT ) ),
			local: array( self::THUMB ),
			remote: true,
			refs: array( self::ORIGINAL => array( 3 ), self::THUMB => array( 3 ) )
		);

		$finding = $this->only( $service->analyze( 42 ) );
		$this->assertSame( DeliveryDriftService::LOCAL_URL_IN_CONTENT, $finding['finding'] );
		$this->assertSame( self::ORIGINAL, $finding['relative'], 'only the cloud-only file is at risk' );
	}

	public function test_editor_image_tag_of_a_current_file_is_not_a_risk_but_a_plain_link_is(): void {
		$content = '<img src="https://site.test/wp-content/uploads/2026/08/photo-150x150.jpg" class="wp-image-42 size-thumbnail" srcset="https://site.test/wp-content/uploads/2026/08/photo.jpg 640w">';
		$this->assertStringNotContainsString( self::THUMB, DeliveryDriftService::without_rewritten_image_tags( $content, 42 ) );
		$this->assertStringContainsString( self::THUMB, DeliveryDriftService::without_rewritten_image_tags( $content, 43 ), 'another attachment\'s tag is not rewritten for this one' );
		$link = '<a href="https://site.test/wp-content/uploads/2026/08/photo.jpg">Download</a>';
		$this->assertStringContainsString( self::ORIGINAL, DeliveryDriftService::without_rewritten_image_tags( $link . $content, 42 ) );
	}

	public function test_content_lookup_receives_the_attachment_and_its_current_paths(): void {
		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->willReturn( array( $this->row( 1, self::ORIGINAL, ObjectRemoteStatus::PRESENT ), $this->row( 1, self::THUMB, ObjectRemoteStatus::PRESENT ) ) );
		$seen = array();
		$refs = static function ( array $paths, int $id, array $current ) use ( &$seen ): array {
			$seen = array( $id, $current );
			return array();
		};

		( new DeliveryDriftService( $objects, $this->manifests(), $this->probe( true ), static fn(): bool => false, $refs ) )->analyze( 42 );

		$this->assertSame( array( 42, array( self::ORIGINAL, self::THUMB ) ), $seen );
	}

	public function test_scan_page_pages_attachments_and_counts_categories_without_writes(): void {
		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )->method( 'attachment_ids_page' )->with( 2, 10 )->willReturn( array( 42, 43 ) );
		$objects->method( 'find_by_attachment' )->willReturn( array( $this->row( 1, self::ORIGINAL, ObjectRemoteStatus::FAILED ), $this->row( 1, self::THUMB, ObjectRemoteStatus::PRESENT ) ) );
		foreach ( array( 'upsert', 'mark_stale_by_relative_paths', 'mark_deleted_by_profile_key', 'delete_by_attachment', 'delete_exact_attachment_profile_key' ) as $write ) {
			$objects->expects( $this->never() )->method( $write );
		}

		$report = ( new DeliveryDriftService( $objects, $this->manifests(), $this->probe( true ), static fn(): bool => false, static fn(): array => array() ) )->scan_page( 2, 10 );

		$this->assertSame( 2, $report['scanned'] );
		$this->assertSame( 43, $report['next_after_id'] );
		$this->assertTrue( $report['has_more'] );
		$this->assertSame( 2, $report['counts'][ DeliveryDriftService::CATEGORY_STORAGE ] );
	}

	public function test_content_lookup_runs_once_per_attachment(): void {
		$calls   = 0;
		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->willReturn( array( $this->row( 1, self::ORIGINAL, ObjectRemoteStatus::PRESENT ), $this->row( 1, self::THUMB, ObjectRemoteStatus::PRESENT ) ) );
		$refs = static function ( array $paths ) use ( &$calls ): array {
			++$calls;
			return array();
		};

		( new DeliveryDriftService( $objects, $this->manifests(), $this->probe( true ), static fn(): bool => false, $refs ) )->analyze( 42 );

		$this->assertSame( 1, $calls );
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @param list<string>              $local
	 * @param array<string,list<int>>   $refs
	 */
	private function service( array $rows, array $local, bool $remote, array $refs = array() ): DeliveryDriftService {
		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->willReturn( $rows );
		return new DeliveryDriftService(
			$objects,
			$this->manifests(),
			$this->probe( $remote ),
			static fn( string $relative ): bool => in_array( $relative, $local, true ),
			static fn( array $paths, int $id = 0, array $current = array() ): array => array_intersect_key( $refs, array_flip( $paths ) )
		);
	}

	private function manifests(): ManifestBuilder {
		$builder = $this->createMock( ManifestBuilder::class );
		$builder->method( 'build' )->willReturnCallback(
			static fn( int $id ): MediaManifest => new MediaManifest(
				$id,
				array(
					array( 'relative' => self::ORIGINAL, 'variant_type' => 'original' ),
					array( 'relative' => self::THUMB, 'variant_type' => 'size' ),
				)
			)
		);
		return $builder;
	}

	private function probe( bool $remote ): CleanupDeliveryProbe {
		$probe = $this->createMock( CleanupDeliveryProbe::class );
		$probe->method( 'resolves_remotely' )->willReturn( $remote );
		return $probe;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function row( int $profile, string $relative, string $status ): array {
		return array(
			'id'                  => crc32( $profile . $relative ),
			'attachment_id'       => 42,
			'storage_profile_id'  => $profile,
			'local_relative_path' => $relative,
			'object_key'          => 'p' . $profile . '/' . $relative,
			'remote_status'       => $status,
			'verified_at'         => $status === ObjectRemoteStatus::PRESENT ? '2026-10-01 00:00:00' : null,
		);
	}

	/**
	 * @param list<array<string,mixed>> $findings
	 * @return array<string,mixed>
	 */
	private function only( array $findings ): array {
		$this->assertCount( 1, $findings, json_encode( array_column( $findings, 'finding' ) ) );
		return $findings[0];
	}
}
