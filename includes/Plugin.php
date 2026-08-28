<?php
/**
 * Main plugin bootstrap with request-scoped service graph.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Admin\AdminLegacyRedirects;
use Kazcode\WpStorage\Admin\AdminMenu;
use Kazcode\WpStorage\Admin\AttachmentDetailsPanel;
use Kazcode\WpStorage\Admin\DashboardPage;
use Kazcode\WpStorage\Admin\HealthPage;
use Kazcode\WpStorage\Admin\LogsPage;
use Kazcode\WpStorage\Admin\MediaLibraryColumn;
use Kazcode\WpStorage\Admin\MediaPage;
use Kazcode\WpStorage\Admin\MigrationPage;
use Kazcode\WpStorage\Admin\OnboardingTour;
use Kazcode\WpStorage\Admin\SettingsPage;
use Kazcode\WpStorage\Admin\SetupWizardPage;
use Kazcode\WpStorage\Admin\StoragePage;
use Kazcode\WpStorage\Attachment\AttachmentOffloader;
use Kazcode\WpStorage\Attachment\AttachmentRestorer;
use Kazcode\WpStorage\Attachment\AttachmentUrlFilter;
use Kazcode\WpStorage\Attachment\LocalFileProvider;
use Kazcode\WpStorage\CLI\CliCommand;
use Kazcode\WpStorage\Core\Module\ModuleRegistry;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Infrastructure\BatchProcessor;
use Kazcode\WpStorage\Infrastructure\Queue\QueueFactory;
use Kazcode\WpStorage\Infrastructure\Queue\QueueGateway;
use Kazcode\WpStorage\Infrastructure\SchemaMigrator;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
use Kazcode\WpStorage\Services\LegacyProfileMigrator;
use Kazcode\WpStorage\Services\AuditLog;
use Kazcode\WpStorage\Services\BackgroundMigrator;
use Kazcode\WpStorage\Services\ConnectionTestService;
use Kazcode\WpStorage\Services\FailedItemsService;
use Kazcode\WpStorage\Services\MigrationService;
use Kazcode\WpStorage\Services\VerificationService;
use Kazcode\WpStorage\Storage\PublicUrlResolver;
use Kazcode\WpStorage\Storage\S3ClientFactory;
use Kazcode\WpStorage\Storage\S3KeyResolver;
use Kazcode\WpStorage\Storage\S3Storage;

/**
 * Plugin singleton.
 */
final class Plugin {

	private static ?self $instance = null;

	private Settings $settings;

	private ?S3Storage $storage = null;
	private ?AttachmentOffloader $offloader = null;
	private ?AttachmentUrlFilter $url_filter = null;
	private ?LocalFileProvider $local_file_provider = null;
	private ?AttachmentRestorer $restorer = null;
	private ?MigrationService $migration_service = null;
	private ?VerificationService $verification_service = null;
	private ?ConnectionTestService $connection_test = null;
	private ?S3KeyResolver $key_resolver = null;
	private ?PublicUrlResolver $url_resolver = null;
	private ?AuditLog $audit_log = null;
	private ?BackgroundMigrator $background = null;
	private ?QueueGateway $queue = null;
	private ?FailedItemsService $failed_items = null;

	private function __construct() {
		$this->settings = new Settings();
	}

	/**
	 * Singleton accessor.
	 */
	public static function instance(): self {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Plugin settings.
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		// No load_plugin_textdomain() call — discouraged since WP 4.6 for a plugin hosted
		// on WordPress.org, which auto-loads translations for the declared Text Domain.

		ModuleRegistry::instance()->boot_all();

		$this->maybe_upgrade_schema();

		add_action(
			'update_option_' . Settings::OPTION_KEY,
			function (): void {
				$this->settings->flush_cache();
				$this->audit_log()->record('settings_saved', array());
				try {
					( new LegacyProfileMigrator( $this->settings, new WpdbStorageProfileRepository() ) )
						->sync_default_profile_from_settings();
				} catch ( \Throwable $e ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						error_log( 'KAZCODE Universal Storage default profile sync failed: ' . $e->getMessage() );
					}
				}
			}
		);
		add_action(
			'update_option_' . Settings::ENCRYPTED_SECRET_KEY,
			function (): void {
				$this->settings->flush_cache();
			}
		);

