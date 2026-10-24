<?php
/**
 * Safe Mode (#61): clone detection and namespace ownership guard.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace {
	if ( ! function_exists( 'home_url' ) ) {
		function home_url( $path = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			return $GLOBALS['kazus_test_home'] ?? 'https://example.test';
		}
	}
	if ( ! function_exists( 'wp_get_environment_type' ) ) {
		function wp_get_environment_type() {
			return $GLOBALS['kazus_test_env'] ?? 'production';
		}
	}
	if ( ! function_exists( 'get_transient' ) ) {
		function get_transient( $key ) {
			return \Kazcode\WpStorage\Tests\Support\WpStubs::$options[ '_transient_' . $key ] ?? false;
		}
	}
	if ( ! function_exists( 'set_transient' ) ) {
		function set_transient( $key, $value, $ttl = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			\Kazcode\WpStorage\Tests\Support\WpStubs::$options[ '_transient_' . $key ] = $value;
			return true;
		}
	}
	if ( ! function_exists( 'delete_transient' ) ) {
		function delete_transient( $key ) {
			unset( \Kazcode\WpStorage\Tests\Support\WpStubs::$options[ '_transient_' . $key ] );
			return true;
		}
	}
}

namespace Kazcode\WpStorage\Tests {

	use Kazcode\WpStorage\Admin\SafeModeNotice;
	use Kazcode\WpStorage\Core\SafeMode;
	use Kazcode\WpStorage\Core\SafeModeException;
	use Kazcode\WpStorage\Core\Settings;
	use Kazcode\WpStorage\Core\SiteIdentity;
	use Kazcode\WpStorage\Services\AuditLog;
	use Kazcode\WpStorage\Services\CleanupLocalFiles;
	use Kazcode\WpStorage\Storage\S3Storage;
	use Kazcode\WpStorage\Tests\Support\WpStubs;
	use PHPUnit\Framework\TestCase;

	final class SafeModeTest extends TestCase {

		/** @var array<string,mixed>|null */
		private ?array $marker = null;
		private int $reads     = 0;
		/** @var list<array<string,mixed>> */
		private array $writes  = array();

		protected function setUp(): void {
			WpStubs::reset();
			$GLOBALS['kazus_test_home'] = 'https://example.test';
			$GLOBALS['kazus_test_env']  = 'production';
			SafeMode::enforce( false );
			SiteIdentity::bootstrap();
			$this->marker = null;
			$this->reads  = 0;
			$this->writes = array();
		}

		protected function tearDown(): void {
			SafeMode::enforce( false );
			unset( $GLOBALS['kazus_test_home'], $GLOBALS['kazus_test_env'] );
			WpStubs::reset();
		}

		public function test_not_enforced_never_touches_storage(): void {
			$this->guard();
			$this->assertSame( 0, $this->reads );
			$this->assertSame( array(), $this->writes );
		}

		public function test_unclaimed_namespace_is_claimed_once_with_a_secret_free_marker(): void {
			SafeMode::enforce();
			$this->guard();
			$this->guard();

			$this->assertSame( 1, $this->reads, 'claim is cached' );
			$this->assertCount( 1, $this->writes );
			$this->assertSame( array( 'plugin', 'site_id', 'home', 'claimed_at' ), array_keys( $this->writes[0] ) );
			$this->assertSame( SiteIdentity::id(), $this->writes[0]['site_id'] );
			$this->assertSame( 'example.test', $this->writes[0]['home'] );
		}

		public function test_namespace_owned_by_another_site_blocks_writes(): void {
			SafeMode::enforce();
			$this->marker = array( 'site_id' => 'other-site', 'home' => 'prod.example' );

			$this->assertBlocked( 'namespace_owned_elsewhere' );
			$this->assertSame( array(), $this->writes, 'never overwrites the other site\'s marker' );
			$this->assertSame( 'namespace_owned_elsewhere', SafeMode::last_block()['reason'] );
			$this->assertStringContainsString( 'prod.example', SafeMode::last_block()['detail'] );
		}

		public function test_unreadable_marker_fails_closed(): void {
			SafeMode::enforce();
			$this->assertBlocked( 'namespace_owner_unknown', static function (): ?array {
				throw new \RuntimeException( 'AccessDenied' );
			} );
		}

		public function test_cloned_database_on_another_url_is_blocked_before_any_storage_request(): void {
			SafeMode::enforce();
			$GLOBALS['kazus_test_home'] = 'https://staging.example.test';

			$this->assertBlocked( 'site_url_changed' );
			$this->assertSame( 0, $this->reads );
		}

		public function test_site_without_inventory_rebinds_instead_of_blocking(): void {
			SafeMode::enforce( true, static fn(): bool => false );
			$id                         = SiteIdentity::id();
			$GLOBALS['kazus_test_home'] = 'https://launched.example';

			$this->guard();

			$this->assertSame( $id, SiteIdentity::id() );
			$this->assertSame( 'launched.example', SiteIdentity::stored()['home'] );
			$this->assertNull( SafeMode::last_block() );
		}

		public function test_site_with_inventory_on_a_new_url_stays_blocked(): void {
			SafeMode::enforce( true, static fn(): bool => true );
			$GLOBALS['kazus_test_home'] = 'https://clone.example';
			$this->assertBlocked( 'site_url_changed' );
		}

		public function test_production_identity_in_a_staging_environment_is_blocked(): void {
			SafeMode::enforce();
			$GLOBALS['kazus_test_env'] = 'staging';
			$this->assertBlocked( 'environment_changed' );
		}

		public function test_rebind_resumes_writes_for_the_same_site_and_is_audited(): void {
			SafeMode::enforce();
			$id                         = SiteIdentity::id();
			$GLOBALS['kazus_test_home'] = 'https://new-domain.example';
			$this->assertBlocked( 'site_url_changed' );

			$audit = $this->createMock( AuditLog::class );
			$audit->expects( $this->once() )->method( 'record' )->with( 'safe_mode_rebind', $this->callback( static fn( array $c ): bool => $c['reason'] === 'site_url_changed' ) );
			$this->assertTrue( ( new SafeModeNotice( $audit ) )->apply( 'rebind', '', false ) );

			$this->assertSame( $id, SiteIdentity::id(), 'same site keeps its identity' );
			$this->guard();
			$this->assertNull( SafeMode::last_block() );
		}

		public function test_independent_copy_gets_a_new_identity_and_cannot_write_the_original_prefix(): void {
			SafeMode::enforce();
			$original                   = SiteIdentity::id();
			$this->marker               = array( 'site_id' => $original, 'home' => 'example.test' );
			$GLOBALS['kazus_test_home'] = 'https://staging.example.test';

			( new SafeModeNotice( $this->createMock( AuditLog::class ) ) )->apply( 'independent', '', false );

			$this->assertNotSame( $original, SiteIdentity::id() );
			$this->assertBlocked( 'namespace_owned_elsewhere' );
		}

		public function test_takeover_needs_explicit_confirmation_then_reclaims_the_marker(): void {
			SafeMode::enforce();
			$this->marker = array( 'site_id' => 'old-install', 'home' => 'old.example' );
			$this->assertBlocked( 'namespace_owned_elsewhere' );
			$namespace = SafeMode::last_block()['namespace'];

			$notice = new SafeModeNotice( $this->createMock( AuditLog::class ) );
			$this->assertFalse( $notice->apply( 'takeover', $namespace, false ) );
			$this->assertBlocked( 'namespace_owned_elsewhere' );

			$this->assertTrue( $notice->apply( 'takeover', $namespace, true ) );
			$this->guard();
			$this->assertSame( SiteIdentity::id(), $this->writes[0]['site_id'] );
			$this->assertNull( SafeMode::last_block() );
		}

		public function test_storage_write_is_refused_before_the_sdk_is_called(): void {
			SafeMode::enforce();
			$GLOBALS['kazus_test_home'] = 'https://clone.example.test';

			$client = $this->getMockBuilder( \stdClass::class )->addMethods( array( 'putObject', 'deleteObject', 'deleteObjects', 'getObject' ) )->getMock();
			$client->expects( $this->never() )->method( 'putObject' );
			$client->expects( $this->never() )->method( 'deleteObject' );
			$client->expects( $this->never() )->method( 'deleteObjects' );
			$storage = $this->getMockBuilder( S3Storage::class )->disableOriginalConstructor()->onlyMethods( array( 'client', 'bucket' ) )->getMock();
			$storage->method( 'client' )->willReturn( $client );
			$storage->method( 'bucket' )->willReturn( 'media' );
			( new \ReflectionProperty( S3Storage::class, 'settings' ) )->setValue( $storage, $this->createMock( Settings::class ) );

			foreach ( array( fn() => $storage->put_object_from_file( 'k', __FILE__, 'text/plain', '' ), fn() => $storage->delete_keys( array( 'k' ) ), fn() => $storage->delete_key( 'k' ) ) as $call ) {
				try {
					$call();
					$this->fail( 'write should be refused' );
				} catch ( SafeModeException $e ) {
					$this->assertSame( 'site_url_changed', $e->reason );
				}
			}
		}

		public function test_local_cleanup_is_refused_while_writes_are_paused(): void {
			SafeMode::enforce();
			$GLOBALS['kazus_test_home'] = 'https://clone.example.test';
			$settings = $this->createMock( Settings::class );
			$settings->method( 'local_storage_policy' )->willReturn( 'remote_only' );

			$result = ( new CleanupLocalFiles( $settings, $this->createMock( S3Storage::class ) ) )->maybe_cleanup( 5, array( '2026/08/a.jpg' => '/tmp/a.jpg' ), array( array( 'relative' => '2026/08/a.jpg', 'variant_type' => 'original' ) ) );

			$this->assertSame( 0, $result['deleted'] );
			$this->assertSame( CleanupLocalFiles::SAFE_MODE, $result['blocked_reason'] );
		}

		private function guard( ?callable $read = null ): void {
			SafeMode::guard_write(
				'test',
				'media',
				'site/',
				$read ?? function (): ?array {
					++$this->reads;
					return $this->marker;
				},
				function ( array $payload ): void {
					$this->writes[] = $payload;
					$this->marker   = $payload;
				}
			);
		}

		private function assertBlocked( string $reason, ?callable $read = null ): void {
			try {
				$this->guard( $read );
				$this->fail( 'expected Safe Mode to block' );
			} catch ( SafeModeException $e ) {
				$this->assertSame( $reason, $e->reason );
			}
		}
	}
}
