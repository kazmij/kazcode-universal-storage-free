<?php
/**
 * Caller-level restore preflight acknowledge gates (CLI / REST / Media / Background).
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

	if ( ! class_exists( 'WP_REST_Request' ) ) {
		class WP_REST_Request {
			/** @param array<string, mixed> $params */
			public function __construct( private array $params = array() ) {
			}

			public function get_param( string $key ) {
				return $this->params[ $key ] ?? null;
			}
		}
	}

	if ( ! class_exists( 'WP_REST_Response' ) ) {
		class WP_REST_Response {
			public function __construct( private mixed $data = null, private int $status = 200 ) {
			}

			public function get_data(): mixed {
				return $this->data;
			}

			public function get_status(): int {
				return $this->status;
			}
		}
	}
}

namespace Kazcode\WpStorage\Tests {

	use Kazcode\WpStorage\Admin\MediaLibraryColumn;
	use Kazcode\WpStorage\Attachment\AttachmentRestorer;
	use Kazcode\WpStorage\CLI\CliCommand;
	use Kazcode\WpStorage\Infrastructure\BatchProcessor;
	use Kazcode\WpStorage\Plugin;
	use Kazcode\WpStorage\Services\MigrationService;
	use PHPUnit\Framework\TestCase;

	final class RestoreEntrypointAcknowledgeTest extends TestCase {

		protected function setUp(): void {
			\WP_CLI::$lines     = array();
			\WP_CLI::$successes = array();
			\WP_CLI::$warnings  = array();
		}

		public function test_explicit_true_is_fail_closed(): void {
			$this->assertTrue( AttachmentRestorer::explicit_true( true ) );
			$this->assertTrue( AttachmentRestorer::explicit_true( 1 ) );
			$this->assertTrue( AttachmentRestorer::explicit_true( '1' ) );
			$this->assertTrue( AttachmentRestorer::explicit_true( 'true' ) );
			$this->assertTrue( AttachmentRestorer::explicit_true( 'yes' ) );

			$this->assertFalse( AttachmentRestorer::explicit_true( false ) );
			$this->assertFalse( AttachmentRestorer::explicit_true( 0 ) );
			$this->assertFalse( AttachmentRestorer::explicit_true( '0' ) );
			$this->assertFalse( AttachmentRestorer::explicit_true( 'false' ) );
			$this->assertFalse( AttachmentRestorer::explicit_true( 'no' ) );
			$this->assertFalse( AttachmentRestorer::explicit_true( 'yesplease' ) );
			$this->assertFalse( AttachmentRestorer::explicit_true( array( true ) ) );
			$this->assertFalse( AttachmentRestorer::explicit_true( null ) );
		}

		public function test_cli_warning_without_acknowledge_does_not_restore(): void {
			$migration = $this->createMock( MigrationService::class );
			$migration->expects( $this->once() )
				->method( 'restore_batch' )
				->with( 100, 42, 0, false )
				->willReturn(
					array(
						'processed' => 1,
						'success'   => 0,
						'failed'    => 1,
						'next_after_id' => 42,
						'results'   => array(
							array(
								'success'   => false,
								'status'    => 'warning_requires_acknowledge',
								'message'   => 'Restore preflight WARNING requires acknowledge_warning.',
								'preflight' => array(
									'outcome'        => 'WARNING',
									'required_bytes' => 10,
									'free_bytes'     => 1000,
									'reserve_bytes'  => 64 * 1024 * 1024,
									'reasons'        => array( 'transient_remote' ),
									'counts'         => array( 'TRANSIENT' => 1 ),
								),
							),
						),
					)
				);

			$plugin = $this->createMock( Plugin::class );
			$plugin->method( 'migration_service' )->willReturn( $migration );

			try {
				$this->cli( $plugin )->restore( array(), array( 'attachment-id' => '42' ) );
				$this->fail( 'Expected WP_CLI::error' );
			} catch ( \RuntimeException $e ) {
				$this->assertStringContainsString( 'Restore failed', $e->getMessage() );
			}
			$this->assertNotEmpty( \WP_CLI::$warnings );
		}

