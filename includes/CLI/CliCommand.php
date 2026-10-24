<?php
/**
 * WP-CLI: wp universal-storage *
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\CLI;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\ProFeatureGate;
use Kazcode\WpStorage\Core\ProServices;
use Kazcode\WpStorage\Infrastructure\Queue\QueueJobType;
use Kazcode\WpStorage\Plugin;
use Kazcode\WpStorage\Services\HealthScanService;
use Kazcode\WpStorage\Services\ObjectStatsAggregator;
use Kazcode\WpStorage\Services\RepairAttachmentService;
use Kazcode\WpStorage\Services\RepairObjectService;
use Kazcode\WpStorage\Services\AdoptService;
use Kazcode\WpStorage\Services\RebuildInventoryService;

/**
 * CLI commands for status, test, migrate, verify, retry, restore.
 */
final class CliCommand {

	private Plugin $plugin;

	public function __construct(Plugin $plugin) {
		$this->plugin = $plugin;
	}

	/**
	 * Register with WP-CLI.
	 */
	public static function register(Plugin $plugin): void {
		\WP_CLI::add_command('universal-storage', new self($plugin));
	}

	/**
	 * Show offload statistics.
	 *
	 * ## EXAMPLES
	 *
	 *     wp universal-storage status
	 *
	 * @when after_wp_load
	 */
	public function status(): void {
		$stats = $this->plugin->migration_service()->stats();
		\WP_CLI::line(sprintf('Enabled: %s', $this->plugin->settings()->is_enabled() ? 'yes' : 'no'));
		\WP_CLI::line(sprintf('Serve from S3: %s', $this->plugin->settings()->is_serve_enabled() ? 'yes' : 'no'));
		\WP_CLI::line(sprintf('AWS configured: %s', $this->plugin->settings()->is_aws_configured() ? 'yes' : 'no'));
		foreach ($stats as $key => $value) {
			\WP_CLI::line(sprintf('%s: %d', $key, $value));
		}
	}

	/**
	 * Test S3 connection.
	 *
	 * ## EXAMPLES
	 *
	 *     wp universal-storage test
	 *
	 * @when after_wp_load
	 */
	public function test(): void {
		$result = $this->plugin->connection_test()->run();
		foreach ($result['steps'] as $step) {
			$mark = $step['ok'] ? 'OK' : 'FAIL';
			\WP_CLI::line(sprintf('[%s] %s — %s', $mark, $step['name'], $step['detail']));
		}
		if ($result['success']) {
			\WP_CLI::success('Connection successful.');
		} else {
			\WP_CLI::error('Connection failed.');
		}
	}

	/**
	 * Migrate attachments to S3.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<n>]
	 * : Number of attachments per batch.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--attachment-id=<id>]
	 * : Process a single attachment.
	 *
	 * [--dry-run]
	 * : Report only; do not upload or delete.
	 *
	 * [--delete-local]
	 * : Delete local files after successful verify.
	 *
	 * [--verbose]
	 * : Print per-attachment details.
	 *
	 * ## EXAMPLES
	 *
	 *     wp universal-storage migrate --dry-run
	 *     wp universal-storage migrate --batch-size=100
	 *
	 * @when after_wp_load
	 *
	 * @param list<string>         $args Positional.
	 * @param array<string, mixed> $assoc Assoc.
	 */
	public function migrate(array $args, array $assoc): void {
		$batch_size    = isset($assoc['batch-size']) ? (int) $assoc['batch-size'] : 100;
		$attachment_id = isset($assoc['attachment-id']) ? (int) $assoc['attachment-id'] : null;
		$dry_run       = isset($assoc['dry-run']);
		$delete_local  = isset($assoc['delete-local']) ? true : null;
		$verbose       = isset($assoc['verbose']);

		$total_success = 0;
		$total_failed  = 0;
		$loops         = 0;
		$after_id      = 0;

		do {
			$result = $this->plugin->migration_service()->migrate_batch(
				$batch_size,
				$dry_run,
				false,
				$attachment_id,
				$delete_local,
				$after_id
			);
			++$loops;
			$total_success += $result['success'];
			$total_failed  += $result['failed'];
			$after_id       = (int) ($result['next_after_id'] ?? $after_id);

			if ($verbose || $dry_run) {
				foreach ($result['results'] as $row) {
					\WP_CLI::line(wp_json_encode($row));
				}
			}

			\WP_CLI::log(sprintf('Batch %d: processed %d (ok=%d fail=%d)', $loops, $result['processed'], $result['success'], $result['failed']));

			if ($attachment_id || $result['processed'] === 0) {
				break;
			}
			if ($dry_run || $result['processed'] < $batch_size) {
				break;
			}
		} while ($loops < 10000);

		\WP_CLI::success(sprintf('Finished. success=%d failed=%d', $total_success, $total_failed));
	}

