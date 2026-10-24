<?php
/**
 * Storage gateway multipart primitive contract tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Storage\ProfileStorageGateway;
use Kazcode\WpStorage\Storage\PublicUrlResolver;
use Kazcode\WpStorage\Storage\S3ClientFactory;
use Kazcode\WpStorage\Storage\S3KeyResolver;
use Kazcode\WpStorage\Storage\S3Storage;
use Kazcode\WpStorage\Storage\StorageGatewayInterface;
use PHPUnit\Framework\TestCase;

final class StorageGatewayMultipartTest extends TestCase {

	public function test_profile_gateway_exposes_expected_identity_contract(): void {
		$client  = new FakeMultipartS3Client();
		$gateway = $this->profile_gateway( $client );

		$this->assertInstanceOf( StorageGatewayInterface::class, $gateway );
		$this->assertSame( 42, $gateway->profile_id() );
		$this->assertSame( 'profile-bucket', $gateway->bucket() );
	}

	public function test_profile_gateway_sends_multipart_request_shapes_and_normalizes_etags(): void {
		$client = new FakeMultipartS3Client();
		$client->responses['createMultipartUpload'][] = new FakeS3Result( array( 'UploadId' => 'upload-123' ) );
		$client->responses['uploadPart'][]            = new FakeS3Result( array( 'ETag' => '"part-etag"' ) );
		$gateway = $this->profile_gateway( $client );
		$body    = fopen( 'php://temp', 'r+' );

		$upload_id = $gateway->create_multipart_upload( 'media/example.jpg', 'image/jpeg', 'public, max-age=60' );
		$etag      = $gateway->upload_part( 'media/example.jpg', $upload_id, 3, $body, 8192 );

		$this->assertSame( 'upload-123', $upload_id );
		$this->assertSame( 'part-etag', $etag );
		$this->assertSame(
			array(
				'Bucket'       => 'profile-bucket',
				'Key'          => 'media/example.jpg',
				'ContentType'  => 'image/jpeg',
				'CacheControl' => 'public, max-age=60',
			),
			$client->calls[0]['params']
		);
		$this->assertSame(
			array(
				'Bucket'        => 'profile-bucket',
				'Key'           => 'media/example.jpg',
				'UploadId'      => 'upload-123',
				'PartNumber'    => 3,
				'Body'          => $body,
				'ContentLength' => 8192,
			),
			$client->calls[1]['params']
		);
	}

	public function test_profile_gateway_presigns_only_a_complete_upload_part_identity(): void {
		$client  = new FakeMultipartS3Client();
		$gateway = $this->profile_gateway( $client );

		$this->expectException( \InvalidArgumentException::class );
		$gateway->presign_upload_part( '', 'upload-123', 1, 900 );
	}

	public function test_profile_gateway_presigns_the_exact_authorized_upload_part(): void {
		$client  = new FakeMultipartS3Client();
		$gateway = $this->profile_gateway( $client );

		$this->assertSame( 'https://uploads.example.test/part', $gateway->presign_upload_part( 'direct-upload/session/attempt/file.zip', 'upload-123', 7, 900 ) );
		$this->assertSame(
			array(
				'Bucket'     => 'profile-bucket',
				'Key'        => 'direct-upload/session/attempt/file.zip',
				'UploadId'   => 'upload-123',
				'PartNumber' => 7,
			),
			$client->calls[0]['params']
		);
	}

	public function test_default_storage_presigns_the_exact_authorized_upload_part(): void {
		$client  = new FakeMultipartS3Client();
		$storage = $this->default_storage( $client );

		$this->assertSame( 'https://uploads.example.test/part', $storage->presign_upload_part( 'direct-upload/session/attempt/file.zip', 'upload-123', 7, 900 ) );
		$this->assertSame( 'default-bucket', $client->calls[0]['params']['Bucket'] );
	}

	public function test_profile_gateway_lists_parts_across_pages_and_completes_sorted_parts(): void {
		$client = new FakeMultipartS3Client();
		$client->responses['listParts'][] = new FakeS3Result(
			array(
				'IsTruncated'          => true,
				'NextPartNumberMarker' => 2,
				'Parts'                => array(
					array( 'PartNumber' => 1, 'ETag' => '"one"', 'Size' => '64' ),
					array( 'PartNumber' => 2, 'ETag' => 'two', 'Size' => 32 ),
				),
			)
		);
		$client->responses['listParts'][] = new FakeS3Result(
			array(
				'IsTruncated' => false,
				'Parts'       => array(
					array( 'PartNumber' => 4, 'ETag' => '"four"', 'Size' => 8 ),
				),
			)
		);
		$gateway = $this->profile_gateway( $client );

		$parts = $gateway->list_parts( 'media/big.bin', 'upload-999' );
		$gateway->complete_multipart_upload(
			'media/big.bin',
			'upload-999',
			array(
				4 => array( 'etag' => 'four', 'size' => 8 ),
				1 => array( 'etag' => 'one', 'size' => 64 ),
			)
		);

		$this->assertSame(
			array(
				1 => array( 'etag' => 'one', 'size' => 64 ),
				2 => array( 'etag' => 'two', 'size' => 32 ),
				4 => array( 'etag' => 'four', 'size' => 8 ),
			),
			$parts
		);
		$this->assertSame(
			array(
				'Bucket'           => 'profile-bucket',
				'Key'              => 'media/big.bin',
				'UploadId'         => 'upload-999',
				'PartNumberMarker' => 2,
			),
			$client->calls[1]['params']
		);
		$this->assertSame(
			array(
				array( 'PartNumber' => 1, 'ETag' => 'one' ),
				array( 'PartNumber' => 4, 'ETag' => 'four' ),
			),
			$client->calls[2]['params']['MultipartUpload']['Parts']
		);
	}

	public function test_gateways_list_only_exact_key_incomplete_uploads_across_pages(): void {
		$client = new FakeMultipartS3Client();
		$client->responses['listMultipartUploads'][] = new FakeS3Result(
			array(
				'IsTruncated'        => true,
				'NextKeyMarker'       => 'direct-upload/session/attempt/file.zip',
				'NextUploadIdMarker'  => 'upload-2',
				'Uploads'             => array(
					array( 'Key' => 'direct-upload/session/attempt/file.zip', 'UploadId' => 'upload-1', 'Initiated' => '2026-10-01T00:00:00Z' ),
					array( 'Key' => 'direct-upload/session/attempt/file.zip-shadow', 'UploadId' => 'wrong-key', 'Initiated' => '2026-10-01T00:00:01Z' ),
				),
			)
		);
		$client->responses['listMultipartUploads'][] = new FakeS3Result(
			array(
				'IsTruncated' => false,
				'Uploads'     => array(
					array( 'Key' => 'direct-upload/session/attempt/file.zip', 'UploadId' => 'upload-2', 'Initiated' => '2026-10-01T00:00:02Z' ),
				),
			)
		);

		$uploads = $this->profile_gateway( $client )->list_multipart_uploads_for_key( 'direct-upload/session/attempt/file.zip' );

		$this->assertSame(
			array(
				array( 'upload_id' => 'upload-1', 'initiated_at' => '2026-10-01T00:00:00Z' ),
				array( 'upload_id' => 'upload-2', 'initiated_at' => '2026-10-01T00:00:02Z' ),
			),
			$uploads
		);
		$this->assertSame(
			array(
				'Bucket'         => 'profile-bucket',
				'Prefix'         => 'direct-upload/session/attempt/file.zip',
				'KeyMarker'      => 'direct-upload/session/attempt/file.zip',
				'UploadIdMarker' => 'upload-2',
			),
			$client->calls[1]['params']
		);
	}

	public function test_gateways_format_datetime_interface_multipart_timestamps(): void {
		foreach ( array( 'profile' => $this->profile_gateway( new FakeMultipartS3Client() ), 'default' => $this->default_storage( new FakeMultipartS3Client() ) ) as $gateway ) {
			$client = new FakeMultipartS3Client();
			$client->responses['listMultipartUploads'][] = new FakeS3Result(
				array(
					'IsTruncated' => false,
					'Uploads'     => array(
						array(
							'Key'       => 'direct-upload/session/attempt/file.zip',
							'UploadId'  => 'upload-1',
							'Initiated' => new \DateTimeImmutable( '2026-10-01T00:00:00Z' ),
						),
					),
				)
			);
			$this->inject_client( $gateway, $client );

			$this->assertSame(
				array(
					array( 'upload_id' => 'upload-1', 'initiated_at' => '2026-10-01T00:00:00+00:00' ),
				),
				$gateway->list_multipart_uploads_for_key( 'direct-upload/session/attempt/file.zip' )
			);
		}
	}

	public function test_profile_gateway_get_range_uses_range_header_and_returns_sdk_body(): void {
		$client = new FakeMultipartS3Client();
		$stream = fopen( 'php://temp', 'r+' );
		$client->responses['getObject'][] = new FakeS3Result( array( 'Body' => $stream ) );
		$gateway = $this->profile_gateway( $client );

		$this->assertSame( $stream, $gateway->get_range( 'media/source.mov', 1024, 4095 ) );
		$this->assertSame(
			array(
				'Bucket' => 'profile-bucket',
				'Key'    => 'media/source.mov',
				'Range'  => 'bytes=1024-4095',
			),
			$client->calls[0]['params']
		);
	}

	public function test_profile_gateway_abort_ignores_missing_upload_errors_only(): void {
		$client = new FakeMultipartS3Client();
		$client->exceptions['abortMultipartUpload'][] = new FakeAwsError( 'NoSuchUpload', 'not found' );
		$client->exceptions['abortMultipartUpload'][] = new FakeAwsError( 'AccessDenied', 'denied' );
		$gateway = $this->profile_gateway( $client );

		$gateway->abort_multipart_upload( 'media/source.mov', 'gone-upload' );

		$this->expectException( FakeAwsError::class );
		$gateway->abort_multipart_upload( 'media/source.mov', 'denied-upload' );
	}

	/**
	 * @dataProvider generic_object_missing_abort_errors
	 */
	public function test_profile_gateway_abort_propagates_generic_object_missing_errors( \Throwable $error ): void {
		$client = new FakeMultipartS3Client();
		$client->exceptions['abortMultipartUpload'][] = $error;
		$gateway = $this->profile_gateway( $client );

		$this->expectExceptionObject( $error );
		$gateway->abort_multipart_upload( 'media/source.mov', 'upload-id' );
	}

	public function test_default_s3_storage_exposes_same_primitive_surface(): void {
		$client = new FakeMultipartS3Client();
		$client->responses['createMultipartUpload'][] = new FakeS3Result( array( 'UploadId' => 'default-upload' ) );
		$client->responses['uploadPart'][]            = new FakeS3Result( array( 'ETag' => '"default-etag"' ) );
		$storage = $this->default_storage( $client );

		$this->assertInstanceOf( StorageGatewayInterface::class, $storage );
		$this->assertSame( 0, $storage->profile_id() );
		$this->assertSame( 'default-bucket', $storage->bucket() );
		$this->assertSame( 'default-upload', $storage->create_multipart_upload( 'key', '', '' ) );
		$this->assertSame( 'default-etag', $storage->upload_part( 'key', 'default-upload', 1, 'body', 4 ) );
		$this->assertSame(
			array(
				'Bucket'      => 'default-bucket',
				'Key'         => 'key',
				'ContentType' => 'application/octet-stream',
			),
			$client->calls[0]['params']
		);
	}

	/**
	 * @dataProvider generic_object_missing_abort_errors
	 */
	public function test_default_s3_storage_abort_propagates_generic_object_missing_errors( \Throwable $error ): void {
		$client = new FakeMultipartS3Client();
		$client->exceptions['abortMultipartUpload'][] = $error;
		$storage = $this->default_storage( $client );

		$this->expectExceptionObject( $error );
		$storage->abort_multipart_upload( 'media/source.mov', 'upload-id' );
	}

	/**
	 * @return iterable<string,array{0:\Throwable}>
	 */
	public static function generic_object_missing_abort_errors(): iterable {
		yield 'nosuchkey_code' => array( new FakeAwsError( 'NoSuchKey', 'object key missing' ) );
		yield 'notfound_code' => array( new FakeAwsError( 'NotFound', 'object missing' ) );
		yield '404_code' => array( new FakeAwsError( '404', 'object missing' ) );
		yield 'generic_not_found_message' => array( new FakeAwsError( 'RequestError', 'provider returned not found' ) );
	}

	private function profile_gateway( FakeMultipartS3Client $client ): ProfileStorageGateway {
		$profile = new StorageProfile(
			42,
			'profile-uuid',
			'Profile',
			'aws',
			'profile-bucket',
			'us-east-1',
			'',
			false,
			'',
			'storage',
			'',
			false,
			'keys',
			'',
			false,
			false,
			false,
			'2026-09-01 00:00:00',
			'2026-09-01 00:00:00'
		);
		$gateway = new ProfileStorageGateway( $profile, new Settings() );
		$this->inject_client( $gateway, $client );
		return $gateway;
	}

	private function default_storage( FakeMultipartS3Client $client ): S3Storage {
		update_option(
			Settings::OPTION_KEY,
			array_merge(
				Settings::defaults(),
				array(
					'bucket'        => 'default-bucket',
					'cache_control' => 'public, max-age=31536000',
				)
			),
			false
		);

		$settings = new Settings();
		$storage  = new S3Storage(
			new S3ClientFactory( $settings ),
			new S3KeyResolver( $settings ),
			new PublicUrlResolver( $settings ),
			$settings
		);
		$this->inject_client( $storage, $client );
		return $storage;
	}

	private function inject_client( object $target, FakeMultipartS3Client $client ): void {
		$ref  = new \ReflectionClass( $target );
		$prop = $ref->getProperty( 'client' );
		$prop->setValue( $target, $client );
	}
}

