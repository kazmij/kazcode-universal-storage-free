<?php
/**
 * REST batch processing for migration UI.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\ProServices;
use Kazcode\WpStorage\Plugin;
use Kazcode\WpStorage\Services\AuditLog;
use Kazcode\WpStorage\Services\BackgroundMigrator;
use Kazcode\WpStorage\Services\FailedItemsService;
use Kazcode\WpStorage\Services\HealthCheckService;
use Kazcode\WpStorage\Services\HealthScanService;
use Kazcode\WpStorage\Services\MigrationService;
use Kazcode\WpStorage\Services\ObjectStatsAggregator;
use Kazcode\WpStorage\Services\RepairAttachmentService;
use Kazcode\WpStorage\Services\RepairObjectService;
use Kazcode\WpStorage\Services\StorageProfileAdminService;
use Kazcode\WpStorage\Services\AdoptService;
use Kazcode\WpStorage\Services\CompetitorImportService;
use Kazcode\WpStorage\Storage\ProfileStorageGateway;
use Kazcode\WpStorage\Infrastructure\Queue\QueueJobType;

/**
 * Registers REST routes for admin migration UI.
 */
final class BatchProcessor {

	private MigrationService $migration;
	private ?CompetitorImportService $competitor_imports;

	public function __construct(MigrationService $migration, ?CompetitorImportService $competitor_imports = null) {
		$this->migration           = $migration;
		$this->competitor_imports = $competitor_imports;
	}

	/**
	 * Register REST API.
	 */
	public function register(): void {
		add_action('rest_api_init', array($this, 'register_routes'));
	}

