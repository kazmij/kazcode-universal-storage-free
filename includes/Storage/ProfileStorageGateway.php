<?php
/**
 * Profile-scoped S3 operations for cross-profile migration.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Storage;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\StorageProfile;

/**
 * Low-level Put/Head/Get/Copy/Delete for one storage profile.
 */
final class ProfileStorageGateway implements StorageGatewayInterface {

	/** @var \Aws\S3\S3Client|null */
	private $client = null;

	public function __construct(
		private StorageProfile $profile,
		private Settings $settings,
	) {
	}

	public function profile(): StorageProfile {
		return $this->profile;
	}

	public function profile_id(): int {
		return (int) ( $this->profile->id ?? 0 );
	}

	/**
	 * @return \Aws\S3\S3Client
	 */
	public function client() {
		if ( $this->client === null ) {
			$this->client = ProfileS3ClientFactory::create( $this->profile, $this->settings );
		}
		return $this->client;
	}

	public function bucket(): string {
		return $this->profile->bucket;
	}

	public function put_object_from_file( string $key, string $local_path, string $content_type, string $cache_control ): void {
		$params = array(
			'Bucket'      => $this->bucket(),
			'Key'         => $key,
			'SourceFile'  => $local_path,
			'ContentType' => $content_type !== '' ? $content_type : 'application/octet-stream',
		);
		if ( $cache_control !== '' ) {
			$params['CacheControl'] = $cache_control;
		}

		$this->client()->putObject( $params );
	}

	public function upload_file_to_key( string $local_path, string $key, string $relative = '' ): void {
		unset( $relative );
		$this->put_object_from_file( $key, $local_path, $this->content_type_for_local_file( $local_path ), (string) $this->settings->get( 'cache_control', 'public, max-age=31536000' ) );
	}

	public function presigned_url_for_key( string $key, int $ttl_seconds ): string {
		if ( $key === '' ) {
			return '';
		}
		$ttl_seconds = max( 60, min( 604800, $ttl_seconds ) );
		$command = $this->client()->getCommand(
			'GetObject',
			array(
				'Bucket' => $this->bucket(),
				'Key'    => $key,
			)
		);
		return (string) $this->client()->createPresignedRequest( $command, '+' . $ttl_seconds . ' seconds' )->getUri();
	}

	public function create_multipart_upload( string $key, string $content_type, string $cache_control ): string {
		$params = array(
			'Bucket'      => $this->bucket(),
			'Key'         => $key,
			'ContentType' => $content_type !== '' ? $content_type : 'application/octet-stream',
		);
		if ( $cache_control !== '' ) {
			$params['CacheControl'] = $cache_control;
		}

		$result = $this->client()->createMultipartUpload( $params );
		return (string) $result->get( 'UploadId' );
	}

	public function upload_part( string $key, string $upload_id, int $part_number, $body, int $content_length ): string {
		$result = $this->client()->uploadPart(
			array(
				'Bucket'        => $this->bucket(),
				'Key'           => $key,
				'UploadId'      => $upload_id,
				'PartNumber'    => $part_number,
				'Body'          => $body,
				'ContentLength' => $content_length,
			)
		);
		return $this->normalize_etag( (string) $result->get( 'ETag' ) );
	}

	/**
	 * @return array<int,array{etag:string,size:int}>
	 */
	public function list_parts( string $key, string $upload_id ): array {
		$parts  = array();
		$marker = null;

		do {
			$params = array(
				'Bucket'   => $this->bucket(),
				'Key'      => $key,
				'UploadId' => $upload_id,
			);
			if ( $marker !== null ) {
				$params['PartNumberMarker'] = $marker;
			}

			$result = $this->client()->listParts( $params );
			foreach ( $result->get( 'Parts' ) ?? array() as $part ) {
				$part_number = (int) ( $part['PartNumber'] ?? 0 );
				if ( $part_number <= 0 ) {
					continue;
				}
				$parts[ $part_number ] = array(
					'etag' => $this->normalize_etag( (string) ( $part['ETag'] ?? '' ) ),
					'size' => (int) ( $part['Size'] ?? 0 ),
				);
			}

			$is_truncated = (bool) ( $result->get( 'IsTruncated' ) ?? false );
			$next_marker  = $result->get( 'NextPartNumberMarker' );
			$marker       = $is_truncated ? (int) $next_marker : null;
		} while ( $is_truncated && $marker !== null && $marker > 0 );

		ksort( $parts );
		return $parts;
	}

	/**
	 * @param array<int,array{etag:string,size?:int}> $parts
	 */
	public function complete_multipart_upload( string $key, string $upload_id, array $parts ): void {
		ksort( $parts );
		$complete_parts = array();
		foreach ( $parts as $part_number => $part ) {
			$complete_parts[] = array(
				'PartNumber' => (int) $part_number,
				'ETag'       => (string) $part['etag'],
			);
		}

		$this->client()->completeMultipartUpload(
			array(
				'Bucket'          => $this->bucket(),
				'Key'             => $key,
				'UploadId'        => $upload_id,
				'MultipartUpload' => array(
					'Parts' => $complete_parts,
				),
			)
		);
	}

