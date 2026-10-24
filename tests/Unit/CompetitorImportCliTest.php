<?php
/**
 * Competitor import WP-CLI command tests.
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

	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ) {
			$key = strtolower( (string) $key );
			return (string) preg_replace( '/[^a-z0-9_\-]/', '', $key );
		}
	}

	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $str ) {
			return trim( strip_tags( (string) $str ) );
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
	use Kazcode\WpStorage\Services\CompetitorImportService;
	use PHPUnit\Framework\TestCase;

	final class CompetitorImportCliTest extends TestCase {

		protected function setUp(): void {
			\WP_CLI::$lines     = array();
			\WP_CLI::$successes = array();
			if ( function_exists( 'remove_all_filters' ) ) {
				remove_all_filters( 'kazus_pro_service_factory' );
			}
		}

		public function test_import_detect_delegates_and_redacts_credentials(): void {
			$imports = $this->createMock( CompetitorImportService::class );
			$imports->expects( $this->once() )
				->method( 'detect' )
				->willReturn(
					array(
						'wp-offload-media' => array(
							'source_id'         => 'wp-offload-media',
							'secret_access_key' => 'do-not-print',
							'token'             => 'session-token',
							'bucket'            => 'media-bucket',
						),
					)
				);

			$this->command( $imports )->import( array( 'detect' ), array() );

			$output = implode( "\n", \WP_CLI::$lines );
			$this->assertStringContainsString( 'media-bucket', $output );
			$this->assertStringNotContainsString( 'do-not-print', $output );
			$this->assertStringNotContainsString( 'session-token', $output );
			$this->assertSame( array( 'Import sources detected.' ), \WP_CLI::$successes );
		}

		public function test_import_analyze_delegates_to_dry_run_with_sanitized_source_and_profile(): void {
			$imports = $this->createMock( CompetitorImportService::class );
			$imports->expects( $this->once() )
				->method( 'start_dry_run' )
				->with( 'wp-offload-media', 12 )
				->willReturn( array( 'success' => true, 'uuid' => '7b85795b-f5c9-41ac-a842-51d139a103c9' ) );

			$this->command( $imports )->import( array( 'analyze' ), array( 'source' => 'wp-offload-media', 'profile' => '12' ) );

			$this->assertSame( array( 'Import analysis started.' ), \WP_CLI::$successes );
		}

		public function test_import_run_without_matching_dry_run_fails_unless_force_reanalyze_is_supplied(): void {
			$imports = $this->createMock( CompetitorImportService::class );
			$imports->expects( $this->exactly( 3 ) )
				->method( 'start_commit' )
				->with( 'wp-offload-media', 12 )
				->willReturnOnConsecutiveCalls(
					array( 'success' => false, 'code' => 'matching_dry_run_required', 'message' => 'Commit requires a completed dry run.' ),
					array( 'success' => false, 'code' => 'matching_dry_run_required', 'message' => 'Commit requires a completed dry run.' ),
					array( 'success' => true, 'uuid' => '56b0e426-b7b8-493d-9e7b-2e95159a66c1', 'status' => 'running' )
				);
			$imports->expects( $this->once() )
				->method( 'start_dry_run' )
				->with( 'wp-offload-media', 12 )
				->willReturn( array( 'success' => true, 'uuid' => '7b85795b-f5c9-41ac-a842-51d139a103c9', 'status' => 'running' ) );
			$imports->expects( $this->exactly( 2 ) )
				->method( 'process_next_page' )
				->with( '7b85795b-f5c9-41ac-a842-51d139a103c9' )
				->willReturnOnConsecutiveCalls(
					array( 'success' => true, 'uuid' => '7b85795b-f5c9-41ac-a842-51d139a103c9', 'status' => 'running' ),
					array( 'success' => true, 'uuid' => '7b85795b-f5c9-41ac-a842-51d139a103c9', 'status' => 'completed' )
				);
			$command = $this->command( $imports );

			try {
				$command->import( array( 'run' ), array( 'source' => 'wp-offload-media', 'profile' => '12' ) );
				$this->fail( 'Expected run without force to fail.' );
			} catch ( \RuntimeException $e ) {
				$this->assertStringContainsString( 'completed dry run', $e->getMessage() );
			}

			$command->import( array( 'run' ), array( 'source' => 'wp-offload-media', 'profile' => '12', 'force-reanalyze' => true ) );

			$this->assertSame( array( 'Import analysis completed.', 'Import commit started.' ), \WP_CLI::$successes );
		}

		public function test_import_status_and_resume_delegate_uuid_commands(): void {
			$uuid    = '7b85795b-f5c9-41ac-a842-51d139a103c9';
			$imports = $this->createMock( CompetitorImportService::class );
			$imports->expects( $this->once() )
				->method( 'status' )
				->with( $uuid )
				->willReturn( array( 'success' => true, 'uuid' => $uuid, 'status' => 'paused' ) );
			$imports->expects( $this->once() )
				->method( 'resume' )
				->with( $uuid )
				->willReturn( array( 'success' => true, 'uuid' => $uuid, 'status' => 'running' ) );
			$command = $this->command( $imports );

			$command->import( array( 'status' ), array( 'uuid' => strtoupper( $uuid ) ) );
			$command->import( array( 'resume' ), array( 'uuid' => $uuid ) );

			$this->assertSame( array( 'Import status loaded.', 'Import resumed.' ), \WP_CLI::$successes );
		}

		public function test_malformed_cli_source_profile_and_uuid_fail_without_delegating(): void {
			$imports = $this->createMock( CompetitorImportService::class );
			$imports->expects( $this->never() )->method( 'start_dry_run' );
			$imports->expects( $this->never() )->method( 'status' );
			$command = $this->command( $imports );

			foreach (
				array(
					static fn() => $command->import( array( 'analyze' ), array( 'source' => 'WP Offload Media!!!', 'profile' => '12' ) ),
					static fn() => $command->import( array( 'analyze' ), array( 'source' => 'wp-offload-media', 'profile' => '0' ) ),
					static fn() => $command->import( array( 'status' ), array( 'uuid' => '../../not-a-uuid' ) ),
				) as $call
			) {
				try {
					$call();
					$this->fail( 'Expected malformed command arguments to fail.' );
				} catch ( \RuntimeException $e ) {
					$this->assertNotSame( '', $e->getMessage() );
				}
			}
		}

		public function test_storage_migrate_batch_with_failures_exits_nonzero(): void {
			add_filter(
				'kazus_pro_service_factory',
				static function ( $factory, string $id ) {
					if ( $id !== 'storage_migration' ) {
						return $factory;
					}
					return static fn() => new class {
						public function migrate_batch(
							int $source_profile_id,
							int $dest_profile_id,
							int $batch_size = 20,
							int $after_id = 0,
							bool $dry_run = false,
							bool $delete_source = false
						): array {
							return array(
								'processed'     => 2,
								'success'       => 1,
								'failed'        => 1,
								'next_after_id' => 42,
								'results'       => array(),
							);
						}
					};
				},
				10,
				2
			);

			try {
				$this->command( $this->createMock( CompetitorImportService::class ) )->storage_migrate(
					array(),
					array(
						'source-profile' => '1',
						'dest-profile'   => '2',
					)
				);
				$this->fail( 'Expected failed batch migration to return a nonzero WP-CLI error.' );
			} catch ( \RuntimeException $e ) {
				$this->assertStringContainsString( 'failed=1', $e->getMessage() );
			}
		}

		private function command( CompetitorImportService $imports ): CliCommand {
			$plugin = $this->createMock( Plugin::class );
			$plugin->method( 'competitor_imports' )->willReturn( $imports );
			$plugin->method( 'settings' )->willReturn( $this->createMock( Settings::class ) );
			return new CliCommand( $plugin );
		}
	}
}