	/**
	 * Verify offloaded attachments.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<n>]
	 * : Batch size.
	 *
	 * [--attachment-id=<id>]
	 * : Single attachment.
	 *
	 * [--verbose]
	 * : Print details.
	 *
	 * @when after_wp_load
	 *
	 * @param list<string>         $args Args.
	 * @param array<string, mixed> $assoc Assoc.
	 */
	public function verify(array $args, array $assoc): void {
		$batch_size    = isset($assoc['batch-size']) ? (int) $assoc['batch-size'] : 100;
		$attachment_id = isset($assoc['attachment-id']) ? (int) $assoc['attachment-id'] : null;
		$verbose       = isset($assoc['verbose']);

		$result = $this->plugin->migration_service()->verify_batch($batch_size, $attachment_id);
		foreach ($result['results'] as $row) {
			$line = sprintf('#%d %s', $row['attachment_id'], $row['status']);
			if ($verbose && !empty($row['details'])) {
				$line .= ' — ' . implode('; ', $row['details']);
			}
			\WP_CLI::line($line);
		}
		\WP_CLI::success(sprintf('Verified %d attachment(s).', $result['processed']));
	}

	/**
	 * Retry failed offloads.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<n>]
	 * : Batch size.
	 *
	 * [--delete-local]
	 * : Delete local after success.
	 *
	 * [--verbose]
	 * : Verbose output.
	 *
	 * @when after_wp_load
	 *
	 * @param list<string>         $args Args.
	 * @param array<string, mixed> $assoc Assoc.
	 */
	public function retry_failed(array $args, array $assoc): void {
		$batch_size   = isset($assoc['batch-size']) ? (int) $assoc['batch-size'] : 100;
		$delete_local = isset($assoc['delete-local']) ? true : null;
		$verbose      = isset($assoc['verbose']);

		$result = $this->plugin->migration_service()->migrate_batch($batch_size, false, true, null, $delete_local);
		if ($verbose) {
			foreach ($result['results'] as $row) {
				\WP_CLI::line(wp_json_encode($row));
			}
		}
		\WP_CLI::success(sprintf('Retry finished. success=%d failed=%d', $result['success'], $result['failed']));
	}

