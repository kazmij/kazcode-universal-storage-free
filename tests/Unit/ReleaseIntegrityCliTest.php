<?php
declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;

final class ReleaseIntegrityCliTest extends TestCase {
	private string $tmp;
	private string $script;

	protected function setUp(): void {
		parent::setUp();
		$this->tmp = sys_get_temp_dir() . '/kazus-release-integrity-' . bin2hex(random_bytes(4));
		mkdir($this->tmp, 0777, true);
		$this->script = dirname(__DIR__, 2) . '/build/release-integrity.php';
	}

	protected function tearDown(): void {
		$this->remove_dir($this->tmp);
		parent::tearDown();
	}

	public function test_manifest_compare_passes_for_identical_runtime_trees(): void {
		$source = $this->tree('source');
		$target = $this->tree('target');
		$this->write($source . '/kazcode-universal-storage.php', "<?php\n");
		$this->write($target . '/kazcode-universal-storage.php', "<?php\n");

		$result = $this->run_cli(array('compare', '--expected', $source, '--actual', $target));

		self::assertSame(0, $result['code'], $result['output']);
		self::assertStringContainsString('local-only=0', $result['output']);
		self::assertStringContainsString('different=0', $result['output']);
	}

	public function test_manifest_compare_fails_when_runtime_file_missing_from_package(): void {
		$source = $this->tree('source');
		$package = $this->tree('package');
		$this->write($source . '/includes/Runtime.php', "<?php\n");

		$result = $this->run_cli(array('compare', '--expected', $source, '--actual', $package));

		self::assertNotSame(0, $result['code']);
		self::assertStringContainsString('local-only=1', $result['output']);
		self::assertStringContainsString('includes/Runtime.php', $result['output']);
	}

	public function test_manifest_compare_fails_when_svn_has_manual_runtime_file(): void {
		$package = $this->tree('package');
		$svn = $this->tree('svn');
		$this->write($package . '/kazcode-universal-storage.php', "<?php\n");
		$this->write($svn . '/kazcode-universal-storage.php', "<?php\n");
		$this->write($svn . '/includes/SvnOnly.php', "<?php\n");

		$result = $this->run_cli(array('compare', '--expected', $package, '--actual', $svn));

		self::assertNotSame(0, $result['code']);
		self::assertStringContainsString('package-only=1', $result['output']);
		self::assertStringContainsString('includes/SvnOnly.php', $result['output']);
	}

	public function test_manifest_compare_fails_when_svn_runtime_content_differs(): void {
		$package = $this->tree('package');
		$svn = $this->tree('svn');
		$this->write($package . '/includes/Runtime.php', "<?php\necho 'a';\n");
		$this->write($svn . '/includes/Runtime.php', "<?php\necho 'b';\n");

		$result = $this->run_cli(array('compare', '--expected', $package, '--actual', $svn));

		self::assertNotSame(0, $result['code']);
		self::assertStringContainsString('different=1', $result['output']);
		self::assertStringContainsString('includes/Runtime.php', $result['output']);
	}

	public function test_preflight_fails_when_git_worktree_is_dirty(): void {
		$repo = $this->git_repo('dirty-repo');
		$this->write_plugin_version_files($repo, '1.0.2');
		$this->git($repo, 'add .');
		$this->git($repo, 'commit -m init');
		$this->git($repo, 'tag v1.0.2');
		file_put_contents($repo . '/kazcode-universal-storage.php', "\n// dirty\n", FILE_APPEND);

		$result = $this->run_cli(array('preflight', '--source', $repo, '--version', '1.0.2', '--tag', 'v1.0.2'));

		self::assertNotSame(0, $result['code']);
		self::assertStringContainsString('dirty working tree', $result['output']);
	}

	public function test_preflight_fails_when_version_header_mismatches(): void {
		$repo = $this->clean_release_repo('version-mismatch', '1.0.1', '1.0.2');

		$result = $this->run_cli(array('preflight', '--source', $repo, '--version', '1.0.2', '--tag', 'v1.0.2'));

		self::assertNotSame(0, $result['code']);
		self::assertStringContainsString('Plugin header Version mismatch', $result['output']);
	}

	public function test_preflight_fails_when_stable_tag_mismatches(): void {
		$repo = $this->clean_release_repo('stable-mismatch', '1.0.2', '1.0.1');

		$result = $this->run_cli(array('preflight', '--source', $repo, '--version', '1.0.2', '--tag', 'v1.0.2'));

		self::assertNotSame(0, $result['code']);
		self::assertStringContainsString('Stable tag mismatch', $result['output']);
	}