	/**
	 * REST routes under kazcode-storage/v1.
	 */
	public function register_routes(): void {
		register_rest_route(
			'kazcode-storage/v1',
			'/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array($this, 'rest_stats'),
				'permission_callback' => array($this, 'can_manage'),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/migrate-batch',
			array(
				'methods'             => 'POST',
				'callback'            => array($this, 'rest_migrate_batch'),
				'permission_callback' => array($this, 'can_manage'),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/verify-batch',
			array(
				'methods'             => 'POST',
				'callback'            => array($this, 'rest_verify_batch'),
				'permission_callback' => array($this, 'can_manage'),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/restore-batch',
			array(
				'methods'             => 'POST',
				'callback'            => array($this, 'rest_restore_batch'),
				'permission_callback' => array($this, 'can_manage'),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/restore-preflight',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_restore_preflight' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/test-connection',
			array(
				'methods'             => 'POST',
				'callback'            => array($this, 'rest_test_connection'),
				'permission_callback' => array($this, 'can_manage'),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/storage-profiles/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_storage_profiles_test' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/failed',
			array(
				'methods'             => 'GET',
				'callback'            => array($this, 'rest_failed_list'),
				'permission_callback' => array($this, 'can_manage'),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/failed/ignore',
			array(
				'methods'             => 'POST',
				'callback'            => array($this, 'rest_failed_ignore'),
				'permission_callback' => array($this, 'can_manage'),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/failed/export',
			array(
				'methods'             => 'GET',
				'callback'            => array($this, 'rest_failed_export'),
				'permission_callback' => array($this, 'can_manage'),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/failed/clear',
			array(
				'methods'             => 'POST',
				'callback'            => array($this, 'rest_failed_clear'),
				'permission_callback' => array($this, 'can_manage'),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/background',
			array(
				'methods'             => 'GET',
				'callback'            => array($this, 'rest_background_status'),
				'permission_callback' => array($this, 'can_manage'),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/background/start',
			array(
				'methods'             => 'POST',
				'callback'            => array($this, 'rest_background_start'),
				'permission_callback' => array($this, 'can_manage'),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/background/stop',
			array(
				'methods'             => 'POST',
				'callback'            => array($this, 'rest_background_stop'),
				'permission_callback' => array($this, 'can_manage'),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array($this, 'rest_health'),
				'permission_callback' => array($this, 'can_manage'),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/health/objects',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_health_objects' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/health/scan',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_health_scan' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/health/orphan-scan',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_orphan_scan' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/repair',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_repair' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/storage-migrate',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_storage_migrate_status' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/storage-migrate-batch',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_storage_migrate_batch' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/storage-migrate-switch',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_storage_migrate_switch' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/adopt-batch',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_adopt_batch' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/imports/detect',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_import_detect' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/imports/dry-run/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_import_dry_run_start' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/imports/commit/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_import_commit_start' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/imports/(?P<uuid>[^/]+)/remediation/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_import_remediation_start' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/imports/(?P<uuid>[^/]+)/process',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_import_process' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/imports/(?P<uuid>[^/]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_import_status' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		foreach ( array( 'pause', 'resume', 'cancel' ) as $action ) {
			register_rest_route(
				'kazcode-storage/v1',
				'/imports/(?P<uuid>[^/]+)/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'rest_import_' . $action ),
					'permission_callback' => array( $this, 'can_manage' ),
				)
			);
		}

		register_rest_route(
			'kazcode-storage/v1',
			'/audit',
			array(
				'methods'             => 'GET',
				'callback'            => array($this, 'rest_audit'),
				'permission_callback' => array($this, 'can_manage'),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/storage-profiles',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_storage_profiles_list' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/storage-profiles/save',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_storage_profiles_save' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/storage-profiles/delete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_storage_profiles_delete' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'kazcode-storage/v1',
			'/storage-profiles/default',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_storage_profiles_default' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Capability check.
	 */
	public function can_manage(): bool {
		return current_user_can('manage_options');
	}

	private function competitor_imports(): CompetitorImportService {
		if ( $this->competitor_imports === null ) {
			$this->competitor_imports = Plugin::instance()->competitor_imports();
		}
		return $this->competitor_imports;
	}

	/**
	 * Background migrator — Plugin accessor with fallback.
	 */
	private function background(): BackgroundMigrator {
		$plugin = Plugin::instance();
		if (method_exists($plugin, 'background')) {
			/** @var BackgroundMigrator */
			return $plugin->background();
		}
		return new BackgroundMigrator($plugin->settings(), new AuditLog());
	}

	/**
	 * Stats endpoint.
	 */
	public function rest_stats(): \WP_REST_Response {
		return new \WP_REST_Response($this->migration->stats(), 200);
	}

	public function rest_import_detect(): \WP_REST_Response {
		return new \WP_REST_Response( $this->redact_credentials( $this->competitor_imports()->detect() ), 200 );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_import_dry_run_start( \WP_REST_Request $request ) {
		return $this->rest_import_start( $request, 'dry_run' );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_import_commit_start( \WP_REST_Request $request ) {
		return $this->rest_import_start( $request, 'commit' );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function rest_import_remediation_start( \WP_REST_Request $request ) {
		$uuid = $this->request_uuid( $request );
		if ( is_wp_error( $uuid ) ) {
			return $uuid;
		}
		return $this->import_response( $this->competitor_imports()->start_remediation_upload( $uuid ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_import_status( \WP_REST_Request $request ) {
		$uuid = $this->request_uuid( $request );
		if ( is_wp_error( $uuid ) ) {
			return $uuid;
		}
		return $this->import_response( $this->competitor_imports()->status( $uuid ) );
	}

	/** Process one bounded import page; status remains a read-only observation. */
	public function rest_import_process( \WP_REST_Request $request ) {
		$uuid = $this->request_uuid( $request );
		if ( is_wp_error( $uuid ) ) {
			return $uuid;
		}
		return $this->import_response( $this->competitor_imports()->process_next_page( $uuid, 50 ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_import_pause( \WP_REST_Request $request ) {
		$uuid = $this->request_uuid( $request );
		if ( is_wp_error( $uuid ) ) {
			return $uuid;
		}
		return $this->import_response( $this->competitor_imports()->pause( $uuid ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_import_resume( \WP_REST_Request $request ) {
		$uuid = $this->request_uuid( $request );
		if ( is_wp_error( $uuid ) ) {
			return $uuid;
		}
		return $this->import_response( $this->competitor_imports()->resume( $uuid ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_import_cancel( \WP_REST_Request $request ) {
		$uuid = $this->request_uuid( $request );
		if ( is_wp_error( $uuid ) ) {
			return $uuid;
		}
		return $this->import_response( $this->competitor_imports()->cancel( $uuid ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function rest_import_start( \WP_REST_Request $request, string $mode ) {
		$source = $this->request_source( $request );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$profile_id = (int) $request->get_param( 'profile' );
		if ( $profile_id <= 0 ) {
			return new \WP_Error( 'invalid_import_profile', 'A valid storage profile is required.', array( 'status' => 400 ) );
		}

		$result = $mode === 'commit'
			? $this->competitor_imports()->start_commit( $source, $profile_id )
			: $this->competitor_imports()->start_dry_run( $source, $profile_id );

		return $this->import_response( $result );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return string|\WP_Error
	 */
	private function request_source( \WP_REST_Request $request ) {
		$raw       = (string) $request->get_param( 'source' );
		$sanitized = sanitize_key( $raw );
		if ( $sanitized === '' || $sanitized !== $raw ) {
			return new \WP_Error( 'invalid_import_source', 'A valid import source is required.', array( 'status' => 400 ) );
		}
		return $sanitized;
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return string|\WP_Error
	 */
	private function request_uuid( \WP_REST_Request $request ) {
		$uuid = strtolower( sanitize_text_field( (string) $request->get_param( 'uuid' ) ) );
		if ( ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $uuid ) ) {
			return new \WP_Error( 'invalid_import_uuid', 'A valid import run UUID is required.', array( 'status' => 400 ) );
		}
		return $uuid;
	}

	/** @param array<string, mixed> $result */
	private function import_response( array $result ): \WP_REST_Response {
		$status = ! empty( $result['success'] ) ? 200 : 400;
		return new \WP_REST_Response( $this->redact_credentials( $result ), $status );
	}

	private function redact_credentials( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$redacted = array();
		foreach ( $value as $key => $item ) {
			$key_string = is_string( $key ) ? strtolower( $key ) : '';
			if ( $key_string !== '' && preg_match( '/(secret|token|credential|access_key|access-key|accesskey)/', $key_string ) ) {
				continue;
			}
			$redacted[ $key ] = $this->redact_credentials( $item );
		}
		return $redacted;
	}

	/**
	 * Migrate batch.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_migrate_batch(\WP_REST_Request $request): \WP_REST_Response {
		$batch_size    = max(1, min(200, (int) $request->get_param('batch_size') ?: 20));
		$dry_run       = (bool) $request->get_param('dry_run');
		$retry_failed  = (bool) $request->get_param('retry_failed');
		$attachment_id = $request->get_param('attachment_id') ? (int) $request->get_param('attachment_id') : null;
		$delete_local  = $request->get_param('delete_local');
		$delete_local  = $delete_local === null ? null : (bool) $delete_local;
		$after_id      = (int) $request->get_param('after_id');

		$result = $this->migration->migrate_batch($batch_size, $dry_run, $retry_failed, $attachment_id, $delete_local, $after_id);
		return new \WP_REST_Response($result, 200);
	}

	/**
	 * Verify batch.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_verify_batch(\WP_REST_Request $request): \WP_REST_Response {
		$batch_size    = max(1, min(200, (int) $request->get_param('batch_size') ?: 20));
		$attachment_id = $request->get_param('attachment_id') ? (int) $request->get_param('attachment_id') : null;
		$after_id      = (int) $request->get_param('after_id');
		return new \WP_REST_Response($this->migration->verify_batch($batch_size, $attachment_id, $after_id), 200);
	}

	/**
	 * Restore batch.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_restore_batch(\WP_REST_Request $request): \WP_REST_Response {
		$batch_size    = max(1, min(200, (int) $request->get_param('batch_size') ?: 20));
		$attachment_id = $request->get_param('attachment_id') ? (int) $request->get_param('attachment_id') : null;
		$after_id      = (int) $request->get_param('after_id');
		$acknowledge   = \Kazcode\WpStorage\Attachment\AttachmentRestorer::explicit_true(
			$request->get_param( 'acknowledge_warning' )
		);
		return new \WP_REST_Response($this->migration->restore_batch($batch_size, $attachment_id, $after_id, $acknowledge), 200);
	}

	/**
	 * Restore preflight (non-mutating).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_restore_preflight( \WP_REST_Request $request ): \WP_REST_Response {
		$attachment_id = $request->get_param( 'attachment_id' ) ? (int) $request->get_param( 'attachment_id' ) : 0;
		$ids_param     = $request->get_param( 'attachment_ids' );
		$ids           = array();
		if ( is_array( $ids_param ) ) {
			foreach ( $ids_param as $id ) {
				$id = (int) $id;
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		} elseif ( $attachment_id > 0 ) {
			$ids[] = $attachment_id;
		}
		$result = Plugin::instance()->restore_preflight()->preflight( $ids );
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Test connection — always HTTP 200; success flag is in body.
	 */
	public function rest_test_connection(): \WP_REST_Response {
		$result = Plugin::instance()->connection_test()->run();
		return new \WP_REST_Response($result, 200);
	}

	/**
	 * Failed items list.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_failed_list(\WP_REST_Request $request): \WP_REST_Response {
		$page     = max(1, (int) $request->get_param('page') ?: 1);
		$per_page = max(1, min(100, (int) $request->get_param('per_page') ?: 20));
		$filter   = sanitize_key((string) ($request->get_param('filter') ?: 'all'));
		if (! in_array($filter, array('all', 'retryable', 'missing_local', 'ignored'), true)) {
			$filter = 'all';
		}

		$result = (new FailedItemsService())->list($page, $per_page, $filter);
		return new \WP_REST_Response($result, 200);
	}

	/**
	 * Mark failed items ignored / un-ignored.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_failed_ignore(\WP_REST_Request $request): \WP_REST_Response {
		$ids     = $request->get_param('ids');
		$ids     = is_array($ids) ? array_map('intval', $ids) : array();
		$ignored = (bool) $request->get_param('ignored');
		$updated = (new FailedItemsService())->set_ignored($ids, $ignored);

		return new \WP_REST_Response(
			array(
				'updated' => $updated,
				'ignored' => $ignored,
			),
			200
		);
	}

	/**
	 * Clear this plugin's own failed/offload bookkeeping for the given
	 * attachments — never the attachment post, local file, or remote object.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_failed_clear(\WP_REST_Request $request): \WP_REST_Response {
		$ids     = $request->get_param('ids');
		$ids     = is_array($ids) ? array_map('intval', $ids) : array();
		$cleared = (new FailedItemsService())->clear($ids);

		if ($cleared > 0) {
			(new AuditLog())->record(
				'failed_items_cleared',
				array(
					'count' => $cleared,
					'ids'   => array_slice($ids, 0, 20),
				)
			);
		}

		return new \WP_REST_Response(
			array(
				'cleared' => $cleared,
			),
			200
		);
	}

	/**
	 * Export failed items as CSV attachment or JSON payload.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_failed_export(\WP_REST_Request $request): \WP_REST_Response {
		$csv      = (new FailedItemsService())->to_csv();
		$download = (bool) $request->get_param('download');

		if ($download) {
			$response = new \WP_REST_Response($csv, 200);
			$response->header('Content-Type', 'text/csv; charset=utf-8');
			$response->header('Content-Disposition', 'attachment; filename="s3ms-failed-items.csv"');
			return $response;
		}

		return new \WP_REST_Response(array('csv' => $csv), 200);
	}

	/**
	 * Background job status.
	 */
	public function rest_background_status(): \WP_REST_Response {
		$status = Plugin::instance()->queue()->status();
		$bulk   = is_array( $status['bulk'] ?? null ) ? $status['bulk'] : $status;
		return new \WP_REST_Response( $bulk, 200 );
	}

	/**
	 * Start background job.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_background_start(\WP_REST_Request $request) {
		$action = sanitize_key( (string) ( $request->get_param( 'job' ) ?: $request->get_param( 'action' ) ?: '' ) );
		$result = Plugin::instance()->queue()->enqueue( $action );
		if (is_wp_error($result)) {
			return $result;
		}
		return new \WP_REST_Response(
			array(
				'job_id' => $result,
				'bulk'   => Plugin::instance()->background()->status(),
			),
			200
		);
	}

	/**
	 * Stop background job.
	 */
	public function rest_background_stop(): \WP_REST_Response {
		$result = Plugin::instance()->queue()->cancel();
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( array( 'message' => $result->get_error_message() ), 400 );
		}
		return new \WP_REST_Response( Plugin::instance()->background()->status(), 200 );
	}

	/**
	 * Health check diagnostics.
	 */
	public function rest_health(): \WP_REST_Response {
		$health = (new HealthCheckService(Plugin::instance()->settings()))->run();
		return new \WP_REST_Response($health, 200);
	}

	/**
	 * Object inventory stats + optional DB health scan summary.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_health_objects( \WP_REST_Request $request ): \WP_REST_Response {
		$refresh = (bool) $request->get_param( 'refresh' );
		$scan    = (bool) $request->get_param( 'scan' );
		$payload = array(
			'object_stats' => ( new ObjectStatsAggregator() )->get( $refresh ),
		);
		if ( $scan ) {
			$limit              = max( 1, min( 2000, (int) ( $request->get_param( 'limit' ) ?: 500 ) ) );
			$after              = (int) $request->get_param( 'after_id' );
			$payload['scan']    = ( new HealthScanService() )->scan_page( $limit, $after );
		}
		return new \WP_REST_Response( $payload, 200 );
	}

	/**
	 * Run DB-first health scan (full or one page).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_health_scan( \WP_REST_Request $request ): \WP_REST_Response {
		$full  = (bool) $request->get_param( 'full' );
		$limit = max( 1, min( 2000, (int) ( $request->get_param( 'limit' ) ?: 500 ) ) );
		$after = (int) $request->get_param( 'after_id' );

		$scan = $full
			? ( new HealthScanService() )->scan_all( $limit )
			: ( new HealthScanService() )->scan_page( $limit, $after );

		return new \WP_REST_Response( $scan, 200 );
	}

	/**
	 * Orphan scan — dry-run LIST page or enqueue async job.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_orphan_scan( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$service = ProServices::require( 'orphan_scan', Plugin::instance()->settings(), Plugin::instance()->storage() );
		} catch ( \RuntimeException $e ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => $e->getMessage() ), 403 );
		}

		$async = (bool) $request->get_param( 'async' );
		if ( $async ) {
			$job_id = Plugin::instance()->queue()->enqueue( QueueJobType::ORPHAN_SCAN, array() );
			if ( is_wp_error( $job_id ) ) {
				return new \WP_REST_Response( array( 'message' => $job_id->get_error_message() ), 400 );
			}
			return new \WP_REST_Response(
				array(
					'job_id'  => $job_id,
					'dry_run' => true,
				),
				200
			);
		}

		$token = $request->get_param( 'continuation_token' );
		$token = is_string( $token ) && $token !== '' ? $token : null;
		$result = $service->scan_page( $token );

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Repair missing remote objects when local exists.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_repair( \WP_REST_Request $request ): \WP_REST_Response {
		$dry_run       = (bool) $request->get_param( 'dry_run' );
		$attachment_id = (int) $request->get_param( 'attachment_id' );
		$object_id     = (int) $request->get_param( 'object_id' );
		$storage       = Plugin::instance()->storage();

		if ( $object_id > 0 ) {
			$result = ( new RepairObjectService( $storage ) )->repair( $object_id, $dry_run );
			return new \WP_REST_Response( $result, 200 );
		}

		if ( $attachment_id > 0 ) {
			$result = ( new RepairAttachmentService( $storage ) )->repair( $attachment_id, $dry_run );
			return new \WP_REST_Response( $result, 200 );
		}

		return new \WP_REST_Response(
			array(
				'success' => false,
				'message' => 'attachment_id or object_id is required.',
			),
			400
		);
	}

	/**
	 * Storage profile migration run status.
	 */
	public function rest_storage_migrate_status(): \WP_REST_Response {
		try {
			$migration = ProServices::require( 'storage_migration', Plugin::instance()->settings() );
		} catch ( \RuntimeException $e ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => $e->getMessage() ), 403 );
		}

		return new \WP_REST_Response( $migration->status(), 200 );
	}

	/**
	 * Batch migrate objects between storage profiles.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_storage_migrate_batch( \WP_REST_Request $request ): \WP_REST_Response {
		$settings = Plugin::instance()->settings();
		try {
			$migration = ProServices::require( 'storage_migration', $settings );
		} catch ( \RuntimeException $e ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => $e->getMessage() ), 403 );
		}

		$source = (int) $request->get_param( 'source_profile_id' );
		$dest   = (int) $request->get_param( 'dest_profile_id' );
		if ( $source <= 0 || $dest <= 0 ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => 'source_profile_id and dest_profile_id are required.',
				),
				400
			);
		}

		$attachment_id = (int) $request->get_param( 'attachment_id' );
		$object_id     = (int) $request->get_param( 'object_id' );
		$dry_run       = (bool) $request->get_param( 'dry_run' );
		$delete_source = (bool) $request->get_param( 'delete_source' );

		if ( $attachment_id > 0 ) {
			$result = ProServices::require( 'migrate_attachment', $settings )->migrate(
				$attachment_id,
				$dest,
				$dry_run,
				$delete_source
			);
			return new \WP_REST_Response( $result, 200 );
		}

		if ( $object_id > 0 ) {
			$result = ProServices::require( 'migrate_object', $settings )->migrate(
				$object_id,
				$dest,
				$dry_run,
				$delete_source
			);
			return new \WP_REST_Response( $result, 200 );
		}

		$batch_size = max( 1, min( 100, (int) ( $request->get_param( 'batch_size' ) ?: 20 ) ) );
		$after_id   = (int) $request->get_param( 'after_id' );
		$result     = $migration->migrate_batch(
			$source,
			$dest,
			$batch_size,
			$after_id,
			$dry_run,
			$delete_source
		);
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Switch default upload profile after migration (P7-T04).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_storage_migrate_switch( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$migration = ProServices::require( 'storage_migration', Plugin::instance()->settings() );
		} catch ( \RuntimeException $e ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => $e->getMessage() ), 403 );
		}

		$dest = (int) $request->get_param( 'dest_profile_id' );
		if ( $dest <= 0 ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => 'dest_profile_id is required.',
				),
				400
			);
		}
		$result = $migration->switch_default_profile( $dest );
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * HEAD-based adopt batch for legacy/existing remote media.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_adopt_batch( \WP_REST_Request $request ): \WP_REST_Response {
		$batch_size    = max( 1, min( 200, (int) ( $request->get_param( 'batch_size' ) ?: 20 ) ) );
		$after_id      = (int) $request->get_param( 'after_id' );
		$dry_run       = (bool) $request->get_param( 'dry_run' );
		$legacy_only   = $request->get_param( 'legacy_only' ) !== null ? (bool) $request->get_param( 'legacy_only' ) : true;
		$attachment_id = $request->get_param( 'attachment_id' ) ? (int) $request->get_param( 'attachment_id' ) : null;
		$profile_id    = $request->get_param( 'profile_id' ) ? (int) $request->get_param( 'profile_id' ) : null;

		$result = ( new AdoptService( Plugin::instance()->settings() ) )->adopt_batch(
			$batch_size,
			$after_id,
			$dry_run,
			$legacy_only,
			$attachment_id,
			$profile_id
		);
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Latest audit log entries.
	 */
	public function rest_audit(): \WP_REST_Response {
		return new \WP_REST_Response((new AuditLog())->latest(50), 200);
	}

	/**
	 * List storage profiles for admin UI.
	 */
	public function rest_storage_profiles_list(): \WP_REST_Response {
		$service = new StorageProfileAdminService( Plugin::instance()->settings() );
		return new \WP_REST_Response(
			array(
				'profiles' => $service->list_summaries(),
			),
			200
		);
	}

	/**
	 * Create or update a storage profile.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_storage_profiles_save( \WP_REST_Request $request ): \WP_REST_Response {
		$service = new StorageProfileAdminService( Plugin::instance()->settings() );
		$params  = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$id = (int) ( $params['id'] ?? 0 );
		if ( $id > 0 ) {
			$result = $service->update( $id, $params );
		} else {
			$result = $service->create( $params );
		}

		$status = ! empty( $result['success'] ) ? 200 : 400;
		return new \WP_REST_Response( $result, $status );
	}

	/**
	 * Test one saved storage profile using that profile's effective credentials.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_storage_profiles_test( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		$id = (int) ( $params['id'] ?? 0 );
		if ( $id <= 0 ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'steps'   => array(
						array(
							'name'   => 'profile',
							'ok'     => false,
							'detail' => 'A saved profile is required before running a profile connection test.',
						),
					),
				),
				200
			);
		}

		$profile = ( new WpdbStorageProfileRepository() )->find( $id );
		if ( $profile === null ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'steps'   => array(
						array(
							'name'   => 'profile',
							'ok'     => false,
							'detail' => 'Storage profile was not found.',
						),
					),
				),
				200
			);
		}

		$gateway = new ProfileStorageGateway( $profile, Plugin::instance()->settings() );
		return new \WP_REST_Response( $this->run_profile_connection_test( $gateway, $profile->prefix ), 200 );
	}

	/**
	 * @return array{success:bool,steps:list<array{name:string,ok:bool,detail:string}>}
	 */
	private function run_profile_connection_test( ProfileStorageGateway $gateway, string $prefix ): array {
		$steps = array();

		try {
			$client = $gateway->client();
			$steps[] = array(
				'name'   => 'client',
				'ok'     => true,
				'detail' => 'Profile S3 client initialized.',
			);
		} catch ( \Throwable $e ) {
			return array(
				'success' => false,
				'steps'   => array(
					array(
						'name'   => 'client',
						'ok'     => false,
						'detail' => 'Failed to initialize profile S3 client.',
					),
				),
			);
		}

		try {
			$client->headBucket( array( 'Bucket' => $gateway->bucket() ) );
			$steps[] = array(
				'name'   => 'bucket',
				'ok'     => true,
				'detail' => 'Profile bucket is reachable.',
			);
		} catch ( \Throwable $e ) {
			$steps[] = array(
				'name'   => 'bucket',
				'ok'     => false,
				'detail' => 'Unable to access profile bucket.',
			);
			return array(
				'success' => false,
				'steps'   => $steps,
			);
		}

		$tmp = TemporaryFile::create( 's3ms-profile-test' );
		if ( $tmp === false ) {
			$steps[] = array(
				'name'   => 'upload',
				'ok'     => false,
				'detail' => 'Unable to create temporary file.',
			);
			return array(
				'success' => false,
				'steps'   => $steps,
			);
		}

		file_put_contents( $tmp, 's3ms-profile-connection-test' );
		$key_prefix = trim( $prefix, '/' );
		$key        = ( $key_prefix !== '' ? $key_prefix . '/' : '' ) . 's3ms-connection-test/' . wp_generate_password( 12, false ) . '.txt';

		try {
			$gateway->put_object_from_file( $key, $tmp, 'text/plain', '' );
			$steps[] = array(
				'name'   => 'upload',
				'ok'     => true,
				'detail' => 'Profile test object uploaded.',
			);

			$head = $gateway->head_key( $key );
			if ( empty( $head['exists'] ) ) {
				throw new \RuntimeException( 'HEAD failed after profile test upload.' );
			}
			$steps[] = array(
				'name'   => 'head',
				'ok'     => true,
				'detail' => 'Profile HEAD succeeded.',
			);

			$gateway->delete_key( $key );
			$steps[] = array(
				'name'   => 'delete',
				'ok'     => true,
				'detail' => 'Profile test object deleted.',
			);
		} catch ( \Throwable $e ) {
			try {
				$gateway->delete_key( $key );
			} catch ( \Throwable $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
			wp_delete_file( $tmp );
			$steps[] = array(
				'name'   => 'upload',
				'ok'     => false,
				'detail' => 'Profile upload/HEAD/delete failed.',
			);
			return array(
				'success' => false,
				'steps'   => $steps,
			);
		}

		wp_delete_file( $tmp );
		return array(
			'success' => true,
			'steps'   => $steps,
		);
	}

	/**
	 * Delete a storage profile.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_storage_profiles_delete( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		$id     = (int) ( $params['id'] ?? 0 );
		$result = ( new StorageProfileAdminService( Plugin::instance()->settings() ) )->delete( $id );
		$status = ! empty( $result['success'] ) ? 200 : 400;
		return new \WP_REST_Response( $result, $status );
	}

	/**
	 * Mark profile as default upload target.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_storage_profiles_default( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		$id     = (int) ( $params['id'] ?? 0 );
		$result = ( new StorageProfileAdminService( Plugin::instance()->settings() ) )->set_default( $id );
		$status = ! empty( $result['success'] ) ? 200 : 400;
		return new \WP_REST_Response( $result, $status );
	}

}