	/**
	 * Restore local files from S3.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<n>]
	 * : Batch size.
	 *
	 * [--attachment-id=<id>]
	 * : Single attachment.
	 *
	 * [--preflight]
	 * : Run Restore Preflight only (no downloads). Reports SAFE/WARNING/BLOCKED.
	 *
	 * [--acknowledge-warning]
	 * : Explicitly allow WARNING preflight outcomes to proceed with restore.
	 *   Required for WARNING; ignored for SAFE; cannot bypass BLOCKED.
	 *
	 * [--verbose]
	 * : Verbose output.
	 *
	 * ## EXAMPLES
	 *
	 *     wp universal-storage restore --attachment-id=42 --preflight
	 *     wp universal-storage restore --attachment-id=42 --acknowledge-warning
	 *
	 * @when after_wp_load
	 *
	 * @param list<string>         $args Args.
	 * @param array<string, mixed> $assoc Assoc.
	 */
	public function restore(array $args, array $assoc): void {
		$batch_size    = isset($assoc['batch-size']) ? (int) $assoc['batch-size'] : 100;
		$attachment_id = isset($assoc['attachment-id']) ? (int) $assoc['attachment-id'] : null;
		$verbose       = isset($assoc['verbose']);
		$preflight_only = isset($assoc['preflight']);
		// Flag presence only — never coerce random truthy strings.
		$acknowledge   = isset($assoc['acknowledge-warning']);

		if ($preflight_only) {
			$ids = array();
			if ($attachment_id !== null && $attachment_id > 0) {
				$ids = array($attachment_id);
			} else {
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- CLI preflight mirrors restore_batch selection.
				$q = new \WP_Query(
					array(
						'post_type'      => 'attachment',
						'post_status'    => 'inherit',
						'posts_per_page' => $batch_size,
						'fields'         => 'ids',
						'meta_key'       => '_s3ms_status',
						'meta_value'     => \Kazcode\WpStorage\Attachment\AttachmentOffloader::STATUS_OFFLOADED,
						'orderby'        => 'ID',
						'order'          => 'ASC',
					)
				);
				$ids = array_map('intval', $q->posts);
			}
			$result = $this->plugin->restore_preflight()->preflight($ids);
			$this->print_preflight_result($result);
			if ((string) ($result['outcome'] ?? '') === 'BLOCKED') {
				\WP_CLI::error('Restore preflight BLOCKED.');
			}
			if ((string) ($result['outcome'] ?? '') === 'WARNING') {
				\WP_CLI::warning('Restore preflight WARNING — pass --acknowledge-warning to proceed with restore.');
				return;
			}
			\WP_CLI::success('Restore preflight SAFE.');
			return;
		}

		$result = $this->plugin->migration_service()->restore_batch($batch_size, $attachment_id, 0, $acknowledge);
		if ($verbose) {
			foreach ($result['results'] as $row) {
				\WP_CLI::line(wp_json_encode($row));
			}
		}
		foreach ($result['results'] as $row) {
			if (!empty($row['preflight']) && is_array($row['preflight'])) {
				$this->print_preflight_result($row['preflight']);
			}
			if (($row['status'] ?? '') === 'warning_requires_acknowledge') {
				\WP_CLI::warning((string) ($row['message'] ?? 'WARNING requires --acknowledge-warning'));
			}
		}
		if ((int) ($result['failed'] ?? 0) > 0 && (int) ($result['success'] ?? 0) === 0) {
			\WP_CLI::error(sprintf('Restore failed. success=%d failed=%d', $result['success'], $result['failed']));
		}
		\WP_CLI::success(sprintf('Restore finished. success=%d failed=%d', $result['success'], $result['failed']));
	}

	/**
	 * @param array<string, mixed> $result Preflight DTO array.
	 */
	private function print_preflight_result(array $result): void {
		\WP_CLI::line(sprintf('outcome: %s', (string) ($result['outcome'] ?? '')));
		\WP_CLI::line(sprintf('required_bytes: %d', (int) ($result['required_bytes'] ?? 0)));
		$free = $result['free_bytes'] ?? null;
		\WP_CLI::line(sprintf('free_bytes: %s', $free === null || $free === false ? 'unknown' : (string) (int) $free));
		\WP_CLI::line(sprintf('reserve_bytes: %d', (int) ($result['reserve_bytes'] ?? 0)));
		$reasons = is_array($result['reasons'] ?? null) ? $result['reasons'] : array();
		\WP_CLI::line(sprintf('reasons: %s', $reasons === array() ? '(none)' : implode(', ', $reasons)));
		$counts = is_array($result['counts'] ?? null) ? $result['counts'] : array();
		\WP_CLI::line('counts: ' . wp_json_encode($counts));
	}

