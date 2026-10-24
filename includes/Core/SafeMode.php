<?php
/**
 * Safe Mode: stop clones and namespace collisions from writing to storage (#61).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Core;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Storage\ObjectKeyService;

/**
 * Every storage write (put, multipart, copy, presigned part upload, abort,
 * delete) passes through guard_write() in S3Storage / ProfileStorageGateway.
 * Reads — HEAD, GET, presigned GET, listing — never do, so delivery keeps
 * working while writes are paused.
 *
 * Two independent conditions pause writes:
 * - the site identity no longer matches (copied database at another URL, or a
 *   production identity running in a non-production environment);
 * - the bucket + prefix carries an ownership marker written by another site.
 *   An unclaimed namespace is claimed by writing the marker on first write; an
 *   unreadable marker fails closed.
 *
 * Enforcement is switched on by Plugin::boot(); code that never boots the
 * plugin (unit tests of unrelated services) is unaffected.
 */
final class SafeMode {

	public const MARKER_NAME     = '.kazcode-site-owner.json';
	public const BLOCK_OPTION    = 's3ms_safe_mode_block';
	public const TAKEOVER_OPTION = 's3ms_namespace_takeover';
	private const OK_TTL         = 600;

	private static bool $enforced = false;
	/** @var (callable():bool)|null */
	private static $has_inventory = null;
	/** @var array<string,bool> */
	private static array $ok = array();

	/**
	 * @param (callable():bool)|null $has_inventory Whether this site has any storage inventory yet.
	 */
	public static function enforce( bool $on = true, ?callable $has_inventory = null ): void {
		self::$enforced      = $on;
		self::$has_inventory = $has_inventory;
		self::$ok            = array();
	}

	public static function is_enforced(): bool {
		return self::$enforced;
	}

	public static function marker_key( string $prefix ): string {
		return ObjectKeyService::normalize_prefix( $prefix ) . self::MARKER_NAME;
	}

	/**
	 * Identity-level pause (no storage request). Null when writes may proceed.
	 *
	 * @return array{reason:string,detail:string}|null
	 */
	public static function identity_block(): ?array {
		return self::$enforced ? self::identity_mismatch() : null;
	}

	/**
	 * A site that has never written to storage has nothing a clone could damage:
	 * a URL or environment change there (typical right after installing) just
	 * rebinds. Namespace ownership still protects a shared prefix.
	 *
	 * @return array{reason:string,detail:string}|null
	 */
	private static function identity_mismatch(): ?array {
		$mismatch = SiteIdentity::mismatch();
		if ( $mismatch !== null && self::$has_inventory !== null && ! ( self::$has_inventory )() ) {
			SiteIdentity::rebind();
			return null;
		}
		return $mismatch;
	}

