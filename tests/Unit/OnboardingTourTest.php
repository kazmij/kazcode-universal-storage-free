<?php
/**
 * Admin onboarding tour behavior.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Admin\OnboardingTour;
use Kazcode\WpStorage\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class OnboardingTourTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
		WpStubs::$current_user_id = 7;
		WpStubs::$scripts['s3ms-admin'] = true;
		$_GET = array();
	}

	protected function tearDown(): void {
		WpStubs::reset();
		$_GET = array();
	}

	public function test_global_tour_disable_suppresses_auto_start_on_every_page(): void {
		update_user_meta( 7, 's3ms_tours_disabled', true );
		$_GET['page'] = 'kazcode-universal-storage-logs';

		( new OnboardingTour() )->enqueue( 'toplevel_page_kazcode-universal-storage' );

		$config = $this->localized_tour_config();
		$this->assertTrue( $config['dismissed'] );
		$this->assertTrue( $config['globallyDisabled'] );
		$this->assertSame( 'logs', $config['page'] );

		$_GET['page'] = 'kazcode-universal-storage-health';
		WpStubs::$inline_scripts = array();

		( new OnboardingTour() )->enqueue( 'kazcode-universal-storage_page_kazcode-universal-storage-health' );

		$config = $this->localized_tour_config();
		$this->assertTrue( $config['dismissed'] );
		$this->assertTrue( $config['globallyDisabled'] );
		$this->assertSame( 'health', $config['page'] );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function localized_tour_config(): array {
		$script = WpStubs::$inline_scripts['s3ms-admin'][0] ?? '';
		$this->assertNotSame( '', $script );
		$this->assertStringStartsWith( 'window.s3msTour = ', $script );
		$json = trim( substr( $script, strlen( 'window.s3msTour = ' ) ) );
		$json = rtrim( $json, ';' );
		$data = json_decode( $json, true );
		$this->assertIsArray( $data );
		return $data;
	}
}
