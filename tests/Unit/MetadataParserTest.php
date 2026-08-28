<?php
/**
 * Attachment metadata file discovery (pure helpers).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Storage\S3KeyResolver;

/**
 * Tests metadata path assembly logic used by AttachmentFileResolver.
 */
final class MetadataParserTest extends TestCase {

	public function test_sizes_and_original_image_paths(): void {
		$settings = $this->createMock(Settings::class);
		$settings->method('get')->willReturn('');
		$keys = new S3KeyResolver($settings);

		$attached = '2026/08/photo.jpg';
		$meta     = array(
			'width'  => 4000,
			'height' => 3000,
			'file'   => '2026/08/photo.jpg',
			'sizes'  => array(
				'thumbnail' => array(
					'file'   => 'photo-150x150.jpg',
					'width'  => 150,
					'height' => 150,
				),
				'medium'    => array(
					'file'   => 'photo-300x225.jpg',
					'width'  => 300,
					'height' => 225,
				),
			),
			'original_image' => 'photo.jpg',
		);

		$paths   = array();
		$paths[] = $keys->normalize_relative($attached);
		$paths[] = $keys->normalize_relative((string) $meta['file']);
		foreach ($meta['sizes'] as $size) {
			$paths[] = $keys->relative_for_size($attached, (string) $size['file']);
		}
		$paths[] = $keys->relative_for_size($attached, (string) $meta['original_image']);
		$paths   = array_values(array_unique($paths));

		$this->assertContains('2026/08/photo.jpg', $paths);
		$this->assertContains('2026/08/photo-150x150.jpg', $paths);
		$this->assertContains('2026/08/photo-300x225.jpg', $paths);
		$this->assertCount(3, $paths); // original_image same basename as attached file path dir+photo.jpg → duplicate
	}

	public function test_scaled_variant_path(): void {
		$settings = $this->createMock(Settings::class);
		$settings->method('get')->willReturn('');
		$keys = new S3KeyResolver($settings);

		$attached = '2026/08/huge-scaled.jpg';
		$this->assertSame(
			'2026/08/huge.jpg',
			$keys->relative_for_size($attached, 'huge.jpg')
		);
	}
}