	/**
	 * Object health stats and DB scan.
	 *
	 * ## OPTIONS
	 *
	 * [<subcommand>]
	 * : stats (default), scan, orphan
	 *
	 * [--refresh]
	 * : Refresh cached object stats.
	 *
	 * [--full]
	 * : Full DB scan (scan subcommand).
	 *
	 * [--limit=<n>]
	 * : Scan page size.
	 *
	 * [--async]
	 * : Enqueue orphan scan job instead of one LIST page.
	 *
	 * ## EXAMPLES
	 *
	 *     wp universal-storage health
	 *     wp universal-storage health scan --full
	 *     wp universal-storage health orphan --async
	 *
	 * @when after_wp_load
	 *
	 * @param list<string>         $args Args.
	 * @param array<string, mixed> $assoc Assoc.
	 */
	public function health(array $args, array $assoc): void {
		$sub     = $args[0] ?? 'stats';
		$refresh = isset($assoc['refresh']);

		if ($sub === 'scan') {
			$full  = isset($assoc['full']);
			$limit = isset($assoc['limit']) ? (int) $assoc['limit'] : 500;
			$scan  = $full
				? ( new HealthScanService() )->scan_all($limit)
				: ( new HealthScanService() )->scan_page($limit, 0);
			\WP_CLI::line(wp_json_encode($scan));
			\WP_CLI::success('Health scan complete.');
			return;
		}

		if ($sub === 'orphan') {
			try {
				$service = ProServices::require( 'orphan_scan', $this->plugin->settings(), $this->plugin->storage() );
			} catch ( \RuntimeException $e ) {
				\WP_CLI::error( $e->getMessage() );
			}

			if (isset($assoc['async'])) {
				$job_id = $this->plugin->queue()->enqueue(QueueJobType::ORPHAN_SCAN, array());
				if (is_wp_error($job_id)) {
					\WP_CLI::error($job_id->get_error_message());
				}
				\WP_CLI::success('Orphan scan job enqueued: ' . $job_id);
				return;
			}
			$result = $service->scan_page();
			\WP_CLI::line(wp_json_encode($result));
			\WP_CLI::success('Orphan scan page complete (dry-run).');
			return;
		}

		$stats = ( new ObjectStatsAggregator() )->get($refresh);
		\WP_CLI::line(wp_json_encode($stats));
		\WP_CLI::success('Object stats loaded.');
	}

	/**
	 * Repair missing remote objects when local file exists.
	 *
	 * ## OPTIONS
	 *
	 * [--attachment-id=<id>]
	 * : Repair all repairable objects for attachment.
	 *
	 * [--object-id=<id>]
	 * : Repair single object row.
	 *
	 * [--dry-run]
	 * : Report only; no upload.
	 *
	 * ## EXAMPLES
	 *
	 *     wp universal-storage repair --attachment-id=42
	 *     wp universal-storage repair --object-id=7 --dry-run
	 *
	 * @when after_wp_load
	 *
	 * @param list<string>         $args Args.
	 * @param array<string, mixed> $assoc Assoc.
	 */
	public function repair(array $args, array $assoc): void {
		$dry_run       = isset($assoc['dry-run']);
		$attachment_id = isset($assoc['attachment-id']) ? (int) $assoc['attachment-id'] : 0;
		$object_id     = isset($assoc['object-id']) ? (int) $assoc['object-id'] : 0;
		$storage       = $this->plugin->storage();

		if ($object_id > 0) {
			$result = ( new RepairObjectService($storage) )->repair($object_id, $dry_run);
			\WP_CLI::line(wp_json_encode($result));
			if (!empty($result['success'])) {
				\WP_CLI::success((string) ($result['message'] ?? 'OK'));
			} else {
				\WP_CLI::error((string) ($result['message'] ?? 'Repair failed.'));
			}
			return;
		}

		if ($attachment_id > 0) {
			$result = ( new RepairAttachmentService($storage) )->repair($attachment_id, $dry_run);
			\WP_CLI::line(wp_json_encode($result));
			if (!empty($result['success'])) {
				\WP_CLI::success((string) ($result['message'] ?? 'OK'));
			} else {
				\WP_CLI::error((string) ($result['message'] ?? 'Repair failed.'));
			}
			return;
		}

		\WP_CLI::error('Provide --attachment-id or --object-id.');
	}

