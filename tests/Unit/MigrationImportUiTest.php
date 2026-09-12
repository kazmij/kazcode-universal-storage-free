<?php
/**
 * Migration page competitor import wizard render tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace {
	if ( ! function_exists( 'current_user_can' ) ) {
		function current_user_can( string $capability ): bool {
			return $capability === 'manage_options';
		}
	}
	if ( ! function_exists( 'admin_url' ) ) {
		function admin_url( string $path = '' ): string {
			return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
		}
	}
	if ( ! function_exists( 'esc_html' ) ) {
		function esc_html( mixed $text ): string {
			return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! function_exists( 'esc_attr' ) ) {
		function esc_attr( mixed $text ): string {
			return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! function_exists( 'esc_url' ) ) {
		function esc_url( mixed $url ): string {
			return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! function_exists( 'esc_html__' ) ) {
		function esc_html__( string $text, string $domain = '' ): string {
			return esc_html( $text );
		}
	}
	if ( ! function_exists( 'esc_attr__' ) ) {
		function esc_attr__( string $text, string $domain = '' ): string {
			return esc_attr( $text );
		}
	}
	if ( ! function_exists( 'esc_html_e' ) ) {
		function esc_html_e( string $text, string $domain = '' ): void {
			echo esc_html( $text );
		}
	}
	if ( ! function_exists( 'esc_attr_e' ) ) {
		function esc_attr_e( string $text, string $domain = '' ): void {
			echo esc_attr( $text );
		}
	}
	if ( ! function_exists( 'wp_kses_post' ) ) {
		function wp_kses_post( mixed $text ): string {
			return (string) $text;
		}
	}
}

namespace Kazcode\WpStorage\Tests {

	use Kazcode\WpStorage\Admin\MigrationPage;
	use Kazcode\WpStorage\Services\MigrationService;
	use Kazcode\WpStorage\Tests\Support\WpStubs;
	use PHPUnit\Framework\TestCase;

	final class MigrationImportUiTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			WpStubs::reset();
			$GLOBALS['s3ms_test_current_user_caps'] = array( 'manage_options' );
		}

		public function test_migration_page_renders_competitor_import_wizard_shell(): void {
			$migration = $this->createMock( MigrationService::class );
			$migration->method( 'stats' )->willReturn(
				array(
					'total'     => 12,
					'offloaded' => 4,
					'pending'   => 6,
					'failed'    => 1,
					'verified'  => 3,
				)
			);

			ob_start();
			( new MigrationPage( $migration ) )->render();
			$html = (string) ob_get_clean();

			$this->assertSame( 1, substr_count( $html, 'Switch from another plugin' ) );
			$this->assertStringContainsString( 'id="s3ms-import-source-wrap"', $html );
			$this->assertStringContainsString( 'id="s3ms-import-profile"', $html );
			$this->assertStringContainsString( 'data-s3ms-import-action="analyze"', $html );
			$this->assertMatchesRegularExpression( '/data-s3ms-import-action="commit"[^>]*disabled/', $html );
			foreach ( array( 'Attachments scanned', 'Objects checked', 'Verified / adopted', 'Confirmed missing', 'Transient errors', 'Conflicts', 'Skipped', 'Updated' ) as $label ) {
				$this->assertStringContainsString( $label, $html );
			}
		}

		public function test_import_source_selector_starts_empty_until_detect_populates_it(): void {
			$migration = $this->createMock( MigrationService::class );
			$migration->method( 'stats' )->willReturn(
				array(
					'total'     => 0,
					'offloaded' => 0,
					'pending'   => 0,
					'failed'    => 0,
					'verified'  => 0,
				)
			);

			ob_start();
			( new MigrationPage( $migration ) )->render();
			$html = (string) ob_get_clean();

			$this->assertMatchesRegularExpression( '/<select[^>]+id="s3ms-import-source"[^>]*>\s*<option value="">Detect sources first<\/option>\s*<\/select>/s', $html );
			$this->assertStringNotContainsString( 'value="wp-offload-media"', $html );
			$this->assertStringNotContainsString( 'value="advanced-media-offloader"', $html );
		}
	}
}
