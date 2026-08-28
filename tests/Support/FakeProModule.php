<?php
/**
 * Minimal stub Pro module for tests that need Features::is_pro_active() to be
 * true — the only way that can happen in production is a real Pro module
 * registering via ModuleRegistry, so tests simulate that instead of the
 * removed KAZUS_PLAN/kazus_plan override.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests\Support;

use Kazcode\WpStorage\Core\Module\ModuleInterface;

final class FakeProModule implements ModuleInterface {

	public function id(): string {
		return 'test-pro';
	}

	public function name(): string {
		return 'Test Pro';
	}

	public function is_pro(): bool {
		return true;
	}

	public function boot(): void {
	}
}
