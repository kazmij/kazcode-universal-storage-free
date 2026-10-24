<?php
/**
 * Legacy S3Storage download must use temp → size validate → atomic rename.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Storage\PublicUrlResolver;
use Kazcode\WpStorage\Storage\S3ClientFactory;
use Kazcode\WpStorage\Storage\S3KeyResolver;
use Kazcode\WpStorage\Storage\S3Storage;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class S3StorageDownloadAtomicTest extends TestCase {

	private string $uploads;

	protected function setUp(): void {
		WpStubs::reset();
		$this->uploads = sys_get_temp_dir() . '/s3ms-legacy-dl-' . uniqid( '', true );
		wp_mkdir_p( $this->uploads . '/2026/08' );
		WpStubs::$uploads_basedir = $this->uploads;
	}

	protected function tearDown(): void {
		$this->rmTree( $this->uploads );
		WpStubs::reset();
	}

	public function test_download_relative_writes_via_temp_then_atomic_rename(): void {
		$dest   = $this->uploads . '/2026/08/photo.jpg';
		$client = new FakeDownloadS3Client(
			static function ( array $params ) use ( $dest ): void {
				TestCase::assertSame( dirname( $dest ), dirname( $params['SaveAs'] ) );
				TestCase::assertStringStartsWith( dirname( $dest ) . '/.kazus-dl-', $params['SaveAs'] );
				file_put_contents( $params['SaveAs'], 'legacy-bytes' );
			}
		);

		$this->storage_with_client( $client )->download_relative( '2026/08/photo.jpg', $dest, 12 );

		$this->assertFileExists( $dest );
		$this->assertSame( 'legacy-bytes', (string) file_get_contents( $dest ) );
	}

	public function test_download_relative_size_mismatch_preserves_preexisting_final(): void {
		$dest = $this->uploads . '/2026/08/keep.jpg';
		file_put_contents( $dest, 'preexisting' );
		$client = new FakeDownloadS3Client(
			static function ( array $params ): void {
				file_put_contents( $params['SaveAs'], 'wrong-size-payload' );
			}
		);

		try {
			$this->storage_with_client( $client )->download_relative( '2026/08/keep.jpg', $dest, 4 );
			$this->fail( 'Expected size mismatch.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Downloaded size does not match', $e->getMessage() );
		}

		$this->assertSame( 'preexisting', (string) file_get_contents( $dest ) );
		$leftover = glob( $this->uploads . '/2026/08/.kazus-dl-*' );
		$this->assertSame( array(), $leftover ?: array() );
	}

	public function test_download_relative_partial_client_failure_does_not_leave_final_partial(): void {
		$dest   = $this->uploads . '/2026/08/fail.jpg';
		$client = new FakeDownloadS3Client(
			static function ( array $params ): void {
				file_put_contents( $params['SaveAs'], 'partial' );
				throw new \RuntimeException( 'connection reset' );
			}
		);

		try {
			$this->storage_with_client( $client )->download_relative( '2026/08/fail.jpg', $dest );
			$this->fail( 'Expected download failure.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'connection reset', $e->getMessage() );
		}

		$this->assertFileDoesNotExist( $dest );
		$leftover = glob( $this->uploads . '/2026/08/.kazus-dl-*' );
		$this->assertSame( array(), $leftover ?: array() );
	}

	private function storage_with_client( FakeDownloadS3Client $client ): S3Storage {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'get' )->willReturnCallback(
			static fn( string $key, mixed $default = null ): mixed => match ( $key ) {
				'bucket' => 'legacy-bucket',
				'path_prefix' => '',
				default => $default,
			}
		);

		$storage = new S3Storage(
			$this->createMock( S3ClientFactory::class ),
			new S3KeyResolver( $settings ),
			new PublicUrlResolver( $settings ),
			$settings
		);
		$ref  = new \ReflectionClass( $storage );
		$prop = $ref->getProperty( 'client' );
		$prop->setAccessible( true );
		$prop->setValue( $storage, $client );
		return $storage;
	}

	private function rmTree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}
}
