<?php
/**
 * ModuleRegistry unit tests (Phase 9).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\Module\ModuleInterface;
use Kazcode\WpStorage\Core\Module\ModuleRegistry;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class ModuleRegistryTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_registers_and_boots_modules(): void {
		$registry = ModuleRegistry::instance();
		$module   = new class() implements ModuleInterface {
			public bool $booted = false;

			public function id(): string {
				return 'test-module';
			}

			public function name(): string {
				return 'Test';
			}

			public function is_pro(): bool {
				return true;
			}

			public function boot(): void {
				$this->booted = true;
			}
		};

		$registry->register( $module );
		$registry->boot_all();

		$this->assertTrue( $module->booted );
		$this->assertTrue( $registry->has_pro_module() );
		$this->assertSame( $module, $registry->get( 'test-module' ) );
	}

	public function test_free_module_does_not_mark_pro_active(): void {
		$registry = ModuleRegistry::instance();
		$registry->register(
			new class() implements ModuleInterface {
				public function id(): string {
					return 'free-helper';
				}

				public function name(): string {
					return 'Free helper';
				}

				public function is_pro(): bool {
					return false;
				}

				public function boot(): void {
				}
			}
		);

		$this->assertFalse( $registry->has_pro_module() );
	}
}
