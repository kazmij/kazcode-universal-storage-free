<?php
/**
 * Integrity Center shell (#27): menu, tabs, overview from existing summaries.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace {
	defined( 'KAZUS_PLUGIN_DIR' ) || define( 'KAZUS_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
	foreach ( array(
		'esc_html_e'  => static function ( $text ) { echo htmlspecialchars( (string) $text, ENT_QUOTES ); },
		'esc_attr_e'  => static function ( $text ) { echo htmlspecialchars( (string) $text, ENT_QUOTES ); },
		'esc_html__'  => static fn( $text ) => (string) $text,
		'esc_attr__'  => static fn( $text ) => (string) $text,
		'esc_html'    => static fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES ),
		'esc_attr'    => static fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES ),
		'esc_url'     => static fn( $url ) => (string) $url,
		'admin_url'   => static fn( $path = '' ) => 'http://example.test/wp-admin/' . ltrim( (string) $path, '/' ),
		'wp_unslash'  => static fn( $value ) => $value,
		'_n'          => static fn( $single, $plural, $number ) => $number === 1 ? $single : $plural,
		'wp_nonce_field' => static function ( $action ) { echo '<input type="hidden" name="_wpnonce" value="nonce-' . htmlspecialchars( (string) $action, ENT_QUOTES ) . '">'; },
	) as $name => $impl ) {
		if ( ! function_exists( $name ) ) {
			$GLOBALS['__kazus_integrity_stub_' . $name] = $impl;
			eval( "function {$name}(...\$a) { return \$GLOBALS['__kazus_integrity_stub_{$name}'](...\$a); }" ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only WordPress shim.
		}
	}
	if ( ! function_exists( 'add_menu_page' ) ) {
		function add_menu_page( $page_title, $menu_title, $capability, $menu_slug ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			$GLOBALS['kazus_test_menu'][ $menu_slug ] = $capability;
			return $menu_slug;
		}
	}
	if ( ! function_exists( 'add_submenu_page' ) ) {
		function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			$GLOBALS['kazus_test_menu'][ $menu_slug ] = $capability;
			return $menu_slug;
		}
	}
}

namespace Kazcode\WpStorage\Tests {

	use Kazcode\WpStorage\Admin\AdminMenu;
	use Kazcode\WpStorage\Admin\DashboardPage;
	use Kazcode\WpStorage\Admin\HealthPage;
	use Kazcode\WpStorage\Admin\IntegrityPage;
	use Kazcode\WpStorage\Admin\LogsPage;
	use Kazcode\WpStorage\Admin\MediaPage;
	use Kazcode\WpStorage\Admin\MigrationPage;
	use Kazcode\WpStorage\Admin\SettingsPage;
	use Kazcode\WpStorage\Admin\SetupWizardPage;
	use Kazcode\WpStorage\Admin\StoragePage;
	use Kazcode\WpStorage\Tests\Support\WpStubs;
	use PHPUnit\Framework\TestCase;

	final class IntegrityPageTest extends TestCase {

		protected function setUp(): void {
			WpStubs::reset();
			$GLOBALS['kazus_test_menu'] = array();
			unset( $_GET['tab'] );
		}

		protected function tearDown(): void {
			unset( $_GET['tab'] );
			WpStubs::reset();
		}

		public function test_integrity_submenu_is_registered_for_administrators(): void {
			$menu = new AdminMenu(
				$this->createMock( DashboardPage::class ),
				$this->createMock( MediaPage::class ),
				$this->createMock( StoragePage::class ),
				$this->createMock( MigrationPage::class ),
				$this->createMock( HealthPage::class ),
				$this->createMock( LogsPage::class ),
				$this->createMock( SettingsPage::class ),
				$this->createMock( SetupWizardPage::class ),
				$this->page( array(), 0 )
			);

			$menu->add_menu();

			$this->assertSame( 'manage_options', $GLOBALS['kazus_test_menu'][ AdminMenu::INTEGRITY_SLUG ] ?? null );
			$this->assertSame( 'kazcode-universal-storage-integrity', AdminMenu::INTEGRITY_SLUG );
		}

		public function test_unknown_tab_falls_back_to_overview(): void {
			$_GET['tab'] = '<script>';
			$this->assertSame( 'overview', $this->page( array(), 0 )->current_tab() );
			$_GET['tab'] = 'rebuild';
			$this->assertSame( 'rebuild', $this->page( array(), 0 )->current_tab() );
		}

		public function test_overview_shows_existing_inventory_summary_and_needs_attention(): void {
			$html = $this->render_private(
				$this->page( array( 'total_objects' => 12, 'present' => 9, 'failed' => 2, 'missing' => 1, 'stale' => 3, 'pending' => 0 ), 2 ),
				'render_overview'
			);

			$this->assertStringContainsString( 'data-integrity-stat="total_objects"', $html );
			$this->assertMatchesRegularExpression( '/data-integrity-stat="present">.*?<strong[^>]*>9</s', $html );
			$this->assertMatchesRegularExpression( '/data-integrity-stat="missing">.*?<strong[^>]*>1</s', $html );
			$this->assertStringContainsString( 'data-integrity-needs-attention="2"', $html );
			$this->assertStringContainsString( 'page=' . AdminMenu::MEDIA_SLUG, $html );
		}

		public function test_clean_inventory_reports_nothing_to_do(): void {
			$html = $this->render_private( $this->page( array( 'total_objects' => 4, 'present' => 4 ), 0 ), 'render_overview' );

			$this->assertStringContainsString( 'Nothing needs attention right now.', $html );
		}

		public function test_orphan_panel_is_a_gated_placeholder_without_pro(): void {
			$html = $this->render_private( $this->page( array(), 0 ), 'render_panel', 'orphans' );

			$this->assertStringContainsString( 'data-integrity-pro-gated', $html );
			$this->assertStringNotContainsString( 'Run the orphan scan', $html );
		}

		public function test_recovery_panels_point_to_dry_run_entry_points(): void {
			$page = $this->page( array(), 0 );

			$this->assertStringContainsString( 'rebuild-inventory --attachment-id=&lt;id&gt; --dry-run', $this->render_private( $page, 'render_panel', 'rebuild' ) );
			$this->assertStringContainsString( 'restore --preflight', $this->render_private( $page, 'render_panel', 'restore' ) );
			$this->assertStringContainsString( 'never recorded as missing', $this->render_private( $page, 'render_verify' ) );
		}

		public function test_verify_panel_offers_only_a_dry_run_until_one_exists(): void {
			$html = $this->render_private( $this->page( array(), 0 ), 'render_verify' );

			$this->assertStringContainsString( 'name="action" value="kazus_integrity_verify"', $html );
			$this->assertStringContainsString( 'data-integrity-dry-run', $html );
			$this->assertStringNotContainsString( 'data-integrity-apply', $html );
		}

		public function test_verify_panel_shows_dry_run_rows_and_apply_count(): void {
			update_option(
				'kazus_integrity_verify_' . get_current_user_id(),
				array(
					'at'     => time(),
					'report' => array(
						'counts'        => array( 'verified' => 1, 'missing' => 1 ),
						'has_more'      => false,
						'next_after_id' => 2,
						'items'         => array(
							array( 'object_id' => 1, 'relative' => '2026/08/a.jpg', 'outcome' => 'verified', 'change' => null ),
							array( 'object_id' => 2, 'relative' => '2026/08/<b>.jpg', 'outcome' => 'missing', 'change' => 'mark_missing' ),
						),
					),
				)
			);

			$html = $this->render_private( $this->page( array(), 0 ), 'render_verify' );

			$this->assertStringContainsString( 'data-integrity-changes="1"', $html );
			$this->assertStringContainsString( 'Apply 1 confirmed change', $html );
			$this->assertStringContainsString( 'mark missing', $html );
			$this->assertStringContainsString( '2026/08/&lt;b&gt;.jpg', $html );
		}

		public function test_repair_panel_lists_repairable_files(): void {
			update_option(
				'kazus_integrity_repair_' . get_current_user_id(),
				array(
					'at'     => time(),
					'report' => array(
						'has_more'      => false,
						'next_after_id' => 5,
						'items'         => array( array( 'object_id' => 5, 'relative' => '2026/08/a.jpg', 'health' => 'remote_missing' ) ),
					),
				)
			);

			$html = $this->render_private( $this->page( array(), 0 ), 'render_repair' );

			$this->assertStringContainsString( 'data-integrity-row="5"', $html );
			$this->assertStringContainsString( 'Repair 1 file', $html );
		}

		public function test_drift_panel_lists_findings_with_post_links_and_no_apply(): void {
			update_option(
				'kazus_integrity_drift_' . get_current_user_id(),
				array(
					'at'     => time(),
					'report' => array(
						'scanned'       => 3,
						'has_more'      => true,
						'next_after_id' => 77,
						'counts'        => array( 'storage' => 0, 'delivery' => 0, 'reference' => 1 ),
						'findings'      => array(
							array(
								'attachment_id'      => 77,
								'relative'           => '2026/08/photo-e1-150x150.jpg',
								'finding'            => 'stale_variant_referenced',
								'category'           => 'reference',
								'storage_profile_id' => 1,
								'object_key'         => 'uploads/2026/08/photo-e1-150x150.jpg',
								'remote_status'      => 'stale',
								'detail'             => 'Post content still references a superseded variant.',
								'next_action'        => 'Re-insert the current image.',
								'post_ids'           => array( 12 ),
							),
						),
					),
				)
			);

			$html = $this->render_private( $this->page( array(), 0 ), 'render_drift' );

			$this->assertStringContainsString( 'data-drift-finding="stale_variant_referenced"', $html );
			$this->assertStringContainsString( 'Old version still used in content', $html );
			$this->assertStringContainsString( 'data-drift-post="12"', $html );
			$this->assertStringContainsString( 'content references: 1', $html );
			$this->assertStringContainsString( 'name="after_id" value="77"', $html );
			$this->assertStringNotContainsString( 'data-integrity-apply', $html );
		}

		/**
		 * @param array<string,int> $stats
		 */
		private function page( array $stats, int $attention ): IntegrityPage {
			return new IntegrityPage( static fn(): array => $stats, static fn(): int => $attention );
		}

		private function render_private( IntegrityPage $page, string $method, string ...$args ): string {
			$reflection = new \ReflectionMethod( $page, $method );
			$reflection->setAccessible( true );
			ob_start();
			$reflection->invoke( $page, ...$args );
			return (string) ob_get_clean();
		}
	}
}
