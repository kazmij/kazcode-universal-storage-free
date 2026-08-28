<?php
/**
 * HealthScanService DB-first scan tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Domain\ObjectHealthState;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Services\HealthScanService;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class HealthScanServiceTest extends TestCase {

	private string $uploads;

	protected function setUp(): void {
		WpStubs::reset();
		$this->uploads = sys_get_temp_dir() . '/s3ms-health-' . uniqid( '', true );
		wp_mkdir_p( $this->uploads . '/2026/08' );
		WpStubs::$uploads_basedir = $this->uploads;
	}

	protected function tearDown(): void {
		$this->rmTree( $this->uploads );
		WpStubs::reset();
	}

	public function test_scan_page_counts_remote_missing_when_local_exists(): void {
		$local = $this->uploads . '/2026/08/photo.jpg';
		file_put_contents( $local, 'bytes' );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->method( 'scan_page' )->willReturn(
			array(
				array(
					'id'                  => 10,
					'attachment_id'       => 5,
					'local_relative_path' => '2026/08/photo.jpg',
					'remote_status'       => ObjectRemoteStatus::MISSING,
				),
			)
		);

		$result = ( new HealthScanService( $objects ) )->scan_page( 100, 0 );

		$this->assertSame( 1, $result['scanned'] );
		$this->assertSame( 1, $result['health_counts'][ ObjectHealthState::REMOTE_MISSING ] );
		$this->assertSame( 1, $result['repairable'] );
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
