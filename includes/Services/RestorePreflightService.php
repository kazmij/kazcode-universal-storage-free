<?php
/**
 * Restore Preflight — §6.4 Media Integrity Center.
 *
 * Non-mutating capacity + remote observation gate shared by CLI/UI/REST/restore Apply.
 *
 * Product policies (#6):
 * - Reserve: max(ceil(required * 0.10), 64 * 1024 * 1024) bytes.
 * - Unknown size among restorable subset → WARNING (not BLOCKED).
 * - SIZE_CONFLICT → BLOCKED.
 * - Concurrent capacity: serialize Applies sharing uploads basedir via
 *   s3ms_restore_capacity_lock, with free-space recheck under that lock.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\ManifestBuilder;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Domain\RemoteObservation;
use Kazcode\WpStorage\Domain\RestorePreflightResult;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Import\ImportRegistry;
use Kazcode\WpStorage\Import\ImportedObjectDescriptor;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
use Kazcode\WpStorage\Storage\PathGuard;
use Kazcode\WpStorage\Storage\ProfileStorageGateway;
use Kazcode\WpStorage\Storage\S3Storage;

/**
 * Computes SAFE / WARNING / BLOCKED before restore downloads begin.
 */
final class RestorePreflightService {

	public const CAPACITY_LOCK_OPTION = 's3ms_restore_capacity_lock';

	/** Default lease TTL (seconds); renewed before each download and sized for large objects. */
	public const CAPACITY_LOCK_TTL_DEFAULT = 1800;

	private const RESERVE_FLOOR_BYTES = 64 * 1024 * 1024;

	/** @var list<string> */
	private const CURRENT_STATUSES = array(
		ObjectRemoteStatus::PENDING,
		ObjectRemoteStatus::UPLOADING,
		ObjectRemoteStatus::PRESENT,
		ObjectRemoteStatus::MISSING,
		ObjectRemoteStatus::FAILED,
	);

	/** @var callable(StorageProfile):ProfileStorageGateway|null */
	private $gateway_factory;

	/** @var callable(string):(int|false)|null */
	private $disk_free_space;

	/** @var callable(string):bool|null */
	private $is_writable;

	public function __construct(
		private Settings $settings,
		private ?ObjectRepository $objects = null,
		private ?WpdbStorageProfileRepository $profiles = null,
		$gateway_factory = null,
		private ?ImportRegistry $imports = null,
		private ?S3Storage $legacy = null,
		$disk_free_space = null,
		$is_writable = null,
		private ?string $uploads_basedir_override = null,
		private ?ManifestBuilder $manifest_builder = null,
	) {
		$this->objects          = $objects ?? new ObjectRepository();
		$this->profiles         = $profiles ?? new WpdbStorageProfileRepository();
		$this->gateway_factory  = is_callable( $gateway_factory ) ? $gateway_factory : null;
		$this->imports          = $imports ?? ImportRegistry::defaults();
		$this->disk_free_space  = is_callable( $disk_free_space ) ? $disk_free_space : null;
		$this->is_writable      = is_callable( $is_writable ) ? $is_writable : null;
		$this->manifest_builder = $manifest_builder ?? new ManifestBuilder();
	}

	/**
	 * @param list<int>           $attachment_ids Attachment IDs.
	 * @param callable|null       $heartbeat      Optional progress callback (e.g. renew attachment lease).
	 * @return array<string, mixed>
	 */
	public function preflight( array $attachment_ids, ?callable $heartbeat = null ): array {
		$ids = array();
		foreach ( $attachment_ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}
		$ids = array_values( $ids );

		$plan    = array();
		$counts  = $this->empty_counts();
		$reasons = array();

		if ( $ids === array() ) {
			return ( new RestorePreflightResult(
				RestorePreflightResult::OUTCOME_BLOCKED,
				0,
				0,
				0,
				0,
				null,
				self::RESERVE_FLOOR_BYTES,
				array( 'invalid_or_empty_manifest' ),
				$counts,
				array()
			) )->to_array();
		}

		$any_path_seen = false;
		foreach ( $ids as $attachment_id ) {
			if ( $heartbeat !== null ) {
				$heartbeat();
			}
			$built = $this->plan_attachment( $attachment_id, $counts, $reasons, $any_path_seen, $heartbeat );
			$plan  = array_merge( $plan, $built );
		}

		if ( ! $any_path_seen ) {
			$reasons[] = 'invalid_or_empty_manifest';
			return $this->finalize( $ids, $plan, $counts, $reasons, true );
		}

		return $this->finalize( $ids, $plan, $counts, $reasons, false );
	}

	/**
	 * @param callable|null $heartbeat Optional progress callback (e.g. renew attachment lease).
	 * @return array<string, mixed>
	 */
	public function preflight_attachment( int $attachment_id, ?callable $heartbeat = null ): array {
		return $this->preflight( array( $attachment_id ), $heartbeat );
	}

	/**
	 * Acquire shared capacity lock for uploads basedir. Returns owner token or null.
	 *
	 * First-writer-wins via add_option(). Expired locks are removed with a SQL
	 * compare-and-swap against the observed serialized option value (same pattern
	 * as AttachmentLock). Leases expire so a crashed Apply cannot permanently
	 * block restores; callers must renew before/during long downloads.
	 */
	public function acquire_capacity_lock( string $basedir, int $ttl_seconds = self::CAPACITY_LOCK_TTL_DEFAULT ): ?string {
		$basedir = rtrim( str_replace( '\\', '/', $basedir ), '/' );
		$now     = time();
		$ttl     = max( 60, $ttl_seconds );

		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$raw = $this->capacity_lock_read_raw();
			if ( $raw !== null ) {
				$observed = $this->capacity_lock_decode( $raw );
				if ( is_array( $observed ) && $this->capacity_lock_is_held( $observed, $basedir, $now ) ) {
					return null;
				}
				// Expired / wrong-basedir — CAS delete only if authoritative value unchanged.
				if ( ! $this->capacity_lock_cas_delete( $raw ) ) {
					continue;
				}
			}

			$token   = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'kazus-restore-', true );
			$payload = array(
				'owner'      => $token,
				'basedir'    => $basedir,
				'expires_at' => $now + $ttl,
			);
			$encoded = $this->capacity_lock_encode( $payload );

			if ( ! $this->capacity_lock_add( $encoded ) ) {
				continue;
			}

