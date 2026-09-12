<?php
/**
 * Uninstall preserve/purge semantics tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace {
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $transient ): bool {
		unset( \Kazcode\WpStorage\Tests\Support\WpStubs::$options[ '_transient_' . $transient ] );
		return true;
	}
}

if ( ! function_exists( 'delete_site_option' ) ) {
	function delete_site_option( string $option ): bool {
		unset( \Kazcode\WpStorage\Tests\Support\WpStubs::$site_options[ $option ] );
		unset( \Kazcode\WpStorage\Tests\Support\WpStubs::$options[ $option ] );
		return true;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( string $hook ): int|false {
		unset( \Kazcode\WpStorage\Tests\Support\WpStubs::$scheduled_hooks[ $hook ] );
		return 1;
	}
}

if ( ! function_exists( 'delete_metadata' ) ) {
	function delete_metadata( string $meta_type, int $object_id, string $meta_key, mixed $meta_value = '', bool $delete_all = false ): bool {
		return true;
	}
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', true );
}
}

namespace Kazcode\WpStorage\Tests {

use Kazcode\WpStorage\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class UninstallSemanticsTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
		$GLOBALS['wpdb'] = new UninstallWpdbStub();
		if ( ! function_exists( 'kazus_uninstall_run_for_current_site' ) ) {
			require_once dirname( __DIR__, 2 ) . '/uninstall.php';
		}
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		WpStubs::reset();
	}

	public function test_preserve_mode_clears_ephemeral_state_but_keeps_durable_v2_tables_and_records(): void {
		WpStubs::$options = array(
			's3ms_settings'              => array( 'delete_data_on_uninstall' => false ),
			's3ms_schema_version'       => 2,
			's3ms_pending_jobs'         => array( 'job' ),
			'kazus_background_tick_lease' => 'leased',
			's3ms_transfer_session_lease' => 'leased',
			's3ms_audit_log'            => array( array( 'action' => 'kept' ) ),
		);
		WpStubs::$scheduled_hooks = array(
			'kazus_background_tick'       => 100,
			'kazus_stale_transfer_cleanup' => 200,
		);

		\kazus_uninstall_run_for_current_site();

		$this->assertArrayNotHasKey( 's3ms_pending_jobs', WpStubs::$options );
		$this->assertArrayNotHasKey( 'kazus_background_tick_lease', WpStubs::$options );
		$this->assertArrayNotHasKey( 's3ms_transfer_session_lease', WpStubs::$options );
		$this->assertArrayNotHasKey( 'kazus_background_tick', WpStubs::$scheduled_hooks );
		$this->assertArrayNotHasKey( 'kazus_stale_transfer_cleanup', WpStubs::$scheduled_hooks );
		$this->assertSame( 2, WpStubs::$options['s3ms_schema_version'] );
		$this->assertSame( array( array( 'action' => 'kept' ) ), WpStubs::$options['s3ms_audit_log'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->dropped_tables );
		$this->assertSame( array(), $GLOBALS['wpdb']->remote_calls );
	}

	public function test_purge_mode_drops_legacy_and_v2_tables_without_remote_calls(): void {
		WpStubs::$options = array(
			's3ms_settings'        => array( 'delete_data_on_uninstall' => true ),
			's3ms_schema_version' => 2,
			's3ms_audit_log'      => array( array( 'action' => 'purge' ) ),
		);

		\kazus_uninstall_run_for_current_site();

		$this->assertSame(
			array(
				'wp_s3ms_objects',
				'wp_s3ms_storage_profiles',
				'wp_s3ms_transfer_sessions',
				'wp_s3ms_import_runs',
			),
			$GLOBALS['wpdb']->dropped_tables
		);
		$this->assertArrayNotHasKey( 's3ms_schema_version', WpStubs::$options );
		$this->assertArrayNotHasKey( 's3ms_audit_log', WpStubs::$options );
		$this->assertSame( array(), $GLOBALS['wpdb']->remote_calls );
	}
}

final class UninstallWpdbStub {
	public string $prefix = 'wp_';
	public string $options = 'wp_options';
	public string $postmeta = 'wp_postmeta';
	/** @var list<string> */
	public array $dropped_tables = array();
	/** @var list<string> */
	public array $remote_calls = array();
	/** @var list<array<string,mixed>> */
	public array $deletes = array();

	public function query( string $sql ): int|false {
		if ( preg_match( '/DROP TABLE IF EXISTS ([a-zA-Z0-9_]+)/', $sql, $matches ) ) {
			$this->dropped_tables[] = $matches[1];
		}
		return 1;
	}

	/** @param array<string,mixed> $where */
	public function delete( string $table, array $where ): int|false {
		$this->deletes[] = compact( 'table', 'where' );
		return 1;
	}
}
}