	/**
	 * Migrate objects between storage profiles (A → B).
	 *
	 * ## OPTIONS
	 *
	 * --source-profile=<id>
	 * : Source storage profile id.
	 *
	 * --dest-profile=<id>
	 * : Destination storage profile id.
	 *
	 * [--attachment-id=<id>]
	 * : Migrate all objects for one attachment.
	 *
	 * [--object-id=<id>]
	 * : Migrate single object row.
	 *
	 * [--batch-size=<n>]
	 * : Objects per batch when migrating by profile.
	 *
	 * [--after-id=<id>]
	 * : Resume cursor for profile batch migration.
	 *
	 * [--dry-run]
	 * : Report only; no copy/stream.
	 *
	 * [--delete-source]
	 * : Enqueue source key delete after verified destination copy.
	 *
	 * [--switch-default]
	 * : After success, set dest profile as default upload target.
	 *
	 * ## EXAMPLES
	 *
	 *     wp universal-storage storage-migrate --source-profile=1 --dest-profile=2 --dry-run
	 *     wp universal-storage storage-migrate --source-profile=1 --dest-profile=2 --attachment-id=42
	 *
	 * @when after_wp_load
	 *
	 * @param list<string>         $args Args.
	 * @param array<string, mixed> $assoc Assoc.
	 */
	public function storage_migrate( array $args, array $assoc ): void {
		try {
			$migration = ProServices::require( 'storage_migration', $this->plugin->settings() );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}

		$source = isset( $assoc['source-profile'] ) ? (int) $assoc['source-profile'] : 0;
		$dest   = isset( $assoc['dest-profile'] ) ? (int) $assoc['dest-profile'] : 0;
		if ( $source <= 0 || $dest <= 0 ) {
			\WP_CLI::error( 'Provide --source-profile and --dest-profile.' );
		}

		$dry_run       = isset( $assoc['dry-run'] );
		$delete_source = isset( $assoc['delete-source'] );
		$settings      = $this->plugin->settings();

		$attachment_id = isset( $assoc['attachment-id'] ) ? (int) $assoc['attachment-id'] : 0;
		$object_id     = isset( $assoc['object-id'] ) ? (int) $assoc['object-id'] : 0;

		if ( $attachment_id > 0 ) {
			$result = ProServices::require( 'migrate_attachment', $settings )->migrate(
				$attachment_id,
				$dest,
				$dry_run,
				$delete_source
			);
			\WP_CLI::line( wp_json_encode( $result ) );
			if ( ! empty( $result['success'] ) ) {
				\WP_CLI::success( (string) ( $result['message'] ?? 'OK' ) );
			} else {
				\WP_CLI::error( (string) ( $result['message'] ?? 'Migration failed.' ) );
			}
			return;
		}

		if ( $object_id > 0 ) {
			$result = ProServices::require( 'migrate_object', $settings )->migrate(
				$object_id,
				$dest,
				$dry_run,
				$delete_source
			);
			\WP_CLI::line( wp_json_encode( $result ) );
			if ( ! empty( $result['success'] ) ) {
				\WP_CLI::success( (string) ( $result['message'] ?? 'OK' ) );
			} else {
				\WP_CLI::error( (string) ( $result['message'] ?? 'Migration failed.' ) );
			}
			return;
		}

		$batch_size = isset( $assoc['batch-size'] ) ? (int) $assoc['batch-size'] : 20;
		$after_id   = isset( $assoc['after-id'] ) ? (int) $assoc['after-id'] : 0;
		$result     = $migration->migrate_batch( $source, $dest, $batch_size, $after_id, $dry_run, $delete_source );
		\WP_CLI::line( wp_json_encode( $result ) );

		if ( isset( $assoc['switch-default'] ) && ! $dry_run && (int) ( $result['failed'] ?? 0 ) === 0 ) {
			$switch = $migration->switch_default_profile( $dest );
			\WP_CLI::line( wp_json_encode( $switch ) );
		}

		if ( (int) ( $result['failed'] ?? 0 ) > 0 ) {
			\WP_CLI::error(
				sprintf(
					'Batch completed with failures. processed=%d success=%d failed=%d',
					(int) ( $result['processed'] ?? 0 ),
					(int) ( $result['success'] ?? 0 ),
					(int) ( $result['failed'] ?? 0 )
				)
			);
		}

		\WP_CLI::success(
			sprintf(
				'Batch complete. processed=%d success=%d failed=%d',
				(int) ( $result['processed'] ?? 0 ),
				(int) ( $result['success'] ?? 0 ),
				(int) ( $result['failed'] ?? 0 )
			)
		);
	}

