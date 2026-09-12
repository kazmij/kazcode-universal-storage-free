<?php
/**
 * Smoke checks for KAZCODE Universal Storage helpers (run via wp eval-file).
 *
 * @package Kazcode\WpStorage
 */

if (!defined('ABSPATH')) {
	exit(1);
}

$report = array(
	'pass' => array(),
	'fail' => array(),
);

$ok = static function (string $msg) use (&$report): void {
	$report['pass'][] = $msg;
	echo "PASS: {$msg}\n";
};

$fail = static function (string $msg) use (&$report): void {
	$report['fail'][] = $msg;
	echo "FAIL: {$msg}\n";
};

if (!class_exists(\Kazcode\WpStorage\Plugin::class)) {
	$fail('Plugin class not loaded — is kazcode-universal-storage active?');
	exit(1);
}

$settings = \Kazcode\WpStorage\Plugin::instance()->settings();
$defaults = \Kazcode\WpStorage\Core\Settings::defaults();
if (isset($defaults['enabled'])) {
	$ok('Settings defaults present');
} else {
	$fail('Settings defaults missing');
}

$enc = new \Kazcode\WpStorage\Core\EncryptionService();
$payload = $enc->encrypt('eval-file-secret');
if ($enc->decrypt($payload) === 'eval-file-secret') {
	$ok('EncryptionService roundtrip');
} else {
	$fail('EncryptionService roundtrip');
}

$keys = new \Kazcode\WpStorage\Storage\S3KeyResolver($settings);
if ($keys->resolve('2026/08/a.jpg') !== '') {
	$ok('S3KeyResolver resolves paths');
} else {
	$fail('S3KeyResolver empty key');
}

$urls = new \Kazcode\WpStorage\Storage\PublicUrlResolver($settings);
$url  = $urls->url_for_relative('2026/08/a.jpg');
// Without CDN/base/bucket configured, URL may be empty — still a valid resolver result.
if (is_string($url)) {
	$ok('PublicUrlResolver returns string URL' . ($url !== '' ? ': ' . $url : ' (empty until AWS/CDN configured)'));
} else {
	$fail('PublicUrlResolver empty URL');
}

$resolver = new \Kazcode\WpStorage\Attachment\AttachmentFileResolver($keys);
// Discovery on non-existent ID should return empty list without fatals.
$paths = $resolver->relative_paths(0);
if (is_array($paths)) {
	$ok('AttachmentFileResolver handles empty attachment');
} else {
	$fail('AttachmentFileResolver failed');
}

echo "\nSummary: " . count($report['pass']) . " passed, " . count($report['fail']) . " failed\n";
exit(count($report['fail']) > 0 ? 1 : 0);
