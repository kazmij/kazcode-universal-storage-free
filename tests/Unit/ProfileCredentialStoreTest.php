<?php
/**
 * ProfileCredentialStore — encrypted per-profile access-key storage.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Services\ProfileCredentialStore;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class ProfileCredentialStoreTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_roundtrip(): void {
		$store = new ProfileCredentialStore();
		$store->set( 'ref-1', 'AKIAEXAMPLE', 'super-secret' );

		$this->assertSame( 'AKIAEXAMPLE', $store->get_access_key_id( 'ref-1' ) );
		$this->assertSame( 'super-secret', $store->get_secret( 'ref-1' ) );
		$this->assertTrue( $store->has_secret( 'ref-1' ) );
		$this->assertTrue( $store->has( 'ref-1' ) );
	}

	public function test_blank_secret_on_set_keeps_existing_secret(): void {
		$store = new ProfileCredentialStore();
		$store->set( 'ref-1', 'AKIAEXAMPLE', 'first-secret' );
		$store->set( 'ref-1', 'AKIAROTATED', '' );

		$this->assertSame( 'AKIAROTATED', $store->get_access_key_id( 'ref-1' ), 'access key still rotates' );
		$this->assertSame( 'first-secret', $store->get_secret( 'ref-1' ), 'blank secret does not clobber the stored one' );
	}

	public function test_unknown_ref_returns_empty(): void {
		$store = new ProfileCredentialStore();
		$this->assertSame( '', $store->get_access_key_id( 'nope' ) );
		$this->assertSame( '', $store->get_secret( 'nope' ) );
		$this->assertFalse( $store->has_secret( 'nope' ) );
		$this->assertFalse( $store->has( 'nope' ) );
	}

	public function test_multiple_refs_are_independent(): void {
		$store = new ProfileCredentialStore();
		$store->set( 'aws-ref', 'AKIA_AWS', 'aws-secret' );
		$store->set( 'r2-ref', 'R2_KEY', 'r2-secret' );

		$this->assertSame( 'aws-secret', $store->get_secret( 'aws-ref' ) );
		$this->assertSame( 'r2-secret', $store->get_secret( 'r2-ref' ) );
	}

	public function test_delete_removes_ref(): void {
		$store = new ProfileCredentialStore();
		$store->set( 'ref-1', 'AKIAEXAMPLE', 'secret' );
		$store->delete( 'ref-1' );

		$this->assertFalse( $store->has( 'ref-1' ) );
		$this->assertSame( '', $store->get_secret( 'ref-1' ) );
	}
}