			$stored_raw = $this->capacity_lock_read_raw();
			$stored     = $this->capacity_lock_decode( $stored_raw );
			if ( is_array( $stored ) && (string) ( $stored['owner'] ?? '' ) === $token ) {
				return $token;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $lock Lock option payload.
	 */
	private function capacity_lock_is_held( array $lock, string $basedir, int $now ): bool {
		$owner    = (string) ( $lock['owner'] ?? '' );
		$expires  = (int) ( $lock['expires_at'] ?? 0 );
		$lock_dir = (string) ( $lock['basedir'] ?? '' );
		return $owner !== '' && $lock_dir === $basedir && $expires > $now;
	}

	/**
	 * Extend lease while Apply still holds the lock (atomic CAS on option_value).
	 */
	public function renew_capacity_lock( string $token, int $ttl_seconds = self::CAPACITY_LOCK_TTL_DEFAULT ): bool {
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$raw = $this->capacity_lock_read_raw();
			$existing = $this->capacity_lock_decode( $raw );
			if ( ! is_array( $existing ) ) {
				return false;
			}
			if ( (string) ( $existing['owner'] ?? '' ) !== $token ) {
				return false;
			}
			$now = time();
			if ( (int) ( $existing['expires_at'] ?? 0 ) <= $now ) {
				return false;
			}
			$payload = array(
				'owner'      => $token,
				'basedir'    => (string) ( $existing['basedir'] ?? '' ),
				'expires_at' => $now + max( 60, $ttl_seconds ),
			);
			if ( $this->capacity_lock_cas_update( $raw, $payload ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Conservative TTL for one download given expected bytes (assumes slow ~25 KiB/s + buffer).
	 */
	public static function ttl_for_expected_bytes( ?int $expected_bytes ): int {
		if ( $expected_bytes === null ) {
			// Unknown-size blocking GET: keep the lease until Apply renews again
			// after the download returns (no Content-Length / acknowledged WARNING).
			return max( self::CAPACITY_LOCK_TTL_DEFAULT, 24 * 3600 );
		}
		$bytes = $expected_bytes > 0 ? $expected_bytes : 0;
		$secs  = $bytes > 0 ? (int) ceil( $bytes / 25000 ) + 600 : self::CAPACITY_LOCK_TTL_DEFAULT;
		// Do not cap below transfer time — large objects must keep the lease
		// for the full conservative download window.
		return max( self::CAPACITY_LOCK_TTL_DEFAULT, $secs );
	}

	public function release_capacity_lock( string $token ): void {
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$raw = $this->capacity_lock_read_raw();
			$existing = $this->capacity_lock_decode( $raw );
			if ( ! is_array( $existing ) ) {
				return;
			}
			if ( (string) ( $existing['owner'] ?? '' ) !== $token ) {
				return;
			}
			if ( $this->capacity_lock_cas_delete( $raw ) ) {
				return;
			}
		}
	}

	/** @return string|null Authoritative option_value or null when absent. */
	private function capacity_lock_read_raw(): ?string {
		global $wpdb;
		$key = self::CAPACITY_LOCK_OPTION;
		if ( is_object( $wpdb ?? null ) && isset( $wpdb->options ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'get_var' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- capacity lock fencing needs the authoritative option row.
			$value = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
					$key
				)
			);
			return $value === null ? null : (string) $value;
		}
		$opt = get_option( $key, null );
		if ( $opt === null ) {
			return null;
		}
		return $this->capacity_lock_encode( is_array( $opt ) ? $opt : array() );
	}

	/** @param array<string, mixed> $payload Payload. */
	private function capacity_lock_encode( array $payload ): string {
		if ( function_exists( 'wp_json_encode' ) ) {
			return (string) wp_json_encode( $payload );
		}
		return (string) json_encode( $payload );
	}

	/** @return array<string, mixed>|null */
	private function capacity_lock_decode( ?string $raw ): ?array {
		if ( $raw === null || $raw === '' ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
		if ( function_exists( 'maybe_unserialize' ) ) {
			$un = maybe_unserialize( $raw );
			return is_array( $un ) ? $un : null;
		}
		return null;
	}

	private function capacity_lock_add( string $encoded ): bool {
		global $wpdb;
		$key = self::CAPACITY_LOCK_OPTION;
		if ( is_object( $wpdb ?? null ) && isset( $wpdb->options ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'query' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- first-writer-wins insert for capacity lock.
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
					$key,
					$encoded,
					'no'
				)
			);
			if ( $inserted === 1 ) {
				$this->capacity_lock_bust_cache();
				return true;
			}
			return false;
		}
		$ok = add_option( $key, json_decode( $encoded, true ) ?? array(), '', false );
		return (bool) $ok;
	}

	/** @param array<string, mixed> $new_payload New payload. */
	private function capacity_lock_cas_update( ?string $expected_raw, array $new_payload ): bool {
		global $wpdb;
		$key      = self::CAPACITY_LOCK_OPTION;
		$new_raw  = $this->capacity_lock_encode( $new_payload );
		if ( $expected_raw === null ) {
			return false;
		}
		if ( $new_raw === $expected_raw ) {
			return true;
		}
		if ( is_object( $wpdb ?? null ) && isset( $wpdb->options ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'query' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- atomic capacity-lock renew.
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s, autoload = %s WHERE option_name = %s AND option_value = %s",
					$new_raw,
					'no',
					$key,
					$expected_raw
				)
			);
			if ( $updated === 1 ) {
				$this->capacity_lock_bust_cache();
				return true;
			}
			return false;
		}
		$current = get_option( $key, null );
		$decoded = $this->capacity_lock_decode( $expected_raw );
		if ( ! is_array( $current ) || ! is_array( $decoded ) ) {
			return false;
		}
		if ( (string) ( $current['owner'] ?? '' ) !== (string) ( $decoded['owner'] ?? '' )
			|| (int) ( $current['expires_at'] ?? -1 ) !== (int) ( $decoded['expires_at'] ?? -1 )
			|| (string) ( $current['basedir'] ?? '' ) !== (string) ( $decoded['basedir'] ?? '' )
		) {
			return false;
		}
		update_option( $key, $new_payload, false );
		return true;
	}

	private function capacity_lock_cas_delete( ?string $expected_raw ): bool {
		global $wpdb;
		$key = self::CAPACITY_LOCK_OPTION;
		if ( $expected_raw === null ) {
			return true;
		}
		if ( is_object( $wpdb ?? null ) && isset( $wpdb->options ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'query' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- atomic expired capacity-lock delete.
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
					$key,
					$expected_raw
				)
			);
			if ( $deleted === 1 ) {
				$this->capacity_lock_bust_cache();
				return true;
			}
			return false;
		}
		$current = get_option( $key, null );
		$decoded = $this->capacity_lock_decode( $expected_raw );
		if ( ! is_array( $current ) || ! is_array( $decoded ) ) {
			delete_option( $key );
			return true;
		}
		if ( (string) ( $current['owner'] ?? '' ) !== (string) ( $decoded['owner'] ?? '' )
			|| (int) ( $current['expires_at'] ?? -1 ) !== (int) ( $decoded['expires_at'] ?? -1 )
			|| (string) ( $current['basedir'] ?? '' ) !== (string) ( $decoded['basedir'] ?? '' )
		) {
			return false;
		}
		delete_option( $key );
		return true;
	}

