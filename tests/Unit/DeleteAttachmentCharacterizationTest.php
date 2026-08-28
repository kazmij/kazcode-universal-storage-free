<?php
/**
 * Characterization: delete_attachment remote key discovery.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Attachment\AttachmentRestorer;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Storage\S3KeyResolver;
use Kazcode\WpStorage\Storage\S3Storage;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class DeleteAttachmentCharacterizationTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_delete_uses_metadata_paths_with_current_prefix_not_original_key_inventory(): void {
		$settings = $this->createMock(Settings::class);
		$settings->method('is_enabled')->willReturn(true);
		$settings->method('should_delete_remote')->willReturn(true);
		$settings->method('is_aws_configured')->willReturn(true);
		$settings->method('get')->willReturnCallback(
			static function (string $key, mixed $default = null): mixed {
				$map = array( 'object_prefix' => 'wordpress/' );
				return $map[ $key ] ?? $default;
			}
		);

		$captured = array();
		$keys     = new S3KeyResolver($settings);
		$storage  = $this->createMock(S3Storage::class);
		$storage->method('keys')->willReturn($keys);
		$storage->method('delete_relatives')->willReturnCallback(
			static function (array $relatives) use (&$captured): void {
				$captured = $relatives;
			}
		);

		WpStubs::set_meta(99, '_s3ms_status', 'offloaded');
		WpStubs::set_meta(99, '_s3ms_original_key', 'wordpress/2026/08/ONLY-ORIGINAL.jpg');
		WpStubs::set_meta(99, '_wp_attached_file', '2026/08/photo.jpg');
		WpStubs::set_meta(
			99,
			'_wp_attachment_metadata',
			array(
				'file'  => '2026/08/photo.jpg',
				'sizes' => array(
					'thumbnail' => array( 'file' => 'photo-150x150.jpg' ),
					'medium'    => array( 'file' => 'photo-300x200.jpg' ),
				),
			)
		);

		$restorer = new AttachmentRestorer($settings, $storage);
		$restorer->on_delete_attachment(99);

		$this->assertSame(
			array(
				'2026/08/photo.jpg',
				'2026/08/photo-150x150.jpg',
				'2026/08/photo-300x200.jpg',
			),
			$captured
		);
		$this->assertNotContains(
			'ONLY-ORIGINAL.jpg',
			$captured,
			'_s3ms_original_key is a presence gate, not the delete inventory'
		);
	}

	public function test_delete_skipped_when_no_s3_meta(): void {
		$settings = $this->createMock(Settings::class);
		$settings->method('is_enabled')->willReturn(true);
		$settings->method('should_delete_remote')->willReturn(true);
		$settings->method('is_aws_configured')->willReturn(true);

		$storage = $this->createMock(S3Storage::class);
		$storage->expects($this->never())->method('delete_relatives');
		$storage->method('keys')->willReturn(new S3KeyResolver($settings));

		$restorer = new AttachmentRestorer($settings, $storage);
		$restorer->on_delete_attachment(1);
	}

	public function test_successful_remote_delete_also_clears_object_inventory_rows(): void {
		// Regression: wp_posts has no FK/cascade into s3ms_objects. Skipping this
		// cleanup left orphaned "present" rows behind forever, which both poisoned
		// Health/stats and permanently blocked the default profile's location sync
		// (count_by_profile() never returning to 0) after any attachment was ever
		// deleted.
		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_enabled' )->willReturn( true );
		$settings->method( 'should_delete_remote' )->willReturn( true );
		$settings->method( 'is_aws_configured' )->willReturn( true );

		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'keys' )->willReturn( new S3KeyResolver( $settings ) );
		$storage->method( 'delete_relatives' );

		WpStubs::set_meta( 7, '_s3ms_status', 'offloaded' );
		WpStubs::set_meta( 7, '_s3ms_original_key', '2026/08/photo.jpg' );
		WpStubs::set_meta( 7, '_wp_attached_file', '2026/08/photo.jpg' );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )->method( 'delete_by_attachment' )->with( 7 );

		$restorer = new AttachmentRestorer( $settings, $storage );
		$ref      = new \ReflectionClass( AttachmentRestorer::class );
		$prop     = $ref->getProperty( 'objects' );
		$prop->setAccessible( true );
		$prop->setValue( $restorer, $objects );

		$restorer->on_delete_attachment( 7 );
	}

	public function test_object_rows_kept_when_remote_delete_disabled(): void {
		// If the admin chose to keep remote objects on attachment delete, the
		// s3ms_objects rows still describe real remote objects — do not delete them.
		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_enabled' )->willReturn( true );
		$settings->method( 'should_delete_remote' )->willReturn( false );

		$storage = $this->createMock( S3Storage::class );
		$storage->expects( $this->never() )->method( 'delete_relatives' );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->never() )->method( 'delete_by_attachment' );

		$restorer = new AttachmentRestorer( $settings, $storage );
		$ref      = new \ReflectionClass( AttachmentRestorer::class );
		$prop     = $ref->getProperty( 'objects' );
		$prop->setAccessible( true );
		$prop->setValue( $restorer, $objects );

		$restorer->on_delete_attachment( 8 );
	}
}
