<?php
/**
 * Low-level S3 object operations.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Storage;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\SecretRedactor;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Core\SafeMode;

/**
 * Put / Head / Delete wrappers. ACL is never set (bucket-owner-enforced friendly).
 */
final class S3Storage implements StorageGatewayInterface {

	private S3ClientFactory $factory;
	private S3KeyResolver $keys;
	private PublicUrlResolver $urls;
	private Settings $settings;

	/** @var \Aws\S3\S3Client|null */
	private $client = null;

	public function __construct(
		S3ClientFactory $factory,
		S3KeyResolver $keys,
		PublicUrlResolver $urls,
		Settings $settings
	) {
		$this->factory  = $factory;
		$this->keys     = $keys;
		$this->urls     = $urls;
		$this->settings = $settings;
	}

	/**
	 * Key resolver.
	 */
	public function keys(): S3KeyResolver {
		return $this->keys;
	}

	/**
	 * Public URL resolver.
	 */
	public function urls(): PublicUrlResolver {
		return $this->urls;
	}

	public function profile_id(): int {
		return 0;
	}

	/**
	 * Lazy S3 client.
	 *
	 * @return \Aws\S3\S3Client
	 */
	public function client() {
		if ($this->client === null) {
			$this->client = $this->factory->create();
		}
		return $this->client;
	}

	/**
	 * Bucket name.
	 */
	public function bucket(): string {
		return (string) $this->settings->get('bucket', '');
	}

