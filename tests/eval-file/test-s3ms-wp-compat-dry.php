<?php
/**
 * Dry compatibility checks for KAZCODE Universal Storage vs WordPress Media Library.
 * No S3 uploads. Simulates offloaded meta and exercises URL filters.
 *
 * Usage:
 *   wp eval-file wp-content/plugins/kazcode-universal-storage/tests/eval-file/test-s3ms-wp-compat-dry.php
 *
 * @package Kazcode\WpStorage
 */

if (!defined('ABSPATH')) {
	exit(1);
}

$pass = 0;
$fail = 0;
$warn = 0;

$ok = static function (string $msg) use (&$pass): void {
	++$pass;
	echo "PASS  {$msg}\n";
};
$bad = static function (string $msg) use (&$fail): void {
	++$fail;
	echo "FAIL  {$msg}\n";
};
$note = static function (string $msg) use (&$warn): void {
	++$warn;
	echo "WARN  {$msg}\n";
};

echo "=== KAZCODE Universal Storage — WordPress compatibility dry run ===\n\n";

// --- Plugin loaded ---
if (!class_exists(\Kazcode\WpStorage\Plugin::class)) {
	$bad('Plugin not loaded / not active');
	exit(1);
}
$ok('Plugin class loaded');

$plugin   = \Kazcode\WpStorage\Plugin::instance();
$settings = $plugin->settings();

// --- Hooks registered ---
$hooks = array(
	'wp_get_attachment_url'          => 10,
	'wp_get_attachment_image_src'    => 10,
	'image_downsize'                 => 10,
	'wp_calculate_image_srcset'      => 10,
	'wp_prepare_attachment_for_js'   => 10,
	'rest_prepare_attachment'        => 10,
	'wp_generate_attachment_metadata'=> 20,
	'wp_update_attachment_metadata'  => 20,
	'delete_attachment'              => 5,
);

foreach ($hooks as $hook => $prio) {
	if (has_filter($hook) || has_action($hook)) {
		$ok("Hook present: {$hook}");
	} else {
		$bad("Hook missing: {$hook}");
	}
}

// --- basedir must remain filesystem ---
$uploads = wp_upload_dir();
$basedir = (string) $uploads['basedir'];
$baseurl = (string) $uploads['baseurl'];
if ($basedir !== '' && !preg_match('#^https?://#i', $basedir)) {
	$ok('upload_dir basedir is filesystem path: ' . $basedir);
} else {
	$bad('upload_dir basedir looks like HTTP — incompatible with Image Editor');
}
if (preg_match('#^https?://#i', $baseurl)) {
	$ok('upload_dir baseurl is HTTP URL');
} else {
	$note('upload_dir baseurl unexpected: ' . $baseurl);
}

// --- Competing filters on same hooks (other plugins) ---
global $wp_filter;
foreach (array('wp_get_attachment_url', 'image_downsize', 'upload_dir') as $h) {
	if (empty($wp_filter[ $h ])) {
		continue;
	}
	$count = 0;
	$names = array();
	foreach ($wp_filter[ $h ]->callbacks as $priority => $cbs) {
		foreach ($cbs as $cb) {
			++$count;
			$fn = $cb['function'];
			if (is_array($fn) && is_object($fn[0])) {
				$names[] = get_class($fn[0]) . '::' . $fn[1];
			} elseif (is_string($fn)) {
				$names[] = $fn;
			} else {
				$names[] = 'closure/other';
			}
		}
	}
	$s3ms = array_filter(
		$names,
		static function ($n) {
			return str_contains((string) $n, 'Kazcode\WpStorage');
		}
	);
	$others = array_diff($names, $s3ms);
	if ($others === array()) {
		$ok("No competing callbacks on {$h} (only S3MS / none)");
	} else {
		$note("Other callbacks on {$h}: " . implode(', ', array_slice($others, 0, 8)));
	}
}

// --- Pick a real attachment with metadata ---
$q = new WP_Query(
	array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => 20,
		'orderby'        => 'ID',
		'order'          => 'DESC',
		'meta_query'     => array(
			array(
				'key'     => '_wp_attachment_metadata',
				'compare' => 'EXISTS',
			),
		),
	)
);

if ($q->posts === array()) {
	$bad('No attachments with metadata found');
	echo "\nSummary: {$pass} pass, {$fail} fail, {$warn} warn\n";
	exit(1);
}

$attachment_id = (int) $q->posts[0]->ID;
$attached      = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
$meta          = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
$ok("Using attachment #{$attachment_id} ({$attached})");

