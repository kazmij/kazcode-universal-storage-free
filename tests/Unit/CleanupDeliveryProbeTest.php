<?php
/**
 * CleanupDeliveryProbe mirrors the active serve decision (#53).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Attachment\AttachmentOffloader;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Services\CleanupDeliveryProbe;
use Kazcode\WpStorage\Storage\ProfileDeliveryUrlResolver;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class CleanupDeliveryProbeTest extends TestCase {

	private const RELATIVE = '2026/08/photo.jpg';

	protected function setUp(): void {
		WpStubs::reset();
		WpStubs::set_meta( 7, '_s3ms_status', AttachmentOffloader::STATUS_OFFLOADED );
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_remote_url_from_recorded_profile_counts_as_remote_delivery(): void {
		$probe = $this->probe( $this->settings(), 'https://cdn.example.com/2026/08/photo.jpg' );

		$this->assertTrue( $probe->resolves_remotely( 7, self::RELATIVE ) );
	}

	public function test_serving_disabled_keeps_delivery_local(): void {
		$probe = $this->probe( $this->settings( serve: false ), 'https://cdn.example.com/2026/08/photo.jpg' );

		$this->assertFalse( $probe->resolves_remotely( 7, self::RELATIVE ) );
	}

	public function test_missing_public_url_config_keeps_delivery_local(): void {
		$probe = $this->probe( $this->settings( public_url: false ), 'https://cdn.example.com/2026/08/photo.jpg' );

		$this->assertFalse( $probe->resolves_remotely( 7, self::RELATIVE ) );
	}

	public function test_attachment_not_marked_offloaded_is_not_served_remotely(): void {
		WpStubs::set_meta( 7, '_s3ms_status', 'partial' );
		$probe = $this->probe( $this->settings(), 'https://cdn.example.com/2026/08/photo.jpg' );

		$this->assertFalse( $probe->resolves_remotely( 7, self::RELATIVE ) );
	}

	public function test_url_resolving_into_local_uploads_is_not_remote(): void {
		$probe = $this->probe( $this->settings(), 'http://example.test/uploads/2026/08/photo.jpg' );

		$this->assertFalse( $probe->resolves_remotely( 7, self::RELATIVE ) );
	}

	public function test_empty_url_is_not_remote(): void {
		$probe = $this->probe( $this->settings(), '' );

		$this->assertFalse( $probe->resolves_remotely( 7, self::RELATIVE ) );
	}

	public function test_private_media_is_delivered_by_signed_urls(): void {
		$urls = $this->createMock( ProfileDeliveryUrlResolver::class );
		$urls->expects( $this->never() )->method( 'url_for_attachment_relative' );

		$probe = new CleanupDeliveryProbe( $this->settings( private: true ), $urls );

		$this->assertTrue( $probe->resolves_remotely( 7, self::RELATIVE ) );
	}

	private function probe( Settings $settings, string $url ): CleanupDeliveryProbe {
		$urls = $this->createMock( ProfileDeliveryUrlResolver::class );
		$urls->method( 'url_for_attachment_relative' )->with( 7, self::RELATIVE )->willReturn( $url );
		return new CleanupDeliveryProbe( $settings, $urls );
	}

	private function settings( bool $serve = true, bool $public_url = true, bool $private = false ): Settings {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'is_serve_enabled' )->willReturn( $serve );
		$settings->method( 'has_public_url_config' )->willReturn( $public_url );
		$settings->method( 'is_private_media' )->willReturn( $private );
		return $settings;
	}
}
