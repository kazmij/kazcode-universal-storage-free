<?php
/**
 * Top-level admin menu for a product-style plugin surface.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the KAZCODE Universal Storage admin menu (v2 Phase 11 IA).
 */
final class AdminMenu {

	public const MENU_SLUG           = 'kazcode-universal-storage';
	public const DASHBOARD_SLUG      = 'kazcode-universal-storage';
	public const MEDIA_SLUG          = 'kazcode-universal-storage-media';
	public const STORAGE_SLUG        = 'kazcode-universal-storage-storage';
	public const MIGRATION_SLUG      = 'kazcode-universal-storage-migration';
	public const HEALTH_SLUG         = 'kazcode-universal-storage-health';
	public const LOGS_SLUG           = 'kazcode-universal-storage-logs';
	public const SETTINGS_SLUG       = 'kazcode-universal-storage-settings';
	public const STORAGE_WIZARD_SLUG = 'kazcode-universal-storage-storage-wizard';

	/** @deprecated Legacy v1.x slug (predates both rebrands) — redirected by AdminLegacyRedirects */
	public const TOOLS_SLUG = 's3-media-storage-tools';

	/** @deprecated Legacy v1.x slug (predates both rebrands) — redirected by AdminLegacyRedirects */
	public const DIAGNOSTICS_SLUG = 's3-media-storage-diagnostics';

	public const WIZARD_SLUG  = 'kazcode-universal-storage-wizard';
	public const NETWORK_SLUG = 'kazcode-universal-storage-network';

	private DashboardPage $dashboard_page;
	private MediaPage $media_page;
	private StoragePage $storage_page;
	private MigrationPage $migration_page;
	private HealthPage $health_page;
	private LogsPage $logs_page;
	private SettingsPage $settings_page;
	private SetupWizardPage $wizard_page;

	public function __construct(
		DashboardPage $dashboard_page,
		MediaPage $media_page,
		StoragePage $storage_page,
		MigrationPage $migration_page,
		HealthPage $health_page,
		LogsPage $logs_page,
		SettingsPage $settings_page,
		SetupWizardPage $wizard_page
	) {
		$this->dashboard_page = $dashboard_page;
		$this->media_page     = $media_page;
		$this->storage_page   = $storage_page;
		$this->migration_page = $migration_page;
		$this->health_page    = $health_page;
		$this->logs_page      = $logs_page;
		$this->settings_page  = $settings_page;
		$this->wizard_page    = $wizard_page;
	}

	/**
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 9 );
		// Multisite network settings are Pro-only — see
		// Kazcode\WpStorage\Pro\Admin\NetworkSettingsPage::register(), which
		// hooks network_admin_menu itself.
	}

	/**
	 * Distinctive top-level menu icon (the brand mark, simplified for 20x20 legibility),
	 * inlined as a base64 SVG data URI per the WP core convention for custom menu icons —
	 * WP only ever varies its opacity for hover/current state, never its fill, so the SVG
	 * carries its own color. Memoized: add_menu() only runs once per admin request via the
	 * admin_menu hook, but this guards against ever being called more than once anyway.
	 */
	private static function menu_icon(): string {
		static $data_uri = null;
		if ( $data_uri === null ) {
			$svg = @file_get_contents( KAZUS_PLUGIN_DIR . 'assets/brand/admin-menu-icon.svg' );
			$data_uri = $svg !== false
				? 'data:image/svg+xml;base64,' . base64_encode( $svg )
				: 'dashicons-cloud';
		}
		return $data_uri;
	}

	/**
	 * @return void
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'KAZCODE Universal Storage', 'kazcode-universal-storage' ),
			__( 'Universal Storage', 'kazcode-universal-storage' ),
			'manage_options',
			self::DASHBOARD_SLUG,
			array( $this->dashboard_page, 'render' ),
			self::menu_icon(),
			81
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'kazcode-universal-storage' ),
			__( 'Dashboard', 'kazcode-universal-storage' ),
			'manage_options',
			self::DASHBOARD_SLUG,
			array( $this->dashboard_page, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Media', 'kazcode-universal-storage' ),
			__( 'Media', 'kazcode-universal-storage' ),
			'manage_options',
			self::MEDIA_SLUG,
			array( $this->media_page, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Storage', 'kazcode-universal-storage' ),
			__( 'Storage', 'kazcode-universal-storage' ),
			'manage_options',
			self::STORAGE_SLUG,
			array( $this->storage_page, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Migration', 'kazcode-universal-storage' ),
			__( 'Migration', 'kazcode-universal-storage' ),
			'manage_options',
			self::MIGRATION_SLUG,
			array( $this->migration_page, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Health', 'kazcode-universal-storage' ),
			__( 'Health', 'kazcode-universal-storage' ),
			'manage_options',
			self::HEALTH_SLUG,
			array( $this->health_page, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Logs', 'kazcode-universal-storage' ),
			__( 'Logs', 'kazcode-universal-storage' ),
			'manage_options',
			self::LOGS_SLUG,
			array( $this->logs_page, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'kazcode-universal-storage' ),
			__( 'Settings', 'kazcode-universal-storage' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( $this->settings_page, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Setup wizard', 'kazcode-universal-storage' ),
			__( 'Setup wizard', 'kazcode-universal-storage' ),
			'manage_options',
			self::WIZARD_SLUG,
			array( $this->wizard_page, 'render' )
		);

		// STORAGE_WIZARD_SLUG (the storage-to-storage migration wizard) is
		// Pro-only and registers its own hidden submenu at this slug — see
		// Kazcode\WpStorage\Pro\Admin\StorageChangeWizardPage::register().
	}

	// Network admin settings (multisite) are Pro-only — see
	// Kazcode\WpStorage\Pro\Admin\NetworkSettingsPage::register(), which
	// hooks network_admin_menu itself and registers self::NETWORK_SLUG.
}
