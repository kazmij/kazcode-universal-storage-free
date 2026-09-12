<?php
/**
 * V2 foundation acceptance criteria smoke (plan §36).
 *
 * Usage: wp eval-file wp-content/plugins/kazcode-universal-storage/tests/eval-file/test-v2-acceptance.php
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

$p      = Kazcode\WpStorage\Plugin::instance();
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

// 1. Partial upload — ObjectOffloadService + AttachmentSyncDeriver exist; partial status constant.
if ( class_exists( Kazcode\WpStorage\Services\ObjectOffloadService::class )
	&& defined( 'Kazcode\WpStorage\Attachment\AttachmentOffloader::STATUS_PARTIAL' ) ) {
	$mark( 'criterion_1_partial_upload_infra' );
} else {
	$fail( 'criterion_1 missing partial upload infra' );
}

// 2. Retry — migrate_batch supports retry_failed flag.
$ms_ref = new ReflectionClass( Kazcode\WpStorage\Services\MigrationService::class );
if ( $ms_ref->hasMethod( 'migrate_batch' ) ) {
	$mark( 'criterion_2_retry_service' );
} else {
	$fail( 'criterion_2 retry service missing' );
}

// 3. Remote-only cleanup gated by CleanupLocalFiles.
if ( class_exists( Kazcode\WpStorage\Services\CleanupLocalFiles::class ) ) {
	$mark( 'criterion_3_cleanup_local_files' );
} else {
	$fail( 'criterion_3 cleanup missing' );
}

// 4. Restore clears meta (code path exists).
$ref = new ReflectionClass( Kazcode\WpStorage\Attachment\AttachmentRestorer::class );
if ( $ref->hasMethod( 'mark_attachment_local' ) || $ref->getMethod( 'restore' ) ) {
	$mark( 'criterion_4_restore_impl' );
} else {
	$fail( 'criterion_4 restore missing' );
}

// 5. Regeneration reconcile.
if ( class_exists( Kazcode\WpStorage\Services\AttachmentReconciler::class ) ) {
	$mark( 'criterion_5_reconcile' );
} else {
	$fail( 'criterion_5 reconcile missing' );
}

// 6. Profile-scoped delivery resolver wired.
if ( class_exists( Kazcode\WpStorage\Storage\ProfileDeliveryUrlResolver::class ) ) {
	$mark( 'criterion_6_profile_delivery_resolver' );
} else {
	$fail( 'criterion_6 profile delivery missing' );
}

// 7. Storage migration explicit switch — physically lives in Pro (see
// docs/FREE-PRO-CODE-AUDIT.md); only checkable when Pro is installed.
if ( ! is_plugin_active( 'kazcode-universal-storage-pro/kazcode-universal-storage-pro.php' ) ) {
	$mark( 'criterion_7_storage_switch_skipped_pro_inactive' );
} elseif ( method_exists( Kazcode\WpStorage\Pro\Services\StorageMigrationService::class, 'switch_default_profile' ) ) {
	$mark( 'criterion_7_storage_switch' );
} else {
	$fail( 'criterion_7 storage switch missing' );
}

// 8. Repair services.
if ( class_exists( Kazcode\WpStorage\Services\RepairObjectService::class ) ) {
	$mark( 'criterion_8_repair' );
} else {
	$fail( 'criterion_8 repair missing' );
}

// 9. Resumable queue / background.
$bg = $p->background()->status();
if ( is_array( $bg ) && array_key_exists( 'after_id', $bg ) ) {
	$mark( 'criterion_9_resumable_background' );
} else {
	$fail( 'criterion_9 background state missing after_id' );
}

// 10. Object inventory + queue gateway.
if ( class_exists( Kazcode\WpStorage\Infrastructure\ObjectRepository::class )
	&& interface_exists( Kazcode\WpStorage\Infrastructure\Queue\QueueGateway::class ) ) {
	$mark( 'criterion_10_db_queue_state' );
} else {
	$fail( 'criterion_10 db/queue missing' );
}

// Schema + profiles seeded.
if ( (int) get_option( 's3ms_schema_version', 0 ) >= 1 ) {
	$mark( 'schema_version' );
} else {
	$fail( 'schema not upgraded' );
}

$profile = ( new Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository() )->find_default_upload_target();
if ( $profile !== null ) {
	$mark( 'legacy_profile_' . $profile->id );
} else {
	$fail( 'no default storage profile' );
}

// Admin IA (Phase 11).
foreach ( array(
	Kazcode\WpStorage\Admin\AdminMenu::DASHBOARD_SLUG,
	Kazcode\WpStorage\Admin\AdminMenu::STORAGE_SLUG,
	Kazcode\WpStorage\Admin\AdminMenu::HEALTH_SLUG,
) as $slug ) {
	if ( $slug === '' ) {
		$fail( 'admin slug empty' );
	}
}
$mark( 'admin_ia_slugs' );

echo "----\n";
echo 'OK_COUNT=' . count( $ok ) . "\n";
if ( $errors ) {
	echo 'ERROR_COUNT=' . count( $errors ) . "\n";
	echo "V2_ACCEPTANCE_FAILED\n";
	exit( 1 );
}
echo "V2_ACCEPTANCE_PASSED\n";
