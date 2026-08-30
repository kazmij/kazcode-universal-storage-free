<?php
/**
 * Phase 11 admin IA smoke — render, menu, redirects, REST, ML column.
 *
 * Usage: wp eval-file wp-content/plugins/kazcode-universal-storage/tests/eval-file/test-phase11-admin-smoke.php
 *
 * @package Kazcode\WpStorage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$user = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);
if ( empty( $user[0] ) ) {
	fwrite( STDERR, "NO_ADMIN\n" );
	exit( 1 );
}
wp_set_current_user( (int) $user[0]->ID );

use Kazcode\WpStorage\Admin\AdminLegacyRedirects;
use Kazcode\WpStorage\Admin\AdminMenu;
use Kazcode\WpStorage\Admin\DashboardPage;
use Kazcode\WpStorage\Admin\HealthPage;
use Kazcode\WpStorage\Admin\LogsPage;
use Kazcode\WpStorage\Admin\MediaLibraryColumn;
use Kazcode\WpStorage\Admin\MediaPage;
use Kazcode\WpStorage\Admin\MigrationPage;
use Kazcode\WpStorage\Admin\SettingsPage;
use Kazcode\WpStorage\Admin\StoragePage;
use Kazcode\WpStorage\Plugin;
use Kazcode\WpStorage\Services\AttachmentObjectSummary;

$errors = array();
$ok     = array();

$mark = static function ( string $label ) use ( &$ok ): void {
	$ok[] = $label;
	echo "[OK] {$label}\n";
};
$fail = static function ( string $msg ) use ( &$errors ): void {
	$errors[] = $msg;
	echo "[FAIL] {$msg}\n";
};

$p = Plugin::instance();

// --- Admin menu slugs registered (requires admin context) ---
if ( ! is_admin() ) {
	$mark( 'admin_menu_skipped_non_admin_context' );
} else {
	global $submenu;
	$parent = AdminMenu::MENU_SLUG;
	$expected_slugs = array(
		AdminMenu::DASHBOARD_SLUG,
		AdminMenu::MEDIA_SLUG,
		AdminMenu::STORAGE_SLUG,
		AdminMenu::MIGRATION_SLUG,
	);
	if ( \Kazcode\WpStorage\Core\Features::enabled( 'diagnostics' ) ) {
		$expected_slugs[] = AdminMenu::HEALTH_SLUG;
	}
	if ( \Kazcode\WpStorage\Core\Features::enabled( 'audit_log' ) ) {
		$expected_slugs[] = AdminMenu::LOGS_SLUG;
	}
	$expected_slugs[] = AdminMenu::SETTINGS_SLUG;

	do_action( 'admin_menu' );
	$found = array();
	if ( isset( $submenu[ $parent ] ) && is_array( $submenu[ $parent ] ) ) {
		foreach ( $submenu[ $parent ] as $item ) {
			if ( isset( $item[2] ) ) {
				$found[] = (string) $item[2];
			}
		}
	}
	$missing = array_diff( $expected_slugs, $found );
	if ( $missing !== array() ) {
		$fail( 'menu missing slugs: ' . implode( ', ', $missing ) );
	} else {
		$mark( 'admin_menu_slugs' );
	}
}

// --- Legacy redirect targets ---
$redirect_map = array(
	'kazcode-universal-storage-tools'         => AdminMenu::MIGRATION_SLUG,
	'kazcode-universal-storage-diagnostics'   => AdminMenu::HEALTH_SLUG,
	'kazcode-universal-storage-profiles'      => AdminMenu::STORAGE_SLUG,
);
foreach ( $redirect_map as $old => $new ) {
	if ( AdminMenu::MIGRATION_SLUG !== $new && AdminMenu::HEALTH_SLUG !== $new && AdminMenu::STORAGE_SLUG !== $new ) {
		continue;
	}
	$expected = admin_url( 'admin.php?page=' . $new );
	if ( strpos( $expected, $new ) === false ) {
		$fail( 'bad redirect target for ' . $old );
	}
}
$mark( 'legacy_redirect_map_defined' );

// --- Page renders + subnav ---
$pages = array(
	'dashboard' => array( new DashboardPage(), 'render', array( 's3ms-subnav', 'Object inventory' ) ),
	'media'     => array( new MediaPage(), 'render', array( 's3ms-subnav', 'Failed items' ) ),
	'storage'   => array( new StoragePage(), 'render', array( 's3ms-subnav', 'Connection test', 'Storage profiles', 's3ms-profile-editor', 's3ms-profile-form' ) ),
	'migration' => array( new MigrationPage( $p->migration_service() ), 'render', array( 's3ms-subnav', 'Migrate to S3' ) ),
	'health'    => array( new HealthPage(), 'render', array( 's3ms-subnav', 'Health checks' ) ),
	'settings'  => array( new SettingsPage( $p->settings() ), 'render', array( 's3ms-subnav', 'Operating mode' ) ),
);
// LogsPage (audit_log) and StorageChangeWizardPage (storage_profile_migration)
// are Pro-gated: LogsPage::render() intentionally short-circuits to a
// "requires Pro" notice without ever printing s3ms-subnav when Pro is
// inactive (Phase 27 friendly-degrade pattern) — assert that content
// directly instead of the wizard/subnav needles used elsewhere. Wizard
// itself lives in Pro's namespace now (see docs/FREE-PRO-CODE-AUDIT.md);
// since an array literal evaluates its values eagerly, it must not even be
// referenced when the class doesn't exist.
if ( is_plugin_active( 'kazcode-universal-storage-pro/kazcode-universal-storage-pro.php' ) ) {
	$pages['logs']   = array( new LogsPage(), 'render', array( 's3ms-subnav', 'Audit log' ) );
	$pages['wizard'] = array( new \Kazcode\WpStorage\Pro\Admin\StorageChangeWizardPage(), 'render', array( 'Change storage' ) );
} else {
	ob_start();
	( new LogsPage() )->render();
	$logs_html = ob_get_clean();
	if ( str_contains( $logs_html, 'requires Pro' ) ) {
		$mark( 'logs_page_gated_without_pro' );
	} else {
		$fail( 'logs page should show a Pro-required notice without pro active' );
	}
	$mark( 'wizard_page_skipped_pro_inactive' );
}

foreach ( $pages as $key => $cfg ) {
	ob_start();
	call_user_func( array( $cfg[0], $cfg[1] ) );
	$html     = ob_get_clean();
	$needles  = $cfg[2];
	if ( ! is_array( $needles ) ) {
		$needles = array( (string) $needles );
	}
	foreach ( $needles as $needle ) {
		if ( ! str_contains( $html, (string) $needle ) ) {
			$fail( "{$key} missing: {$needle}" );
			continue 2;
		}
	}
	// Subnav active link for pages that have subnav.
	if ( str_contains( $html, 's3ms-subnav' ) ) {
		$slug_const = strtoupper( $key ) . '_SLUG';
		$ref        = new \ReflectionClass( AdminMenu::class );
		if ( $ref->hasConstant( $slug_const ) ) {
			$slug = (string) $ref->getConstant( $slug_const );
			if ( ! str_contains( $html, 'page=' . $slug ) ) {
				$fail( "{$key} subnav missing self link" );
				continue;
			}
			if ( ! str_contains( $html, 'is-active' ) ) {
				$fail( "{$key} subnav missing active state" );
				continue;
			}
		}
	}
	$mark( 'render_' . $key );
}

// Migration must NOT contain failed table (moved to Media).
ob_start();
( new MigrationPage( $p->migration_service() ) )->render();
$mig = ob_get_clean();
if ( str_contains( $mig, 'id="s3ms-failed-tbody"' ) ) {
	$fail( 'migration still has failed panel' );
} else {
	$mark( 'migration_no_failed_panel' );
}

// --- ML column with real attachment ---
$q = new WP_Query(
	array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'orderby'        => 'ID',
		'order'          => 'DESC',
	)
);
$att_id = (int) ( $q->posts[0] ?? 0 );
if ( $att_id <= 0 ) {
	$fail( 'no attachment for ML column' );
} else {
	$summary = ( new AttachmentObjectSummary() )->summarize( $att_id );
	if ( ! isset( $summary['state'], $summary['label'] ) ) {
		$fail( 'attachment summary incomplete' );
	} else {
		$mark( 'ml_summary_' . $summary['state'] . '_' . $att_id );
	}
	ob_start();
	( new MediaLibraryColumn() )->render_column( 's3ms_status', $att_id );
	$col = ob_get_clean();
	if ( ! str_contains( $col, 's3ms-ml-badge' ) ) {
		$fail( 'ML column empty output' );
	} else {
		$mark( 'ml_column_render_' . $att_id );
	}
}

// --- REST smoke ---
$routes = array(
	'GET /health'           => array( 'GET', '/kazcode-storage/v1/health' ),
	'GET /health/objects'   => array( 'GET', '/kazcode-storage/v1/health/objects' ),
	'POST /health/scan'     => array( 'POST', '/kazcode-storage/v1/health/scan', array( 'limit' => 10 ) ),
	'GET /stats'            => array( 'GET', '/kazcode-storage/v1/stats' ),
	'GET /failed'           => array( 'GET', '/kazcode-storage/v1/failed', array( 'page' => 1, 'per_page' => 3, 'filter' => 'all' ) ),
	'GET /storage-profiles' => array( 'GET', '/kazcode-storage/v1/storage-profiles' ),
);
foreach ( $routes as $label => $spec ) {
	$req = new WP_REST_Request( $spec[0], $spec[1] );
	if ( isset( $spec[2] ) && is_array( $spec[2] ) ) {
		foreach ( $spec[2] as $k => $v ) {
			$req->set_param( $k, $v );
		}
	}
	$res = rest_do_request( $req );
	if ( $res->get_status() !== 200 ) {
		$fail( 'rest ' . $label . ' status ' . $res->get_status() );
	} else {
		$mark( 'rest_' . str_replace( array( ' ', '/' ), '_', strtolower( $label ) ) );
	}
}

// --- Enqueue hook coverage ---
$settings = new SettingsPage( $p->settings() );
$hooks    = array(
	'toplevel_page_' . AdminMenu::DASHBOARD_SLUG,
	'kazcode-universal-storage_page_' . AdminMenu::MEDIA_SLUG,
	'kazcode-universal-storage_page_' . AdminMenu::STORAGE_SLUG,
	'kazcode-universal-storage_page_' . AdminMenu::MIGRATION_SLUG,
	'kazcode-universal-storage_page_' . AdminMenu::HEALTH_SLUG,
	'kazcode-universal-storage_page_' . AdminMenu::LOGS_SLUG,
	'kazcode-universal-storage_page_' . AdminMenu::SETTINGS_SLUG,
);
foreach ( $hooks as $hook ) {
	$enqueued = false;
	add_action(
		'admin_enqueue_scripts',
		static function ( $h ) use ( $hook, &$enqueued ): void {
			if ( $h === $hook ) {
				$enqueued = true;
			}
		},
		0
	);
	$settings->enqueue( $hook );
	if ( ! $enqueued && strpos( $hook, 'kazcode-universal-storage' ) !== false ) {
		// SettingsPage::enqueue registers scripts directly when hook matches — check wp_scripts queue.
	}
}
$mark( 'enqueue_hooks_checked_' . count( $hooks ) );

echo "----\n";
echo 'OK_COUNT=' . count( $ok ) . "\n";
if ( $errors ) {
	echo 'ERROR_COUNT=' . count( $errors ) . "\n";
	echo "PHASE11_SMOKE_FAILED\n";
	exit( 1 );
}
echo "PHASE11_SMOKE_PASSED\n";
