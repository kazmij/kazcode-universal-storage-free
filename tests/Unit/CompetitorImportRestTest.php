<?php
/**
 * Competitor import REST endpoint tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace {
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

	if ( ! function_exists( 'register_rest_route' ) ) {
		function register_rest_route( string $namespace, string $route, array $args ): bool {
			$GLOBALS['s3ms_test_rest_routes'][ $namespace . $route ] = $args;
			return true;
		}
	}

	if ( ! function_exists( 'current_user_can' ) ) {
		function current_user_can( string $capability ): bool {
			return in_array( $capability, $GLOBALS['s3ms_test_current_user_caps'] ?? array(), true );
		}
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
			return json_encode( $value, $flags, $depth );
		}
	}
}

namespace Kazcode\WpStorage\Tests {

	use Kazcode\WpStorage\Infrastructure\BatchProcessor;
	use Kazcode\WpStorage\Services\CompetitorImportService;
	use Kazcode\WpStorage\Services\MigrationService;
	use PHPUnit\Framework\TestCase;

	final class CompetitorImportRestTest extends TestCase {

		protected function setUp(): void {
			$GLOBALS['s3ms_test_rest_routes']       = array();
			$GLOBALS['s3ms_test_current_user_caps'] = array();
		}

		protected function tearDown(): void {
			$GLOBALS['s3ms_test_rest_routes']       = array();
			$GLOBALS['s3ms_test_current_user_caps'] = array();
		}

		public function test_registers_import_routes_with_manage_options_on_mutations(): void {
			$processor = new BatchProcessor(
				$this->createMock( MigrationService::class ),
				$this->createMock( CompetitorImportService::class )
			);

			$processor->register_routes();

			foreach (
				array(
					'GET:/imports/detect',
					'POST:/imports/dry-run/start',
					'POST:/imports/commit/start',
					'GET:/imports/(?P<uuid>[^/]+)',
					'POST:/imports/(?P<uuid>[^/]+)/pause',
					'POST:/imports/(?P<uuid>[^/]+)/resume',
					'POST:/imports/(?P<uuid>[^/]+)/cancel',
				) as $route
			) {
				list( $method, $path ) = explode( ':', $route, 2 );
				$key = 'kazcode-storage/v1' . $path;
				$this->assertArrayHasKey( $key, $GLOBALS['s3ms_test_rest_routes'] );
				$this->assertSame( $method, $GLOBALS['s3ms_test_rest_routes'][ $key ]['methods'] );
			}

			$mutation = $GLOBALS['s3ms_test_rest_routes']['kazcode-storage/v1/imports/dry-run/start'];
			$this->assertFalse( $mutation['permission_callback']() );
			$GLOBALS['s3ms_test_current_user_caps'] = array( 'manage_options' );
			$this->assertTrue( $mutation['permission_callback']() );
		}

		public function test_start_sanitizes_source_and_profile_before_delegating(): void {
			$imports = $this->createMock( CompetitorImportService::class );
			$imports->expects( $this->once() )
				->method( 'start_dry_run' )
				->with( 'wp-offload-media', 42 )
				->willReturn( array( 'success' => true, 'uuid' => '7b85795b-f5c9-41ac-a842-51d139a103c9' ) );
			$processor = new BatchProcessor( $this->createMock( MigrationService::class ), $imports );

			$response = $processor->rest_import_dry_run_start( new \WP_REST_Request( array( 'source' => 'wp-offload-media', 'profile' => '42' ) ) );

			$this->assertInstanceOf( \WP_REST_Response::class, $response );
			$this->assertSame( 200, $response->get_status() );
		}

		public function test_malformed_source_profile_and_uuid_return_safe_4xx_without_delegating(): void {
			$imports = $this->createMock( CompetitorImportService::class );
			$imports->expects( $this->never() )->method( 'start_dry_run' );
			$imports->expects( $this->never() )->method( 'status' );
			$processor = new BatchProcessor( $this->createMock( MigrationService::class ), $imports );

			$bad_source = $processor->rest_import_dry_run_start( new \WP_REST_Request( array( 'source' => 'WP Offload Media!!!', 'profile' => '42' ) ) );
			$bad_profile = $processor->rest_import_dry_run_start( new \WP_REST_Request( array( 'source' => 'wp-offload-media', 'profile' => '0' ) ) );
			$bad_uuid = $processor->rest_import_status( new \WP_REST_Request( array( 'uuid' => '../../not-a-uuid' ) ) );

			$this->assertInstanceOf( \WP_Error::class, $bad_source );
			$this->assertSame( 400, $bad_source->get_error_data()['status'] );
			$this->assertInstanceOf( \WP_Error::class, $bad_profile );
			$this->assertSame( 400, $bad_profile->get_error_data()['status'] );
			$this->assertInstanceOf( \WP_Error::class, $bad_uuid );
			$this->assertSame( 400, $bad_uuid->get_error_data()['status'] );
		}

		public function test_rest_response_redacts_credentials_from_service_payloads(): void {
			$imports = $this->createMock( CompetitorImportService::class );
			$imports->method( 'detect' )->willReturn(
				array(
					'wp-offload-media' => array(
						'source_id'         => 'wp-offload-media',
						'access_key_id'     => 'AKIASECRET',
						'secret_access_key' => 'do-not-print',
						'token'             => 'session-token',
						'bucket'            => 'media-bucket',
					),
				)
			);
			$processor = new BatchProcessor( $this->createMock( MigrationService::class ), $imports );

			$response = $processor->rest_import_detect();

			$encoded = wp_json_encode( $response->get_data() );
			$this->assertStringContainsString( 'media-bucket', $encoded );
			$this->assertStringNotContainsString( 'AKIASECRET', $encoded );
			$this->assertStringNotContainsString( 'do-not-print', $encoded );
			$this->assertStringNotContainsString( 'session-token', $encoded );
		}
	}
}