final class FakeMultipartS3Client {
	/** @var list<array{method:string,params:array<string,mixed>}> */
	public array $calls = array();

	/** @var array<string,list<FakeS3Result>> */
	public array $responses = array();

	/** @var array<string,list<\Throwable>> */
	public array $exceptions = array();

	/**
	 * @param array<string,mixed> $params
	 */
	public function createMultipartUpload( array $params ): FakeS3Result {
		return $this->record( 'createMultipartUpload', $params );
	}

	/**
	 * @param array<string,mixed> $params
	 */
	public function uploadPart( array $params ): FakeS3Result {
		return $this->record( 'uploadPart', $params );
	}

	/**
	 * @param array<string,mixed> $params
	 */
	public function listParts( array $params ): FakeS3Result {
		return $this->record( 'listParts', $params );
	}

	/**
	 * @param array<string,mixed> $params
	 */
	public function completeMultipartUpload( array $params ): FakeS3Result {
		return $this->record( 'completeMultipartUpload', $params );
	}

	/**
	 * @param array<string,mixed> $params
	 */
	public function abortMultipartUpload( array $params ): FakeS3Result {
		return $this->record( 'abortMultipartUpload', $params );
	}

	/**
	 * @param array<string,mixed> $params
	 */
	public function getObject( array $params ): FakeS3Result {
		return $this->record( 'getObject', $params );
	}

