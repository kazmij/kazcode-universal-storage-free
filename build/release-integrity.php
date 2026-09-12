#!/usr/bin/env php
<?php
declare(strict_types=1);

final class ReleaseIntegrity {
	private const SLUG = 'kazcode-universal-storage';

	public static function main(array $argv): int {
		array_shift($argv);
		$command = array_shift($argv);
		try {
			switch ($command) {
				case 'manifest':
					return self::manifest_command($argv);
				case 'compare':
					return self::compare_command($argv);
				case 'preflight':
					return self::preflight_command($argv);
				case 'package':
					return self::package_command($argv);
				case 'post-publish':
					return self::post_publish_command($argv);
				case 'record':
					return self::record_command($argv);
				default:
					self::usage();
					return 2;
			}
		} catch (RuntimeException $e) {
			fwrite(STDERR, 'FAIL: ' . $e->getMessage() . PHP_EOL);
			return 1;
		}
	}

	private static function manifest_command(array $argv): int {
		$options = self::parse_options($argv);
		$path = self::require_option($options, 'path');
		$manifest = self::manifest($path);
		$output = self::format_manifest($manifest);
		if (isset($options['output'])) {
			self::write_file($options['output'], $output);
		} else {
			echo $output;
		}
		return 0;
	}

	private static function compare_command(array $argv): int {
		$options = self::parse_options($argv);
		$expected = self::manifest(self::require_option($options, 'expected'));
		$actual = self::manifest(self::require_option($options, 'actual'));
		$diff = self::manifest_diff($expected, $actual);
		self::print_diff_summary($diff);
		if (! empty($diff['local_only']) || ! empty($diff['package_only']) || ! empty($diff['different'])) {
			return 1;
		}
		return 0;
	}

	private static function preflight_command(array $argv): int {
		$options = self::parse_options($argv);
		$source = realpath(self::require_option($options, 'source'));
		if (! $source || ! is_dir($source)) {
			throw new RuntimeException('source directory does not exist.');
		}
		$version = self::require_option($options, 'version');
		$tag = self::require_option($options, 'tag');
		$expected_commit = $options['commit'] ?? null;

		$head = trim(self::run('git -C ' . escapeshellarg($source) . ' rev-parse HEAD'));
		if ($expected_commit !== null && $head !== $expected_commit) {
			throw new RuntimeException("HEAD {$head} does not match expected commit {$expected_commit}.");
		}

		$tag_commit = trim(self::run('git -C ' . escapeshellarg($source) . ' rev-list -n 1 ' . escapeshellarg($tag)));
		if ($tag_commit !== $head) {
			throw new RuntimeException("release tag {$tag} does not point at HEAD ({$tag_commit} != {$head}).");
		}

		$status = self::git_status($source);
		if ($status !== array()) {
			foreach ($status as $line) {
				$path = substr($line, 3);
				if (str_starts_with($line, '?? ') && self::is_runtime_path($path)) {
					throw new RuntimeException("untracked runtime file: {$path}");
				}
				if (str_starts_with($line, '!! ') && self::is_runtime_path($path)) {
					throw new RuntimeException("ignored runtime file: {$path}");
				}
			}
			throw new RuntimeException('dirty working tree: ' . implode('; ', $status));
		}

		self::assert_version_metadata($source, $version);
		if (isset($options['package'])) {
			self::assert_no_forbidden_package_files($options['package']);
		}

		echo "OK: release preflight passed version={$version} tag={$tag} commit={$head}" . PHP_EOL;
		return 0;
	}

	private static function package_command(array $argv): int {
		$options = self::parse_options($argv);
		$package = self::require_option($options, 'package');
		$version = self::require_option($options, 'version');
		$tmp = self::extract_zip($package);
		try {
			$root = $tmp . '/' . self::SLUG;
			self::assert_version_metadata($root, $version);
			self::assert_no_forbidden_package_files($package);
		} finally {
			self::remove_dir($tmp);
		}
		echo "OK: package integrity passed version={$version} package={$package}" . PHP_EOL;
		return 0;
	}

