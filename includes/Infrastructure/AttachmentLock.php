<?php
/**
 * Per-attachment renewable lease with owner token and fencing generation.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure;

defined( 'ABSPATH' ) || exit;

/**
 * Uses wp_options for no-schema-change 1.0.x compatibility. Production writes
 * use direct SQL compare-and-swap so expired takeover, renew, and release are
 * based on the authoritative database row rather than a stale object cache read.
 */
final class AttachmentLock {

	private const TTL_SECONDS = 300;
	private const FORMAT_VERSION = 2;

	/**
	 * Legacy bool acquire wrapper. State-mutating code must use acquire_lease().
	 */
	public function acquire( int $attachment_id, string $operation ): bool {
		return $this->acquire_lease( $attachment_id, $operation ) instanceof AttachmentLeaseHandle;
	}

	/**
	 * Attempt to acquire a fenced attachment lease.
	 */
	public function acquire_lease( int $attachment_id, string $operation ): ?AttachmentLeaseHandle {
		$key = $this->option_key( $attachment_id );

		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$raw   = $this->read_option_value( $key );
			$state = $this->parse_state( $raw );
			$now   = time();

			if ( $state['active'] && (int) $state['expires'] > $now ) {
				return null;
			}

			if ( ! $state['valid'] && $raw !== null ) {
				return null;
			}

			$generation = (int) $state['generation'] + 1;
			$expires    = $now + self::TTL_SECONDS;
			$token      = $this->new_owner_token();
			$new_state  = array(
				'version'     => self::FORMAT_VERSION,
				'active'      => true,
				'generation'  => $generation,
				'owner_token' => $token,
				'operation'   => $operation,
				'acquired_at' => $now,
				'expires'     => $expires,
			);

			$written = $raw === null
				? add_option( $key, $this->storage_value( $new_state ), '', false )
				: $this->cas_update( $key, $raw, $new_state );

			if ( $written ) {
				$this->store_option_cache( $key, $this->storage_value( $new_state ) );
				return new AttachmentLeaseHandle( $attachment_id, $token, $generation, $operation, $expires );
			}
		}