	/**
	 * Adopt existing remote objects into inventory (HEAD only, no upload).
	 *
	 * ## OPTIONS
	 *
	 * [--attachment-id=<id>]
	 * : Adopt a single attachment.
	 *
	 * [--batch-size=<n>]
	 * : Attachments per batch.
	 *
	 * [--after-id=<id>]
	 * : Resume cursor.
	 *
	 * [--dry-run]
	 * : Report HEAD results without writing rows.
	 *
	 * [--all]
	 * : Include attachments without legacy offload meta (default: legacy only).
	 *
	 * [--profile-id=<id>]
	 * : Storage profile id (default: active upload target).
	 *
	 * ## EXAMPLES
	 *
	 *     wp universal-storage adopt --dry-run
	 *     wp universal-storage adopt --attachment-id=42
	 *
	 * @when after_wp_load
	 *
	 * @param list<string>         $args Args.
	 * @param array<string, mixed> $assoc Assoc.
	 */
	public function adopt( array $args, array $assoc ): void {
		$service       = new AdoptService( $this->plugin->settings() );
		$batch_size    = isset( $assoc['batch-size'] ) ? (int) $assoc['batch-size'] : 50;
		$after_id      = isset( $assoc['after-id'] ) ? (int) $assoc['after-id'] : 0;
		$dry_run       = isset( $assoc['dry-run'] );
		$legacy_only   = ! isset( $assoc['all'] );
		$attachment_id = isset( $assoc['attachment-id'] ) ? (int) $assoc['attachment-id'] : null;
		$profile_id    = isset( $assoc['profile-id'] ) ? (int) $assoc['profile-id'] : null;

		$result = $service->adopt_batch(
			$batch_size,
			$after_id,
			$dry_run,
			$legacy_only,
			$attachment_id,
			$profile_id
		);

		\WP_CLI::line( wp_json_encode( $result ) );
		\WP_CLI::success(
			sprintf(
				'Adopt batch complete. processed=%d success=%d failed=%d',
				(int) ( $result['processed'] ?? 0 ),
				(int) ( $result['success'] ?? 0 ),
				(int) ( $result['failed'] ?? 0 )
			)
		);
	}

	/**
	 * Rebuild storage inventory via exact-key HEAD (Integrity §6.3).
	 *
	 * ## OPTIONS
	 *
	 * --attachment-id=<id>
	 * : Attachment to rebuild (required for this leaf).
	 *
	 * [--profile-id=<id>]
	 * : Explicit storage profile id when compatible with surviving rows.
	 *
	 * [--dry-run]
	 * : Observe only; no inventory writes.
	 *
	 * ## EXAMPLES
	 *
	 *     wp universal-storage rebuild-inventory --attachment-id=42 --dry-run
	 *     wp universal-storage rebuild-inventory --attachment-id=42
	 *
	 * @when after_wp_load
	 *
	 * @param list<string>         $args Args.
	 * @param array<string, mixed> $assoc Assoc.
	 */
	public function rebuild_inventory( array $args, array $assoc ): void {
		$attachment_id = $this->cli_rebuild_attachment_id( $assoc );
		$profile_id    = $this->cli_rebuild_profile_id( $assoc );
		$dry_run       = isset( $assoc['dry-run'] );

		$result = $this->plugin->rebuild_inventory()->rebuild_attachment( $attachment_id, $profile_id, $dry_run );
		\WP_CLI::line( wp_json_encode( $result ) );
		$counts  = is_array( $result['counts'] ?? null ) ? $result['counts'] : array();
		$outcome = (string) ( $result['outcome'] ?? ( empty( $result['success'] ) ? 'failed' : 'complete' ) );
		$summary = sprintf(
			'Rebuild %s %s. scanned=%d present=%d exists_only=%d adopted=%d missing=%d conflicts=%d auth=%d transient=%d',
			$dry_run ? 'dry-run' : 'apply',
			$outcome,
			(int) ( $counts['scanned'] ?? 0 ),
			(int) ( $counts['present'] ?? 0 ),
			(int) ( $counts['exists_only'] ?? 0 ),
			(int) ( $counts['present_adopted'] ?? 0 ),
			(int) ( $counts['confirmed_missing'] ?? 0 ),
			(int) ( $counts['conflicts'] ?? 0 ),
			(int) ( $counts['auth'] ?? 0 ),
			(int) ( $counts['transient'] ?? 0 )
		);
		if ( $outcome === RebuildInventoryService::OUTCOME_FAILED ) {
			\WP_CLI::error( (string) ( $result['message'] ?? $summary ) );
		}
		if ( $outcome === RebuildInventoryService::OUTCOME_PARTIAL ) {
			\WP_CLI::warning( $summary );
			return;
		}
		\WP_CLI::success( $summary );
	}

