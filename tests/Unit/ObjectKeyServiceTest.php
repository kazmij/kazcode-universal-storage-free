<?php
/**
 * ObjectKeyService unit tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Storage\ObjectKeyService;

final class ObjectKeyServiceTest extends TestCase {

	public function test_normalize_prefix_adds_trailing_slash(): void {
		$this->assertSame( 'uploads/', ObjectKeyService::normalize_prefix( '/uploads' ) );
		$this->assertSame( 'uploads/', ObjectKeyService::normalize_prefix( 'uploads/' ) );
		$this->assertSame( '', ObjectKeyService::normalize_prefix( '' ) );
	}

	public function test_key_for_joins_without_double_slash(): void {
		$this->assertSame(
			'wordpress/2026/08/photo.jpg',
			ObjectKeyService::key_for( 'wordpress/', '2026/08/photo.jpg' )
		);
		$this->assertSame(
			'2026/08/photo.jpg',
			ObjectKeyService::key_for( '', '2026/08/photo.jpg' )
		);
	}

	public function test_rejects_traversal_in_prefix(): void {
		$this->expectException( \InvalidArgumentException::class );
		ObjectKeyService::normalize_prefix( '../evil' );
	}
}