	private static function post_publish_command(array $argv): int {
		$options = self::parse_options($argv);
		$local = self::require_option($options, 'local-zip');
		$public = self::require_option($options, 'public-zip');
		$version = self::require_option($options, 'version');
		$diff = self::manifest_diff(self::manifest($local), self::manifest($public));
		self::print_diff_summary($diff);
		if (! empty($diff['local_only']) || ! empty($diff['package_only']) || ! empty($diff['different'])) {
			return 1;
		}
		$tmp = self::extract_zip($public);
		try {
			self::assert_version_metadata($tmp . '/' . self::SLUG, $version);
		} finally {
			self::remove_dir($tmp);
		}
		echo "OK: public package runtime matches local package version={$version}" . PHP_EOL;
		return 0;
	}

	private static function record_command(array $argv): int {
		$options = self::parse_options($argv);
		if (isset($options['runtime-match'])) {
			throw new RuntimeException('runtime_match is computed by the verifier; do not pass --runtime-match.');
		}
		$runtime_match = null;
		if (isset($options['local-zip'], $options['public-zip'])) {
			$diff = self::manifest_diff(self::manifest($options['local-zip']), self::manifest($options['public-zip']));
			$runtime_match = empty($diff['local_only']) && empty($diff['package_only']) && empty($diff['different']) ? 'PASS' : 'FAIL';
		} elseif (isset($options['local-manifest'], $options['public-manifest'])) {
			$runtime_match = hash_file('sha256', $options['local-manifest']) === hash_file('sha256', $options['public-manifest']) ? 'PASS' : 'FAIL';
		}
		$record = array(
			'version' => self::require_option($options, 'version'),
			'source_commit' => $options['source-commit'] ?? null,
			'source_tag' => $options['source-tag'] ?? null,
			'local_zip_sha256' => isset($options['local-zip']) ? hash_file('sha256', $options['local-zip']) : null,
			'local_manifest_sha256' => isset($options['local-manifest']) ? hash_file('sha256', $options['local-manifest']) : null,
			'svn_revision' => $options['svn-revision'] ?? null,
			'public_zip_sha256' => isset($options['public-zip']) ? hash_file('sha256', $options['public-zip']) : null,
			'public_manifest_sha256' => isset($options['public-manifest']) ? hash_file('sha256', $options['public-manifest']) : null,
			'runtime_match' => $runtime_match,
		);
		$json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
		if (isset($options['output'])) {
			self::write_file($options['output'], $json);
		} else {
			echo $json;
		}
		return 0;
	}

	/** @return array<string,array{size:int,sha256:string}> */
	private static function manifest(string $path): array {
		if (is_file($path)) {
			return self::zip_manifest($path);
		}
		if (is_dir($path)) {
			return self::dir_manifest($path);
		}
		throw new RuntimeException("manifest path does not exist: {$path}");
	}