		public function test_cli_warning_with_acknowledge_passes_true(): void {
			$migration = $this->createMock( MigrationService::class );
			$migration->expects( $this->once() )
				->method( 'restore_batch' )
				->with( 100, 42, 0, true )
				->willReturn(
					array(
						'processed'     => 1,
						'success'       => 1,
						'failed'        => 0,
						'next_after_id' => 42,
						'results'       => array(
							array( 'success' => true, 'message' => 'Restore completed.' ),
						),
					)
				);

			$plugin = $this->createMock( Plugin::class );
			$plugin->method( 'migration_service' )->willReturn( $migration );

			$this->cli( $plugin )->restore(
				array(),
				array(
					'attachment-id'        => '42',
					'acknowledge-warning'  => true,
				)
			);
			$this->assertNotEmpty( \WP_CLI::$successes );
		}

		public function test_rest_warning_without_acknowledge_passes_false(): void {
			$migration = $this->createMock( MigrationService::class );
			$migration->expects( $this->once() )
				->method( 'restore_batch' )
				->with( 20, 7, 0, false )
				->willReturn(
					array(
						'processed' => 1,
						'success'   => 0,
						'failed'    => 1,
						'results'   => array(
							array(
								'success' => false,
								'status'  => 'warning_requires_acknowledge',
							),
						),
					)
				);

			$processor = new BatchProcessor( $migration );
			$response  = $processor->rest_restore_batch(
				new \WP_REST_Request(
					array(
						'batch_size'            => 20,
						'attachment_id'         => 7,
						'acknowledge_warning'   => 'false',
					)
				)
			);
			$data = $response->get_data();
			$this->assertSame( 0, $data['success'] );
			$this->assertSame( 'warning_requires_acknowledge', $data['results'][0]['status'] );
		}

		public function test_rest_acknowledge_true_string_is_accepted(): void {
			$migration = $this->createMock( MigrationService::class );
			$migration->expects( $this->once() )
				->method( 'restore_batch' )
				->with( 20, 7, 0, true )
				->willReturn(
					array(
						'processed' => 1,
						'success'   => 1,
						'failed'    => 0,
						'results'   => array( array( 'success' => true ) ),
					)
				);

			$processor = new BatchProcessor( $migration );
			$processor->rest_restore_batch(
				new \WP_REST_Request(
					array(
						'batch_size'          => 20,
						'attachment_id'       => 7,
						'acknowledge_warning' => 'true',
					)
				)
			);
		}

		public function test_media_library_restore_does_not_count_warning_as_success(): void {
			$restorer = $this->createMock( AttachmentRestorer::class );
			$restorer->expects( $this->once() )
				->method( 'restore' )
				->with( 9 )
				->willReturn(
					array(
						'success' => false,
						'status'  => 'warning_requires_acknowledge',
						'message' => 'Restore preflight WARNING requires acknowledge_warning.',
					)
				);

			$plugin = $this->createMock( Plugin::class );
			$plugin->method( 'restorer' )->willReturn( $restorer );

			$ref  = new \ReflectionClass( Plugin::class );
			$prop = $ref->getProperty( 'instance' );
			$prop->setAccessible( true );
			$prop->setValue( null, $plugin );

			try {
				$column = new MediaLibraryColumn();
				$method = new \ReflectionMethod( MediaLibraryColumn::class, 'run_many' );
				$method->setAccessible( true );
				/** @var array{ok:int,fail:int,message:string} $summary */
				$summary = $method->invoke( $column, 'restore', array( 9 ) );
				$this->assertSame( 0, $summary['ok'] );
				$this->assertSame( 1, $summary['fail'] );
				$this->assertStringContainsString( 'acknowledge_warning', $summary['message'] );
			} finally {
				$prop->setValue( null, null );
			}
		}

		public function test_background_migrator_restore_batch_does_not_auto_acknowledge(): void {
			$source = file_get_contents(
				dirname( __DIR__, 2 ) . '/includes/Services/BackgroundMigrator.php'
			);
			$this->assertIsString( $source );
			$this->assertMatchesRegularExpression(
				'/restore_batch\s*\(\s*\$batch\s*,\s*null\s*,\s*\$after\s*\)/',
				$source
			);
			$this->assertStringNotContainsString(
				'acknowledge_warning',
				$source
			);
		}

		private function cli( Plugin $plugin ): CliCommand {
			return new CliCommand( $plugin );
		}
	}
}