	/**
	 * Parse required --attachment-id for rebuild-inventory (reject non-digit / zero).
	 *
	 * @param array<string, mixed> $assoc Assoc.
	 */
	private function cli_rebuild_attachment_id( array $assoc ): int {
		if ( ! array_key_exists( 'attachment-id', $assoc ) ) {
			\WP_CLI::error( '--attachment-id is required.' );
		}
		$raw = trim( (string) $assoc['attachment-id'] );
		if ( $raw === '' || ! ctype_digit( $raw ) || (int) $raw <= 0 ) {
			\WP_CLI::error( 'Provide a valid positive --attachment-id.' );
		}
		return (int) $raw;
	}

	/**
	 * Parse optional --profile-id for rebuild-inventory (reject zero/negative/non-numeric).
	 *
	 * @param array<string, mixed> $assoc Assoc.
	 */
	private function cli_rebuild_profile_id( array $assoc ): ?int {
		if ( ! array_key_exists( 'profile-id', $assoc ) ) {
			return null;
		}
		$raw = trim( (string) $assoc['profile-id'] );
		if ( $raw === '' || ! ctype_digit( $raw ) || (int) $raw <= 0 ) {
			\WP_CLI::error( 'Provide a valid positive --profile-id.' );
		}
		return (int) $raw;
	}

	/**
	 * Import media ownership from supported offload plugins.
	 *
	 * ## OPTIONS
	 *
	 * <subcommand>
	 * : detect, analyze, run, status, or resume.
	 *
	 * [--source=<id>]
	 * : Import source id for analyze/run.
	 *
	 * [--profile=<id>]
	 * : KAZCODE storage profile id for analyze/run.
	 *
	 * [--uuid=<uuid>]
	 * : Import run UUID for status/resume.
	 *
	 * [--force-reanalyze]
	 * : For run, perform a fresh dry-run before starting commit.
	 *
	 * @when after_wp_load
	 *
	 * @param list<string>         $args Args.
	 * @param array<string, mixed> $assoc Assoc.
	 */
	public function import( array $args, array $assoc ): void {
		$subcommand = $args[0] ?? 'detect';
		$imports    = $this->plugin->competitor_imports();

		if ( $subcommand === 'detect' ) {
			\WP_CLI::line( wp_json_encode( $this->redact_credentials( $imports->detect() ) ) );
			\WP_CLI::success( 'Import sources detected.' );
			return;
		}

		if ( $subcommand === 'analyze' ) {
			$source     = $this->cli_source( $assoc );
			$profile_id = $this->cli_profile_id( $assoc );
			$this->print_import_result( $imports->start_dry_run( $source, $profile_id ), 'Import analysis started.' );
			return;
		}

		if ( $subcommand === 'run' ) {
			$source     = $this->cli_source( $assoc );
			$profile_id = $this->cli_profile_id( $assoc );
			$result     = $imports->start_commit( $source, $profile_id );
			if ( empty( $result['success'] ) && (string) ( $result['code'] ?? '' ) === 'matching_dry_run_required' && isset( $assoc['force-reanalyze'] ) ) {
				$dry_run = $imports->start_dry_run( $source, $profile_id );
				if ( empty( $dry_run['success'] ) || empty( $dry_run['uuid'] ) ) {
					$this->print_import_result( $dry_run, 'Import analysis completed.' );
					return;
				}
				do {
					$dry_run = $imports->process_next_page( (string) $dry_run['uuid'] );
				} while ( ! empty( $dry_run['success'] ) && (string) ( $dry_run['status'] ?? '' ) === 'running' );
				$this->print_import_result( $dry_run, 'Import analysis completed.' );
				$result = $imports->start_commit( $source, $profile_id );
			}
			$this->print_import_result( $result, 'Import commit started.' );
			return;
		}

		if ( $subcommand === 'status' ) {
			$this->print_import_result( $imports->status( $this->cli_uuid( $assoc ) ), 'Import status loaded.' );
			return;
		}

		if ( $subcommand === 'resume' ) {
			$this->print_import_result( $imports->resume( $this->cli_uuid( $assoc ) ), 'Import resumed.' );
			return;
		}

		\WP_CLI::error( 'Unknown import subcommand. Use detect, analyze, run, status, or resume.' );
	}