	/** @return array<string,array{size:int,sha256:string}> */
	private static function dir_manifest(string $root): array {
		$root = rtrim((string) realpath($root), '/');
		$result = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
		);
		foreach ($iterator as $file) {
			if (! $file->isFile()) {
				continue;
			}
			$relative = substr($file->getPathname(), strlen($root) + 1);
			if (self::is_ignored_manifest_path($relative)) {
				continue;
			}
			$result[self::normalize_package_path($relative)] = array(
				'size' => $file->getSize(),
				'sha256' => hash_file('sha256', $file->getPathname()),
			);
		}
		ksort($result, SORT_STRING);
		return $result;
	}

	/** @return array<string,array{size:int,sha256:string}> */
	private static function zip_manifest(string $zip): array {
		$entries = self::zip_entries($zip);
		$result = array();
		foreach ($entries as $entry) {
			if ($entry === '' || str_ends_with($entry, '/')) {
				continue;
			}
			$relative = self::normalize_package_path($entry);
			if (self::is_ignored_manifest_path($relative)) {
				continue;
			}
			$data = self::run('unzip -p ' . escapeshellarg($zip) . ' ' . escapeshellarg($entry), true);
			$result[$relative] = array(
				'size' => strlen($data),
				'sha256' => hash('sha256', $data),
			);
		}
		ksort($result, SORT_STRING);
		return $result;
	}

	private static function normalize_package_path(string $path): string {
		$path = str_replace('\\', '/', $path);
		$prefix = self::SLUG . '/';
		if (str_starts_with($path, $prefix)) {
			$path = substr($path, strlen($prefix));
		}
		return ltrim($path, '/');
	}

	/** @return list<string> */
	private static function zip_entries(string $zip): array {
		$list = self::run('unzip -Z1 ' . escapeshellarg($zip));
		$entries = array_values(array_filter(preg_split('/\R/', trim($list)) ?: array(), static fn($entry) => $entry !== ''));
		$seen = array();
		foreach ($entries as $entry) {
			self::assert_safe_zip_entry($entry);
			$normalized = self::normalize_package_path($entry);
			if (isset($seen[$normalized])) {
				throw new RuntimeException("duplicate ZIP path after normalization: {$normalized}");
			}
			$seen[$normalized] = true;
		}
		return $entries;
	}

	private static function assert_safe_zip_entry(string $entry): void {
		$path = str_replace('\\', '/', $entry);
		if ($path === '' || str_starts_with($path, '/') || preg_match('#^[A-Za-z]:/#', $path)) {
			throw new RuntimeException("unsafe ZIP path: {$entry}");
		}
		foreach (explode('/', $path) as $part) {
			if ($part === '..') {
				throw new RuntimeException("unsafe ZIP path: {$entry}");
			}
			if (preg_match('/[\x00-\x1F\x7F]/', $part)) {
				throw new RuntimeException("unsupported control character in ZIP path: {$entry}");
			}
		}
	}

	private static function is_ignored_manifest_path(string $relative): bool {
		$parts = explode('/', $relative);
		return in_array('.git', $parts, true) || in_array('.svn', $parts, true);
	}

	private static function format_manifest(array $manifest): string {
		$out = '';
		foreach ($manifest as $path => $meta) {
			$out .= $meta['sha256'] . '  ' . $meta['size'] . '  ' . $path . PHP_EOL;
		}
		return $out;
	}

	private static function manifest_diff(array $expected, array $actual): array {
		$expected_paths = array_keys($expected);
		$actual_paths = array_keys($actual);
		$local_only = array_values(array_diff($expected_paths, $actual_paths));
		$package_only = array_values(array_diff($actual_paths, $expected_paths));
		$different = array();
		foreach (array_intersect($expected_paths, $actual_paths) as $path) {
			if ($expected[$path] !== $actual[$path]) {
				$different[] = $path;
			}
		}
		sort($local_only, SORT_STRING);
		sort($package_only, SORT_STRING);
		sort($different, SORT_STRING);
		return array(
			'local_only' => $local_only,
			'package_only' => $package_only,
			'different' => $different,
			'same_count' => count($expected_paths) - count($local_only) - count($different),
		);
	}

	private static function print_diff_summary(array $diff): void {
		printf(
			"local-only=%d\npackage-only=%d\ndifferent=%d\nsame=%d\n",
			count($diff['local_only']),
			count($diff['package_only']),
			count($diff['different']),
			$diff['same_count']
		);
		foreach (array('local_only', 'package_only', 'different') as $key) {
			foreach ($diff[$key] as $path) {
				echo "{$key}: {$path}" . PHP_EOL;
			}
		}
	}

	private static function assert_version_metadata(string $source, string $version): void {
		$main = $source . '/kazcode-universal-storage.php';
		$readme = $source . '/readme.txt';
		if (! is_file($main)) {
			throw new RuntimeException('missing kazcode-universal-storage.php');
		}
		if (! is_file($readme)) {
			throw new RuntimeException('missing readme.txt');
		}
		$main_contents = file_get_contents($main);
		$readme_contents = file_get_contents($readme);
		if (! preg_match('/^\s*\*\s*Version:\s*' . preg_quote($version, '/') . '\s*$/mi', $main_contents)) {
			throw new RuntimeException('Plugin header Version mismatch.');
		}
		if (! preg_match("/define\\(\\s*'KAZUS_VERSION'\\s*,\\s*'" . preg_quote($version, '/') . "'\\s*\\)/", $main_contents)) {
			throw new RuntimeException('KAZUS_VERSION mismatch.');
		}
		if (! preg_match('/^Stable tag:\s*' . preg_quote($version, '/') . '\s*$/mi', $readme_contents)) {
			throw new RuntimeException('Stable tag mismatch.');
		}
		if (! preg_match('/^=\s*' . preg_quote($version, '/') . '\s*=$/mi', $readme_contents)) {
			throw new RuntimeException('Changelog missing expected version.');
		}
	}

	private static function assert_no_forbidden_package_files(string $source): void {
		$manifest = self::manifest($source);
		foreach (array_keys($manifest) as $path) {
			if (preg_match('#(^|/)(\.git|\.github|\.idea|tests|\.phpunit\.cache)(/|$)#', $path)) {
				throw new RuntimeException("forbidden production package path: {$path}");
			}
			if (str_ends_with($path, '.py')) {
				throw new RuntimeException("forbidden Python file in production package boundary: {$path}");
			}
			if ($path === 'vendor/aws/aws-crt-php/format-check.py') {
				throw new RuntimeException('forbidden aws-crt format-check.py in production package boundary.');
			}
		}
	}

	private static function git_status(string $source): array {
		$output = self::run('git -C ' . escapeshellarg($source) . ' status --porcelain --untracked-files=all --ignored=matching');
		$lines = array_values(array_filter(preg_split('/\R/', trim($output)) ?: array()));
		return $lines;
	}

	private static function is_runtime_path(string $path): bool {
		return (bool) preg_match('#^(includes|assets|vendor|kazcode-universal-storage\.php|readme\.txt|uninstall\.php|composer\.json|README\.md|LICENSE|THIRD-PARTY-LICENSES\.txt)(/|$)#', $path);
	}

	private static function parse_options(array $argv): array {
		$options = array();
		for ($i = 0; $i < count($argv); $i++) {
			$arg = $argv[$i];
			if (! str_starts_with($arg, '--')) {
				throw new RuntimeException("unexpected argument {$arg}");
			}
			$key = substr($arg, 2);
			$value = $argv[$i + 1] ?? null;
			if ($value === null || str_starts_with($value, '--')) {
				throw new RuntimeException("missing value for --{$key}");
			}
			$options[$key] = $value;
			$i++;
		}
		return $options;
	}

	private static function require_option(array $options, string $key): string {
		if (! isset($options[$key]) || $options[$key] === '') {
			throw new RuntimeException("missing --{$key}");
		}
		return $options[$key];
	}

	private static function run(string $command, bool $binary = false): string {
		$descriptor = array(
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		$process = proc_open($command, $descriptor, $pipes);
		if (! is_resource($process)) {
			throw new RuntimeException("could not run command: {$command}");
		}
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($process);
		if ($code !== 0) {
			throw new RuntimeException(trim($stderr !== '' ? $stderr : $stdout));
		}
		return $stdout;
	}

	private static function extract_zip(string $zip): string {
		self::zip_entries($zip);
		$tmp = sys_get_temp_dir() . '/kazus-release-integrity-' . bin2hex(random_bytes(4));
		mkdir($tmp, 0777, true);
		self::run('unzip -q ' . escapeshellarg($zip) . ' -d ' . escapeshellarg($tmp));
		return $tmp;
	}

	private static function write_file(string $path, string $contents): void {
		$dir = dirname($path);
		if (! is_dir($dir)) {
			mkdir($dir, 0777, true);
		}
		file_put_contents($path, $contents);
	}

	private static function remove_dir(string $path): void {
		if (! is_dir($path)) {
			return;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($items as $item) {
			$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}
		rmdir($path);
	}

	private static function usage(): void {
		fwrite(STDERR, "Usage:\n");
		fwrite(STDERR, "  php build/release-integrity.php preflight --source DIR --version VERSION --tag TAG [--commit SHA]\n");
		fwrite(STDERR, "  php build/release-integrity.php package --package ZIP --version VERSION\n");
		fwrite(STDERR, "  php build/release-integrity.php manifest --path DIR_OR_ZIP [--output FILE]\n");
		fwrite(STDERR, "  php build/release-integrity.php compare --expected DIR_OR_ZIP --actual DIR_OR_ZIP\n");
		fwrite(STDERR, "  php build/release-integrity.php post-publish --local-zip ZIP --public-zip ZIP --version VERSION\n");
		fwrite(STDERR, "  php build/release-integrity.php record --version VERSION [--output FILE] [...metadata]\n");
	}
}

exit(ReleaseIntegrity::main($argv));
