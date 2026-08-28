<?php
/**
 * AttachmentReconciler unit tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Attachment\AttachmentOffloader;
use Kazcode\WpStorage\Domain\ManifestBuilder;
use Kazcode\WpStorage\Domain\MediaManifest;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Services\AttachmentReconciler;
use Kazcode\WpStorage\Services\ObjectOffloadService;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class AttachmentReconcilerTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
		WpStubs::$options[ ObjectOffloadService::OPTION_ENABLED ] = true;
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_marks_removed_sizes_stale_on_regenerate(): void {
		WpStubs::set_meta( 5, '_wp_attached_file', '2026/08/photo.jpg' );
		WpStubs::set_meta(
			5,
			'_wp_attachment_metadata',
			array(
				'file'  => '2026/08/photo.jpg',
				'sizes' => array(
					'medium' => array( 'file' => 'photo-300.jpg' ),
				),
			)
		);

		$rows = array(
			array(
				'id'                  => 1,
				'attachment_id'       => 5,
				'local_relative_path' => '2026/08/photo.jpg',
				'object_key'          => 'uploads/2026/08/photo.jpg',
				'remote_status'       => ObjectRemoteStatus::PRESENT,
				'variant_type'        => 'original',
			),
			array(
				'id'                  => 2,
				'attachment_id'       => 5,
				'local_relative_path' => '2026/08/photo-150.jpg',
				'object_key'          => 'uploads/2026/08/photo-150.jpg',
				'remote_status'       => ObjectRemoteStatus::PRESENT,
				'variant_type'        => 'size',
			),
		);

		$builder = $this->createMock( ManifestBuilder::class );
		$builder->method( 'build' )->willReturn(
			new MediaManifest(
				5,
				array(
					array( 'relative' => '2026/08/photo.jpg', 'variant_type' => 'original' ),
					array( 'relative' => '2026/08/photo-300.jpg', 'variant_type' => 'size' ),
				)
			)
		);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'find_by_attachment' )->willReturn( $rows );
		$objects->expects( $this->once() )
			->method( 'mark_stale_by_relative_paths' )
			->with( 5, array( '2026/08/photo-150.jpg' ) )
			->willReturn( 1 );

		$result = ( new AttachmentReconciler( $builder, $objects ) )->reconcile( 5 );

		$this->assertSame( 1, $result['stale_marked'] );
		$this->assertContains( '2026/08/photo-300.jpg', $result['added'] );
		$this->assertContains( '2026/08/photo-150.jpg', $result['removed'] );
	}

	public function test_skips_when_object_offload_disabled(): void {
		WpStubs::$options[ ObjectOffloadService::OPTION_ENABLED ] = false;
		$result = ( new AttachmentReconciler() )->reconcile( 1 );
		$this->assertTrue( $result['skipped'] ?? false );
	}
}
