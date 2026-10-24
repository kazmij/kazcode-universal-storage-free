<?php
/**
 * ProfileStorageGateway::download_key_to_local characterization.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Storage\ProfileStorageGateway;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class ProfileStorageGatewayDownloadTest extends TestCase {

	private string $uploads;

	protected function setUp(): void {
		WpStubs::reset();
		$this->uploads = sys_get_temp_dir() . '/s3ms-profile-dl-' . uniqid( '', true );
		wp_mkdir_p( $this->uploads . '/2026/08' );
		WpStubs::$uploads_basedir = $this->uploads;
	}

	protected function tearDown(): void {
		$this->rmTree( $this->uploads );
		WpStubs::reset();
	}

	public function test_download_key_to_local_writes_under_uploads_via_profile_client(): void {
		$dest   = $this->uploads . '/2026/08/photo.jpg';
		$client = new FakeDownloadS3Client(
			static function ( array $params ) use ( $dest ): void {
				TestCase::assertSame( 'profile-bucket', $params['Bucket'] );
				TestCase::assertSame( 'prefix/2026/08/photo.jpg', $params['Key'] );
				TestCase::assertSame( dirname( $dest ), dirname( $params['SaveAs'] ) );
				TestCase::assertStringStartsWith( dirname( $dest ) . '/.kazus-dl-', $params['SaveAs'] );
				TestCase::assertStringNotContainsString( basename( $dest ), basename( $params['SaveAs'] ) );
				file_put_contents( $params['SaveAs'], 'profile-bytes' );
			}
		);

		$gateway = $this->gateway_with_client( $client );
		$gateway->download_key_to_local( 'prefix/2026/08/photo.jpg', $dest );

		$this->assertFileExists( $dest );
		$this->assertSame( 'profile-bytes', (string) file_get_contents( $dest ) );
		$this->assertCount( 1, $client->calls );
		$this->assertSame( 'getObject', $client->calls[0]['method'] );
	}

	public function test_download_key_to_local_creates_missing_parent_directory(): void {
		$dest   = $this->uploads . '/2026/09/nested/photo.jpg';
		$client = new FakeDownloadS3Client(
			static function ( array $params ): void {
				file_put_contents( $params['SaveAs'], 'ok' );
			}
		);

		$this->gateway_with_client( $client )->download_key_to_local( 'k', $dest );

		$this->assertDirectoryExists( dirname( $dest ) );
		$this->assertFileExists( $dest );
	}

	public function test_download_key_to_local_rejects_path_outside_uploads(): void {
		$outside = sys_get_temp_dir() . '/s3ms-outside-' . uniqid( '', true ) . '.jpg';
		$client  = new FakeDownloadS3Client(
			static function (): void {
				TestCase::fail( 'getObject must not run for paths outside uploads.' );
			}
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'outside uploads' );
		$this->gateway_with_client( $client )->download_key_to_local( 'k', $outside );
	}

	public function test_download_key_to_local_rejects_escaping_symlink_ancestor(): void {
		$outside_root = sys_get_temp_dir() . '/s3ms-escape-' . uniqid( '', true );
		wp_mkdir_p( $outside_root );
		$link = $this->uploads . '/escape-link';
		symlink( $outside_root, $link );

		$dest  = $link . '/nested/photo.jpg';
		$client = new FakeDownloadS3Client(
			static function (): void {
				TestCase::fail( 'getObject must not run through escaping symlink.' );
			}
		);

		try {
			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( 'outside uploads' );
			$this->gateway_with_client( $client )->download_key_to_local( 'k', $dest );
		} finally {
			if ( is_link( $link ) ) {
				unlink( $link );
			}
			$this->rmTree( $outside_root );
		}
	}

	public function test_download_key_to_local_rejects_dangling_symlink_destination(): void {
		$outside_dir = sys_get_temp_dir() . '/s3ms-dangling-dir-' . uniqid( '', true );
		wp_mkdir_p( $outside_dir );
		$dangling = $this->uploads . '/dangling.jpg';
		symlink( $outside_dir . '/missing-target.jpg', $dangling );

		$client = new FakeDownloadS3Client(
			static function (): void {
				TestCase::fail( 'getObject must not run for dangling symlink destinations.' );
			}
		);

		try {
			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( 'outside uploads' );
			$this->gateway_with_client( $client )->download_key_to_local( 'k', $dangling );
		} finally {
			if ( is_link( $dangling ) ) {
				unlink( $dangling );
			}
			$this->rmTree( $outside_dir );
		}
	}

	public function test_download_key_to_local_allows_recreate_when_uploads_basedir_missing(): void {
		$parent = sys_get_temp_dir() . '/s3ms-missing-basedir-parent-' . uniqid( '', true );
		wp_mkdir_p( $parent );
		$missing_basedir = $parent . '/uploads-missing';
		WpStubs::$uploads_basedir = $missing_basedir;

		$dest   = $missing_basedir . '/2026/08/photo.jpg';
		$client = new FakeDownloadS3Client(
			static function ( array $params ): void {
				file_put_contents( $params['SaveAs'], 'recreated' );
			}
		);

		try {
			$this->gateway_with_client( $client )->download_key_to_local( 'k', $dest );
			$this->assertDirectoryExists( $missing_basedir . '/2026/08' );
			$this->assertFileExists( $dest );
			$this->assertSame( 'recreated', (string) file_get_contents( $dest ) );
		} finally {
			$this->rmTree( $parent );
			WpStubs::$uploads_basedir = $this->uploads;
		}
	}

	public function test_download_key_to_local_allows_recreate_when_basedir_parent_is_symlink(): void {
		$real_root = sys_get_temp_dir() . '/s3ms-symlink-real-' . uniqid( '', true );
		$link_root = sys_get_temp_dir() . '/s3ms-symlink-link-' . uniqid( '', true );
		wp_mkdir_p( $real_root . '/wp-content' );
		symlink( $real_root . '/wp-content', $link_root );

		// Lexical basedir under the symlink; uploads dir itself is missing so
		// nearest existing ancestor realpath is the symlink target.
		$missing_basedir          = $link_root . '/uploads';
		WpStubs::$uploads_basedir = $missing_basedir;
		$dest                     = $missing_basedir . '/2026/08/photo.jpg';
		$client                   = new FakeDownloadS3Client(
			static function ( array $params ): void {
				file_put_contents( $params['SaveAs'], 'via-symlink-parent' );
			}
		);

		try {
			$this->gateway_with_client( $client )->download_key_to_local( 'k', $dest );
			$this->assertDirectoryExists( $real_root . '/wp-content/uploads/2026/08' );
			$this->assertFileExists( $dest );
			$this->assertSame( 'via-symlink-parent', (string) file_get_contents( $dest ) );
		} finally {
			$this->rmTree( $real_root . '/wp-content/uploads' );
			if ( is_link( $link_root ) ) {
				unlink( $link_root );
			}
			$this->rmTree( $real_root );
			WpStubs::$uploads_basedir = $this->uploads;
		}
	}

	public function test_download_key_to_local_does_not_leave_partial_file_on_client_failure(): void {
		$dest   = $this->uploads . '/2026/08/fail.jpg';
		$client = new FakeDownloadS3Client(
			static function (): void {
				throw new \RuntimeException( 'network timeout' );
			}
		);

		try {
			$this->gateway_with_client( $client )->download_key_to_local( 'k', $dest );
			$this->fail( 'Expected download failure.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'network timeout', $e->getMessage() );
		}

		$this->assertFileDoesNotExist( $dest );
		$leftover = glob( $this->uploads . '/2026/08/.kazus-dl-*' );
		$this->assertSame( array(), $leftover ?: array() );
	}

	public function test_download_key_to_local_preserves_preexisting_destination_on_failure(): void {
		$dest = $this->uploads . '/2026/08/keep.jpg';
		file_put_contents( $dest, 'preexisting' );
		$client = new FakeDownloadS3Client(
			static function (): void {
				throw new \RuntimeException( 'credential init failed' );
			}
		);

		try {
			$this->gateway_with_client( $client )->download_key_to_local( 'k', $dest );
			$this->fail( 'Expected download failure.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'credential init failed', $e->getMessage() );
		}

		$this->assertFileExists( $dest );
		$this->assertSame( 'preexisting', (string) file_get_contents( $dest ) );
	}

	public function test_download_key_to_local_validates_expected_size_before_rename(): void {
		$dest = $this->uploads . '/2026/08/sized.jpg';
		file_put_contents( $dest, 'old-final' );
		$client = new FakeDownloadS3Client(
			static function ( array $params ): void {
				file_put_contents( $params['SaveAs'], 'abcd' ); // 4 bytes
			}
		);

		$this->gateway_with_client( $client )->download_key_to_local( 'k', $dest, 4 );

		$this->assertSame( 'abcd', (string) file_get_contents( $dest ) );
	}

	public function test_download_key_to_local_rejects_size_mismatch_without_replacing_final(): void {
		$dest = $this->uploads . '/2026/08/mismatch.jpg';
		file_put_contents( $dest, 'keep-me' );
		$client = new FakeDownloadS3Client(
			static function ( array $params ): void {
				file_put_contents( $params['SaveAs'], 'too-long' );
			}
		);

		try {
			$this->gateway_with_client( $client )->download_key_to_local( 'k', $dest, 3 );
			$this->fail( 'Expected size mismatch failure.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Downloaded size does not match', $e->getMessage() );
		}

		$this->assertFileExists( $dest );
		$this->assertSame( 'keep-me', (string) file_get_contents( $dest ) );
		$leftover = glob( $this->uploads . '/2026/08/.kazus-dl-*' );
		$this->assertSame( array(), $leftover ?: array() );
	}

	public function test_download_key_to_local_accepts_zero_byte_expected_size(): void {
		$dest   = $this->uploads . '/2026/08/empty.bin';
		$client = new FakeDownloadS3Client(
			static function ( array $params ): void {
				file_put_contents( $params['SaveAs'], '' );
			}
		);

		$this->gateway_with_client( $client )->download_key_to_local( 'k', $dest, 0 );

		$this->assertFileExists( $dest );
		$this->assertSame( 0, filesize( $dest ) );
	}

	public function test_download_key_to_local_rejects_nonzero_when_expected_zero(): void {
		$dest = $this->uploads . '/2026/08/not-empty.bin';
		file_put_contents( $dest, 'prior' );
		$client = new FakeDownloadS3Client(
			static function ( array $params ): void {
				file_put_contents( $params['SaveAs'], 'x' );
			}
		);

		try {
			$this->gateway_with_client( $client )->download_key_to_local( 'k', $dest, 0 );
			$this->fail( 'Expected size mismatch for expected=0.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Downloaded size does not match', $e->getMessage() );
		}

		$this->assertSame( 'prior', (string) file_get_contents( $dest ) );
	}

	private function gateway_with_client( FakeDownloadS3Client $client ): ProfileStorageGateway {
		$profile = new StorageProfile(
			42,
			'profile-uuid',
			'Profile',
			'aws',
			'profile-bucket',
			'us-east-1',
			'',
			false,
			'',
			'storage',
			'',
			false,
			'keys',
			'cred-ref',
			false,
			false,
			false,
			'2026-09-01 00:00:00',
			'2026-09-01 00:00:00'
		);
		$gateway = new ProfileStorageGateway( $profile, new Settings() );
		$ref     = new \ReflectionClass( $gateway );
		$prop    = $ref->getProperty( 'client' );
		$prop->setAccessible( true );
		$prop->setValue( $gateway, $client );
		return $gateway;
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

final class FakeDownloadS3Client {
	/** @var list<array{method:string,params:array<string,mixed>}> */
	public array $calls = array();

	/** @var callable(array<string,mixed>):void */
	private $handler;

	/**
	 * @param callable(array<string,mixed>):void $handler
	 */
	public function __construct( callable $handler ) {
		$this->handler = $handler;
	}

	/**
	 * @param array<string,mixed> $params
	 */
	public function getObject( array $params ): object {
		$this->calls[] = array(
			'method' => 'getObject',
			'params' => $params,
		);
		( $this->handler )( $params );
		return new \stdClass();
	}
}
