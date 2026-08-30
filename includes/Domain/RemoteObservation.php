<?php
/**
 * Remote object observation and verification semantics.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Interprets provider HEAD results without conflating absence with uncertainty.
 */
final class RemoteObservation {

	public const REMOTE_PRESENT           = 'remote_present';
	public const REMOTE_CONFIRMED_MISSING = 'remote_confirmed_missing';
	public const REMOTE_UNKNOWN           = 'remote_unknown';
	public const REMOTE_ERROR             = 'remote_error';

	public const EXISTS_VERIFIED  = 'exists_verified';
	public const SIZE_VERIFIED    = 'size_verified';
	public const CONTENT_VERIFIED = 'content_verified';
	public const SIZE_MISMATCH    = 'size_mismatch';
	public const NOT_VERIFIED     = 'not_verified';

	public const ERROR_AUTH            = 'auth';
	public const ERROR_THROTTLED       = 'throttled';
	public const ERROR_TIMEOUT         = 'timeout';
	public const ERROR_NETWORK         = 'network';
	public const ERROR_TLS             = 'tls';
	public const ERROR_DNS             = 'dns';
	public const ERROR_PROVIDER        = 'provider';
	public const ERROR_INVALID_REQUEST = 'invalid_request';
	public const ERROR_UNKNOWN         = 'unknown';

	private function __construct(
		public readonly string $status,
		public readonly string $verification_level,
		public readonly ?string $error_class = null,
		public readonly string $message = '',
	) {
	}

	/**
	 * @param array<string, mixed> $head
	 */
	public static function from_head_result( array $head, ?int $expected_size = null ): self {
		if ( ! empty( $head['exists'] ) ) {
			if ( $expected_size !== null && $expected_size >= 0 ) {
				if ( ! array_key_exists( 'content_length', $head ) ) {
					return new self( self::REMOTE_UNKNOWN, self::NOT_VERIFIED, self::ERROR_UNKNOWN, 'Remote Content-Length unavailable.' );
				}
				if ( (int) $head['content_length'] !== $expected_size ) {
					return new self( self::REMOTE_PRESENT, self::SIZE_MISMATCH, null, 'Remote Content-Length does not match expected local size.' );
				}
				return new self( self::REMOTE_PRESENT, self::SIZE_VERIFIED );
			}
			return new self( self::REMOTE_PRESENT, self::EXISTS_VERIFIED );
		}

		if ( ! empty( $head['confirmed_missing'] ) ) {
			return new self( self::REMOTE_CONFIRMED_MISSING, self::NOT_VERIFIED );
		}

		$error = (string) ( $head['error'] ?? '' );
		return new self( self::REMOTE_UNKNOWN, self::NOT_VERIFIED, self::classify_error( $error, (string) ( $head['error_code'] ?? '' ), isset( $head['status_code'] ) ? (int) $head['status_code'] : null ), $error );
	}

	public static function classify_exception( \Throwable $e ): string {
		$code = method_exists( $e, 'getAwsErrorCode' ) ? (string) $e->getAwsErrorCode() : '';
		$status = null;
		if ( method_exists( $e, 'getStatusCode' ) ) {
			$status = (int) $e->getStatusCode();
		} elseif ( method_exists( $e, 'getAwsStatusCode' ) ) {
			$status = (int) $e->getAwsStatusCode();
		}
		return self::classify_error( $e->getMessage(), $code, $status );
	}

	public static function classify_error( string $error, string $provider_code = '', ?int $status_code = null ): string {
		$normalized = strtolower( trim( $error . ' ' . $provider_code . ' ' . (string) ( $status_code ?? '' ) ) );
		if ( str_contains( $normalized, 'access denied' ) || str_contains( $normalized, 'forbidden' ) || str_contains( $normalized, 'unauthorized' ) || str_contains( $normalized, '403' ) || str_contains( $normalized, '401' ) ) {
			return self::ERROR_AUTH;
		}
		if ( str_contains( $normalized, 'throttl' ) || str_contains( $normalized, 'too many requests' ) || str_contains( $normalized, '429' ) || str_contains( $normalized, 'slowdown' ) ) {
			return self::ERROR_THROTTLED;
		}
		if ( str_contains( $normalized, 'timed out' ) || str_contains( $normalized, 'timeout' ) || str_contains( $normalized, 'requesttimeout' ) ) {
			return self::ERROR_TIMEOUT;
		}
		if ( str_contains( $normalized, 'tls' ) || str_contains( $normalized, 'ssl' ) || str_contains( $normalized, 'certificate' ) ) {
			return self::ERROR_TLS;
		}
		if ( str_contains( $normalized, 'dns' ) || str_contains( $normalized, 'could not resolve' ) || str_contains( $normalized, 'name or service not known' ) ) {
			return self::ERROR_DNS;
		}
		if ( str_contains( $normalized, 'connection reset' ) || str_contains( $normalized, 'connection refused' ) || str_contains( $normalized, 'network' ) ) {
			return self::ERROR_NETWORK;
		}
		if ( str_contains( $normalized, '500' ) || str_contains( $normalized, '502' ) || str_contains( $normalized, '503' ) || str_contains( $normalized, 'service unavailable' ) ) {
			return self::ERROR_PROVIDER;
		}
		if ( str_contains( $normalized, 'invalid' ) || str_contains( $normalized, 'bad request' ) || str_contains( $normalized, '400' ) ) {
			return self::ERROR_INVALID_REQUEST;
		}
		return self::ERROR_UNKNOWN;
	}

	public function is_size_verified(): bool {
		return $this->status === self::REMOTE_PRESENT && $this->verification_level === self::SIZE_VERIFIED;
	}

	public function is_confirmed_missing(): bool {
		return $this->status === self::REMOTE_CONFIRMED_MISSING;
	}

	public function is_unknown(): bool {
		return $this->status === self::REMOTE_UNKNOWN || $this->status === self::REMOTE_ERROR;
	}
}
