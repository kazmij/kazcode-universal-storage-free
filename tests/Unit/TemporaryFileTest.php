<?php

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Infrastructure\TemporaryFile;
use PHPUnit\Framework\TestCase;

final class TemporaryFileTest extends TestCase {

	public function test_creates_a_writable_file_for_runtime_operations(): void {
		$path = TemporaryFile::create( 's3ms-test' );

		$this->assertIsString( $path );
		$this->assertFileExists( $path );
		$this->assertNotFalse( file_put_contents( $path, 'test' ) );
		unlink( $path );
	}
}
