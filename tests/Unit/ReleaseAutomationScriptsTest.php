<?php
declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;

final class ReleaseAutomationScriptsTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root = dirname(__DIR__, 2);
	}

	public function test_freemius_dry_run_upload_does_not_require_or_print_token(): void {
		$zip = tempnam(sys_get_temp_dir(), 'kazus-pro-') . '.zip';
		file_put_contents($zip, 'not-a-real-zip');
		try {
			$result = $this->run_command('php ' . escapeshellarg($this->root . '/build/release-freemius.php') . ' upload --version 1.1.0 --zip ' . escapeshellarg($zip) . ' --mode beta --dry-run');

			self::assertSame(0, $result['code'], $result['output']);
			self::assertStringContainsString('DRY RUN', $result['output']);
			self::assertStringContainsString('mode=beta', $result['output']);
			self::assertStringNotContainsString('KAZUS_FREEMIUS_BEARER_TOKEN', $result['output']);
			self::assertStringNotContainsString('Authorization', $result['output']);
		} finally {
			@unlink($zip);
		}
	}

	public function test_freemius_rejects_invalid_release_mode_before_api_call(): void {
		$zip = tempnam(sys_get_temp_dir(), 'kazus-pro-') . '.zip';
		file_put_contents($zip, 'not-a-real-zip');
		try {
			$result = $this->run_command('php ' . escapeshellarg($this->root . '/build/release-freemius.php') . ' upload --version 1.1.0 --zip ' . escapeshellarg($zip) . ' --mode public --dry-run');

			self::assertNotSame(0, $result['code']);
			self::assertStringContainsString('mode must be pending, beta, or released', $result['output']);
		} finally {
			@unlink($zip);
		}
	}

	public function test_freemius_promote_dry_run_reports_mode_without_api_call(): void {
		$result = $this->run_command('php ' . escapeshellarg($this->root . '/build/release-freemius.php') . ' promote --tag-id 123 --mode released --dry-run');

		self::assertSame(0, $result['code'], $result['output']);
		self::assertStringContainsString('DRY RUN', $result['output']);
		self::assertStringContainsString('release_mode=released', $result['output']);
	}

	public function test_release_scripts_expose_help(): void {
		foreach (array(
			'/build/release-wordpress-org.sh',
			'/build/release-github-free.sh',
			'/build/capture-wordpress-screenshots.sh',
			'/build/deploy-kazcode-site.sh',
		) as $script) {
			$result = $this->run_command('bash ' . escapeshellarg($this->root . $script) . ' --help');

			self::assertSame(0, $result['code'], $script . "\n" . $result['output']);
			self::assertStringContainsString('Usage:', $result['output']);
			self::assertStringContainsString('dry-run', $result['output']);
		}
	}

	public function test_wordpress_script_never_passes_password_as_argument(): void {
		$script = file_get_contents($this->root . '/build/release-wordpress-org.sh');

		self::assertIsString($script);
		self::assertStringContainsString('--password-from-stdin', $script);
		self::assertStringNotContainsString('--password "${KAZUS_WPORG_PASSWORD}"', $script);
		self::assertStringNotContainsString("--password '\${KAZUS_WPORG_PASSWORD}'", $script);
	}

	public function test_makefile_uses_canonical_freemius_script_and_mode_variable(): void {
		$makefile_path = dirname($this->root) . '/Makefile';
		if (!is_file($makefile_path)) {
			self::markTestSkipped('Repository Makefile is not available in plugin-only test installs.');
		}

		$makefile = file_get_contents($makefile_path);

		self::assertIsString($makefile);
		self::assertStringContainsString('build/release-freemius.php upload', $makefile);
		self::assertStringContainsString('--mode "$(MODE)"', $makefile);
		self::assertStringContainsString('DEXEC_RELEASE', $makefile);
	}

	public function test_github_free_release_script_uses_gh_as_primary_path(): void {
		$script = file_get_contents($this->root . '/build/release-github-free.sh');

		self::assertIsString($script);
		self::assertStringContainsString('command -v gh', $script);
		self::assertStringContainsString('gh release create', $script);
		self::assertStringContainsString('--verify-tag', $script);
		self::assertStringContainsString('gh release view', $script);
		self::assertStringContainsString('gh release upload', $script);
		self::assertStringContainsString('gh release download', $script);
		self::assertDoesNotMatchRegularExpression('/gh release upload[^\n]+--clobber/', $script);
	}

	public function test_github_free_release_script_prefers_gh_token_without_printing_tokens(): void {
		$script = file_get_contents($this->root . '/build/release-github-free.sh');

		self::assertIsString($script);
		self::assertMatchesRegularExpression('/GH_TOKEN[^\\n]+GITHUB_TOKEN/s', $script);
		self::assertStringContainsString('export GH_TOKEN="${GITHUB_TOKEN}"', $script);
		self::assertStringNotContainsString('echo "${GH_TOKEN', $script);
		self::assertStringNotContainsString('echo "${GITHUB_TOKEN', $script);
	}

	public function test_github_free_release_script_fails_closed_on_duplicate_or_wrong_assets(): void {
		$script = file_get_contents($this->root . '/build/release-github-free.sh');

		self::assertIsString($script);
		self::assertStringContainsString('asset_count', $script);
		self::assertStringContainsString('expected exactly one asset', $script);
		self::assertStringContainsString('do not use --clobber', $script);
		self::assertStringContainsString('dry-run', $script);
	}

	private function run_command(string $command): array {
		$output = array();
		$code = 0;
		exec($command . ' 2>&1', $output, $code);
		return array('code' => $code, 'output' => implode("\n", $output));
	}
}
