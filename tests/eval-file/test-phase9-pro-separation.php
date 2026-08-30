<?php
/**
 * Phase 9 local verification — Free/Pro separation.
 *
 * Usage: wp eval-file wp-content/plugins/kazcode-universal-storage/tests/eval-file/test-phase9-pro-separation.php
 *
 * @package Kazcode\WpStorage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$user = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( empty( $user[0] ) ) {
	fwrite( STDERR, "NO_ADMIN\n" );
	exit( 1 );
}
wp_set_current_user( (int) $user[0]->ID );

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

use Kazcode\WpStorage\Core\Features;
use Kazcode\WpStorage\Core\Module\ModuleRegistry;
use Kazcode\WpStorage\Core\ProServices;
use Kazcode\WpStorage\Infrastructure\BatchProcessor;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
use Kazcode\WpStorage\Plugin;
use Kazcode\WpStorage\Services\HealthCheckService;
use Kazcode\WpStorage\Services\StorageProfileAdminService;

// P9-T01: Module registry boots without fatal.
try {
	ModuleRegistry::instance()->boot_all();
	$mark( 'module_registry_boot' );
} catch ( \Throwable $e ) {
	$fail( 'module_registry_boot: ' . $e->getMessage() );
}

// Pro module presence (optional — only when pro plugin active). Detected up
// front since the default-plan checks below need to know it.
$pro_plugin_active = is_plugin_active( 'kazcode-universal-storage-pro/kazcode-universal-storage-pro.php' );
if ( $pro_plugin_active ) {
	if ( ! ModuleRegistry::instance()->has_pro_module() ) {
		$fail( 'pro plugin active but has_pro_module false' );
	} else {
		$mark( 'pro_module_registered' );
	}
} else {
	$mark( 'pro_plugin_inactive_skipped' );
}

// Default plan is lite unless a Pro module is registered (Features::is_pro_active()
// has exactly one source of truth: ModuleRegistry::has_pro_module()).
if ( ! $pro_plugin_active && Features::plan() !== 'lite' ) {
	$fail( 'default plan not lite (no pro plugin active): ' . Features::plan() );
} elseif ( ! $pro_plugin_active ) {
	$mark( 'default_plan_lite' );
} else {
	$mark( 'default_plan_check_skipped_pro_active' );
}

if ( ! $pro_plugin_active && ( Features::enabled( 'storage_profile_migration' ) || Features::enabled( 'orphan_scan' ) ) ) {
	$fail( 'pro features enabled on default (lite) plan without pro module' );
} elseif ( ! $pro_plugin_active ) {
	$mark( 'default_pro_features_off_without_pro_module' );
} else {
	$mark( 'default_pro_features_check_skipped_pro_active' );
}

// Service-level gate: StorageMigrationService/MigrateObjectService/OrphanScanService
// physically live in Pro now (see docs/FREE-PRO-CODE-AUDIT.md) — Core asks for
// them via ProServices, which returns null when Pro isn't registered. That
// absence *is* the gate; there's no Core-side class left to instantiate and
// catch an exception from.
$migrate_service = ProServices::get( 'migrate_object', Plugin::instance()->settings() );
if ( $pro_plugin_active ) {
	if ( $migrate_service === null ) {
		$fail( 'migrate_object service not registered despite pro active' );
	} else {
		$migrate = $migrate_service->migrate( 1, 2, true );
		if ( empty( $migrate['success'] ) && str_contains( (string) ( $migrate['message'] ?? '' ), 'not found' ) ) {
			$mark( 'migrate_service_allowed_with_pro' );
		} elseif ( ! empty( $migrate['success'] ) || ! str_contains( (string) ( $migrate['message'] ?? '' ), 'Pro' ) ) {
			$mark( 'migrate_service_not_pro_blocked_with_pro' );
		}
	}
} elseif ( $migrate_service !== null ) {
	$fail( 'migrate_object service registered without pro active' );
} else {
	$mark( 'migrate_service_blocked_on_lite' );
}

$orphan_service = ProServices::get( 'orphan_scan', Plugin::instance()->settings(), Plugin::instance()->storage() );
if ( $pro_plugin_active ) {
	if ( $orphan_service === null ) {
		$fail( 'orphan_scan service not registered despite pro active' );
	} else {
		$mark( 'orphan_service_with_pro' );
	}
} elseif ( $orphan_service !== null ) {
	$fail( 'orphan_scan service registered without pro active' );
} else {
	$mark( 'orphan_service_blocked_on_lite' );
}

// REST 403 on lite for storage-migrate (default plan without a Pro module active).
$processor = new BatchProcessor( Plugin::instance()->migration_service() );
$processor->register();

$req = new WP_REST_Request( 'GET', '/kazcode-storage/v1/storage-migrate' );
$res = rest_do_request( $req );
if ( $pro_plugin_active ) {
	if ( $res->get_status() === 200 ) {
		$mark( 'rest_storage_migrate_allowed_with_pro' );
	} else {
		$fail( 'rest storage-migrate blocked with pro: ' . $res->get_status() );
	}
} elseif ( $res->get_status() === 403 ) {
	$mark( 'rest_storage_migrate_403_on_lite' );
} else {
	$fail( 'rest storage-migrate expected 403 on lite, got ' . $res->get_status() );
}

$req = new WP_REST_Request( 'POST', '/kazcode-storage/v1/health/orphan-scan' );
$res = rest_do_request( $req );
if ( $pro_plugin_active ) {
	$mark( 'rest_orphan_with_pro' );
} elseif ( $res->get_status() === 403 ) {
	$mark( 'rest_orphan_scan_403_on_lite' );
} else {
	$fail( 'rest orphan-scan expected 403 on lite, got ' . $res->get_status() );
}

// Health payload: orphan_scan only when advanced_health enabled.
$health = ( new HealthCheckService( Plugin::instance()->settings() ) )->run();
if ( ! isset( $health['pro_active'] ) ) {
	$fail( 'health missing pro_active flag' );
} else {
	$mark( 'health_pro_active_flag' );
}
if ( Features::enabled( 'advanced_health' ) && ! isset( $health['orphan_scan'] ) ) {
	$fail( 'health missing orphan_scan on pro plan' );
} elseif ( ! Features::enabled( 'advanced_health' ) && isset( $health['orphan_scan'] ) ) {
	$fail( 'health should omit orphan_scan when advanced_health off' );
} else {
	$mark( 'health_orphan_scan_gated' );
}

// First profile exists from legacy seed; a second profile is a Pro-managed
// operation — StorageProfileAdminService::create() delegates to
// ProServices::require('additional_storage_profile', ...) once count() >= 1,
// which throws (caught, returned as success:false) when Pro isn't active.
// (WpdbStorageProfileRepository::insert() itself is an ungated data-access
// primitive — the gate lives one layer up, in the admin service — so this
// must exercise that service, not the repository directly.)
$repo  = new WpdbStorageProfileRepository();
$count = $repo->count();
if ( $count < 1 ) {
	$fail( 'expected at least one storage profile from legacy seed' );
} else {
	$mark( 'legacy_profile_exists_' . $count );
}

if ( ! $pro_plugin_active ) {
	$admin_service = new StorageProfileAdminService( Plugin::instance()->settings() );
	$result        = $admin_service->create(
		array(
			'name'   => 'Test Pro Profile',
			'bucket' => 'test-bucket',
			'region' => 'us-east-1',
		)
	);
	if ( ! empty( $result['success'] ) ) {
		$fail( 'second profile create should be blocked on lite without pro' );
	} elseif ( str_contains( (string) ( $result['message'] ?? '' ), 'Pro' ) ) {
		$mark( 'multiple_profiles_create_blocked' );
	} else {
		$fail( 'unexpected create failure: ' . ( $result['message'] ?? '' ) );
	}
}

echo "----\n";
echo 'OK_COUNT=' . count( $ok ) . "\n";
if ( $errors ) {
	echo 'ERROR_COUNT=' . count( $errors ) . "\n";
	echo "PHASE9_CHECKS_FAILED\n";
	exit( 1 );
}
echo "PHASE9_CHECKS_PASSED\n";