	/** @param array<string, mixed> $assoc */
	private function cli_source( array $assoc ): string {
		$raw       = (string) ( $assoc['source'] ?? '' );
		$sanitized = sanitize_key( $raw );
		if ( $sanitized === '' || $sanitized !== $raw ) {
			\WP_CLI::error( 'Provide a valid --source.' );
		}
		return $sanitized;
	}

	/** @param array<string, mixed> $assoc */
	private function cli_profile_id( array $assoc ): int {
		$profile_id = (int) ( $assoc['profile'] ?? 0 );
		if ( $profile_id <= 0 ) {
			\WP_CLI::error( 'Provide a valid --profile.' );
		}
		return $profile_id;
	}

	/** @param array<string, mixed> $assoc */
	private function cli_uuid( array $assoc ): string {
		$uuid = strtolower( sanitize_text_field( (string) ( $assoc['uuid'] ?? '' ) ) );
		if ( ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $uuid ) ) {
			\WP_CLI::error( 'Provide a valid --uuid.' );
		}
		return $uuid;
	}

	/** @param array<string, mixed> $result */
	private function print_import_result( array $result, string $success_message ): void {
		\WP_CLI::line( wp_json_encode( $this->redact_credentials( $result ) ) );
		if ( ! empty( $result['success'] ) ) {
			\WP_CLI::success( $success_message );
			return;
		}
		\WP_CLI::error( (string) ( $result['message'] ?? 'Import command failed.' ) );
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
	 * Queue status and manual run.
	 *
	 * ## OPTIONS
	 *
	 * [<subcommand>]
	 * : status (default) or run
	 *
	 * ## EXAMPLES
	 *
	 *     wp universal-storage queue status
	 *     wp universal-storage queue run
	 *
	 * @when after_wp_load
	 *
	 * @param list<string>         $args Args.
	 * @param array<string, mixed> $assoc Assoc.
	 */
	public function queue(array $args, array $assoc): void {
		$sub = $args[0] ?? 'status';
		if ($sub === 'run') {
			$ran = $this->plugin->queue()->run_next();
			if ($ran) {
				\WP_CLI::success('Processed queue work.');
			} else {
				\WP_CLI::log('No queue work pending.');
			}
			return;
		}

		$status = $this->plugin->queue()->status();
		\WP_CLI::line('driver: ' . (string) ($status['driver'] ?? ''));
		\WP_CLI::line('pending: ' . (int) ($status['pending_count'] ?? 0));
		$bulk = is_array($status['bulk'] ?? null) ? $status['bulk'] : array();
		if ($bulk !== array()) {
			\WP_CLI::line('bulk running: ' . (!empty($bulk['running']) ? 'yes' : 'no'));
			\WP_CLI::line('bulk action: ' . (string) ($bulk['action'] ?? ''));
			\WP_CLI::line('bulk after_id: ' . (int) ($bulk['after_id'] ?? 0));
		}
	}
}
