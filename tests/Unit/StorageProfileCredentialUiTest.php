<?php
/**
 * Storage profile credential UI regression tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;

final class StorageProfileCredentialUiTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		$this->root = dirname( __DIR__, 2 );
	}

	public function test_profile_editor_exposes_customer_path_for_per_profile_credentials(): void {
		$markup = file_get_contents( $this->root . '/includes/Admin/StoragePage.php' );

		$this->assertIsString( $markup );
		$this->assertStringContainsString( 's3ms-profile-use-site-credentials', $markup );
		$this->assertStringContainsString( 's3ms-profile-access-key-id', $markup );
		$this->assertStringContainsString( 's3ms-profile-secret-access-key', $markup );
		$this->assertStringContainsString( 's3ms-profile-test', $markup );
		$this->assertStringNotContainsString( 'value="<?php echo esc_attr( $secret', $markup, 'Profile secrets must not be rendered back into HTML.' );
	}

	public function test_profile_editor_payload_sends_distinct_credentials_without_requiring_secret_redisplay(): void {
		$script = file_get_contents( $this->root . '/assets/admin.js' );

		$this->assertIsString( $script );
		$this->assertStringContainsString( 'use_site_credentials', $script );
		$this->assertStringContainsString( 'access_key_id', $script );
		$this->assertStringContainsString( 'secret_access_key', $script );
		$this->assertStringContainsString( 's3ms-profile-secret-access-key', $script );
		$this->assertStringContainsString( 'storage-profiles/test', $script );
		$this->assertMatchesRegularExpression(
			'/if \\(secretField && secretField\\.value !== \'\'\\) \\{\\s*payload\\.secret_access_key = secretField\\.value;\\s*\\}/s',
			$script,
			'Blank profile secret field must preserve the existing stored secret.'
		);
		$this->assertStringContainsString( "document.getElementById('s3ms-profile-secret-access-key')", $script );
		$this->assertStringContainsString( "secretField.value = '';", $script, 'Profile secret field must be cleared from the current DOM after a successful save.' );
	}

	public function test_profile_connection_endpoint_uses_saved_profile_route(): void {
		$processor = file_get_contents( $this->root . '/includes/Infrastructure/BatchProcessor.php' );

		$this->assertIsString( $processor );
		$this->assertStringContainsString( '/storage-profiles/test', $processor );
		$this->assertStringContainsString( 'rest_storage_profiles_test', $processor );
		$this->assertStringContainsString( 'ProfileStorageGateway', $processor );
	}
}
