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
use Kazcode\WpStorage\Infrastructure\Queue\QueueJobType;

/**
 * Registers REST routes for admin migration UI.
 */
final class BatchProcessor {

	private MigrationService $migration;

	public function __construct(MigrationService $migration) {
		$this->migration = $migration;
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
			'/test-connection',
			array(
				'methods'             => 'POST',
				'callback'            => array($this, 'rest_test_connection'),
				'permission_callback' => array($this, 'can_manage'),
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
		return new \WP_REST_Response($this->migration->restore_batch($batch_size, $attachment_id, $after_id), 200);
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