	/** @param array<string,mixed> $params */
	public function listMultipartUploads( array $params ): FakeS3Result {
		return $this->record( 'listMultipartUploads', $params );
	}

	/** @param array<string,mixed> $params */
	public function getCommand( string $name, array $params ): array {
		$this->calls[] = array( 'method' => $name, 'params' => $params );
		return array( 'name' => $name, 'params' => $params );
	}

	/** @param array<string,mixed> $command */
	public function createPresignedRequest( array $command, string $expires ): FakePresignedRequest {
		return new FakePresignedRequest();
	}

	/**
	 * @param array<string,mixed> $params
	 */
	private function record( string $method, array $params ): FakeS3Result {
		$this->calls[] = array(
			'method' => $method,
			'params' => $params,
		);
		if ( ! empty( $this->exceptions[ $method ] ) ) {
			throw array_shift( $this->exceptions[ $method ] );
		}
		return ! empty( $this->responses[ $method ] ) ? array_shift( $this->responses[ $method ] ) : new FakeS3Result();
	}
}

final class FakePresignedRequest {
	public function getUri(): string {
		return 'https://uploads.example.test/part';
	}
}

final class FakeS3Result implements \ArrayAccess {
	/**
	 * @param array<string,mixed> $data
	 */
	public function __construct( private array $data = array() ) {
	}

	public function get( string $key ): mixed {
		return $this->data[ $key ] ?? null;
	}

	public function offsetExists( mixed $offset ): bool {
		return array_key_exists( (string) $offset, $this->data );
	}

	public function offsetGet( mixed $offset ): mixed {
		return $this->data[ (string) $offset ] ?? null;
	}

	public function offsetSet( mixed $offset, mixed $value ): void {
		if ( $offset === null ) {
			return;
		}
		$this->data[ (string) $offset ] = $value;
	}

	public function offsetUnset( mixed $offset ): void {
		unset( $this->data[ (string) $offset ] );
	}
}

final class FakeAwsError extends \RuntimeException {
	public function __construct( private string $aws_error_code, string $message ) {
		parent::__construct( $message );
	}

	public function getAwsErrorCode(): string {
		return $this->aws_error_code;
	}
}
