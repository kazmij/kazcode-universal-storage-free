<?php
/**
 * Retryable provider and transport failure classification.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Storage;

defined( 'ABSPATH' ) || exit;

use Throwable;

final class RetryClassifier {

	/** @var array<int, true> */
	private const RETRYABLE_STATUS_CODES = array(
		408 => true,
		429 => true,
		500 => true,
		502 => true,
		503 => true,
		504 => true,
	);

	/** @var array<string, true> */
	private const NON_RETRYABLE_AWS_CODES = array(
		'accessdenied'             => true,
		'invalidaccesskeyid'       => true,
		'signaturedoesnotmatch'    => true,
	);

	/** @var array<string, true> */
	private const RETRYABLE_AWS_CODES = array(
		'requesttimeout'           => true,
		'requesttimeoutexception'  => true,
		'slowdown'                 => true,
		'throttling'               => true,
		'throttlingexception'      => true,
		'toomanyrequestsexception' => true,
	);

	/** @var list<string> */
	private const NON_RETRYABLE_MESSAGE_PATTERNS = array(
		'access denied',
		'accessdenied',
		'authentication',
		'credential',
		'forbidden',
		'invalid access key',
		'invalidaccesskeyid',
		'signature does not match',
		'signaturedoesnotmatch',
		'unauthorized',
	);

	/** @var list<string> */
	private const RETRYABLE_MESSAGE_PATTERNS = array(
		'could not connect',
		'connection refused',
		'connection reset',
		'connection timed out',
		'failed to connect',
		'network is unreachable',
		'operation timed out',
		'temporary failure',
		'temporary network',
		'timed out',
		'tls handshake',
	);

	public static function is_retryable( Throwable $throwable ): bool {
		$aws_code = self::aws_error_code( $throwable );
		if ( $aws_code !== '' ) {
			if ( isset( self::NON_RETRYABLE_AWS_CODES[ $aws_code ] ) ) {
				return false;
			}
			if ( isset( self::RETRYABLE_AWS_CODES[ $aws_code ] ) ) {
				return true;
			}
		}

		$status_code = self::status_code( $throwable );
		if ( $status_code !== null ) {
			if ( isset( self::RETRYABLE_STATUS_CODES[ $status_code ] ) ) {
				return true;
			}
			if ( $status_code >= 400 && $status_code < 500 ) {
				return false;
			}
		}

		return self::message_is_retryable( $throwable->getMessage() );
	}

	private static function aws_error_code( Throwable $throwable ): string {
		if ( ! method_exists( $throwable, 'getAwsErrorCode' ) ) {
			return '';
		}

		$code = $throwable->getAwsErrorCode();
		if ( ! is_scalar( $code ) ) {
			return '';
		}

		return (string) preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $code ) );
	}

	private static function status_code( Throwable $throwable ): ?int {
		if ( ! method_exists( $throwable, 'getStatusCode' ) ) {
			return null;
		}

		$status_code = $throwable->getStatusCode();
		if ( ! is_numeric( $status_code ) ) {
			return null;
		}

		return (int) $status_code;
	}

	private static function message_is_retryable( string $message ): bool {
		$message = strtolower( $message );

		foreach ( self::NON_RETRYABLE_MESSAGE_PATTERNS as $pattern ) {
			if ( str_contains( $message, $pattern ) ) {
				return false;
			}
		}

		foreach ( self::RETRYABLE_MESSAGE_PATTERNS as $pattern ) {
			if ( str_contains( $message, $pattern ) ) {
				return true;
			}
		}

		return false;
	}
}
