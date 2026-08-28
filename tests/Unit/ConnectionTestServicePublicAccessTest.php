<?php
/**
 * ConnectionTestService's public-access check — warns (without failing the
 * overall connection test) when a plainly-delivered bucket denies anonymous
 * reads, since that's a silent trap: connectivity is perfect, but every
 * offloaded image 403s in a browser, only discoverable after migrating a
 * whole library unless caught up front.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Services\ConnectionTestService;
use Kazcode\WpStorage\Storage\PublicUrlResolver;
use Kazcode\WpStorage\Storage\S3Storage;

final class ConnectionTestServicePublicAccessTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['s3ms_test_wp_remote_get'] );
		unset( $GLOBALS['s3ms_test_wp_safe_remote_get_args'] );
	}

	/**
	 * SSRF guard: the public-access check must go through wp_safe_remote_get()
	 * (which applies WordPress core's loopback/private/reserved-IP rejection),
	 * not the plain wp_remote_get() — $url here derives from admin-configured
	 * storage/CDN settings, so it is not a fixed trusted endpoint.
	 */
	public function test_public_access_check_uses_safe_remote_get_api(): void {
		$GLOBALS['s3ms_test_wp_remote_get'] = array( 'response' => array( 'code' => 200 ) );

		$this->run_test( false );

		$this->assertTrue(
			$GLOBALS['s3ms_test_wp_safe_remote_get_args']['reject_unsafe_urls'] ?? false,
			'Public-access check must call wp_safe_remote_get() with unsafe URLs rejected, not wp_remote_get() directly.'
		);
	}

	public function test_warns_when_bucket_denies_anonymous_access(): void {
		$GLOBALS['s3ms_test_wp_remote_get'] = array( 'response' => array( 'code' => 403 ) );

		$result = $this->run_test( false );

		$this->assertTrue( $result['success'], 'A denied public-read check must not fail the overall connection test.' );
		$step = $this->find_step( $result, 'public_access' );
		$this->assertFalse( $step['ok'] );
		$this->assertStringContainsString( 'denied anonymous access', $step['detail'] );
	}

	public function test_ok_when_bucket_is_publicly_readable(): void {
		$GLOBALS['s3ms_test_wp_remote_get'] = array( 'response' => array( 'code' => 200 ) );

		$result = $this->run_test( false );

		$step = $this->find_step( $result, 'public_access' );
		$this->assertTrue( $step['ok'] );
		$this->assertStringContainsString( 'publicly', $step['detail'] );
	}

	public function test_skipped_when_private_media_enabled(): void {
		$GLOBALS['s3ms_test_wp_remote_get'] = array( 'response' => array( 'code' => 403 ) );

		$result = $this->run_test( true );

		$step = $this->find_step( $result, 'public_access' );
		$this->assertTrue( $step['ok'] );
		$this->assertStringContainsString( 'Private Media', $step['detail'] );
	}

	/**
	 * @return array{success:bool,steps:list<array{name:string,ok:bool,detail:string}>}
	 */
	private function run_test( bool $private_media ): array {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_aws_configured' )->willReturn( true );
		$settings->method( 'is_private_media' )->willReturn( $private_media );

		$urls = $this->createMock( PublicUrlResolver::class );
		$urls->method( 'url_for_key' )->willReturn( 'https://bucket.s3.amazonaws.com/test-key.txt' );

		$storage = $this->createMock( S3Storage::class );
		$storage->method( 'client' )->willReturn( new \stdClass() );
		$storage->method( 'assert_bucket_exists' );
		$storage->method( 'upload_file' )->willReturn( 'test-key.txt' );
		$storage->method( 'head_key' )->willReturn( array( 'exists' => true ) );
		$storage->method( 'urls' )->willReturn( $urls );

		return ( new ConnectionTestService( $settings, $storage ) )->run();
	}

	/**
	 * @param array{success:bool,steps:list<array{name:string,ok:bool,detail:string}>} $result
	 * @return array{name:string,ok:bool,detail:string}
	 */
	private function find_step( array $result, string $name ): array {
		foreach ( $result['steps'] as $step ) {
			if ( $step['name'] === $name ) {
				return $step;
			}
		}
		$this->fail( "No '{$name}' step found in: " . print_r( $result, true ) );
	}
}