	public function test_preflight_fails_when_tag_points_to_other_commit(): void {
		$repo = $this->clean_release_repo('wrong-tag', '1.0.2', '1.0.2');
		$this->write($repo . '/README.md', "second\n");
		$this->git($repo, 'add .');
		$this->git($repo, 'commit -m second');

		$result = $this->run_cli(array('preflight', '--source', $repo, '--version', '1.0.2', '--tag', 'v1.0.2'));

		self::assertNotSame(0, $result['code']);
		self::assertStringContainsString('does not point at HEAD', $result['output']);
	}

	public function test_preflight_fails_on_untracked_runtime_php_file(): void {
		$repo = $this->clean_release_repo('untracked-runtime', '1.0.2', '1.0.2');
		mkdir($repo . '/includes', 0777, true);
		$this->write($repo . '/includes/UntrackedRuntime.php', "<?php\n");

		$result = $this->run_cli(array('preflight', '--source', $repo, '--version', '1.0.2', '--tag', 'v1.0.2'));

		self::assertNotSame(0, $result['code']);
		self::assertStringContainsString('untracked runtime file', $result['output']);
	}

	public function test_preflight_fails_on_ignored_runtime_php_file(): void {
		$repo = $this->clean_release_repo('ignored-runtime', '1.0.2', '1.0.2');
		$this->write($repo . '/.gitignore', "includes/*.php\n");
		$this->git($repo, 'add .gitignore');
		$this->git($repo, 'commit -m ignore-runtime');
		$this->git($repo, 'tag -f v1.0.2');
		mkdir($repo . '/includes', 0777, true);
		$this->write($repo . '/includes/IgnoredRuntime.php', "<?php\n");

		$result = $this->run_cli(array('preflight', '--source', $repo, '--version', '1.0.2', '--tag', 'v1.0.2'));

		self::assertNotSame(0, $result['code']);
		self::assertStringContainsString('ignored runtime file', $result['output']);
	}

	public function test_preflight_accepts_annotated_tag_pointing_at_head(): void {
		$repo = $this->git_repo('annotated-tag');
		$this->write_plugin_version_files($repo, '1.0.2');
		$this->git($repo, 'add .');
		$this->git($repo, 'commit -m init');
		$this->git($repo, 'tag -a v1.0.2 -m release');

		$result = $this->run_cli(array('preflight', '--source', $repo, '--version', '1.0.2', '--tag', 'v1.0.2'));

		self::assertSame(0, $result['code'], $result['output']);
	}

	public function test_compare_handles_filename_with_spaces(): void {
		$source = $this->tree('source-spaces');
		$target = $this->tree('target-spaces');
		$this->write($source . '/assets/file with spaces.txt', "same\n");
		$this->write($target . '/assets/file with spaces.txt', "same\n");

		$result = $this->run_cli(array('compare', '--expected', $source, '--actual', $target));

		self::assertSame(0, $result['code'], $result['output']);
		self::assertStringContainsString('same=1', $result['output']);
	}

	public function test_package_gate_accepts_zip_package_with_matching_version(): void {
		$package_root = $this->tree('zip-src') . '/kazcode-universal-storage';
		mkdir($package_root, 0777, true);
		$this->write_plugin_version_files($package_root, '1.0.2');
		$zip = $this->tmp . '/kazcode-universal-storage-1.0.2.zip';
		$this->zip(dirname($package_root), $zip);

		$result = $this->run_cli(array('package', '--package', $zip, '--version', '1.0.2'));

		self::assertSame(0, $result['code'], $result['output']);
		self::assertStringContainsString('OK: package integrity passed', $result['output']);
	}

	public function test_package_gate_rejects_zip_slip_paths_before_extracting(): void {
		$zip = $this->tmp . '/zip-slip.zip';
		$this->write($this->tmp . '/outside.php', "<?php\n");
		$cwd = $this->tree('zip-slip-cwd');
		$this->zip_file($cwd, $zip, '../outside.php');

		$result = $this->run_cli(array('package', '--package', $zip, '--version', '1.0.2'));

		self::assertNotSame(0, $result['code']);
		self::assertStringContainsString('unsafe ZIP path', $result['output']);
	}

	public function test_post_publish_fails_when_public_download_is_not_zip(): void {
		$package_root = $this->tree('zip-src-html') . '/kazcode-universal-storage';
		mkdir($package_root, 0777, true);
		$this->write_plugin_version_files($package_root, '1.0.2');
		$zip = $this->tmp . '/local.zip';
		$this->zip(dirname($package_root), $zip);
		$html = $this->tmp . '/public.html';
		$this->write($html, '<html>not found</html>');

		$result = $this->run_cli(array('post-publish', '--local-zip', $zip, '--public-zip', $html, '--version', '1.0.2'));

		self::assertNotSame(0, $result['code']);
	}