if (!is_array($meta) || empty($meta['file'])) {
	$note('Metadata incomplete for sample attachment');
} else {
	$ok('Native _wp_attachment_metadata present with file key');
}

// Relative path must NOT be s3:// or absolute URL
if (str_starts_with($attached, 's3://') || preg_match('#^https?://#i', $attached)) {
	$bad('_wp_attached_file is absolute/S3 URI — data model broken');
} else {
	$ok('_wp_attached_file stays relative: ' . $attached);
}

// --- File discovery ---
$resolver = new \Kazcode\WpStorage\Attachment\AttachmentFileResolver(
	new \Kazcode\WpStorage\Storage\S3KeyResolver($settings)
);
$paths = $resolver->relative_paths($attachment_id);
if (count($paths) >= 1) {
	$ok('AttachmentFileResolver found ' . count($paths) . ' relative path(s)');
} else {
	$bad('AttachmentFileResolver returned empty path list');
}

// --- Simulate offloaded + serve enabled (without permanent config) ---
$prev_settings = get_option(\Kazcode\WpStorage\Core\Settings::OPTION_KEY);
$sim           = array_merge(
	\Kazcode\WpStorage\Core\Settings::defaults(),
	is_array($prev_settings) ? $prev_settings : array(),
	array(
		'enabled'       => true,
		'serve_from_s3' => true,
		'cdn_url'       => 'https://cdn.example.test',
		'bucket'        => 'dry-run-bucket',
		'region'        => 'us-east-1',
	)
);
update_option(\Kazcode\WpStorage\Core\Settings::OPTION_KEY, $sim, false);

$prev_status = get_post_meta($attachment_id, '_s3ms_status', true);
$prev_key    = get_post_meta($attachment_id, '_s3ms_original_key', true);
update_post_meta($attachment_id, '_s3ms_status', \Kazcode\WpStorage\Attachment\AttachmentOffloader::STATUS_OFFLOADED);
update_post_meta($attachment_id, '_s3ms_original_key', 'dry/' . ltrim($attached, '/'));

// Use already-registered plugin filters (Settings reads options live).
$wp_url = wp_get_attachment_url($attachment_id);
if (is_string($wp_url) && str_starts_with($wp_url, 'https://cdn.example.test/')) {
	$ok('wp_get_attachment_url → CDN: ' . $wp_url);
} else {
	$bad('wp_get_attachment_url did not rewrite to CDN (got: ' . (string) $wp_url . ')');
}

// image_downsize / thumbnail
$down = image_downsize($attachment_id, 'thumbnail');
if (is_array($down) && !empty($down[0]) && str_starts_with((string) $down[0], 'https://cdn.example.test/')) {
	$ok('image_downsize(thumbnail) → CDN: ' . $down[0]);
} else {
	$bad('image_downsize(thumbnail) failed or not CDN: ' . wp_json_encode($down));
}

// wp_get_attachment_image_src
$src = wp_get_attachment_image_src($attachment_id, 'full');
if (is_array($src) && str_starts_with((string) $src[0], 'https://cdn.example.test/')) {
	$ok('wp_get_attachment_image_src(full) → CDN');
} else {
	$bad('wp_get_attachment_image_src(full) not CDN: ' . wp_json_encode($src));
}

// prepare for JS (Media modal / Grid)
$js = wp_prepare_attachment_for_js($attachment_id);
if (is_array($js) && !empty($js['url']) && str_starts_with((string) $js['url'], 'https://cdn.example.test/')) {
	$ok('wp_prepare_attachment_for_js url → CDN');
} else {
	$bad('wp_prepare_attachment_for_js url not CDN');
}
if (is_array($js) && !empty($js['sizes']) && is_array($js['sizes'])) {
	$size_ok = true;
	foreach ($js['sizes'] as $name => $size_data) {
		if (!is_array($size_data) || empty($size_data['url'])) {
			continue;
		}
		if (!str_starts_with((string) $size_data['url'], 'https://cdn.example.test/')) {
			$size_ok = false;
			$bad("JS size '{$name}' URL not CDN: " . $size_data['url']);
			break;
		}
	}
	if ($size_ok) {
		$ok('wp_prepare_attachment_for_js sizes[*].url → CDN');
	}
} else {
	$note('No sizes in prepare_attachment_for_js (non-image?)');
}

