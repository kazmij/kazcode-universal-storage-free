<?php
/**
 * Optional plugin module contract (v2 Phase 9).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Core\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Pro/free add-ons register via ModuleRegistry.
 */
interface ModuleInterface {

	public function id(): string;

	public function name(): string;

	/**
	 * Whether this module unlocks Pro-tier capabilities.
	 */
	public function is_pro(): bool;

	public function boot(): void;
}