	public function put_object_from_file( string $key, string $local_path, string $content_type, string $cache_control ): void {
		$this->guard_write( 'put_object_from_file' );
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

	public function create_multipart_upload( string $key, string $content_type, string $cache_control ): string {
		$this->guard_write( 'create_multipart_upload' );
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
		$this->guard_write( 'upload_part' );
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
	 * Create a short-lived browser UploadPart URL for an already-authorized
	 * exact multipart identity. Callers must never derive these values from
	 * browser input.
	 */
	public function presign_upload_part( string $key, string $upload_id, int $part_number, int $ttl_seconds ): string {
		$this->guard_write( 'presign_upload_part' );
		if ( $key === '' || $upload_id === '' || $part_number < 1 || $part_number > 10000 || $ttl_seconds < 1 ) {
			throw new \InvalidArgumentException( 'A complete multipart upload-part identity is required.' );
		}
		$command = $this->client()->getCommand( 'UploadPart', array( 'Bucket' => $this->bucket(), 'Key' => $key, 'UploadId' => $upload_id, 'PartNumber' => $part_number ) );
		return (string) $this->client()->createPresignedRequest( $command, '+' . min( 900, $ttl_seconds ) . ' seconds' )->getUri();
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
	 * List incomplete multipart uploads for one exact, server-authorized key.
	 *
	 * S3 only offers prefix listing, so callers must never infer that a prefix
	 * match belongs to their attempt. A truncated response without continuation
	 * markers is ambiguous and deliberately fails instead of looking empty.
	 *
	 * @return list<array{upload_id:string,initiated_at:string}>
	 */
	public function list_multipart_uploads_for_key( string $key ): array {
		if ( $key === '' ) {
			throw new \InvalidArgumentException( 'An exact multipart object key is required.' );
		}

		$uploads          = array();
		$key_marker       = null;
		$upload_id_marker = null;

		do {
			$params = array(
				'Bucket' => $this->bucket(),
				'Prefix' => $key,
			);
			if ( $key_marker !== null && $upload_id_marker !== null ) {
				$params['KeyMarker']      = $key_marker;
				$params['UploadIdMarker'] = $upload_id_marker;
			}

			$result = $this->client()->listMultipartUploads( $params );
			foreach ( $result->get( 'Uploads' ) ?? array() as $upload ) {
				if ( (string) ( $upload['Key'] ?? '' ) !== $key || (string) ( $upload['UploadId'] ?? '' ) === '' ) {
					continue;
				}
				$initiated_at = $upload['Initiated'] ?? '';
				$uploads[] = array(
					'upload_id'    => (string) $upload['UploadId'],
					'initiated_at' => $initiated_at instanceof \DateTimeInterface ? $initiated_at->format( DATE_ATOM ) : (string) $initiated_at,
				);
			}

			if ( ! (bool) ( $result->get( 'IsTruncated' ) ?? false ) ) {
				break;
			}
			$key_marker       = (string) ( $result->get( 'NextKeyMarker' ) ?? '' );
			$upload_id_marker = (string) ( $result->get( 'NextUploadIdMarker' ) ?? '' );
			if ( $key_marker === '' || $upload_id_marker === '' ) {
				throw new \RuntimeException( 'Incomplete multipart listing continuation is ambiguous.' );
			}
		} while ( true );

		return $uploads;
	}

	/**
	 * @param array<int,array{etag:string,size?:int}> $parts
	 */
	public function complete_multipart_upload( string $key, string $upload_id, array $parts ): void {
		$this->guard_write( 'complete_multipart_upload' );
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
		$this->guard_write( 'abort_multipart_upload' );
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
	 * Upload a local file to the given relative uploads path.
	 *
	 * @param string $local_path Absolute filesystem path.
	 * @param string $relative   Relative uploads path.
	 * @return string S3 object key.
	 */
	public function upload_file(string $local_path, string $relative): string {
		$key = $this->keys->resolve($relative);
		$this->upload_file_to_key($local_path, $key, $relative);
		return $key;
	}

	/**
	 * Upload a local file to an explicit S3 object key (v2 object-level offload).
	 *
	 * @param string $local_path Absolute filesystem path.
	 * @param string $key        Full S3 object key.
	 * @param string $relative   Relative uploads path (MIME detection).
	 */
	public function upload_file_to_key(string $local_path, string $key, string $relative): void {
		if (!is_readable($local_path)) {
			throw new \RuntimeException('Local file is not readable.');
		}

		$content_type = $this->content_type_for_local_file($local_path, $relative);
		$cache        = (string) $this->settings->get('cache_control', 'public, max-age=31536000');
		$this->put_object_from_file( $key, $local_path, $content_type, $cache );
	}

	public function content_type_for_local_file( string $local_path, string $relative ): string {
		return $this->detect_mime( $local_path, $relative );
	}

	/**
	 * HEAD object by relative path.
	 *
	 * @param string $relative Relative uploads path.
	 * @return array{exists:bool,content_length?:int,content_type?:string}
	 */
	public function head_relative(string $relative): array {
		return $this->head_key($this->keys->resolve($relative));
	}

	/**
	 * HEAD object by key.
	 *
	 * `confirmed_missing` is true only for a definitive 404/NoSuchKey response — any
	 * other failure (network, throttling, auth) also yields `exists:false` for callers
	 * that fail closed on any HEAD problem, but callers that persist state from this
	 * result (e.g. adopt/inventory) must check `confirmed_missing` before recording an
	 * object as remotely missing, or a transient error gets written as data loss.
	 *
	 * @param string $key S3 key.
	 * @return array{exists:bool,confirmed_missing?:bool,error?:string,content_length?:int,content_type?:string,etag?:string}
	 */
	public function head_key(string $key): array {
		try {
			$result = $this->client()->headObject(
				array(
					'Bucket' => $this->bucket(),
					'Key'    => $key,
				)
			);
			return array(
				'exists'          => true,
				'content_length'  => (int) $result->get('ContentLength'),
				'content_type'    => (string) $result->get('ContentType'),
				'etag'            => $this->normalize_etag( (string) $result->get( 'ETag' ) ),
			);
		} catch (\Throwable $e) {
			$confirmed_missing = $this->is_missing_object_error($e);
			if (!$confirmed_missing && defined('WP_DEBUG') && WP_DEBUG) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log('KAZCODE Universal Storage head_key transient error for key ' . $key . ': ' . SecretRedactor::redact($e));
			}
			$error_code  = method_exists( $e, 'getAwsErrorCode' ) ? (string) $e->getAwsErrorCode() : '';
			$status_code = 0;
			if ( method_exists( $e, 'getStatusCode' ) ) {
				$status_code = (int) $e->getStatusCode();
			} elseif ( method_exists( $e, 'getAwsStatusCode' ) ) {
				$status_code = (int) $e->getAwsStatusCode();
			}
			return array(
				'exists'            => false,
				'confirmed_missing' => $confirmed_missing,
				'error'             => $confirmed_missing ? '' : SecretRedactor::redact( $e ),
				'error_code'        => $error_code,
				'status_code'       => $status_code,
			);
		}
	}

	/**
	 * Download object to a local path via temp → optional size validate → atomic rename.
	 *
	 * @param string   $relative      Relative uploads path.
	 * @param string   $local_path    Destination filesystem path.
	 * @param int|null $expected_size Trustworthy byte length, or null when unknown.
	 *                                Zero is a valid expected size and is validated.
	 */
	public function download_relative(string $relative, string $local_path, ?int $expected_size = null): void {
		$key = $this->keys->resolve($relative);
		AtomicLocalDownload::install(
			$local_path,
			function (string $temp_path) use ($key): void {
				$this->client()->getObject(
					array(
						'Bucket' => $this->bucket(),
						'Key'    => $key,
						'SaveAs' => $temp_path,
					)
				);
			},
			$expected_size
		);
	}

	/**
	 * Delete one or more keys (never recursive prefix delete).
	 *
	 * Uses DeleteObjects on AWS; falls back to per-key DeleteObject when the
	 * provider rejects batch delete (e.g. MinIO MissingContentMD5).
	 *
	 * @param list<string> $keys Object keys.
	 */
	public function delete_keys(array $keys): void {
		$this->guard_write( 'delete_keys' );
		$keys = array_values(array_unique(array_filter($keys)));
		if ($keys === array()) {
			return;
		}

		foreach (array_chunk($keys, 1000) as $chunk) {
			if (!$this->try_delete_objects_batch($chunk)) {
				foreach ($chunk as $key) {
					$this->delete_key($key);
				}
			}
		}
	}

	/**
	 * Delete a single object key (idempotent when already absent).
	 */
	public function delete_key(string $key): void {
		$this->guard_write( 'delete_key' );
		if ($key === '') {
			return;
		}

		try {
			$this->client()->deleteObject(
				array(
					'Bucket' => $this->bucket(),
					'Key'    => $key,
				)
			);
		} catch (\Throwable $e) {
			if ($this->is_missing_object_error($e)) {
				return;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- callers (ConnectionTestService, attachment delete/restore flows) catch \Throwable generically and never echo the raw message as HTML.
			throw new \RuntimeException('Delete failed for key: ' . $key, 0, $e);
		}
	}

	/**
	 * @param list<string> $keys Keys in one DeleteObjects batch (max 1000).
	 */
	private function try_delete_objects_batch(array $keys): bool {
		$objects = array();
		foreach ($keys as $key) {
			$objects[] = array('Key' => $key);
		}

		try {
			$this->client()->deleteObjects(
				array(
					'Bucket' => $this->bucket(),
					'Delete' => array(
						'Objects' => $objects,
						'Quiet'   => true,
					),
				)
			);
			return true;
		} catch (\Throwable $e) {
			if ($this->should_fallback_to_single_delete($e)) {
				return false;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- callers catch \Throwable generically and never echo the raw message as HTML; message itself is a static string with no interpolation.
			throw new \RuntimeException('Batch delete failed.', 0, $e);
		}
	}

	private function should_fallback_to_single_delete(\Throwable $e): bool {
		$msg = strtolower($e->getMessage());
		if (str_contains($msg, 'missingcontentmd5') || str_contains($msg, 'content-md5')) {
			return true;
		}
		$code = method_exists($e, 'getAwsErrorCode') ? (string) $e->getAwsErrorCode() : '';
		return $code === 'MissingContentMD5';
	}

	private function is_missing_object_error(\Throwable $e): bool {
		$code = method_exists($e, 'getAwsErrorCode') ? (string) $e->getAwsErrorCode() : '';
		// Bucket-level / location failures are not object-level confirmed missing.
		if (in_array($code, array('NoSuchBucket', 'InvalidBucketName', 'PermanentRedirect', 'AuthorizationHeaderMalformed'), true)) {
			return false;
		}
		if (in_array($code, array('NoSuchKey', 'NotFound'), true)) {
			return true;
		}
		$status = method_exists($e, 'getStatusCode') ? (int) $e->getStatusCode() : 0;
		// Bare HTTP 404 with no AWS object/bucket code — treat as object absence (S3-compatible quirk).
		if ($status === 404 && ($code === '' || $code === '404')) {
			return true;
		}
		return false;
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

	/**
	 * Delete objects for relative paths.
	 *
	 * @param list<string> $relatives Relative uploads paths.
	 */
	public function delete_relatives(array $relatives): void {
		$keys = array();
		foreach ($relatives as $relative) {
			$keys[] = $this->keys->resolve($relative);
		}
		$this->delete_keys($keys);
	}

	/**
	 * Ensure bucket is reachable (HeadBucket).
	 */
	public function assert_bucket_exists(): void {
		$this->client()->headBucket(array('Bucket' => $this->bucket()));
	}

	/**
	 * Create a time-limited signed GET URL for an object key.
	 *
	 * @param string $key S3 object key.
	 * @param int    $ttl Seconds.
	 */
	public function presigned_url_for_key(string $key, int $ttl = 3600): string {
		$ttl  = max(60, min(86400, $ttl));
		$cmd  = $this->client()->getCommand(
			'GetObject',
			array(
				'Bucket' => $this->bucket(),
				'Key'    => $key,
			)
		);
		$request = $this->client()->createPresignedRequest($cmd, '+' . $ttl . ' seconds');
		return (string) $request->getUri();
	}

	/**
	 * Signed URL for a relative uploads path.
	 *
	 * @param string $relative Relative path.
	 * @param int    $ttl Seconds.
	 */
	public function presigned_url_for_relative(string $relative, int $ttl = 3600): string {
		return $this->presigned_url_for_key($this->keys->resolve($relative), $ttl);
	}

	/**
	 * List object keys under a prefix (one page — never recursive delete).
	 *
	 * @return array{keys:list<string>,next_token:?string}
	 */
	public function list_keys_page( string $prefix, ?string $continuation_token = null, int $max_keys = 1000 ): array {
		$params = array(
			'Bucket'  => $this->bucket(),
			'Prefix'  => $prefix,
			'MaxKeys' => max( 1, min( 1000, $max_keys ) ),
		);
		if ( $continuation_token !== null && $continuation_token !== '' ) {
			$params['ContinuationToken'] = $continuation_token;
		}

		$result = $this->client()->listObjectsV2( $params );
		$keys   = array();
		foreach ( $result->get( 'Contents' ) ?? array() as $item ) {
			if ( ! empty( $item['Key'] ) ) {
				$keys[] = (string) $item['Key'];
			}
		}

		$next = null;
		if ( ! empty( $result['IsTruncated'] ) ) {
			$token = $result->get( 'NextContinuationToken' );
			$next  = is_string( $token ) && $token !== '' ? $token : null;
		}

		return array(
			'keys'       => $keys,
			'next_token' => $next,
		);
	}

	/**
	 * Detect MIME type.
	 */
	private function detect_mime(string $local_path, string $relative): string {
		$mime = '';
		if (function_exists('mime_content_type')) {
			$detected = @mime_content_type($local_path);
			if (is_string($detected) && $detected !== '') {
				$mime = $detected;
			}
		}
		if ($mime === '' && function_exists('wp_check_filetype')) {
			$check = wp_check_filetype($relative);
			if (!empty($check['type'])) {
				$mime = (string) $check['type'];
			}
		}
		return $mime !== '' ? $mime : 'application/octet-stream';
	}

	/**
	 * Safe Mode (#61): refuse writes on a cloned site or a namespace owned by another site.
	 */
	private function guard_write( string $operation ): void {
		SafeMode::guard_write(
			$operation,
			$this->bucket(),
			(string) $this->settings->get( 'object_prefix', '' ),
			function (): ?array {
				try {
					$result = $this->client()->getObject(
						array(
							'Bucket' => $this->bucket(),
							'Key'    => SafeMode::marker_key( (string) $this->settings->get( 'object_prefix', '' ) ),
						)
					);
				} catch ( \Throwable $e ) {
					if ( $this->is_missing_object_error( $e ) ) {
						return null;
					}
					throw $e;
				}
				$decoded = json_decode( (string) $result['Body'], true );
				return is_array( $decoded ) ? $decoded : array();
			},
			function ( array $payload ): void {
				$this->client()->putObject(
					array(
						'Bucket'      => $this->bucket(),
						'Key'         => SafeMode::marker_key( (string) $this->settings->get( 'object_prefix', '' ) ),
						'Body'        => (string) wp_json_encode( $payload ),
						'ContentType' => 'application/json',
					)
				);
			}
		);
	}
}
