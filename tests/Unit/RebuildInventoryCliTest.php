<?php
/**
 * Rebuild inventory WP-CLI command tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace {
	if ( ! class_exists( 'WP_CLI' ) ) {
		class WP_CLI {
			/** @var list<string> */
			public static array $lines = array();
			/** @var list<string> */
			public static array $successes = array();
			/** @var list<string> */
			public static array $warnings = array();

			public static function line( string $message = '' ): void {
				self::$lines[] = $message;
			}

			public static function log( string $message = '' ): void {
				self::$lines[] = $message;
			}

			public static function success( string $message = '' ): void {
				self::$successes[] = $message;
			}

			public static function warning( string $message = '' ): void {
				self::$warnings[] = $message;
			}

			public static function error( string $message = '' ): void {
				throw new \RuntimeException( $message );
			}

			public static function add_command( string $name, object $callable ): void {
				$GLOBALS['s3ms_test_wp_cli_commands'][ $name ] = $callable;
			}
		}
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
			return json_encode( $value, $flags, $depth );
		}
	}
}

namespace Kazcode\WpStorage\Tests {

	use Kazcode\WpStorage\CLI\CliCommand;
	use Kazcode\WpStorage\Core\Settings;
	use Kazcode\WpStorage\Plugin;
	use Kazcode\WpStorage\Services\RebuildInventoryService;
	use PHPUnit\Framework\TestCase;

	final class RebuildInventoryCliTest extends TestCase {

		protected function setUp(): void {
			\WP_CLI::$lines     = array();
			\WP_CLI::$successes = array();
			\WP_CLI::$warnings  = array();
		}

		public function test_rejects_non_positive_profile_id_before_service_call(): void {
			$service = $this->createMock( RebuildInventoryService::class );
			$service->expects( $this->never() )->method( 'rebuild_attachment' );

			foreach ( array( '0', '-1', 'abc', '12x' ) as $raw ) {
				try {
					$this->command( $service )->rebuild_inventory(
						array(),
						array(
							'attachment-id' => '42',
							'profile-id'    => $raw,
							'dry-run'       => true,
						)
					);
					$this->fail( 'Expected WP_CLI::error for profile-id=' . $raw );
				} catch ( \RuntimeException $e ) {
					$this->assertStringContainsString( 'positive --profile-id', $e->getMessage() );
				}
			}
		}

		public function test_rejects_malformed_attachment_id_before_service_call(): void {
			$service = $this->createMock( RebuildInventoryService::class );
			$service->expects( $this->never() )->method( 'rebuild_attachment' );

			foreach ( array( '12x', '1e2', '0', '-3', 'abc' ) as $raw ) {
				try {
					$this->command( $service )->rebuild_inventory(
						array(),
						array(
							'attachment-id' => $raw,
							'dry-run'       => true,
						)
					);
					$this->fail( 'Expected WP_CLI::error for attachment-id=' . $raw );
				} catch ( \RuntimeException $e ) {
					$this->assertMatchesRegularExpression( '/attachment-id/', $e->getMessage() );
				}
			}
		}

		public function test_failed_outcome_prints_service_failure_message(): void {
			$service = $this->createMock( RebuildInventoryService::class );
			$service->expects( $this->once() )
				->method( 'rebuild_attachment' )
				->with( 42, 3, true )
				->willReturn(
					array(
						'success' => false,
						'outcome' => RebuildInventoryService::OUTCOME_FAILED,
						'message' => 'Rebuild dry-run failed.',
						'counts'  => array(
							'present'           => 0,
							'exists_only'       => 0,
							'present_adopted'   => 0,
							'confirmed_missing' => 0,
							'conflicts'         => 0,
							'auth'              => 1,
							'transient'         => 0,
						),
					)
				);

			try {
				$this->command( $service )->rebuild_inventory(
					array(),
					array(
						'attachment-id' => '42',
						'profile-id'    => '3',
						'dry-run'       => true,
					)
				);
				$this->fail( 'Expected WP_CLI::error' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'Rebuild dry-run failed.', $e->getMessage() );
			}
			$this->assertSame( array(), \WP_CLI::$successes );
		}

		private function command( RebuildInventoryService $service ): CliCommand {
			$plugin = $this->createMock( Plugin::class );
			$plugin->method( 'rebuild_inventory' )->willReturn( $service );
			$plugin->method( 'settings' )->willReturn( $this->createMock( Settings::class ) );
			return new CliCommand( $plugin );
		}
	}
}
