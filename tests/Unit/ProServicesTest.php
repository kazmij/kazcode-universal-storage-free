<?php
/**
 * ProServices extension point tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\ProServices;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class ProServicesTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_get_returns_null_when_no_factory_registered(): void {
		$this->assertNull( ProServices::get( 'storage_migration' ) );
	}

	public function test_require_throws_when_no_factory_registered(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/requires KAZCODE Universal Storage Pro/' );
		ProServices::require( 'orphan_scan' );
	}

	public function test_get_returns_factory_result_with_forwarded_args(): void {
		add_filter(
			'kazus_pro_service_factory',
			static function ( $default, string $id ) {
				if ( $id === 'widget' ) {
					return static fn( int $n ): \ArrayObject => new \ArrayObject( array( 'n' => $n ) );
				}
				return $default;
			},
			10,
			2
		);

		$service = ProServices::get( 'widget', 42 );
		$this->assertInstanceOf( \ArrayObject::class, $service );
		$this->assertSame( 42, $service['n'] );
	}

	public function test_require_returns_service_when_factory_registered(): void {
		add_filter(
			'kazus_pro_service_factory',
			static function ( $default, string $id ) {
				if ( $id === 'widget' ) {
					return static fn() => new \stdClass();
				}
				return $default;
			},
			10,
			2
		);

		$this->assertInstanceOf( \stdClass::class, ProServices::require( 'widget' ) );
	}
}