	/**
	 * @param callable():(array<string,mixed>|null) $read_marker  Marker contents, null when absent; throws when unreadable.
	 * @param callable(array<string,mixed>):void     $write_marker Writes the marker without passing through this guard.
	 * @throws SafeModeException When writes to this namespace are paused.
	 */
	public static function guard_write( string $operation, string $bucket, string $prefix, callable $read_marker, callable $write_marker ): void {
		if ( ! self::$enforced ) {
			return;
		}

		$identity = self::identity_mismatch();
		if ( $identity !== null ) {
			self::block( $identity['reason'], $identity['detail'], $operation, $bucket, $prefix );
		}

		$namespace = $bucket . '|' . ObjectKeyService::normalize_prefix( $prefix );
		$cache_key = 'kazus_ns_ok_' . md5( $namespace . '|' . SiteIdentity::id() );
		if ( isset( self::$ok[ $namespace ] ) || get_transient( $cache_key ) === '1' ) {
			self::$ok[ $namespace ] = true;
			return;
		}

		try {
			$marker = $read_marker();
		} catch ( \Throwable $e ) {
			self::block(
				'namespace_owner_unknown',
				'The ownership marker of this bucket/prefix could not be read (' . SecretRedactor::redact( $e, 200 ) . '), so writes are paused rather than risk another site\'s media.',
				$operation,
				$bucket,
				$prefix
			);
		}

		$takeover = get_option( self::TAKEOVER_OPTION, array() );
		$take     = is_array( $takeover ) && ! empty( $takeover[ $namespace ] );
		$owner    = is_array( $marker ) ? (string) ( $marker['site_id'] ?? '' ) : '';

		if ( $marker !== null && $owner !== SiteIdentity::id() && ! $take ) {
			self::block(
				'namespace_owned_elsewhere',
				sprintf(
					'Bucket "%1$s" prefix "%2$s" belongs to another installation (%3$s). Two sites writing to one prefix would overwrite or delete each other\'s media.',
					$bucket,
					ObjectKeyService::normalize_prefix( $prefix ),
					(string) ( $marker['home'] ?? 'unknown site' )
				),
				$operation,
				$bucket,
				$prefix
			);
		}

		if ( $marker === null || $owner !== SiteIdentity::id() ) {
			$write_marker( self::marker_payload() );
			if ( $take ) {
				unset( $takeover[ $namespace ] );
				update_option( self::TAKEOVER_OPTION, $takeover, false );
			}
		}

		self::$ok[ $namespace ] = true;
		set_transient( $cache_key, '1', self::OK_TTL );
		$block = get_option( self::BLOCK_OPTION, null );
		if ( is_array( $block ) && ( $block['namespace'] ?? '' ) === $namespace ) {
			delete_option( self::BLOCK_OPTION );
		}
	}

	/**
	 * Marker body: identifies the owning site; never contains credentials.
	 *
	 * @return array{plugin:string,site_id:string,home:string,claimed_at:string}
	 */
	public static function marker_payload(): array {
		return array(
			'plugin'     => 'kazcode-universal-storage',
			'site_id'    => SiteIdentity::id(),
			'home'       => SiteIdentity::current_home(),
			'claimed_at' => gmdate( 'c' ),
		);
	}

	/**
	 * The most recent block, for the admin notice.
	 *
	 * @return array<string,string>|null
	 */
	public static function last_block(): ?array {
		$identity = self::identity_block();
		if ( $identity !== null ) {
			return $identity;
		}
		$block = get_option( self::BLOCK_OPTION, null );
		return is_array( $block ) ? array_map( 'strval', $block ) : null;
	}

	/**
	 * Operator acknowledgement: the next write to this namespace re-claims it.
	 */
	public static function allow_takeover( string $namespace ): void {
		$takeover               = get_option( self::TAKEOVER_OPTION, array() );
		$takeover               = is_array( $takeover ) ? $takeover : array();
		$takeover[ $namespace ] = gmdate( 'c' );
		update_option( self::TAKEOVER_OPTION, $takeover, false );
		delete_option( self::BLOCK_OPTION );
		self::$ok = array();
	}

	public static function clear_block(): void {
		delete_option( self::BLOCK_OPTION );
		self::$ok = array();
	}

	/**
	 * @return never
	 * @throws SafeModeException Always.
	 */
	private static function block( string $reason, string $detail, string $operation, string $bucket, string $prefix ): void {
		$namespace = $bucket . '|' . ObjectKeyService::normalize_prefix( $prefix );
		$previous  = get_option( self::BLOCK_OPTION, null );
		if ( ! is_array( $previous ) || ( $previous['reason'] ?? '' ) !== $reason || ( $previous['namespace'] ?? '' ) !== $namespace ) {
			update_option(
				self::BLOCK_OPTION,
				array(
					'reason'    => $reason,
					'detail'    => $detail,
					'namespace' => $namespace,
					'operation' => $operation,
					'at'        => gmdate( 'c' ),
				),
				false
			);
		}
		throw new SafeModeException( $reason, 'Safe Mode: storage write blocked (' . $operation . '). ' . $detail ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught and redacted by callers; never echoed raw.
	}
}
