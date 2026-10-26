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
			'/build/release-verify-wordpress-org.sh',
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

	public function test_wordpress_org_operator_wrapper_rejects_malformed_credentials_without_invoking_make(): void {
		$script = $this->root . '/build/wordpress-org.sh';
		$credentials = tempnam(sys_get_temp_dir(), 'kazus-wporg-credentials-');
		file_put_contents($credentials, "username-only\n");

		try {
			$result = $this->run_command('bash ' . escapeshellarg($script) . ' release --credentials-file ' . escapeshellarg($credentials) . ' --version 1.2.0 --tag v1.2.0 --commit deadbeef');

			self::assertNotSame(0, $result['code']);
			self::assertStringContainsString('exactly two non-empty lines', $result['output']);
			self::assertStringNotContainsString('make release-wp', $result['output']);
		} finally {
			@unlink($credentials);
		}
	}

	public function test_wordpress_org_operator_wrapper_has_readme_mode_and_keeps_password_off_the_command_line(): void {
		$script = $this->root . '/build/wordpress-org.sh';
		$result = $this->run_command('bash ' . escapeshellarg($script) . ' --help');
		$source = is_file($script) ? file_get_contents($script) : '';

		self::assertSame(0, $result['code'], $result['output']);
		self::assertStringContainsString('readme', $result['output']);
		self::assertStringContainsString('--assets-dir', $result['output']);
		self::assertStringContainsString('--credentials-file', $result['output']);
		self::assertIsString($source);
		self::assertStringContainsString('KAZUS_WPORG_PASSWORD', $source);
		self::assertStringNotContainsString('--password "${KAZUS_WPORG_PASSWORD}"', $source);
		self::assertStringContainsString('pwd)/$(basename', $source);
	}

	public function test_wordpress_org_listing_updater_can_include_reviewed_assets_without_touching_tags(): void {
		$script = file_get_contents($this->root . '/build/update-wordpress-org-readme.sh');

		self::assertIsString($script);
		self::assertStringContainsString('--assets-dir', $script);
		self::assertStringContainsString('svn update --set-depth infinity "${TMP}/svn/assets"', $script);
		self::assertStringContainsString('rsync -rt --exclude', $script);
		self::assertStringNotContainsString('/tags/', $script);
	}

	public function test_makefile_listing_update_does_not_require_composer(): void {
		$makefile_path = dirname($this->root) . '/Makefile';
		if (!is_file($makefile_path)) {
			self::markTestSkipped('Repository Makefile is not available in plugin-only test installs.');
		}

		$makefile = file_get_contents($makefile_path);
		self::assertIsString($makefile);
		self::assertStringContainsString('WPORG_LISTING_DOCKER', $makefile);
		self::assertStringContainsString('apt-get install -y -qq rsync subversion', $makefile);
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

	public function test_pro_release_sdk_gate_rejects_a_vendored_sdk_older_than_the_latest_stable_version(): void {
		$script = $this->root . '/build/verify-freemius-sdk.sh';
		$pro_dir = $this->freemius_sdk_fixture( '2.13.4' );
		try {
			$result = $this->run_command(
				'bash ' . escapeshellarg( $script ) .
				' --pro-dir ' . escapeshellarg( $pro_dir ) .
				' --latest-version 999.0.0'
			);

			self::assertNotSame( 0, $result['code'] );
			self::assertStringContainsString( 'update the vendored SDK before releasing Pro', $result['output'] );
		} finally {
			$this->remove_dir( $pro_dir );
		}
	}

	public function test_pro_release_sdk_gate_accepts_the_exact_vendored_sdk_version(): void {
		$script = $this->root . '/build/verify-freemius-sdk.sh';
		$pro_dir = $this->freemius_sdk_fixture( '2.13.5' );
		try {
			$result = $this->run_command(
				'bash ' . escapeshellarg( $script ) .
				' --pro-dir ' . escapeshellarg( $pro_dir ) .
				' --latest-version 2.13.5'
			);

			self::assertSame( 0, $result['code'], $result['output'] );
			self::assertStringContainsString( 'Freemius SDK version verified: 2.13.5', $result['output'] );
		} finally {
			$this->remove_dir( $pro_dir );
		}
	}

	private function freemius_sdk_fixture( string $version ): string {
		$dir = sys_get_temp_dir() . '/kazus-freemius-sdk-' . bin2hex( random_bytes( 6 ) );
		mkdir( $dir . '/vendor/freemius', 0700, true );
		file_put_contents( $dir . '/vendor/freemius/start.php', "<?php\n" . '$this_sdk_version' . " = '{$version}';\n" );

		return $dir;
	}

	private function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		@unlink( $dir . '/vendor/freemius/start.php' );
		@rmdir( $dir . '/vendor/freemius' );
		@rmdir( $dir . '/vendor' );
		@rmdir( $dir );
	}

	public function test_release_metadata_allows_free_patch_without_matching_pro_patch(): void {
		$script = file_get_contents($this->root . '/build/release-metadata.php');

		self::assertIsString($script);
		self::assertStringNotContainsString('Pro plugin Version header does not match requested version.', $script);
		self::assertStringContainsString("'pro_version' =>", $script);
		self::assertStringContainsString("'pro_requires_core' =>", $script);
		self::assertStringContainsString('"kazcode-universal-storage-pro-{$pro_version}.zip"', $script);
	}

	public function test_build_release_packages_pro_using_declared_pro_version(): void {
		$script = file_get_contents($this->root . '/build/build-release.sh');
		$from_tag = file_get_contents($this->root . '/build/release-build-from-tag.sh');

		self::assertIsString($script);
		self::assertIsString($from_tag);
		self::assertStringContainsString('KAZUS_PRO_VERSION', $script);
		self::assertStringContainsString('PRO_ZIP="${DIST}/${PRO_SLUG}-${PRO_VERSION}.zip"', $script);
		self::assertStringContainsString("find dist -maxdepth 1 -name 'kazcode-universal-storage-pro-*.zip'", $from_tag);
		self::assertStringNotContainsString('dist/kazcode-universal-storage-pro-${VERSION}.zip', $from_tag);
	}

	public function test_build_release_fails_closed_when_the_pro_package_lacks_its_required_readme(): void {
		$script = file_get_contents($this->root . '/build/build-release.sh');
		$verifier = file_get_contents($this->root . '/build/verify-pro-package.sh');

		self::assertIsString($script);
		self::assertIsString($verifier);
		self::assertStringContainsString('verify-pro-package.sh', $script);
		self::assertStringContainsString('SLUG="kazcode-universal-storage-pro"', $verifier);
		self::assertStringContainsString('"${SLUG}/readme.txt"', $verifier);
		self::assertStringContainsString('grep -Fxq "${SLUG}/readme.txt" <<< "${MANIFEST}"', $verifier);
		self::assertStringNotContainsString("printf '%s\\n' \"\${MANIFEST}\" | grep", $verifier);
	}

	public function test_ci_package_audit_does_not_hide_matches_behind_pipefail_broken_pipes(): void {
		$ci = dirname( $this->root ) . '/tools/ci/release-build.sh';
		if ( ! is_file( $ci ) ) {
			self::markTestSkipped( 'Repository CI scripts are not available in plugin-only test installs.' );
		}
		$script = file_get_contents( $ci );

		self::assertIsString( $script );
		self::assertStringNotContainsString( 'echo "$PRO_LIST" | grep', $script );
		self::assertStringContainsString( 'grep -Fxq "kazcode-universal-storage-pro/readme.txt" <<< "$PRO_LIST"', $script );
	}

	public function test_github_free_release_notes_use_actual_pro_compatibility_not_free_version(): void {
		$script = file_get_contents($this->root . '/build/release-github-free.sh');

		self::assertIsString($script);
		self::assertStringContainsString('KAZUS_PRO_VERSION', $script);
		self::assertStringContainsString('KAZUS_PRO_REQUIRES_CORE', $script);
		self::assertStringContainsString('## Pro ${PRO_VERSION}', $script);
		self::assertStringContainsString('${PRO_VERSION} requires Free/Core ${PRO_REQUIRES_CORE} or newer', $script);
		self::assertStringNotContainsString('## Pro ${VERSION}', $script);
		self::assertStringNotContainsString('${VERSION} requires Free/Core ${VERSION} or newer', $script);
	}

	public function test_makefile_wordpress_release_verification_is_worktree_safe_by_default(): void {
		$makefile_path = dirname($this->root) . '/Makefile';
		if (!is_file($makefile_path)) {
			self::markTestSkipped('Repository Makefile is not available in plugin-only test installs.');
		}

		$makefile = file_get_contents($makefile_path);

		self::assertIsString($makefile);
		self::assertStringContainsString('release-verify-wordpress-org.sh', $makefile);
		self::assertStringContainsString('$(RELEASE_DOCKER)', $makefile);
		self::assertStringContainsString('ifeq ($(origin CONTAINER),command line)', $makefile);
		self::assertStringNotContainsString('release-verify-wp: setup', $makefile);
		self::assertStringNotContainsString("cd $(CORE_DIR) && curl -fsSL \"https://downloads.wordpress.org/plugin/kazcode-universal-storage.$(VERSION).zip\"", $makefile);
	}

	public function test_makefile_release_mount_root_does_not_require_newer_git_path_format_support(): void {
		$makefile_path = dirname($this->root) . '/Makefile';
		if (!is_file($makefile_path)) {
			self::markTestSkipped('Repository Makefile is not available in plugin-only test installs.');
		}

		$makefile = file_get_contents($makefile_path);

		self::assertIsString($makefile);
		self::assertStringContainsString('RELEASE_MOUNT_ROOT := $(shell', $makefile);
		self::assertStringNotContainsString('rev-parse --path-format=absolute --git-common-dir', $makefile);

		$result = $this->run_command(
			'make -C ' . escapeshellarg(dirname($this->root)) .
			' -n release-prepare VERSION=1.2.0 TAG=v1.2.0 COMMIT=release-test COMPOSER_PHAR_SRC=/dev/null'
		);
		self::assertSame(0, $result['code'], $result['output']);
		self::assertStringNotContainsString('Syntax error', $result['output']);
	}

	public function test_wordpress_release_verifier_supports_explicit_container_without_fixed_plugin_path(): void {
		$script = file_get_contents($this->root . '/build/release-verify-wordpress-org.sh');

		self::assertIsString($script);
		self::assertStringContainsString('--container NAME', $script);
		self::assertStringContainsString('docker|podman', $script);
		self::assertStringContainsString('container not found or not running', $script);
		self::assertStringContainsString('must provide php and unzip', $script);
		self::assertStringContainsString('release-integrity.php" post-publish', $script);
		self::assertStringContainsString('docker cp', str_replace('"${RUNTIME}" cp', 'docker cp', $script));
		self::assertStringNotContainsString('/var/www/html/wp-content/plugins/kazcode-universal-storage', $script);
	}

	public function test_wordpress_release_verifier_fails_clearly_when_no_php_runtime_is_available(): void {
		$result = $this->run_command('PATH=/nonexistent /bin/bash ' . escapeshellarg($this->root . '/build/release-verify-wordpress-org.sh') . ' --version 0.0.0 --zip ' . escapeshellarg($this->root . '/missing.zip'));

		self::assertNotSame(0, $result['code']);
		self::assertStringContainsString('local ZIP not found', $result['output']);
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
