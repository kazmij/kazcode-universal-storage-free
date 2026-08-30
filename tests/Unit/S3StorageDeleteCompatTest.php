<?php
/**
 * S3Storage delete compatibility (MinIO batch fallback).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Storage\S3ClientFactory;
use Kazcode\WpStorage\Storage\S3KeyResolver;
use Kazcode\WpStorage\Storage\S3Storage;
use Kazcode\WpStorage\Storage\PublicUrlResolver;

final class S3StorageDeleteCompatTest extends TestCase {

	public function test_falls_back_to_delete_object_when_batch_rejected(): void {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'get' )->willReturnCallback(
			static fn( string $key, mixed $default = null ): mixed => match ( $key ) {
				'bucket' => 'test-bucket',
				default => $default,
			}
		);
		$settings->method( 'is_aws_configured' )->willReturn( true );

		$client = new class() {
			public int $batch_calls = 0;
			public int $single_calls = 0;
			public ?array $last_single_args = null;

			public function deleteObjects( array $args ): array {
				++$this->batch_calls;
				throw new class( 'MissingContentMD5: Missing required header' ) extends \Exception {
					public function getAwsErrorCode(): string {
						return 'MissingContentMD5';
					}
				};
			}

			public function deleteObject( array $args ): array {
				++$this->single_calls;
				$this->last_single_args = $args;
				return array();
			}
		};

		$storage = new S3Storage(
			$this->createMock( S3ClientFactory::class ),
			new S3KeyResolver( $settings ),
			new PublicUrlResolver( $settings ),
			$settings
		);

		$ref = new \ReflectionClass( $storage );
		$prop = $ref->getProperty( 'client' );
		$prop->setValue( $storage, $client );

		$storage->delete_keys( array( 'uploads/a.jpg' ) );

		$this->assertSame( 1, $client->batch_calls );
		$this->assertSame( 1, $client->single_calls );
		$this->assertSame( 'test-bucket', $client->last_single_args['Bucket'] ?? '' );
		$this->assertSame( 'uploads/a.jpg', $client->last_single_args['Key'] ?? '' );
	}

	public function test_batch_delete_partial_errors_are_not_treated_as_success(): void {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'get' )->willReturnCallback(
			static fn( string $key, mixed $default = null ): mixed => match ( $key ) {
				'bucket' => 'test-bucket',
				default => $default,
			}
		);

		$client = new class() {
			public int $batch_calls = 0;

			public function deleteObjects( array $args ): array {
				++$this->batch_calls;
				return array(
					'Deleted' => array(
						array( 'Key' => 'uploads/a.jpg' ),
						array( 'Key' => 'uploads/b.jpg' ),
					),
					'Errors'  => array(
						array(
							'Key'     => 'uploads/c.jpg',
							'Code'    => 'AccessDenied',
							'Message' => 'Access Denied',
						),
					),
				);
			}
		};

		$storage = new S3Storage(
			$this->createMock( S3ClientFactory::class ),
			new S3KeyResolver( $settings ),
			new PublicUrlResolver( $settings ),
			$settings
		);

		$ref  = new \ReflectionClass( $storage );
		$prop = $ref->getProperty( 'client' );
		$prop->setValue( $storage, $client );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Batch delete partial failure' );

		$storage->delete_keys( array( 'uploads/a.jpg', 'uploads/b.jpg', 'uploads/c.jpg' ) );
	}

	public function test_delete_key_is_idempotent_for_missing_object(): void {
		$settings = $this->createMock( Settings::class );
		$settings->method( 'get' )->willReturn( 'test-bucket' );
		$settings->method( 'is_aws_configured' )->willReturn( true );

		$client = new class() {
			public function deleteObject( array $args ): never {
				throw new class( 'NoSuchKey' ) extends \Exception {
					public function getAwsErrorCode(): string {
						return 'NoSuchKey';
					}
				};
			}
		};

		$storage = new S3Storage(
			$this->createMock( S3ClientFactory::class ),
			new S3KeyResolver( $settings ),
			new PublicUrlResolver( $settings ),
			$settings
		);

		$ref = new \ReflectionClass( $storage );
		$prop = $ref->getProperty( 'client' );
		$prop->setValue( $storage, $client );

		$storage->delete_key( 'uploads/missing.jpg' );
		$this->assertTrue( true );
	}
}
