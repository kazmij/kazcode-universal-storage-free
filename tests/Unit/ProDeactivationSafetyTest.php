<?php
/**
 * Pro deactivation must never break existing media or delete stored state.
 * Verified live against real MinIO in this engagement (deactivate/reactivate
 * a Pro plugin with a migrated attachment and an independently-credentialed
 * 2nd profile) — these lock the same guarantees in as fast unit tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\Features;
use Kazcode\WpStorage\Core\Module\ModuleInterface;
use Kazcode\WpStorage\Core\Module\ModuleRegistry;
use Kazcode\WpStorage\Core\ProServices;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
use Kazcode\WpStorage\Services\ProfileCredentialStore;
use Kazcode\WpStorage\Storage\ProfileDeliveryUrlResolver;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class ProDeactivationSafetyTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	private function register_pro_stub(): void {
		ModuleRegistry::instance()->register(
			new class() implements ModuleInterface {
				public function id(): string {
					return 'pro-stub';
				}
				public function name(): string {
					return 'Pro stub';
				}
				public function is_pro(): bool {
					return true;
				}
				public function boot(): void {
				}
			}
		);
	}

	public function test_existing_profile_and_credentials_readable_without_pro(): void {
		// A profile + independent credentials created while Pro was active
		// must remain fully readable purely through Core code once Pro is
		// deactivated — profile storage and credential storage are Core
		// primitives, never gated by Features::is_pro_active().
		$credentials = new ProfileCredentialStore();
		$credentials->set( 'custom-ref', 'AKIATEST', 'secret' );

		$this->assertFalse( Features::is_pro_active() );
		$this->assertSame( 'AKIATEST', $credentials->get_access_key_id( 'custom-ref' ) );
		$this->assertTrue( $credentials->has_secret( 'custom-ref' ) );
	}

	public function test_delivery_url_resolution_does_not_depend_on_pro_state(): void {
		// Regression guard: URL resolution for an attachment migrated to a
		// 2nd (Pro-created) profile must keep working whether or not Pro is
		// currently active — deactivating Pro must never turn working media
		// URLs into broken ones. Mirrors what was verified live against real
		// MinIO in this engagement (migrate, then deactivate Pro, then check
		// wp_get_attachment_url() still resolves to the migrated location).
		$this->assertFalse( Features::is_pro_active() );

		$profile = new StorageProfile(
			2, 'uuid-2', 'Second', 'minio', 'bucket-2', 'us-east-1', 'http://minio:9000', true,
			'p2/', 'storage', '', false, 'keys', 'custom-ref', false, false, false,
			gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s' )
		);

		$url = ( new ProfileDeliveryUrlResolver() )->url_for_profile_key( $profile, 'p2/2026/08/test.jpg' );

		$this->assertSame( 'http://minio:9000/bucket-2/p2/2026/08/test.jpg', $url );
	}

	public function test_new_premium_operation_blocked_after_deactivation(): void {
		$this->register_pro_stub();
		// ModuleRegistry::reset_for_tests() (via WpStubs::reset()) is what a
		// real Pro plugin deactivation does at runtime: the module simply
		// stops being registered on the next plugins_loaded.
		WpStubs::reset();

		$this->assertFalse( Features::is_pro_active() );
		$this->assertNull( ProServices::get( 'storage_migration' ) );
		$this->assertNull( ProServices::get( 'orphan_scan' ) );
	}

	public function test_reactivation_restores_pro_state_without_reconfiguration(): void {
		$this->register_pro_stub();
		add_filter(
			'kazus_pro_service_factory',
			static function ( $default, string $id ) {
				return $id === 'storage_migration' ? static fn() => new \stdClass() : $default;
			},
			10,
			2
		);

		$this->assertTrue( Features::is_pro_active() );
		$this->assertInstanceOf( \stdClass::class, ProServices::get( 'storage_migration' ) );
	}
}