		if (is_admin()) {
			$settings_page = new SettingsPage($this->settings);
			$dashboard     = new DashboardPage();
			$media         = new MediaPage();
			$storage       = new StoragePage();
			$migration     = new MigrationPage($this->migration_service());
			$health        = new HealthPage();
			$logs          = new LogsPage();
			$wizard        = new SetupWizardPage($this->settings);
			(new AdminMenu($dashboard, $media, $storage, $migration, $health, $logs, $settings_page, $wizard))->register();
			(new AdminLegacyRedirects())->register();
			$settings_page->register();
			$migration->register();
			$wizard->register();
			(new OnboardingTour())->register();
			(new AttachmentDetailsPanel())->register();
			(new MediaLibraryColumn())->register();

			add_filter(
				'plugin_row_meta',
				function ( array $links, string $plugin_file ): array {
					if ( $plugin_file !== KAZUS_PLUGIN_BASENAME ) {
						return $links;
					}
					$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=' . AdminMenu::SETTINGS_SLUG ) ) . '">' . esc_html__( 'Settings', 'kazcode-universal-storage' ) . '</a>';
					$links[] = '<a href="https://kazcode.net/universal-storage/docs/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Documentation', 'kazcode-universal-storage' ) . '</a>';
					$links[] = '<a href="mailto:kazmij@gmail.com">' . esc_html__( 'Support', 'kazcode-universal-storage' ) . '</a>';
					$links[] = '<a href="https://kazcode.net/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'KAZCODE', 'kazcode-universal-storage' ) . '</a>';
					return $links;
				},
				10,
				2
			);
		}

		$this->background()->register();
		$this->queue()->register();
		$this->url_filter()->register();
		$this->offloader()->register();
		$this->local_file_provider()->register();
		$this->restorer()->register_delete_hooks();
		(new BatchProcessor($this->migration_service()))->register();

		if (defined('WP_CLI') && WP_CLI) {
			CliCommand::register($this);
		}
	}

	/**
	 * Activation: ensure defaults + v2 schema/legacy profile seed.
	 */
	public function activate(): void {
		$this->settings->ensure_defaults();
		$this->maybe_upgrade_schema();
		// Short-lived — consumed by SetupWizardPage::maybe_redirect() on the very
		// next single-plugin-activation admin request, then deleted. Short TTL is
		// deliberate: if a bulk-activate flow's own redirect suppression check
		// somehow doesn't catch it, this still self-expires quickly rather than
		// firing on some unrelated later page load.
		set_transient( 'kazus_activation_redirect', 1, MINUTE_IN_SECONDS );
	}

	/**
	 * Deactivation: keep DB and S3 intact.
	 */
	public function deactivate(): void {
		// Intentionally empty — do not remove options, meta, or remote objects.
	}

	/**
	 * Create/upgrade custom tables and seed legacy Storage Profile (idempotent).
	 */
	public function maybe_upgrade_schema(): void {
		( new SchemaMigrator() )->maybe_upgrade();
		try {
			$repo = new WpdbStorageProfileRepository();
			( new LegacyProfileMigrator( $this->settings, $repo ) )->ensure_legacy_profile();
		} catch ( \Throwable $e ) {
			// Schema may not be writable yet; do not fatally break site boot.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'KAZCODE Universal Storage schema/profile upgrade: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Shared audit log service.
	 */
	public function audit_log(): AuditLog {
		if ($this->audit_log === null) {
			$this->audit_log = new AuditLog();
		}
		return $this->audit_log;
	}

	/**
	 * Background migration cron service.
	 */
	public function background(): BackgroundMigrator {
		if ($this->background === null) {
			$this->background = new BackgroundMigrator($this->settings, $this->audit_log());
		}
		return $this->background;
	}

	/**
	 * Durable queue gateway (cron default; Action Scheduler when available).
	 */
	public function queue(): QueueGateway {
		if ($this->queue === null) {
			$this->queue = QueueFactory::create($this->background());
		}
		return $this->queue;
	}

	/**
	 * Failed attachment listing service.
	 */
	public function failed_items(): FailedItemsService {
		if ($this->failed_items === null) {
			$this->failed_items = new FailedItemsService();
		}
		return $this->failed_items;
	}

	/**
	 * Shared key resolver.
	 */
	public function key_resolver(): S3KeyResolver {
		if ($this->key_resolver === null) {
			$this->key_resolver = new S3KeyResolver($this->settings);
		}
		return $this->key_resolver;
	}

	/**
	 * Shared public URL resolver.
	 */
	public function url_resolver(): PublicUrlResolver {
		if ($this->url_resolver === null) {
			$this->url_resolver = new PublicUrlResolver($this->settings);
		}
		return $this->url_resolver;
	}

	/**
	 * Shared S3 storage service.
	 */
	public function storage(): S3Storage {
		if ($this->storage === null) {
			$this->storage = new S3Storage(
				new S3ClientFactory($this->settings),
				$this->key_resolver(),
				$this->url_resolver(),
				$this->settings
			);
		}
		return $this->storage;
	}

	/**
	 * Shared attachment offloader.
	 */
	public function offloader(): AttachmentOffloader {
		if ($this->offloader === null) {
			$this->offloader = new AttachmentOffloader($this->settings, $this->storage());
		}
		return $this->offloader;
	}

	/**
	 * Shared URL filter layer.
	 */
	public function url_filter(): AttachmentUrlFilter {
		if ($this->url_filter === null) {
			$this->url_filter = new AttachmentUrlFilter(
				$this->settings,
				$this->url_resolver(),
				$this->key_resolver(),
				$this->storage()
			);
		}
		return $this->url_filter;
	}

	/**
	 * Shared local file provider.
	 */
	public function local_file_provider(): LocalFileProvider {
		if ($this->local_file_provider === null) {
			$this->local_file_provider = new LocalFileProvider($this->settings, $this->storage());
		}
		return $this->local_file_provider;
	}

	/**
	 * Shared restorer + delete sync.
	 */
	public function restorer(): AttachmentRestorer {
		if ($this->restorer === null) {
			$this->restorer = new AttachmentRestorer($this->settings, $this->storage());
		}
		return $this->restorer;
	}

	/**
	 * Shared migration service.
	 */
	public function migration_service(): MigrationService {
		if ($this->migration_service === null) {
			$this->migration_service = new MigrationService(
				$this->settings,
				$this->offloader(),
				$this->verification_service()
			);
		}
		return $this->migration_service;
	}

	/**
	 * Shared verification service.
	 */
	public function verification_service(): VerificationService {
		if ($this->verification_service === null) {
			$this->verification_service = new VerificationService($this->settings, $this->storage());
		}
		return $this->verification_service;
	}

	/**
	 * Shared connection test.
	 */
	public function connection_test(): ConnectionTestService {
		if ($this->connection_test === null) {
			$this->connection_test = new ConnectionTestService($this->settings, $this->storage());
		}
		return $this->connection_test;
	}
}
