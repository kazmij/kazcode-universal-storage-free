<?php
/**
 * head_key() must distinguish a confirmed-missing object (404/NoSuchKey) from a
 * transient HEAD failure, so callers that persist inventory state (AdoptAttachmentService)
 * do not record a real object as remote_status=missing just because of a network blip.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\RemoteObservation;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Storage\ProfileStorageGateway;
use Kazcode\WpStorage\Storage\S3ClientFactory;
use Kazcode\WpStorage\Storage\S3KeyResolver;
use Kazcode\WpStorage\Storage\S3Storage;
use Kazcode\WpStorage\Storage\PublicUrlResolver;

final class HeadKeyConfirmedMissingTest extends TestCase {

	public function test_s3storage_head_key_confirmed_missing_on_no_such_key(): void {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'get' )->willReturn( 'test-bucket' );
		$settings->method( 'is_aws_configured' )->willReturn( true );

		$client = new class() {
			public function headObject( array $args ): never {
				throw new class( 'NoSuchKey' ) extends \Exception {
					public function getAwsErrorCode(): string {
						return 'NoSuchKey';
					}
				};
			}
		};

		$storage = $this->storageWithClient( $settings, $client );

		$head = $storage->head_key( 'uploads/missing.jpg' );

		$this->assertFalse( $head['exists'] );
		$this->assertTrue( $head['confirmed_missing'] );
	}

	public function test_s3storage_head_key_not_confirmed_missing_on_transient_error(): void {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'get' )->willReturn( 'test-bucket' );
		$settings->method( 'is_aws_configured' )->willReturn( true );

		$client = new class() {
			public function headObject( array $args ): never {
				throw new class( 'Connection timed out.' ) extends \Exception {
					public function getAwsErrorCode(): string {
						return 'RequestTimeout';
					}
				};
			}
		};

		$storage = $this->storageWithClient( $settings, $client );

		$head = $storage->head_key( 'uploads/a.jpg' );

		$this->assertFalse( $head['exists'] );
		$this->assertFalse( $head['confirmed_missing'] );
		$this->assertSame( 'Connection timed out.', $head['error'] );
		$this->assertSame( RemoteObservation::REMOTE_UNKNOWN, $head['remote_status'] );
		$this->assertSame( RemoteObservation::ERROR_TIMEOUT, $head['error_class'] );
	}

	public function test_profile_storage_gateway_head_key_confirmed_missing_on_not_found(): void {
		$settings = $this->createMock( Settings::class );
		$profile  = $this->profile();

		$client = new class() {
			public function headObject( array $args ): never {
				throw new class( 'Not Found' ) extends \Exception {
					public function getAwsErrorCode(): string {
						return 'NotFound';
					}
				};
			}
		};

		$gateway = $this->gatewayWithClient( $profile, $settings, $client );

		$head = $gateway->head_key( 'uploads/missing.jpg' );

		$this->assertFalse( $head['exists'] );
		$this->assertTrue( $head['confirmed_missing'] );
	}

	public function test_profile_storage_gateway_head_key_not_confirmed_missing_on_access_denied(): void {
		$settings = $this->createMock( Settings::class );
		$profile  = $this->profile();

		$client = new class() {
			public function headObject( array $args ): never {
				throw new class( 'Access Denied' ) extends \Exception {
					public function getAwsErrorCode(): string {
						return 'AccessDenied';
					}
				};
			}
		};

		$gateway = $this->gatewayWithClient( $profile, $settings, $client );

		$head = $gateway->head_key( 'uploads/a.jpg' );

		$this->assertFalse( $head['exists'] );
		$this->assertFalse( $head['confirmed_missing'] );
		$this->assertSame( 'Access Denied', $head['error'] );
		$this->assertSame( RemoteObservation::REMOTE_UNKNOWN, $head['remote_status'] );
		$this->assertSame( RemoteObservation::ERROR_AUTH, $head['error_class'] );
	}

	/**
	 * @dataProvider transient_error_provider
	 */
	public function test_head_key_classifies_transient_error_classes( string $message, string $aws_code, string $expected ): void {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'get' )->willReturn( 'test-bucket' );
		$settings->method( 'is_aws_configured' )->willReturn( true );

		$client = new class($message, $aws_code) {
			public function __construct(private string $message, private string $awsCode) {}
			public function headObject( array $args ): never {
				throw new class( $this->message, $this->awsCode ) extends \Exception {
					public function __construct( string $message, private string $awsCode ) {
						parent::__construct( $message );
					}
					public function getAwsErrorCode(): string {
						return $this->awsCode;
					}
				};
			}
		};

		$storage = $this->storageWithClient( $settings, $client );

		$head = $storage->head_key( 'uploads/a.jpg' );

		$this->assertFalse( $head['exists'] );
		$this->assertFalse( $head['confirmed_missing'] );
		$this->assertSame( RemoteObservation::REMOTE_UNKNOWN, $head['remote_status'] );
		$this->assertSame( $expected, $head['error_class'] );
	}

	/**
	 * @return iterable<string, array{string,string,string}>
	 */
	public static function transient_error_provider(): iterable {
		yield '403' => array( 'Access Denied', 'AccessDenied', RemoteObservation::ERROR_AUTH );
		yield '429' => array( 'Too Many Requests', 'SlowDown', RemoteObservation::ERROR_THROTTLED );
		yield '503' => array( 'Service Unavailable', 'ServiceUnavailable', RemoteObservation::ERROR_PROVIDER );
		yield 'connection_reset' => array( 'Connection reset by peer', 'RequestError', RemoteObservation::ERROR_NETWORK );
		yield 'dns' => array( 'Could not resolve host: bucket.example.test', 'RequestError', RemoteObservation::ERROR_DNS );
		yield 'tls' => array( 'SSL certificate problem', 'RequestError', RemoteObservation::ERROR_TLS );
	}

	private function storageWithClient( Settings $settings, object $client ): S3Storage {
		$storage = new S3Storage(
			$this->createMock( S3ClientFactory::class ),
			new S3KeyResolver( $settings ),
			new PublicUrlResolver( $settings ),
			$settings
		);

		$ref  = new \ReflectionClass( $storage );
		$prop = $ref->getProperty( 'client' );
		$prop->setValue( $storage, $client );

		return $storage;
	}

	private function gatewayWithClient( StorageProfile $profile, Settings $settings, object $client ): ProfileStorageGateway {
		$gateway = new ProfileStorageGateway( $profile, $settings );

		$ref  = new \ReflectionClass( $gateway );
		$prop = $ref->getProperty( 'client' );
		$prop->setValue( $gateway, $client );

		return $gateway;
	}

	private function profile(): StorageProfile {
		$now = gmdate( 'Y-m-d H:i:s' );
		return new StorageProfile(
			1,
			'uuid-1',
			'Legacy',
			'aws',
			'bucket',
			'us-east-1',
			'',
			false,
			'uploads/',
			'storage',
			'',
			false,
			'keys',
			'legacy_default',
			true,
			false,
			false,
			$now,
			$now
		);
	}
}
