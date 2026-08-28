<?php
/**
 * Settings sanitize / defaults (no WordPress option API).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\LocalStoragePolicy;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class SettingsTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_defaults_contain_required_keys(): void {
		$defaults = Settings::defaults();
		$this->assertArrayHasKey('enabled', $defaults);
		$this->assertArrayHasKey('serve_from_s3', $defaults);
		$this->assertArrayHasKey('bucket', $defaults);
		$this->assertFalse($defaults['enabled']);
	}

	public function test_sanitize_prefix(): void {
		$settings = new Settings();
		$this->assertSame('wordpress/', $settings->sanitize_prefix('/wordpress'));
		$this->assertSame('wordpress/', $settings->sanitize_prefix('wordpress/'));
		$this->assertSame('', $settings->sanitize_prefix(''));
	}

	public function test_policy_select_syncs_legacy_flags(): void {
		$settings = new Settings();
		$clean    = $settings->sanitize(
			array(
				'local_storage_policy' => LocalStoragePolicy::REMOTE_ONLY,
			),
			Settings::defaults()
		);
		$this->assertSame( LocalStoragePolicy::REMOTE_ONLY, $clean['local_storage_policy'] );
		$this->assertTrue( $clean['delete_local_after_upload'] );
		$this->assertFalse( $clean['keep_local_files'] );
		$this->assertTrue( $clean['verify_before_delete'] );
	}

	public function test_private_media_can_be_enabled_on_free(): void {
		// Private/signed media delivery is a Free capability — it is the
		// serve-time URL-rewriting pipeline every attachment already goes
		// through, not a standalone premium operation that can be excised
		// without forking that core machinery. See docs/FREE-PRO-CODE-AUDIT.md.
		$settings = new Settings();
		$clean    = $settings->sanitize(
			array(
				'_s3ms_full_form' => '1',
				'private_media'   => '1',
			),
			Settings::defaults()
		);
		$this->assertTrue( $clean['private_media'] );
	}

	public function test_private_media_can_be_disabled_on_free(): void {
		$settings = new Settings();
		$current  = array_merge( Settings::defaults(), array( 'private_media' => true ) );
		$clean    = $settings->sanitize(
			array(
				'_s3ms_full_form' => '1',
				'bucket'          => 'renamed-bucket',
			),
			$current
		);
		$this->assertFalse( $clean['private_media'] );
	}

	public function test_is_private_media_ignores_plan_once_configured(): void {
		update_option(
			Settings::OPTION_KEY,
			array_merge( Settings::defaults(), array( 'private_media' => true ) )
		);
		$settings = new Settings();
		$this->assertTrue( $settings->is_private_media() );
	}

	/**
	 * Regression: the Settings form always posts every field, so checking
	 * "Inherit network settings" and saving used to persist this site's own
	 * (blank) bucket/region/etc. alongside the flag — those blank values then
	 * permanently shadowed the network defaults in all()'s
	 * array_merge($base, $stored), making inheritance a no-op the instant it
	 * was saved. Reproduced live on a fresh multisite subsite: enabling
	 * inherit via Settings::update() left get('bucket') empty instead of
	 * resolving the network default. See NetworkSettingsPage.
	 */
	public function test_inherit_network_settings_actually_inherits_after_a_full_form_save(): void {
		WpStubs::$is_multisite = true;
		update_site_option(
			Settings::NETWORK_OPTION_KEY,
			array(
				'bucket'          => 'network-bucket',
				'region'          => 'eu-west-1',
				'object_prefix'   => 'site-{blog_id}/',
				'endpoint'        => '',
				'cdn_url'         => '',
				'credential_mode' => 'keys',
			)
		);

		$settings = new Settings();
		// A fresh subsite's Settings page: user checks "Inherit network
		// settings" and saves the (otherwise untouched, so blank) full form.
		$settings->update(
			array(
				'_s3ms_full_form'          => '1',
				'inherit_network_settings' => '1',
				'bucket'                   => '',
				'region'                   => '',
			)
		);

		$this->assertSame( 'network-bucket', $settings->get( 'bucket' ) );
		$this->assertSame( 'eu-west-1', $settings->get( 'region' ) );
		$this->assertSame( 'site-{blog_id}/', $settings->get( 'object_prefix' ) );
	}

	public function test_inherit_network_settings_does_not_leak_into_secrets(): void {
		WpStubs::$is_multisite = true;
		update_site_option( Settings::NETWORK_OPTION_KEY, array( 'bucket' => 'network-bucket' ) );

		$settings = new Settings();
		$settings->update(
			array(
				'_s3ms_full_form'          => '1',
				'inherit_network_settings' => '1',
				'access_key_id'            => 'AKIASITEOWNKEY',
			)
		);

		// Secrets stay per-site even while connection fields inherit.
		$this->assertSame( 'AKIASITEOWNKEY', $settings->get( 'access_key_id' ) );
	}

	public function test_explicit_site_bucket_still_overrides_network_default(): void {
		WpStubs::$is_multisite = true;
		update_site_option( Settings::NETWORK_OPTION_KEY, array( 'bucket' => 'network-bucket' ) );

		$settings = new Settings();
		$settings->update(
			array(
				'_s3ms_full_form'          => '1',
				'inherit_network_settings' => '', // not inheriting.
				'bucket'                   => 'this-sites-own-bucket',
			)
		);

		$this->assertSame( 'this-sites-own-bucket', $settings->get( 'bucket' ) );
	}

	public function test_status_transition_constants(): void {
		$this->assertSame('pending', \Kazcode\WpStorage\Attachment\AttachmentOffloader::STATUS_PENDING);
		$this->assertSame('uploading', \Kazcode\WpStorage\Attachment\AttachmentOffloader::STATUS_UPLOADING);
		$this->assertSame('offloaded', \Kazcode\WpStorage\Attachment\AttachmentOffloader::STATUS_OFFLOADED);
		$this->assertSame('failed', \Kazcode\WpStorage\Attachment\AttachmentOffloader::STATUS_FAILED);
	}
}
