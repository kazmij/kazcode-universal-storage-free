#!/usr/bin/env php
<?php
declare(strict_types=1);

function usage(): void {
	fwrite(STDERR, "Usage: php build/release-metadata.php --version VERSION --tag TAG [--commit SHA] [--format json|shell]\n");
	fwrite(STDERR, "Reports non-secret release provenance derived from Git and plugin metadata.\n");
}

function fail(string $message): int {
	fwrite(STDERR, "FAIL: {$message}\n");
	return 1;
}

function opt(array $argv, string $name): ?string {
	$flag = '--' . $name;
	for ($i = 0; $i < count($argv); $i++) {
		if ($argv[$i] === $flag) {
			return $argv[$i + 1] ?? null;
		}
	}
	return null;
}

function run(string $command): string {
	$out = array();
	$code = 0;
	exec($command . ' 2>&1', $out, $code);
	if ($code !== 0) {
		throw new RuntimeException(implode("\n", $out));
	}
	return trim(implode("\n", $out));
}

array_shift($argv);
if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
	usage();
	exit(0);
}

$version = opt($argv, 'version');
$tag = opt($argv, 'tag');
$expected_commit = opt($argv, 'commit');
$format = opt($argv, 'format') ?? 'json';
if (!$version || !$tag) {
	usage();
	exit(2);
}

try {
	$root = dirname(__DIR__);
	$repo = run('git -C ' . escapeshellarg($root) . ' rev-parse --show-toplevel');
	$commit = run('git -C ' . escapeshellarg($repo) . ' rev-parse HEAD');
	$tag_commit = run('git -C ' . escapeshellarg($repo) . ' rev-list -n 1 ' . escapeshellarg($tag));
	if ($tag_commit !== $commit) {
		exit(fail("tag {$tag} points to {$tag_commit}, not HEAD {$commit}."));
	}
	if ($expected_commit !== null && $expected_commit !== $commit) {
		exit(fail("HEAD {$commit} does not match expected commit {$expected_commit}."));
	}

	$core_main = $root . '/kazcode-universal-storage.php';
	$pro_main = dirname($root) . '/kazcode-universal-storage-pro/kazcode-universal-storage-pro.php';
	$core = file_get_contents($core_main) ?: '';
	$pro = is_file($pro_main) ? (file_get_contents($pro_main) ?: '') : '';
	if (!preg_match('/Version:\s*' . preg_quote($version, '/') . '\b/', $core)) {
		exit(fail('Free plugin Version header does not match requested version.'));
	}
	$pro_version = null;
	$pro_requires_core = null;
	if ($pro !== '' && preg_match('/Version:\s*([0-9.]+)\b/', $pro, $m)) {
		$pro_version = $m[1];
	}
	if ($pro !== '' && preg_match("/define\\(\\s*'KAZUS_PRO_REQUIRES_CORE'\\s*,\\s*'([^']+)'\\s*\\)/", $pro, $m)) {
		$pro_requires_core = $m[1];
	}

	$freemius_product = null;
	if ($pro !== '' && preg_match("/'id'\\s*=>\\s*'([0-9]+)'/", $pro, $m)) {
		$freemius_product = $m[1];
	}

	$record = array(
		'version' => $version,
		'source_commit' => $commit,
		'source_tag' => $tag,
		'free_artifact' => "kazcode-universal-storage-{$version}.zip",
		'pro_version' => $pro_version,
		'pro_requires_core' => $pro_requires_core,
		'pro_artifact' => $pro_version !== null ? "kazcode-universal-storage-pro-{$pro_version}.zip" : null,
		'wporg_slug' => 'kazcode-universal-storage',
		'freemius_product_id' => $freemius_product,
		'release_channel' => 'stable',
	);

	if ($format === 'shell') {
		foreach ($record as $key => $value) {
			printf("KAZUS_%s=%s\n", strtoupper($key), escapeshellarg((string) $value));
		}
	} elseif ($format === 'json') {
		echo json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
	} else {
		exit(fail('unsupported --format; use json or shell.'));
	}
	exit(0);
} catch (Throwable $e) {
	exit(fail($e->getMessage()));
}