	public function abort_multipart_upload( string $key, string $upload_id ): void {
		try {
			$this->client()->abortMultipartUpload(
				array(
					'Bucket'   => $this->bucket(),
					'Key'      => $key,
					'UploadId' => $upload_id,
				)
			);
		} catch ( \Throwable $e ) {
			if ( ! $this->is_missing_multipart_upload_error( $e ) ) {
				throw $e;
			}
		}
	}

	public function get_range( string $key, int $start, int $end ) {
		$result = $this->client()->getObject(
			array(
				'Bucket' => $this->bucket(),
				'Key'    => $key,
				'Range'  => 'bytes=' . $start . '-' . $end,
			)
		);
		return $result['Body'];
	}

	/**
	 * `confirmed_missing` is true only for a definitive 404/NoSuchKey response — see
	 * S3Storage::head_key() for why callers that persist inventory state must check it.
	 *
	 * @return array{exists:bool,confirmed_missing?:bool,error?:string,content_length?:int,content_type?:string,etag?:string}
	 */
	public function head_key( string $key ): array {
		try {
			$result = $this->client()->headObject(
				array(
					'Bucket' => $this->bucket(),
					'Key'    => $key,
				)
			);
			return array(
				'exists'         => true,
				'content_length' => (int) $result->get( 'ContentLength' ),
				'content_type'   => (string) $result->get( 'ContentType' ),
				'etag'           => $this->normalize_etag( (string) $result->get( 'ETag' ) ),
			);
		} catch ( \Throwable $e ) {
			$confirmed_missing = $this->is_missing_object_error( $e );
			if ( ! $confirmed_missing && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'KAZCODE Universal Storage head_key transient error for key ' . $key . ': ' . $e->getMessage() );
			}
			return array(
				'exists'            => false,
				'confirmed_missing' => $confirmed_missing,
				'error'             => $confirmed_missing ? '' : $e->getMessage(),
			);
		}
	}

	public function copy_object( string $source_bucket, string $source_key, string $dest_key ): void {
		$copy_source = rawurlencode( $source_bucket . '/' . $source_key );
		$this->client()->copyObject(
			array(
				'Bucket'     => $this->bucket(),
				'Key'        => $dest_key,
				'CopySource' => $copy_source,
			)
		);
	}

	/**
	 * Stream Get from this profile → Put on destination (no full-file memory load).
	 */
	public function stream_to(
		self $destination,
		string $source_key,
		string $dest_key,
		string $content_type,
		string $cache_control,
	): void {
		$result = $this->client()->getObject(
			array(
				'Bucket' => $this->bucket(),
				'Key'    => $source_key,
			)
		);

		$destination->client()->putObject(
			array(
				'Bucket'       => $destination->bucket(),
				'Key'          => $dest_key,
				'Body'         => $result['Body'],
				'ContentType'  => $content_type !== '' ? $content_type : 'application/octet-stream',
				'CacheControl' => $cache_control,
			)
		);
	}

	public function delete_key( string $key ): void {
		if ( $key === '' ) {
			return;
		}
		try {
			$this->client()->deleteObject(
				array(
					'Bucket' => $this->bucket(),
					'Key'    => $key,
				)
			);
		} catch ( \Throwable $e ) {
			if ( ! $this->is_missing_object_error( $e ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- only reached from queue job handlers / adopt orchestration (background execution, no HTML render); never echoed.
				throw new \RuntimeException( 'Delete failed for key: ' . $key, 0, $e );
			}
		}
	}

	private function is_missing_object_error( \Throwable $e ): bool {
		$code = method_exists( $e, 'getAwsErrorCode' ) ? (string) $e->getAwsErrorCode() : '';
		if ( method_exists( $e, 'getStatusCode' ) && (int) $e->getStatusCode() === 404 ) {
			return true;
		}
		return in_array( $code, array( 'NoSuchKey', 'NotFound', '404' ), true );
	}

	private function is_missing_multipart_upload_error( \Throwable $e ): bool {
		$code = method_exists( $e, 'getAwsErrorCode' ) ? (string) $e->getAwsErrorCode() : '';
		if ( $code === 'NoSuchUpload' ) {
			return true;
		}
		$msg = strtolower( $e->getMessage() );
		return str_contains( $msg, 'nosuchupload' )
			|| str_contains( $msg, 'no such upload' );
	}

	private function normalize_etag( string $etag ): string {
		if ( strlen( $etag ) >= 2 && $etag[0] === '"' && $etag[ strlen( $etag ) - 1 ] === '"' ) {
			return substr( $etag, 1, -1 );
		}
		return $etag;
	}

	private function content_type_for_local_file( string $local_path ): string {
		$type = function_exists( 'wp_check_filetype' )
			? (string) ( wp_check_filetype( basename( $local_path ) )['type'] ?? '' )
			: '';
		if ( $type !== '' ) {
			return $type;
		}
		if ( function_exists( 'mime_content_type' ) && is_file( $local_path ) ) {
			$mime = mime_content_type( $local_path );
			if ( is_string( $mime ) && $mime !== '' ) {
				return $mime;
			}
		}
		return 'application/octet-stream';
	}
}
