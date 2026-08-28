<?php
/**
 * Local verification script for KAZCODE Universal Storage v1.1 product features.
 *
 * Usage: wp eval-file wp-content/plugins/kazcode-universal-storage/test-product-features-local.php
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

// Settings keys.
$s = $p->settings()->all();
foreach ( array( 'provider_preset', 'credential_mode', 'private_media', 'signed_url_ttl', 'background_batch_size', 'compat_gutenberg' ) as $k ) {
	if ( ! array_key_exists( $k, $s ) ) {
		$fail( "missing setting {$k}" );
	}
}
$mark( 'settings_keys' );

if ( ! $p->settings()->is_aws_configured() ) {
	$fail( 'aws not configured' );
} else {
	$mark( 'aws_configured' );
}

$clean = $p->settings()->sanitize(
	array(
		'credential_mode' => 'iam_role',
		'bucket'          => 'b',
		'region'          => 'us-east-1',
		'access_key_id'   => '',
	),
	$s
);
if ( ( $clean['credential_mode'] ?? '' ) !== 'iam_role' ) {
	$fail( 'credential_mode sanitize fail' );
} else {
	$mark( 'iam_sanitize' );
}

// Wizard partial must not wipe enabled/serve when those keys are absent.
$partial = $p->settings()->sanitize(
	array(
		'provider_preset' => 'r2',
		'bucket'          => (string) $s['bucket'],
		'region'          => (string) $s['region'],
	),
	array_merge(
		$s,
		array(
			'enabled'       => true,
			'serve_from_s3' => true,
			'keep_local_files' => true,
		)
	)
);
if ( empty( $partial['enabled'] ) || empty( $partial['serve_from_s3'] ) || empty( $partial['keep_local_files'] ) ) {
	$fail( 'wizard partial sanitize wiped booleans' );
} elseif ( ( $partial['provider_preset'] ?? '' ) !== 'r2' ) {
	$fail( 'wizard partial did not set preset' );
} else {
	$mark( 'wizard_partial_sanitize' );
}

// Full form marker must clear unchecked booleans.
$full = $p->settings()->sanitize(
	array(
		'_s3ms_full_form' => '1',
		'bucket'          => 'keep-bucket',
		'region'          => 'us-east-1',
		'access_key_id'   => 'AKIATEST',
	),
	array_merge( $s, array( 'enabled' => true, 'serve_from_s3' => true ) )
);
if ( ! empty( $full['enabled'] ) || ! empty( $full['serve_from_s3'] ) ) {
	$fail( 'full form sanitize did not clear absent checkboxes' );
} else {
	$mark( 'full_form_sanitize' );
}

// Default plan is lite on a clean core-only install (no KAZUS_PLAN, no Pro
// module) — this eval-file runs against core alone, so Pro-tier features must
// be off by default, not on.
if ( Kazcode\WpStorage\Core\Features::plan() !== 'lite' ) {
	$fail( 'plan not lite (expected default on core-only install): ' . Kazcode\WpStorage\Core\Features::plan() );
}
foreach ( array( 'background_migrate', 'private_media', 'audit_log', 'multisite_network', 'multiple_profiles', 'storage_profile_migration', 'orphan_scan', 'advanced_health' ) as $f ) {
	if ( Kazcode\WpStorage\Core\Features::enabled( $f ) ) {
		$fail( "feature unexpectedly on for lite default: {$f}" );
	}
}
$mark( 'features_lite_default' );

// These moved to Free (basic health, native Media Library integration, first-run
// wizard, failed-items dashboard) — they must stay on even without Pro.
foreach ( array( 'failed_dashboard', 'media_library_actions', 'diagnostics', 'setup_wizard' ) as $f ) {
	if ( ! Kazcode\WpStorage\Core\Features::enabled( $f ) ) {
		$fail( "free-tier feature unexpectedly off on lite default: {$f}" );
	}
}
$mark( 'free_tier_features_on' );

$presets = Kazcode\WpStorage\Core\ProviderPresets::all();
if ( count( $presets ) < 5 ) {
	$fail( 'presets few' );
} else {
	$mark( 'presets_' . count( $presets ) );
}

$pol     = ( new Kazcode\WpStorage\Services\AwsAssistant() )->build( (string) $s['bucket'], (string) $s['object_prefix'] );
$decoded = json_decode( $pol['policy'], true );
if ( ! is_array( $decoded ) || empty( $decoded['Statement'] ) ) {
	$fail( 'bad IAM policy json' );
} else {
	$mark( 'aws_assistant' );
}

$health = ( new Kazcode\WpStorage\Services\HealthCheckService( $p->settings() ) )->run();
$conn   = null;
foreach ( $health['checks'] as $c ) {
	if ( ( $c['id'] ?? '' ) === 'connection' ) {
		$conn = $c;
	}
}
if ( ! $conn || ( $conn['status'] ?? '' ) !== 'ok' ) {
	$fail( 'health connection not ok: ' . wp_json_encode( $conn ) );
} else {
	$mark( 'health_connection' );
}

$allf = $p->failed_items()->list( 1, 10, 'all' );
$miss = $p->failed_items()->list( 1, 10, 'missing_local' );
if ( (int) $allf['total'] < 1 ) {
	$fail( 'expected failed items' );
} else {
	$mark( 'failed_list_total_' . (int) $allf['total'] . '_miss_' . count( $miss['items'] ) );
}

$id = (int) ( $allf['items'][0]['id'] ?? 0 );
if ( $id > 0 ) {
	$p->failed_items()->set_ignored( array( $id ), true );
	$row = $p->failed_items()->row( $id );
	if ( empty( $row['ignored'] ) ) {
		$fail( 'ignore failed' );
	}
	$p->failed_items()->set_ignored( array( $id ), false );
	$row = $p->failed_items()->row( $id );
	if ( ! empty( $row['ignored'] ) ) {
		$fail( 'unignore failed' );
	} else {
		$mark( 'ignore_roundtrip_' . $id );
	}
}

$csv = $p->failed_items()->to_csv( 5 );
if ( strpos( $csv, 'id' ) !== 0 ) {
	$fail( 'csv bad: ' . substr( $csv, 0, 80 ) );
} else {
	$mark( 'csv_len_' . strlen( $csv ) );
}

$q   = new WP_Query(
	array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'meta_key'       => '_s3ms_status',
		'meta_value'     => 'offloaded',
	)
);
$oid = (int) ( $q->posts[0] ?? 0 );
if ( ! $oid ) {
	$fail( 'no offloaded attachment' );
} else {
	$file = (string) get_post_meta( $oid, '_wp_attached_file', true );
	$url  = $p->storage()->presigned_url_for_relative( $file, 300 );
	if ( $url === '' || strpos( $url, 'http' ) !== 0 ) {
		$fail( 'presign empty' );
	} else {
		$qs = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( $qs === '' ) {
			$fail( 'presign no query: ' . $url );
		} else {
			$mark( 'presign_' . $oid );
		}
	}

	$pub = (string) wp_get_attachment_url( $oid );
	if ( strpos( $pub, 'amazonaws.com' ) === false && strpos( $pub, 's3.' ) === false ) {
		$fail( 'attachment url not S3: ' . $pub );
	} else {
		$mark( 'serve_url' );
	}
}

$bg = $p->background()->status();
if ( ! empty( $bg['running'] ) ) {
	$p->background()->stop();
}
$start = $p->background()->start( 'migrate' );
if ( is_wp_error( $start ) ) {
	$fail( 'bg start: ' . $start->get_error_message() );
} else {
	$st = $p->background()->status();
	if ( empty( $st['running'] ) || ( $st['action'] ?? '' ) !== 'migrate' ) {
		$fail( 'bg not running after start' );
	} else {
		$p->background()->stop();
		$st2 = $p->background()->status();
		if ( ! empty( $st2['running'] ) ) {
			$fail( 'bg still running after stop' );
		} else {
			$mark( 'background_start_stop' );
		}
	}
}

$log = $p->audit_log()->latest( 5 );
if ( ! $log ) {
	$fail( 'audit empty after bg' );
} else {
	$mark( 'audit_' . implode( ',', array_map( static fn( $r ) => (string) ( $r['action'] ?? '' ), $log ) ) );
}

ob_start();
( new Kazcode\WpStorage\Admin\SettingsPage( $p->settings() ) )->render();
$h = ob_get_clean();
foreach ( array( 'Provider preset', 'Credential mode', 'Private media', 'Operating mode' ) as $needle ) {
	if ( ! str_contains( $h, $needle ) ) {
		$fail( 'settings missing: ' . $needle );
	}
}
$mark( 'settings_render' );

ob_start();
( new Kazcode\WpStorage\Admin\DashboardPage() )->render();
$h = ob_get_clean();
foreach ( array( 'Object inventory', 'Background queue', 'Recent failures' ) as $needle ) {
	if ( ! str_contains( $h, $needle ) ) {
		$fail( 'dashboard missing: ' . $needle );
	}
}
$mark( 'dashboard_render' );

ob_start();
( new Kazcode\WpStorage\Admin\MediaPage() )->render();
$h = ob_get_clean();
if ( ! str_contains( $h, 'Failed items' ) ) {
	$fail( 'media missing failed panel' );
} else {
	$mark( 'media_render' );
}

ob_start();
( new Kazcode\WpStorage\Admin\StoragePage() )->render();
$h = ob_get_clean();
foreach ( array( 'Storage profiles', 'Connection test', 'Public delivery' ) as $needle ) {
	if ( ! str_contains( $h, $needle ) ) {
		$fail( 'storage missing: ' . $needle );
	}
}
$mark( 'storage_render' );

ob_start();
( new Kazcode\WpStorage\Admin\MigrationPage( $p->migration_service() ) )->render();
$h = ob_get_clean();
foreach ( array( 'Background jobs', 'Migrate to S3' ) as $needle ) {
	if ( ! str_contains( $h, $needle ) ) {
		$fail( 'migration missing: ' . $needle );
	}
}
if ( stripos( $h, 'theme' ) === false ) {
	$fail( 'migration missing theme notice' );
}
$mark( 'migration_render' );

ob_start();
( new Kazcode\WpStorage\Admin\HealthPage() )->render();
$h = ob_get_clean();
foreach ( array( 'Health checks', 'Object inventory', 'AWS setup assistant' ) as $needle ) {
	if ( ! str_contains( $h, $needle ) ) {
		$fail( 'health missing: ' . $needle );
	}
}
$mark( 'health_render' );

ob_start();
( new Kazcode\WpStorage\Admin\LogsPage() )->render();
$h = ob_get_clean();
if ( ! str_contains( $h, 'Audit log' ) ) {
	$fail( 'logs missing audit' );
} else {
	$mark( 'logs_render' );
}

ob_start();
( new Kazcode\WpStorage\Admin\SetupWizardPage( $p->settings() ) )->render();
$h = ob_get_clean();
if ( ! str_contains( $h, 'Choose provider' ) ) {
	$fail( 'wizard step1 missing' );
} else {
	$mark( 'wizard_render' );
}

$routes = rest_get_server()->get_routes();
foreach ( array( '/kazcode-storage/v1/failed', '/kazcode-storage/v1/background', '/kazcode-storage/v1/health', '/kazcode-storage/v1/audit', '/kazcode-storage/v1/stats' ) as $r ) {
	if ( ! isset( $routes[ $r ] ) ) {
		$fail( 'missing route ' . $r );
	}
}
$mark( 'rest_routes' );

$req = new WP_REST_Request( 'GET', '/kazcode-storage/v1/failed' );
$req->set_param( 'page', 1 );
$req->set_param( 'per_page', 3 );
$req->set_param( 'filter', 'all' );
$res = rest_do_request( $req );
if ( $res->get_status() !== 200 ) {
	$fail( 'rest failed status ' . $res->get_status() );
} else {
	$data = $res->get_data();
	if ( empty( $data['items'] ) ) {
		$fail( 'rest failed empty' );
	} else {
		$mark( 'rest_failed' );
	}
}

foreach ( array( '/kazcode-storage/v1/health', '/kazcode-storage/v1/background', '/kazcode-storage/v1/audit' ) as $path ) {
	$req = new WP_REST_Request( 'GET', $path );
	$res = rest_do_request( $req );
	if ( $res->get_status() !== 200 ) {
		$fail( 'rest ' . $path . ' status ' . $res->get_status() );
	} else {
		$mark( 'rest_' . basename( $path ) );
	}
}

$cols_probe = new Kazcode\WpStorage\Admin\MediaLibraryColumn();
$cols_probe->register();
$cols = apply_filters( 'manage_media_columns', array( 'title' => 'File' ) );
if ( ! isset( $cols['s3ms_status'] ) ) {
	$fail( 'media column missing' );
} else {
	$mark( 'media_column' );
}

// From inside PHP container localhost:8080 is unreachable — use nginx service host.
$home_check_url = 'http://nginx/';
$html           = (string) wp_remote_retrieve_body(
	wp_remote_get(
		$home_check_url,
		array(
			'timeout' => 20,
			'headers' => array( 'Host' => 'localhost' ),
		)
	)
);
if ( $html === '' ) {
	$html = (string) shell_exec( 'curl -sL --max-time 20 http://nginx/ 2>/dev/null' );
}
$bucket_host_pattern = preg_quote( (string) $s['bucket'], '#' ) . '\.s3\.amazonaws\.com';
$s3n                 = preg_match_all( '#' . $bucket_host_pattern . '#', $html );
$mark( 'home_s3_' . $s3n );
if ( $s3n < 1 ) {
	$fail( 'homepage has no S3 media urls for the configured bucket (html_len=' . strlen( $html ) . ')' );
}

echo "----\n";
echo 'OK_COUNT=' . count( $ok ) . "\n";
if ( $errors ) {
	echo 'ERROR_COUNT=' . count( $errors ) . "\n";
	echo "ALL_CHECKS_FAILED\n";
	exit( 1 );
}
echo "ALL_CHECKS_PASSED\n";
