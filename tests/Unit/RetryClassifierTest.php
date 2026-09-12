<?php
/**
 * Retryable provider and transport failure classification.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Storage\RetryClassifier;
use PHPUnit\Framework\TestCase;
use Throwable;

final class RetryClassifierTest extends TestCase {

	public function test_retries_retryable_status_codes(): void {
		foreach ( array( 408, 429, 500, 502, 503, 504 ) as $status_code ) {
			$this->assertTrue(
				RetryClassifier::is_retryable( self::status_exception( $status_code ) ),
				"Expected HTTP {$status_code} to be retryable."
			);
		}
	}

	public function test_does_not_retry_auth_or_ordinary_client_statuses(): void {
		foreach ( array( 400, 401, 403 ) as $status_code ) {
			$this->assertFalse(
				RetryClassifier::is_retryable( self::status_exception( $status_code, 'Connection reset by peer' ) ),
				"Expected HTTP {$status_code} to be non-retryable."
			);
		}
	}

	public function test_retries_throttling_and_timeout_aws_error_codes(): void {
		foreach ( array( 'Throttling', 'ThrottlingException', 'TooManyRequestsException', 'SlowDown', 'RequestTimeout' ) as $aws_code ) {
			$this->assertTrue(
				RetryClassifier::is_retryable( self::aws_exception( $aws_code, 'Permanent wording is ignored when the AWS code is retryable.' ) ),
				"Expected {$aws_code} to be retryable."
			);
		}
	}

	public function test_does_not_retry_credential_aws_error_codes(): void {
		foreach ( array( 'AccessDenied', 'InvalidAccessKeyId', 'SignatureDoesNotMatch' ) as $aws_code ) {
			$this->assertFalse(
				RetryClassifier::is_retryable( self::aws_exception( $aws_code, 'Operation timed out after TLS handshake.' ) ),
				"Expected {$aws_code} to be non-retryable."
			);
		}
	}

	public function test_retries_narrow_transport_message_fallbacks(): void {
		foreach ( array( 'Operation timed out', 'Connection reset by peer', 'TLS handshake failed', 'Temporary failure in name resolution', 'Could not connect to endpoint' ) as $message ) {
			$this->assertTrue(
				RetryClassifier::is_retryable( new \RuntimeException( $message ) ),
				"Expected '{$message}' to be retryable."
			);
		}
	}

	public function test_message_fallback_does_not_retry_auth_or_signature_errors(): void {
		foreach ( array( 'AccessDenied: operation timed out', 'InvalidAccessKeyId: temporary network failure', 'SignatureDoesNotMatch: connection reset by peer' ) as $message ) {
			$this->assertFalse(
				RetryClassifier::is_retryable( new \RuntimeException( $message ) ),
				"Expected '{$message}' to be non-retryable."
			);
		}
	}

	private static function status_exception( int $status_code, string $message = 'Provider response' ): Throwable {
		return new class( $message, $status_code ) extends \RuntimeException {
			public function __construct( string $message, private int $status_code ) {
				parent::__construct( $message );
			}

			public function getStatusCode(): int {
				return $this->status_code;
			}
		};
	}

	private static function aws_exception( string $aws_code, string $message ): Throwable {
		return new class( $message, $aws_code ) extends \RuntimeException {
			public function __construct( string $message, private string $aws_code ) {
				parent::__construct( $message );
			}

			public function getAwsErrorCode(): string {
				return $this->aws_code;
			}
		};
	}
}