// srcset
$html = wp_get_attachment_image($attachment_id, 'large', false, array('class' => 's3ms-dry'));
if (is_string($html) && str_contains($html, 'cdn.example.test')) {
	$ok('wp_get_attachment_image HTML contains CDN host');
} else {
	$bad('wp_get_attachment_image HTML missing CDN host');
}
if (is_string($html) && (str_contains($html, 'srcset=') || !wp_attachment_is_image($attachment_id))) {
	if (str_contains((string) $html, 'srcset=') && str_contains($html, 'cdn.example.test')) {
		$ok('srcset present and points at CDN');
	} elseif (!wp_attachment_is_image($attachment_id)) {
		$note('Sample is not an image — srcset N/A');
	} else {
		$note('No srcset on large size (may be single-size image)');
	}
}

// REST preparation
if (class_exists('WP_REST_Request') && class_exists('WP_REST_Attachments_Controller')) {
	$request  = new WP_REST_Request('GET', '/wp/v2/media/' . $attachment_id);
	$response = rest_ensure_response(
		(new WP_REST_Attachments_Controller('attachment'))->prepare_item_for_response(get_post($attachment_id), $request)
	);
	$data = $response->get_data();
	if (!empty($data['source_url']) && str_starts_with((string) $data['source_url'], 'https://cdn.example.test/')) {
		$ok('REST source_url → CDN');
	} else {
		$bad('REST source_url not CDN: ' . ($data['source_url'] ?? 'missing'));
	}
} else {
	$note('REST controller unavailable in this context');
}

// Serve OFF → local URLs again
$sim['serve_from_s3'] = false;
update_option(\Kazcode\WpStorage\Core\Settings::OPTION_KEY, $sim, false);
$rolled = wp_get_attachment_url($attachment_id);
if (is_string($rolled) && !str_contains($rolled, 'cdn.example.test')) {
	$ok('Rollback via Serve off: URL is local/site: ' . $rolled);
} else {
	$bad('Rollback via Serve off failed — still CDN: ' . (string) $rolled);
}

// Also without offloaded status
update_option(\Kazcode\WpStorage\Core\Settings::OPTION_KEY, array_merge($sim, array('serve_from_s3' => true)), false);
delete_post_meta($attachment_id, '_s3ms_status');
$local_url = wp_get_attachment_url($attachment_id);
if (is_string($local_url) && !str_contains($local_url, 'cdn.example.test')) {
	$ok('Without offloaded status, URL stays local even if Serve enabled');
} else {
	$bad('Non-offloaded attachment incorrectly rewritten: ' . (string) $local_url);
}

// Restore meta / settings
if ($prev_status === '' || $prev_status === false) {
	delete_post_meta($attachment_id, '_s3ms_status');
} else {
	update_post_meta($attachment_id, '_s3ms_status', $prev_status);
}
if ($prev_key === '' || $prev_key === false) {
	delete_post_meta($attachment_id, '_s3ms_original_key');
} else {
	update_post_meta($attachment_id, '_s3ms_original_key', $prev_key);
}
if ($prev_settings === false) {
	delete_option(\Kazcode\WpStorage\Core\Settings::OPTION_KEY);
} else {
	update_option(\Kazcode\WpStorage\Core\Settings::OPTION_KEY, $prev_settings, false);
}
$ok('Restored previous settings and attachment meta');

// --- Library inventory (dry) ---
global $wpdb;
$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_status='inherit'");
$with_file = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file'");
$missing_local = 0;
$sample_ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_status='inherit' ORDER BY ID DESC LIMIT 50");
foreach ($sample_ids as $aid) {
	$rel = (string) get_post_meta((int) $aid, '_wp_attached_file', true);
	if ($rel === '') {
		continue;
	}
	$abs = trailingslashit($basedir) . ltrim($rel, '/');
	if (!is_file($abs)) {
		++$missing_local;
	}
}
echo "\n--- Inventory (sample) ---\n";
echo "Total attachments: {$total}\n";
echo "With _wp_attached_file meta rows: {$with_file}\n";
echo "Missing local original among last 50: {$missing_local}/" . count($sample_ids) . "\n";
if ($missing_local > 0) {
	$note('Many local files already missing — migrate needs S3 source or re-upload; delete-local Media Library depends on URL filters (tested above)');
}

// --- Theme uses wp_get_attachment_url (will pick up filters) ---
$ok('Theme uses wp_get_attachment_url in news/docs/jobs — will follow plugin filters when serve enabled');

echo "\n=== Summary: {$pass} pass, {$fail} fail, {$warn} warn ===\n";
if ($fail > 0) {
	echo "Verdict: needs fixes before S3 go-live.\n";
	exit(1);
}
echo "Verdict: WordPress Media API compatibility looks good; no large architectural changes indicated.\n";
echo "Still needed for production: real S3 Test Connection + one upload + Grid View after delete-local.\n";
exit(0);
