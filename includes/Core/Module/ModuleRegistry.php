<?php
/**
 * Registers optional core/pro extension modules.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Core\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Central module registry — fired on `kazus_register_modules`.
 */
final class ModuleRegistry {

	private static ?self $instance = null;

	/** @var array<string, ModuleInterface> */
	private array $modules = array();

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register( ModuleInterface $module ): void {
		$this->modules[ $module->id() ] = $module;
	}

	public function get( string $id ): ?ModuleInterface {
		return $this->modules[ $id ] ?? null;
	}

	/**
	 * @return list<ModuleInterface>
	 */
	public function all(): array {
		return array_values( $this->modules );
	}

	public function has_pro_module(): bool {
		foreach ( $this->modules as $module ) {
			if ( $module->is_pro() ) {
				return true;
			}
		}
		return false;
	}

	public function boot_all(): void {
		/**
		 * Register optional modules before boot.
		 *
		 * @param ModuleRegistry $registry Registry instance.
		 */
		do_action( 'kazus_register_modules', $this );

		foreach ( $this->modules as $module ) {
			$module->boot();
		}
	}

	/**
	 * Test helper — reset singleton.
	 */
	public static function reset_for_tests(): void {
		self::$instance = null;
	}
}