		return null;
	}

	/**
	 * Renew only the current owner/current generation.
	 */
	public function renew( AttachmentLeaseHandle $lease ): bool {
		$key = $this->option_key( $lease->attachment_id );

		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$raw   = $this->read_option_value( $key );
			$state = $this->parse_state( $raw );
			$now   = time();
			if ( ! $this->matches_current_owner( $state, $lease, $now ) ) {
				return false;
			}

			$new_state            = $state['raw'];
			$new_state['expires'] = $now + self::TTL_SECONDS;
			$new_state['renewed_at'] = $now;

			if ( $this->cas_update( $key, $raw, $new_state ) ) {
				$this->store_option_cache( $key, $this->storage_value( $new_state ) );
				$lease->expires_at = (int) $new_state['expires'];
				return true;
			}
		}

		return false;
	}

	/**
	 * Release only the current owner/current generation. Generation survives.
	 */
	public function release_lease( AttachmentLeaseHandle $lease ): bool {
		$key = $this->option_key( $lease->attachment_id );

		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$raw   = $this->read_option_value( $key );
			$state = $this->parse_state( $raw );
			if ( ! $this->matches_current_owner( $state, $lease, null ) ) {
				return false;
			}

			$new_state = array(
				'version'     => self::FORMAT_VERSION,
				'active'      => false,
				'generation'  => $lease->generation,
				'owner_token' => null,
				'operation'   => null,
				'acquired_at' => (int) $state['raw']['acquired_at'],
				'expires'     => null,
				'released_at' => time(),
			);

			if ( $this->cas_update( $key, $raw, $new_state ) ) {
				$this->store_option_cache( $key, $this->storage_value( $new_state ) );
				return true;
			}
		}

		return false;
	}

	/**
	 * Backward-compatible release wrapper. Without a handle, ownership cannot be
	 * proven, so this intentionally does not delete a possibly newer lease.
	 */
	public function release( int|AttachmentLeaseHandle $lease ): bool {
		if ( $lease instanceof AttachmentLeaseHandle ) {
			return $this->release_lease( $lease );
		}
		return false;
	}

	/**
	 * Whether any non-expired lease exists. This is not proof of ownership.
	 */
	public function is_locked( int $attachment_id ): bool {
		$state = $this->parse_state( $this->read_option_value( $this->option_key( $attachment_id ) ) );
		return $state['active'] && (int) $state['expires'] > time();
	}

	/**
	 * Whether this exact handle still owns the current non-expired generation.
	 */
	public function is_current( AttachmentLeaseHandle $lease ): bool {
		$state = $this->parse_state( $this->read_option_value( $this->option_key( $lease->attachment_id ) ) );
		return $this->matches_current_owner( $state, $lease, time() );
	}

	public function ttl_seconds(): int {
		return self::TTL_SECONDS;
	}

	private function matches_current_owner( array $state, AttachmentLeaseHandle $lease, ?int $now ): bool {
		if ( ! $state['valid'] || ! $state['active'] ) {
			return false;
		}
		if ( (int) $state['generation'] !== $lease->generation ) {
			return false;
		}
		if ( (string) $state['owner_token'] !== $lease->owner_token ) {
			return false;
		}
		if ( $now !== null && (int) $state['expires'] <= $now ) {
			return false;
		}
		return true;
	}

	/**
	 * @return array{valid:bool,active:bool,generation:int,owner_token:?string,operation:?string,expires:?int,raw:array<string,mixed>}
	 */
	private function parse_state( mixed $raw ): array {
		if ( $raw === null ) {
			return $this->empty_state();
		}

		if ( is_string( $raw ) && str_starts_with( trim( $raw ), '{' ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			}
		}

		if ( is_string( $raw ) && function_exists( 'is_serialized' ) && is_serialized( $raw ) ) {
			$decoded = maybe_unserialize( $raw );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			}
		}

		if ( ! is_array( $raw ) ) {
			return array_merge( $this->empty_state(), array( 'valid' => false ) );
		}

		if ( (int) ( $raw['version'] ?? 1 ) === self::FORMAT_VERSION ) {
			$generation = (int) ( $raw['generation'] ?? 0 );
			$active     = (bool) ( $raw['active'] ?? false );
			$expires    = $raw['expires'] ?? null;
			if ( $generation < 0 || ( $active && ( ! is_int( $expires ) && ! ctype_digit( (string) $expires ) ) ) ) {
				return array_merge( $this->empty_state(), array( 'valid' => false ) );
			}
			return array(
				'valid'       => true,
				'active'      => $active,
				'generation'  => $generation,
				'owner_token' => is_string( $raw['owner_token'] ?? null ) ? $raw['owner_token'] : null,
				'operation'   => is_string( $raw['operation'] ?? null ) ? $raw['operation'] : null,
				'expires'     => $expires === null ? null : (int) $expires,
				'raw'         => $raw,
			);
		}

		if ( isset( $raw['expires'], $raw['operation'] ) ) {
			return array(
				'valid'       => true,
				'active'      => true,
				'generation'  => 0,
				'owner_token' => null,
				'operation'   => is_string( $raw['operation'] ) ? $raw['operation'] : null,
				'expires'     => (int) $raw['expires'],
				'raw'         => $raw,
			);
		}

		return array_merge( $this->empty_state(), array( 'valid' => false ) );
	}

	/**
	 * @return array{valid:bool,active:bool,generation:int,owner_token:?string,operation:?string,expires:?int,raw:array<string,mixed>}
	 */
	private function empty_state(): array {
		return array(
			'valid'       => true,
			'active'      => false,
			'generation'  => 0,
			'owner_token' => null,
			'operation'   => null,
			'expires'     => null,
			'raw'         => array(),
		);
	}

	private function cas_update( string $key, mixed $expected, array $new_state ): bool {
		global $wpdb;
		$new_value      = $this->storage_value( $new_state );
		$expected_value = $this->expected_storage_value( $expected );
		if ( $new_value === $expected_value ) {
			return true;
		}

		if ( is_object( $wpdb ?? null ) && isset( $wpdb->options ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'query' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- attachment lease fencing needs an atomic compare-and-swap against the authoritative option row.
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s, autoload = %s WHERE option_name = %s AND option_value = %s",
					$new_value,
					'no',
					$key,
					$expected_value
				)
			);
			return $updated === 1;
		}

		$current = $this->read_option_value( $key );
		if ( $current !== $expected ) {
			return false;
		}
		update_option( $key, $this->storage_value( $new_state ), false );
		return true;
	}

	private function storage_value( array $state ): string {
		if ( function_exists( 'wp_json_encode' ) ) {
			return (string) wp_json_encode( $state );
		}
		return (string) json_encode( $state );
	}

	private function expected_storage_value( mixed $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( function_exists( 'maybe_serialize' ) ) {
			return (string) maybe_serialize( $value );
		}
		return is_scalar( $value ) || $value === null ? (string) $value : serialize( $value );
	}

	private function read_option_value( string $key ): mixed {
		global $wpdb;
		if ( is_object( $wpdb ?? null ) && isset( $wpdb->options ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'get_var' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- leases must read the authoritative option row, not a possibly stale in-request option cache.
			$value = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
					$key
				)
			);
			return $value === null ? null : $value;
		}

		return get_option( $key, null );
	}

	private function store_option_cache( string $key, string $value ): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $key, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}
		if ( function_exists( 'wp_cache_set' ) ) {
			wp_cache_set( $key, $value, 'options' );
		}
	}

	private function new_owner_token(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	private function option_key( int $attachment_id ): string {
		return 's3ms_lock_' . $attachment_id;
	}
}