	private function capacity_lock_bust_cache(): void {
		$key = self::CAPACITY_LOCK_OPTION;
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $key, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}
	}

	/**
	 * Recheck free space + writability under an held capacity lock.
	 *
	 * @param array<string, mixed> $preflight Prior preflight array.
	 * @return array{ok:bool,outcome?:string,reasons?:list<string>,free_bytes?:int|false|null}
	 */
	public function recheck_capacity_under_lock( array $preflight ): array {
		$required = (int) ( $preflight['required_bytes'] ?? 0 );
		$reserve  = (int) ( $preflight['reserve_bytes'] ?? self::RESERVE_FLOOR_BYTES );
		$plan     = is_array( $preflight['plan'] ?? null ) ? $preflight['plan'] : array();
		$restorable = ( new RestorePreflightResult(
			(string) ( $preflight['outcome'] ?? RestorePreflightResult::OUTCOME_SAFE ),
			(int) ( $preflight['attachment_count'] ?? 0 ),
			(int) ( $preflight['required_download_count'] ?? 0 ),
			(int) ( $preflight['restorable_count'] ?? 0 ),
			$required,
			$preflight['free_bytes'] ?? null,
			$reserve,
			is_array( $preflight['reasons'] ?? null ) ? $preflight['reasons'] : array(),
			is_array( $preflight['counts'] ?? null ) ? $preflight['counts'] : array(),
			$plan
		) )->restorable_plan();
		$probe = $this->probe_destination_for_plan( $restorable );
		$free  = $probe['free_bytes'];

		$reasons = array();
		if ( $required > 0 && $free === false ) {
			$reasons[] = 'unknown_free_space';
		} elseif ( $required > 0 && is_int( $free ) && ( $free - $reserve ) < $required ) {
			$reasons[] = 'insufficient_disk';
		}
		if ( ! $probe['writable'] ) {
			$reasons[] = 'destination_not_writable';
		}
		if ( $reasons !== array() ) {
			return array(
				'ok'         => false,
				'outcome'    => RestorePreflightResult::OUTCOME_BLOCKED,
				'reasons'    => $reasons,
				'free_bytes' => $free,
			);
		}
		return array(
			'ok'         => true,
			'free_bytes' => $free,
		);
	}

	/**
	 * @param array<string, int> $counts Counts.
	 * @param list<string>       $reasons Reasons.
	 * @param callable|null      $heartbeat Optional progress callback.
	 * @return list<array<string,mixed>>
	 */
	private function plan_attachment( int $attachment_id, array &$counts, array &$reasons, bool &$any_path_seen, ?callable $heartbeat = null ): array {
		$post = get_post( $attachment_id );
		if ( ! $post || $post->post_type !== 'attachment' ) {
			$reasons[] = 'invalid_or_empty_manifest';
			return array();
		}

		$manifest = $this->manifest_builder->build( $attachment_id );
		$items    = $manifest->items();
		if ( $items === array() ) {
			$reasons[] = 'invalid_or_empty_manifest';
			return array();
		}

		if ( $this->manifest_builder->has_invalid_expected_path( $attachment_id ) ) {
			$reasons[] = 'invalid_restore_path';
			++$counts[ RestorePreflightResult::CLASS_STRUCTURAL ];
			// Still mark path seen so finalize does not collapse to empty-manifest alone.
			$any_path_seen = true;
			return array(
				$this->plan_entry(
					$attachment_id,
					'',
					null,
					'',
					false,
					null,
					RestorePreflightResult::CLASS_STRUCTURAL
				),
			);
		}

		$rows              = $this->current_rows( $attachment_id );
		$importer_all      = $this->imports->descriptors_for_attachment( $attachment_id );
		$any_importer      = $importer_all !== array();
		$plan              = array();

		foreach ( $items as $item ) {
			if ( $heartbeat !== null ) {
				$heartbeat();
			}
			$relative = (string) ( $item['relative'] ?? '' );
			if ( $relative === '' ) {
				continue;
			}
			$any_path_seen = true;

			$absolute = PathGuard::absolute_under_uploads( $relative );
			if ( $absolute === null ) {
				$reasons[] = 'invalid_restore_path';
				++$counts[ RestorePreflightResult::CLASS_STRUCTURAL ];
				$plan[] = $this->plan_entry(
					$attachment_id,
					$relative,
					null,
					'',
					false,
					null,
					RestorePreflightResult::CLASS_STRUCTURAL
				);
				continue;
			}

			$path_rows     = $this->rows_for_relative( $rows, $relative );
			$expected_size = $this->inventory_expected_size( $path_rows );
			if ( $expected_size === null ) {
				$expected_size = $this->importer_expected_size( $importer_all, $relative );
			}

			if ( $this->local_is_trustworthy( $absolute, $expected_size ) ) {
				continue;
			}

			$resolved = $this->resolve_location( $attachment_id, $relative, $rows, $path_rows, $importer_all, $any_importer );
			if ( ! $resolved['ok'] ) {
				++$counts[ RestorePreflightResult::CLASS_STRUCTURAL ];
				$reason = (string) ( $resolved['reason'] ?? 'ambiguous_key' );
				if ( ! in_array( $reason, $reasons, true ) ) {
					$reasons[] = $reason;
				}
				$plan[] = $this->plan_entry(
					$attachment_id,
					$relative,
					$resolved['profile_id'] ?? null,
					(string) ( $resolved['object_key'] ?? '' ),
					false,
					$expected_size,
					RestorePreflightResult::CLASS_STRUCTURAL
				);
				continue;
			}

			$resolved['relative'] = $relative;
			$head                 = $this->observe_head( $resolved );

			// When local exists without inventory size, compare HEAD to the local
			// filesize so a replaced remote cannot silently overwrite a valid local.
			$compare_expected = $expected_size;
			$local_size       = null;
			if ( is_file( $absolute ) ) {
				$fs = filesize( $absolute );
				if ( $fs !== false ) {
					$local_size = (int) $fs;
					if ( $compare_expected === null ) {
						$compare_expected = $local_size;
					}
				}
			}

			$obs   = RemoteObservation::from_head_result( $head, $compare_expected );
			$class = $this->observation_class( $obs, $compare_expected !== null && $compare_expected >= 0 );

			// Local matches remote size → already restored (exclude from download set).
			if ( $local_size !== null
				&& $class === RestorePreflightResult::CLASS_PRESENT
				&& isset( $head['content_length'] )
				&& (int) $head['content_length'] === $local_size
			) {
				continue;
			}

			++$counts[ $class ];

			// Prefer HEAD content_length for capacity when inventory/importer size unknown.
			// Do not treat expected_size=0 as unknown — zero is a valid validated size.
			$size_for_plan = $expected_size;
			if ( $size_for_plan === null
				&& array_key_exists( 'content_length', $head )
				&& $head['content_length'] !== null
				&& $head['content_length'] !== ''
			) {
				$cl = (int) $head['content_length'];
				if ( $cl >= 0 ) {
					$size_for_plan = $cl;
				}
			}

			$plan[] = $this->plan_entry(
				$attachment_id,
				$relative,
				$resolved['profile_id'] ?? null,
				(string) ( $resolved['object_key'] ?? '' ),
				! empty( $resolved['legacy_fallback'] ),
				$size_for_plan,
				$class
			);
		}

		return $plan;
	}

	/**
	 * @param list<int>              $ids Attachment ids.
	 * @param list<array<string,mixed>> $plan Plan.
	 * @param array<string, int>     $counts Counts.
	 * @param list<string>           $reasons Reasons.
	 * @return array<string, mixed>
	 */
	private function finalize( array $ids, array $plan, array $counts, array $reasons, bool $empty_manifest ): array {
		$required_download_count = count( $plan );
		$restorable              = array();
		$required_bytes          = 0;
		$unknown_size            = 0;
		$bytes_overflow          = false;

		foreach ( $plan as $entry ) {
			$class = (string) ( $entry['class'] ?? '' );
			if ( $class === RestorePreflightResult::CLASS_STRUCTURAL ) {
				continue;
			}
			if ( $class === RestorePreflightResult::CLASS_CONFIRMED_MISSING ) {
				continue;
			}
			if ( in_array( $class, array( RestorePreflightResult::CLASS_AUTH, RestorePreflightResult::CLASS_INVALID_REQUEST, RestorePreflightResult::CLASS_SIZE_CONFLICT ), true ) ) {
				// Still "restorable attempt" for capacity? No — blocked classes excluded from capacity.
				continue;
			}
			$restorable[] = $entry;
			$size         = $entry['expected_size'] ?? null;
			if ( ! is_int( $size ) || $size < 0 ) {
				++$unknown_size;
				continue;
			}
			// expected_size 0 is known (adds zero bytes); do not treat as unknown.
			$sum = $this->add_bytes_safe( $required_bytes, $size );
			if ( $sum === null ) {
				$bytes_overflow = true;
				$required_bytes = 0;
				break;
			}
			$required_bytes = $sum;
		}

		$restorable_count = count( $restorable );
		$reserve_bytes    = $this->reserve_bytes( $required_bytes );
		$probe            = $this->probe_destination_for_plan( $restorable );
		$free_bytes       = $probe['free_bytes'];

		$outcome_reasons = array_values( array_unique( $reasons ) );

		// Precedence §6.4 steps 0–9.
		$outcome = RestorePreflightResult::OUTCOME_SAFE;

		if ( $empty_manifest || in_array( 'invalid_or_empty_manifest', $outcome_reasons, true ) ) {
			$outcome = RestorePreflightResult::OUTCOME_BLOCKED;
			if ( ! in_array( 'invalid_or_empty_manifest', $outcome_reasons, true ) ) {
				$outcome_reasons[] = 'invalid_or_empty_manifest';
			}
		} elseif ( in_array( 'invalid_restore_path', $outcome_reasons, true )
			|| ( $counts[ RestorePreflightResult::CLASS_STRUCTURAL ] ?? 0 ) > 0
			|| $this->has_structural_reason( $outcome_reasons )
		) {
			$outcome = RestorePreflightResult::OUTCOME_BLOCKED;
		} elseif ( ( $counts[ RestorePreflightResult::CLASS_AUTH ] ?? 0 ) > 0 ) {
			$outcome           = RestorePreflightResult::OUTCOME_BLOCKED;
			$outcome_reasons[] = 'auth';
		} elseif ( ( $counts[ RestorePreflightResult::CLASS_INVALID_REQUEST ] ?? 0 ) > 0 ) {
			$outcome           = RestorePreflightResult::OUTCOME_BLOCKED;
			$outcome_reasons[] = 'invalid_request';
		} elseif ( ( $counts[ RestorePreflightResult::CLASS_SIZE_CONFLICT ] ?? 0 ) > 0 ) {
			$outcome           = RestorePreflightResult::OUTCOME_BLOCKED;
			$outcome_reasons[] = 'size_conflict';
		} elseif ( $required_download_count > 0
			&& ( $counts[ RestorePreflightResult::CLASS_CONFIRMED_MISSING ] ?? 0 ) === $required_download_count
		) {
			$outcome           = RestorePreflightResult::OUTCOME_BLOCKED;
			$outcome_reasons[] = 'nothing_restorable';
		} elseif ( $bytes_overflow ) {
			$outcome           = RestorePreflightResult::OUTCOME_BLOCKED;
			$outcome_reasons[] = 'byte_overflow';
		} elseif ( $restorable_count > 0 && $required_bytes > 0 && $free_bytes === false ) {
			$outcome           = RestorePreflightResult::OUTCOME_BLOCKED;
			$outcome_reasons[] = 'unknown_free_space';
		} elseif ( $restorable_count > 0 && $required_bytes > 0 && is_int( $free_bytes )
			&& ( $free_bytes - $reserve_bytes ) < $required_bytes
		) {
			$outcome           = RestorePreflightResult::OUTCOME_BLOCKED;
			$outcome_reasons[] = 'insufficient_disk';
		} elseif ( $restorable_count > 0 && ! $probe['writable'] ) {
			$outcome           = RestorePreflightResult::OUTCOME_BLOCKED;
			$outcome_reasons[] = 'destination_not_writable';
		} elseif ( $unknown_size > 0 ) {
			$outcome           = RestorePreflightResult::OUTCOME_WARNING;
			$outcome_reasons[] = 'unknown_size';
		} elseif ( ( $counts[ RestorePreflightResult::CLASS_CONFIRMED_MISSING ] ?? 0 ) > 0 ) {
			$outcome           = RestorePreflightResult::OUTCOME_WARNING;
			$outcome_reasons[] = 'partial_missing';
		} elseif ( ( $counts[ RestorePreflightResult::CLASS_TRANSIENT ] ?? 0 ) > 0
			|| ( $counts[ RestorePreflightResult::CLASS_UNKNOWN ] ?? 0 ) > 0
		) {
			$outcome           = RestorePreflightResult::OUTCOME_WARNING;
			$outcome_reasons[] = 'transient';
		}

		$outcome_reasons = array_values( array_unique( $outcome_reasons ) );

		return ( new RestorePreflightResult(
			$outcome,
			count( $ids ),
			$required_download_count,
			$restorable_count,
			$required_bytes,
			$free_bytes,
			$reserve_bytes,
			$outcome_reasons,
			$counts,
			$plan
		) )->to_array();
	}

	/**
	 * @param list<string> $reasons Reasons.
	 */
	private function has_structural_reason( array $reasons ): bool {
		foreach ( array( 'ambiguous_key', 'ambiguous_profile', 'ambiguous_object_location', 'profile_missing', 'object_key_missing' ) as $r ) {
			if ( in_array( $r, $reasons, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param list<array<string,mixed>>        $rows All current rows.
	 * @param list<array<string,mixed>>        $path_rows Path rows.
	 * @param list<ImportedObjectDescriptor>   $importer_all Importer descriptors.
	 * @return array{ok:bool,profile_id?:int|null,object_key?:string,legacy_fallback?:bool,reason?:string,profile?:StorageProfile|null}
	 */
	private function resolve_location(
		int $attachment_id,
		string $relative,
		array $rows,
		array $path_rows,
		array $importer_all,
		bool $any_importer
	): array {
		if ( $path_rows !== array() ) {
			$binding = $this->unambiguous_binding( $path_rows );
			if ( $binding === null ) {
				return array( 'ok' => false, 'reason' => 'ambiguous_object_location' );
			}
			$profile_id = (int) ( $binding['storage_profile_id'] ?? 0 );
			$object_key = (string) ( $binding['object_key'] ?? '' );
			if ( $profile_id <= 0 ) {
				return array( 'ok' => false, 'reason' => 'profile_missing', 'profile_id' => 0 );
			}
			if ( $object_key === '' ) {
				return array( 'ok' => false, 'reason' => 'object_key_missing', 'profile_id' => $profile_id );
			}
			$profile = $this->profiles->find( $profile_id );
			if ( $profile === null ) {
				return array( 'ok' => false, 'reason' => 'profile_missing', 'profile_id' => $profile_id );
			}
			return array(
				'ok'              => true,
				'profile_id'      => $profile_id,
				'object_key'      => $object_key,
				'legacy_fallback' => false,
				'profile'         => $profile,
			);
		}

		// NOT_IN_INVENTORY for this path.
		$sibling_profiles = array();
		foreach ( $rows as $row ) {
			$pid = (int) ( $row['storage_profile_id'] ?? 0 );
			if ( $pid > 0 ) {
				$sibling_profiles[ $pid ] = true;
			}
		}
		$has_siblings = $sibling_profiles !== array();

		$importer_keys = array(); // key => list of fingerprints
		foreach ( $importer_all as $descriptor ) {
			if ( $descriptor->wp_relative_path() !== $relative ) {
				continue;
			}
			$key = $descriptor->source_object_key();
			if ( $key === '' ) {
				continue;
			}
			if ( ! isset( $importer_keys[ $key ] ) ) {
				$importer_keys[ $key ] = array();
			}
			$importer_keys[ $key ][ $descriptor->source_fingerprint() ] = true;
		}

		if ( count( $importer_keys ) > 1 ) {
			return array( 'ok' => false, 'reason' => 'ambiguous_key' );
		}

		if ( count( $importer_keys ) === 1 ) {
			$key = (string) array_key_first( $importer_keys );
			if ( count( $importer_keys[ $key ] ) > 1 ) {
				// Same key, conflicting source fingerprints/locations.
				return array( 'ok' => false, 'reason' => 'ambiguous_profile' );
			}
			if ( $has_siblings ) {
				if ( count( $sibling_profiles ) !== 1 ) {
					return array( 'ok' => false, 'reason' => 'ambiguous_profile' );
				}
				$profile_id = (int) array_key_first( $sibling_profiles );
			} else {
				// Bind importer exact key to a fingerprint-compatible profile —
				// never silently use the current default upload target.
				$matched = $this->profiles_matching_importer_key( $importer_all, $relative, $key );
				if ( count( $matched ) === 0 ) {
					return array( 'ok' => false, 'reason' => 'profile_missing' );
				}
				if ( count( $matched ) > 1 ) {
					return array( 'ok' => false, 'reason' => 'ambiguous_profile' );
				}
				$profile_id = (int) $matched[0]->id;
			}
			if ( $profile_id <= 0 ) {
				return array( 'ok' => false, 'reason' => 'profile_missing' );
			}
			$profile = $this->profiles->find( $profile_id );
			if ( $profile === null ) {
				return array( 'ok' => false, 'reason' => 'profile_missing', 'profile_id' => $profile_id );
			}
			// Sibling binding must still be fingerprint-compatible with the importer key.
			if ( ! $this->importer_key_compatible_with_profile( $importer_all, $relative, $key, $profile ) ) {
				return array( 'ok' => false, 'reason' => 'ambiguous_profile', 'profile_id' => $profile_id );
			}
			return array(
				'ok'              => true,
				'profile_id'      => $profile_id,
				'object_key'      => $key,
				'legacy_fallback' => false,
				'profile'         => $profile,
			);
		}

		if ( $any_importer ) {
			// Importer evidence exists but no exact key for this variant — never legacy.
			return array( 'ok' => false, 'reason' => 'ambiguous_key' );
		}

		if ( $has_siblings ) {
			// Partial gap without authoritative exact key.
			return array( 'ok' => false, 'reason' => 'ambiguous_key' );
		}

		// Wholly untracked → legacy Settings/S3Storage when configured.
		if ( $this->legacy !== null && $this->settings->is_aws_configured() ) {
			return array(
				'ok'              => true,
				'profile_id'      => null,
				'object_key'      => '',
				'legacy_fallback' => true,
				'profile'         => null,
			);
		}

		return array( 'ok' => false, 'reason' => 'profile_missing' );
	}

	/**
	 * @param array{ok:bool,profile_id?:int|null,object_key?:string,legacy_fallback?:bool,profile?:StorageProfile|null} $resolved
	 * @return array<string, mixed>
	 */
	private function observe_head( array $resolved ): array {
		if ( ! empty( $resolved['legacy_fallback'] ) ) {
			if ( $this->legacy === null ) {
				return array(
					'exists'            => false,
					'confirmed_missing' => false,
					'error'             => 'legacy_unavailable',
					'error_code'        => 'invalid_request',
					'status_code'       => 400,
				);
			}
			// Caller passes relative via plan — legacy head uses relative from resolve context.
			// Stash relative on resolved via object_key empty; observe_head needs relative.
			return $this->legacy_head_relative( (string) ( $resolved['relative'] ?? '' ) );
		}

		$profile = $resolved['profile'] ?? null;
		$key     = (string) ( $resolved['object_key'] ?? '' );
		if ( ! $profile instanceof StorageProfile || $key === '' ) {
			return array(
				'exists'            => false,
				'confirmed_missing' => false,
				'error'             => 'unresolved',
				'error_code'        => 'invalid_request',
				'status_code'       => 400,
			);
		}
		return $this->gateway( $profile )->head_key( $key );
	}

	private function legacy_head_relative( string $relative ): array {
		if ( $relative === '' || $this->legacy === null ) {
			return array(
				'exists'            => false,
				'confirmed_missing' => false,
				'error'             => 'legacy_unavailable',
				'error_code'        => 'invalid_request',
				'status_code'       => 400,
			);
		}
		return $this->legacy->head_relative( $relative );
	}

	private function gateway( StorageProfile $profile ): ProfileStorageGateway {
		if ( $this->gateway_factory !== null ) {
			return ( $this->gateway_factory )( $profile );
		}
		return ( new ProfileStorageGatewayResolver( $this->settings ) )->gateway_for_profile( $profile );
	}

	private function observation_class( RemoteObservation $obs, bool $has_trustworthy_expected ): string {
		if ( $obs->is_confirmed_missing() ) {
			return RestorePreflightResult::CLASS_CONFIRMED_MISSING;
		}
		if ( $obs->status === RemoteObservation::REMOTE_PRESENT ) {
			if ( $obs->verification_level === RemoteObservation::SIZE_MISMATCH ) {
				return RestorePreflightResult::CLASS_SIZE_CONFLICT;
			}
			if ( $obs->verification_level === RemoteObservation::SIZE_VERIFIED ) {
				return RestorePreflightResult::CLASS_PRESENT;
			}
			return $has_trustworthy_expected ? RestorePreflightResult::CLASS_PRESENT : RestorePreflightResult::CLASS_EXISTS_ONLY;
		}

		$error = (string) ( $obs->error_class ?? RemoteObservation::ERROR_UNKNOWN );
		return match ( $error ) {
			RemoteObservation::ERROR_AUTH => RestorePreflightResult::CLASS_AUTH,
			RemoteObservation::ERROR_INVALID_REQUEST => RestorePreflightResult::CLASS_INVALID_REQUEST,
			RemoteObservation::ERROR_THROTTLED,
			RemoteObservation::ERROR_TIMEOUT,
			RemoteObservation::ERROR_NETWORK,
			RemoteObservation::ERROR_TLS,
			RemoteObservation::ERROR_DNS,
			RemoteObservation::ERROR_PROVIDER => RestorePreflightResult::CLASS_TRANSIENT,
			default => RestorePreflightResult::CLASS_UNKNOWN,
		};
	}

	/**
	 * @param list<array<string,mixed>> $path_rows Rows.
	 */
	private function inventory_expected_size( array $path_rows ): ?int {
		$sizes = array();
		foreach ( $path_rows as $row ) {
			if ( ! array_key_exists( 'size_bytes', $row ) || $row['size_bytes'] === null ) {
				continue;
			}
			$size = (int) $row['size_bytes'];
			if ( $size >= 0 ) {
				$sizes[ $size ] = true;
			}
		}
		if ( count( $sizes ) === 1 ) {
			return (int) array_key_first( $sizes );
		}
		return null;
	}

	/**
	 * @param list<ImportedObjectDescriptor> $importer_all Descriptors.
	 */
	private function importer_expected_size( array $importer_all, string $relative ): ?int {
		$sizes = array();
		foreach ( $importer_all as $descriptor ) {
			if ( $descriptor->wp_relative_path() !== $relative ) {
				continue;
			}
			$size = $descriptor->source_size();
			if ( $size !== null && $size >= 0 ) {
				$sizes[ $size ] = true;
			}
		}
		if ( count( $sizes ) === 1 ) {
			return (int) array_key_first( $sizes );
		}
		return null;
	}

	private function local_is_trustworthy( string $absolute, ?int $expected_size ): bool {
		if ( ! is_file( $absolute ) ) {
			return false;
		}
		if ( $expected_size === null || $expected_size < 0 ) {
			// Unknown expected size → not trustworthy; include in download set.
			// expected_size 0 is valid and must match an empty local file.
			return false;
		}
		$size = filesize( $absolute );
		return $size !== false && (int) $size === $expected_size;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function current_rows( int $attachment_id ): array {
		$out = array();
		foreach ( $this->objects->find_by_attachment( $attachment_id ) as $row ) {
			$status = (string) ( $row['remote_status'] ?? '' );
			if ( in_array( $status, self::CURRENT_STATUSES, true ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * @param list<array<string,mixed>> $rows Rows.
	 * @return list<array<string,mixed>>
	 */
	private function rows_for_relative( array $rows, string $relative ): array {
		$out = array();
		foreach ( $rows as $row ) {
			if ( (string) ( $row['local_relative_path'] ?? '' ) === $relative ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * @param list<array<string,mixed>> $path_rows Rows for one relative.
	 * @return array<string,mixed>|null
	 */
	private function unambiguous_binding( array $path_rows ): ?array {
		$seen = array();
		$first = null;
		foreach ( $path_rows as $row ) {
			$identity = (int) ( $row['storage_profile_id'] ?? 0 ) . "\n" . (string) ( $row['object_key'] ?? '' );
			$seen[ $identity ] = true;
			$first ??= $row;
		}
		return count( $seen ) === 1 ? $first : null;
	}

	/**
	 * @return array{free_bytes:int|false|null,writable:bool,probe_path:string}
	 */
	private function probe_destination(): array {
		$basedir = $this->uploads_basedir();
		$ancestor = $this->nearest_existing_ancestor( $basedir );
		$free     = null;
		if ( $ancestor !== null ) {
			$free = $this->disk_free( $ancestor );
		} else {
			$free = false;
		}
		$writable = $ancestor !== null && $this->path_is_writable( $ancestor );
		return array(
			'free_bytes' => $free,
			'writable'   => $writable,
			'probe_path' => $ancestor ?? $basedir,
		);
	}

	/**
	 * Capacity free-space from basedir ancestor + writability for every restorable path's parent.
	 *
	 * @param list<array<string,mixed>> $restorable Restorable plan entries.
	 * @return array{free_bytes:int|false|null,writable:bool,probe_path:string}
	 */
	private function probe_destination_for_plan( array $restorable ): array {
		$base = $this->probe_destination();
		if ( ! $base['writable'] ) {
			return $base;
		}
		foreach ( $restorable as $entry ) {
			$relative = (string) ( $entry['relative'] ?? '' );
			$absolute = PathGuard::absolute_under_uploads( $relative );
			if ( $absolute === null ) {
				$base['writable'] = false;
				return $base;
			}
			$parent = dirname( $absolute );
			if ( is_dir( $parent ) ) {
				if ( ! $this->path_is_writable( $parent ) ) {
					$base['writable']   = false;
					$base['probe_path'] = $parent;
					return $base;
				}
				continue;
			}
			$ancestor = $this->nearest_existing_ancestor( $parent );
			if ( $ancestor === null || ! $this->path_is_writable( $ancestor ) ) {
				$base['writable']   = false;
				$base['probe_path'] = $ancestor ?? $parent;
				return $base;
			}
		}
		return $base;
	}

	/**
	 * True when the importer exact key's fingerprint is compatible with $profile.
	 *
	 * @param list<ImportedObjectDescriptor> $importer_all Descriptors.
	 */
	private function importer_key_compatible_with_profile(
		array $importer_all,
		string $relative,
		string $key,
		StorageProfile $profile
	): bool {
		foreach ( $importer_all as $descriptor ) {
			if ( $descriptor->wp_relative_path() !== $relative || $descriptor->source_object_key() !== $key ) {
				continue;
			}
			$fingerprint = $descriptor->source_fingerprint();
			$location    = $this->parse_importer_fingerprint_location( $fingerprint );
			if ( $location !== null ) {
				if (
					$location['endpoint'] === null
					&& ! $this->endpointless_media_cloud_matches_profile( $fingerprint, $location, $profile )
				) {
					return false;
				}
				return $this->fingerprint_location_matches_profile( $location, $profile );
			}
			if ( $this->is_builtin_importer_fingerprint( $fingerprint ) ) {
				return false;
			}
			// Non-location fingerprints (tests / other importers): allow selected profile.
			return true;
		}
		return false;
	}

	/**
	 * Profiles whose location matches the importer fingerprint for this exact key.
	 *
	 * @param list<ImportedObjectDescriptor> $importer_all Descriptors.
	 * @return list<StorageProfile>
	 */
	private function profiles_matching_importer_key( array $importer_all, string $relative, string $key ): array {
		$saw_builtin_without_location = false;
		foreach ( $importer_all as $descriptor ) {
			if ( $descriptor->wp_relative_path() !== $relative || $descriptor->source_object_key() !== $key ) {
				continue;
			}
			$fingerprint = $descriptor->source_fingerprint();
			$location    = $this->parse_importer_fingerprint_location( $fingerprint );
			if ( $location !== null ) {
				$matched = array();
				foreach ( $this->profiles->all() as $profile ) {
					if ( ! $profile instanceof StorageProfile ) {
						continue;
					}
					if (
						$location['endpoint'] === null
						&& ! $this->endpointless_media_cloud_matches_profile( $fingerprint, $location, $profile )
					) {
						continue;
					}
					if ( $this->fingerprint_location_matches_profile( $location, $profile ) ) {
						$matched[] = $profile;
					}
				}
				if ( $location['endpoint'] === null && $matched === array() ) {
					$saw_builtin_without_location = true;
				}
				return $matched;
			}
			if ( $this->is_builtin_importer_fingerprint( $fingerprint ) ) {
				$saw_builtin_without_location = true;
			}
		}
		if ( $saw_builtin_without_location ) {
			return array();
		}
		// Non-location fingerprints (tests / other importers): default upload target only.
		$default = $this->profiles->find_default_upload_target();
		return $default instanceof StorageProfile ? array( $default ) : array();
	}

	private function is_builtin_importer_fingerprint( string $fingerprint ): bool {
		foreach ( array( 'wp-offload-media:', 'advanced-media-offloader:', 'media-cloud:' ) as $prefix ) {
			if ( str_starts_with( $fingerprint, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array{provider:string,bucket:string,region:?string,endpoint:?string}|null
	 */
	private function parse_importer_fingerprint_location( string $fingerprint ): ?array {
		foreach ( array( 'wp-offload-media', 'advanced-media-offloader', 'media-cloud' ) as $source ) {
			$prefix = $source . ':';
			if ( ! str_starts_with( $fingerprint, $prefix ) ) {
				continue;
			}
			$bits = explode( ':', substr( $fingerprint, strlen( $prefix ) ) );
			if ( count( $bits ) < 2 ) {
				return null;
			}
			$provider = (string) $bits[0];
			$bucket   = (string) $bits[1];
			if ( $provider === '' || $bucket === '' || str_starts_with( $provider, 'unknown-' ) || str_starts_with( $bucket, 'unknown-' ) ) {
				return null;
			}
			$region = isset( $bits[2] ) && $bits[2] !== '' && ! str_starts_with( (string) $bits[2], 'unknown-' )
				? (string) $bits[2]
				: null;
			$endpoint = null;
			if ( count( $bits ) > 3 ) {
				$raw_endpoint = implode( ':', array_slice( $bits, 3 ) );
				if ( $raw_endpoint !== '' && ! str_starts_with( $raw_endpoint, 'unknown-' ) ) {
					$endpoint = ( $raw_endpoint === '-' || $raw_endpoint === '.' )
						? ''
						: untrailingslashit( $raw_endpoint );
				}
			}
			return array(
				'provider' => $provider,
				'bucket'   => $bucket,
				'region'   => $region,
				'endpoint' => $endpoint,
			);
		}
		return null;
	}

	/**
	 * @param array{provider:string,bucket:string,region:?string,endpoint:?string} $location Location.
	 */
	private function fingerprint_location_matches_profile( array $location, StorageProfile $profile ): bool {
		if ( $location['bucket'] !== $profile->bucket ) {
			return false;
		}
		$family = match ( $location['provider'] ) {
			'aws', 's3', 'r2', 'wasabi', 'b2',
			'aws_s3', 'cloudflare_r2', 'minio', 'backblaze_b2',
			'other-s3', 'cloudflare', 'backblaze-s3' => 's3',
			'do', 'spaces', 'digitalocean'           => 'digitalocean',
			'gcp'                                    => 'gcs',
			default                                  => null,
		};
		$profile_family = match ( $profile->provider_type ) {
			'spaces', 'digitalocean' => 'digitalocean',
			default => 's3',
		};
		if ( $family === null || $family !== $profile_family ) {
			return false;
		}
		if ( $location['region'] !== null && $profile->region !== '' && $location['region'] !== $profile->region ) {
			return false;
		}
		if ( $location['endpoint'] !== null ) {
			$profile_endpoint = untrailingslashit( (string) $profile->endpoint );
			if ( $location['endpoint'] !== $profile_endpoint ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Endpointless Media Cloud fingerprints can still be authoritative when the
	 * retained provider slug maps to a concrete storage-profile preset.
	 *
	 * @param array{provider:string,bucket:string,region:?string,endpoint:?string} $location
	 */
	private function endpointless_media_cloud_matches_profile(
		string $fingerprint,
		array $location,
		StorageProfile $profile
	): bool {
		if ( ! str_starts_with( $fingerprint, 'media-cloud:' ) || $location['endpoint'] !== null ) {
			return false;
		}
		$expected = match ( $location['provider'] ) {
			'aws', 's3', 'aws_s3' => array( 'aws', 's3' ),
			'wasabi' => array( 'wasabi' ),
			'cloudflare', 'r2', 'cloudflare_r2' => array( 'r2' ),
			'backblaze-s3', 'b2', 'backblaze_b2' => array( 'b2' ),
			'minio' => array( 'minio' ),
			'do', 'spaces', 'digitalocean' => array( 'spaces', 'digitalocean' ),
			default => array(),
		};
		return in_array( $profile->provider_type, $expected, true );
	}

	private function uploads_basedir(): string {
		if ( $this->uploads_basedir_override !== null && $this->uploads_basedir_override !== '' ) {
			return rtrim( str_replace( '\\', '/', $this->uploads_basedir_override ), '/' );
		}
		$uploads = wp_upload_dir();
		return rtrim( str_replace( '\\', '/', (string) ( $uploads['basedir'] ?? '' ) ), '/' );
	}

	private function nearest_existing_ancestor( string $path ): ?string {
		$path = rtrim( str_replace( '\\', '/', $path ), '/' );
		$basedir = $this->uploads_basedir();
		$guard_root = dirname( $basedir );
		// Walk up until an existing directory is found; stay under PathGuard jail spirit
		// (do not escape above the parent of basedir when basedir itself is missing).
		$current = $path;
		for ( $i = 0; $i < 64; $i++ ) {
			if ( $current === '' || $current === '/' ) {
				break;
			}
			if ( is_dir( $current ) ) {
				return $current;
			}
			$parent = dirname( $current );
			if ( $parent === $current ) {
				break;
			}
			$current = $parent;
		}
		return null;
	}

	/** @return int|false */
	private function disk_free( string $path ): int|false {
		if ( $this->disk_free_space !== null ) {
			return ( $this->disk_free_space )( $path );
		}
		$result = @disk_free_space( $path );
		return $result === false ? false : (int) $result;
	}

	private function path_is_writable( string $path ): bool {
		if ( $this->is_writable !== null ) {
			return (bool) ( $this->is_writable )( $path );
		}
		return is_writable( $path );
	}

	private function reserve_bytes( int $required ): int {
		if ( $required <= 0 ) {
			return self::RESERVE_FLOOR_BYTES;
		}
		$ten_percent = (int) ceil( $required * 0.10 );
		return max( $ten_percent, self::RESERVE_FLOOR_BYTES );
	}

	private function add_bytes_safe( int $a, int $b ): ?int {
		if ( $b < 0 || $a < 0 ) {
			return null;
		}
		if ( $a > PHP_INT_MAX - $b ) {
			return null;
		}
		return $a + $b;
	}

	/**
	 * @return array<string, int>
	 */
	private function empty_counts(): array {
		return array(
			RestorePreflightResult::CLASS_PRESENT           => 0,
			RestorePreflightResult::CLASS_EXISTS_ONLY       => 0,
			RestorePreflightResult::CLASS_CONFIRMED_MISSING => 0,
			RestorePreflightResult::CLASS_SIZE_CONFLICT     => 0,
			RestorePreflightResult::CLASS_TRANSIENT         => 0,
			RestorePreflightResult::CLASS_AUTH              => 0,
			RestorePreflightResult::CLASS_INVALID_REQUEST   => 0,
			RestorePreflightResult::CLASS_UNKNOWN           => 0,
			RestorePreflightResult::CLASS_STRUCTURAL        => 0,
		);
	}

	/**
	 * @return array{attachment_id:int,relative:string,profile_id:int|null,object_key:string,legacy_fallback:bool,expected_size:int|null,class:string}
	 */
	private function plan_entry(
		int $attachment_id,
		string $relative,
		?int $profile_id,
		string $object_key,
		bool $legacy_fallback,
		?int $expected_size,
		string $class
	): array {
		return array(
			'attachment_id'   => $attachment_id,
			'relative'        => $relative,
			'profile_id'      => $profile_id,
			'object_key'      => $object_key,
			'legacy_fallback' => $legacy_fallback,
			'expected_size'   => $expected_size,
			'class'           => $class,
		);
	}
}