	public function test_post_publish_fails_when_public_zip_has_wrong_version(): void {
		$local_root = $this->tree('zip-local') . '/kazcode-universal-storage';
		$public_root = $this->tree('zip-public') . '/kazcode-universal-storage';
		mkdir($local_root, 0777, true);
		mkdir($public_root, 0777, true);
		$this->write_plugin_version_files($local_root, '1.0.2');
		$this->write_plugin_version_files($public_root, '1.0.1');
		$local_zip = $this->tmp . '/local-version.zip';
		$public_zip = $this->tmp . '/public-version.zip';
		$this->zip(dirname($local_root), $local_zip);
		$this->zip(dirname($public_root), $public_zip);

		$result = $this->run_cli(array('post-publish', '--local-zip', $local_zip, '--public-zip', $public_zip, '--version', '1.0.2'));

		self::assertNotSame(0, $result['code']);
	}

	public function test_record_computes_runtime_match_instead_of_trusting_argument(): void {
		$package_root = $this->tree('zip-record') . '/kazcode-universal-storage';
		mkdir($package_root, 0777, true);
		$this->write_plugin_version_files($package_root, '1.0.2');
		$zip = $this->tmp . '/record.zip';
		$this->zip(dirname($package_root), $zip);

		$result = $this->run_cli(array('record', '--version', '1.0.2', '--local-zip', $zip, '--public-zip', $zip));

		self::assertSame(0, $result['code'], $result['output']);
		self::assertStringContainsString('"runtime_match": "PASS"', $result['output']);

		$forged = $this->run_cli(array('record', '--version', '1.0.2', '--runtime-match', 'PASS'));
		self::assertNotSame(0, $forged['code']);
	}

	private function clean_release_repo(string $name, string $plugin_version, string $stable_tag): string {
		$repo = $this->git_repo($name);
		$this->write_plugin_version_files($repo, $plugin_version, $stable_tag);
		$this->git($repo, 'add .');
		$this->git($repo, 'commit -m init');
		$this->git($repo, 'tag v1.0.2');
		return $repo;
	}

	private function write_plugin_version_files(string $repo, string $plugin_version, ?string $stable_tag = null): void {
		$stable_tag ??= $plugin_version;
		$this->write(
			$repo . '/kazcode-universal-storage.php',
			"<?php\n/*\n * Plugin Name: KAZCODE Universal Storage\n * Version: {$plugin_version}\n */\ndefine('KAZUS_VERSION', '{$plugin_version}');\n"
		);
		$this->write($repo . '/readme.txt', "Stable tag: {$stable_tag}\n\n== Changelog ==\n\n= {$plugin_version} =\n* Test.\n");
	}

	private function git_repo(string $name): string {
		$repo = $this->tree($name);
		$this->git($repo, 'init');
		$this->git($repo, 'config user.email test@example.com');
		$this->git($repo, 'config user.name Test');
		return $repo;
	}

	private function run_cli(array $args): array {
		$cmd = 'php ' . escapeshellarg($this->script);
		foreach ($args as $arg) {
			$cmd .= ' ' . escapeshellarg($arg);
		}
		$output = array();
		$code = 0;
		exec($cmd . ' 2>&1', $output, $code);
		return array('code' => $code, 'output' => implode("\n", $output));
	}

	private function git(string $cwd, string $command): void {
		$output = array();
		$code = 0;
		exec('git -C ' . escapeshellarg($cwd) . ' ' . $command . ' 2>&1', $output, $code);
		if ($code !== 0) {
			throw new \RuntimeException('git failed: ' . implode("\n", $output));
		}
	}

	private function zip(string $cwd, string $zip): void {
		$output = array();
		$code = 0;
		exec('cd ' . escapeshellarg($cwd) . ' && zip -qr ' . escapeshellarg($zip) . ' kazcode-universal-storage 2>&1', $output, $code);
		if ($code !== 0) {
			throw new \RuntimeException('zip failed: ' . implode("\n", $output));
		}
	}

	private function zip_file(string $cwd, string $zip, string $entry): void {
		$output = array();
		$code = 0;
		exec('cd ' . escapeshellarg($cwd) . ' && zip -q ' . escapeshellarg($zip) . ' ' . escapeshellarg($entry) . ' 2>&1', $output, $code);
		if ($code !== 0) {
			throw new \RuntimeException('zip failed: ' . implode("\n", $output));
		}
	}

	private function tree(string $name): string {
		$path = $this->tmp . '/' . $name;
		mkdir($path, 0777, true);
		return $path;
	}

	private function write(string $path, string $contents): void {
		$dir = dirname($path);
		if (! is_dir($dir)) {
			mkdir($dir, 0777, true);
		}
		file_put_contents($path, $contents);
	}

	private function remove_dir(string $path): void {
		if (! is_dir($path)) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($items as $item) {
			$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}
		rmdir($path);
	}
}
