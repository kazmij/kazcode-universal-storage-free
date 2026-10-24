<?php
/**
 * SecretRedactor: provider/SDK messages never carry credential material (#74).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\SecretRedactor;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\RemoteObservation;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Storage\ProfileStorageGateway;
use Kazcode\WpStorage\Storage\PublicUrlResolver;
use Kazcode\WpStorage\Storage\S3ClientFactory;
use Kazcode\WpStorage\Storage\S3KeyResolver;
use Kazcode\WpStorage\Storage\S3Storage;

final class SecretRedactorTest extends TestCase {

	// Deterministic fake markers; none of these are real credentials.
	private const AWS_KEY_ID    = 'AKIAKAZUSMARKER0000001';
	private const R2_KEY_ID     = 'kazusr2markerkeyid00000000000001';
	private const SECRET        = 'kazusSecretMarker/0000+0000/0000000000000001';
	private const SESSION_TOKEN = 'kazusSessionTokenMarker0000000001';
	private const SIGNATURE     = 'kazussignaturemarker000000000000000000000000000000000000000001';

	/**
	 * A realistic SignatureDoesNotMatch S3 error as the AWS SDK formats it.
	 */
	private function signature_mismatch_message(): string {
		return 'Error executing "HeadObject" on "https://bucket.s3.amazonaws.com/uploads/a.jpg?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential='
			. self::AWS_KEY_ID . '%2F20261022%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Security-Token=' . self::SESSION_TOKEN
			. '&X-Amz-Signature=' . self::SIGNATURE . '"; AWS HTTP error: Client error: `HEAD https://bucket.s3.amazonaws.com/uploads/a.jpg` resulted in a `403 Forbidden` response:'
			. "\n" . '<?xml version="1.0" encoding="UTF-8"?><Error><Code>SignatureDoesNotMatch</Code><Message>The request signature we calculated does not match</Message>'
			. '<AWSAccessKeyId>' . self::AWS_KEY_ID . '</AWSAccessKeyId><StringToSign>AWS4-HMAC-SHA256' . "\n" . '20261022T000000Z</StringToSign>'
			. '<SignatureProvided>' . self::SIGNATURE . '</SignatureProvided><CanonicalRequest>HEAD /uploads/a.jpg x-amz-security-token:' . self::SESSION_TOKEN . '</CanonicalRequest></Error>'
			. "\nAuthorization: AWS4-HMAC-SHA256 Credential=" . self::R2_KEY_ID . '/20261022/auto/s3/aws4_request, SignedHeaders=host, Signature=' . self::SIGNATURE
			. "\nsecret_access_key=" . self::SECRET . ' token=' . self::SESSION_TOKEN;
	}

	public function test_signing_material_and_key_ids_are_removed_from_sdk_messages(): void {
		$redacted = SecretRedactor::redact( $this->signature_mismatch_message(), 0 );

		$this->assert_no_markers( $redacted );
		$this->assertStringContainsString( 'SignatureDoesNotMatch', $redacted, 'error code must survive for classification' );
		$this->assertStringContainsString( '403 Forbidden', $redacted );
		$this->assertStringContainsString( 'https://bucket.s3.amazonaws.com/uploads/a.jpg', $redacted, 'URL path stays useful for diagnosis' );
	}

	public function test_exceptions_are_accepted_and_bounded(): void {
		$redacted = SecretRedactor::redact( new \RuntimeException( $this->signature_mismatch_message() ), 200 );

		$this->assert_no_markers( $redacted );
		$this->assertLessThanOrEqual( 200, strlen( $redacted ) );
	}

	public function test_plain_operational_messages_are_unchanged(): void {
		foreach ( array(
			'cURL error 28: Operation timed out after 30001 milliseconds',
			'Access Denied (AccessDenied)',
			'Download failed for key: uploads/2026/10/photo.jpg (Not Found)',
			'Object key is owned by another attachment.',
		) as $message ) {
			$this->assertSame( $message, SecretRedactor::redact( $message ) );
		}
	}

	public function test_classification_still_works_on_redacted_text(): void {
		$this->assertSame(
			RemoteObservation::ERROR_AUTH,
			RemoteObservation::classify_error( SecretRedactor::redact( $this->signature_mismatch_message() ), 'SignatureDoesNotMatch', 403 )
		);
	}

	public function test_s3storage_head_key_error_carries_no_credentials(): void {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'get' )->willReturn( 'test-bucket' );
		$settings->method( 'is_aws_configured' )->willReturn( true );
		$storage = new S3Storage(
			$this->createMock( S3ClientFactory::class ),
			new S3KeyResolver( $settings ),
			new PublicUrlResolver( $settings ),
			$settings
		);
		$this->inject_client( $storage, $this->throwing_client() );

		$head = $storage->head_key( 'uploads/a.jpg' );

		$this->assertFalse( $head['exists'] );
		$this->assertFalse( $head['confirmed_missing'] );
		$this->assert_no_markers( (string) $head['error'] );
		$this->assertStringContainsString( 'SignatureDoesNotMatch', (string) $head['error'] );
	}

	public function test_profile_gateway_head_key_error_carries_no_credentials(): void {
		$gateway = new ProfileStorageGateway( $this->profile(), $this->createMock( Settings::class ) );
		$this->inject_client( $gateway, $this->throwing_client() );

		$head = $gateway->head_key( 'uploads/a.jpg' );

		$this->assertFalse( $head['exists'] );
		$this->assert_no_markers( (string) $head['error'] );
	}

	private function assert_no_markers( string $text ): void {
		foreach ( array( self::AWS_KEY_ID, self::R2_KEY_ID, self::SECRET, self::SESSION_TOKEN, self::SIGNATURE ) as $marker ) {
			$this->assertStringNotContainsString( $marker, $text );
			$this->assertStringNotContainsString( rawurlencode( $marker ), $text );
		}
	}

	private function throwing_client(): object {
		$message = $this->signature_mismatch_message();
		return new class( $message ) {
			public function __construct( private string $message ) {
			}

			public function headObject( array $args ): never {
				throw new class( $this->message ) extends \Exception {
					public function getAwsErrorCode(): string {
						return 'SignatureDoesNotMatch';
					}

					public function getStatusCode(): int {
						return 403;
					}
				};
			}
		};
	}

	private function inject_client( object $target, object $client ): void {
		$prop = ( new \ReflectionClass( $target ) )->getProperty( 'client' );
		$prop->setAccessible( true );
		$prop->setValue( $target, $client );
	}

	private function profile(): StorageProfile {
		return new StorageProfile( 1, 'uuid-1', 'Profile', 'aws', 'bucket', 'us-east-1', '', false, 'uploads/', 'storage', '', false, 'keys', 'legacy', true, false, false, '', '' );
	}
}
