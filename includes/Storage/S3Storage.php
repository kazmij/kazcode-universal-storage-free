<?php
/**
 * Low-level S3 object operations.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Storage;

defined( 'ABSPATH' ) || exit;

use Aws\S3\MultipartUploader;
use Aws\Exception\MultipartUploadException;
use Kazcode\WpStorage\Core\Settings;

/**
 * Put / Head / Delete wrappers. ACL is never set (bucket-owner-enforced friendly).
 */
final class S3Storage {

	private const MULTIPART_THRESHOLD = 104857600; // 100 MiB

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

		$content_type = $this->detect_mime($local_path, $relative);
		$cache        = (string) $this->settings->get('cache_control', 'public, max-age=31536000');
		$size         = (int) filesize($local_path);

		if ($size >= self::MULTIPART_THRESHOLD) {
			$uploader = new MultipartUploader(
				$this->client(),
				$local_path,
				array(
					'bucket' => $this->bucket(),
					'key'    => $key,
					'params' => array(
						'ContentType'  => $content_type,
						'CacheControl' => $cache,
					),
				)
			);
			try {
				$uploader->upload();
			} catch (MultipartUploadException $e) {
				throw new \RuntimeException('Multipart upload failed: ' . $e->getMessage(), 0, $e);
			}
		} else {
			$this->client()->putObject(
				array(
					'Bucket'       => $this->bucket(),
					'Key'          => $key,
					'SourceFile'   => $local_path,
					'ContentType'  => $content_type,
					'CacheControl' => $cache,
				)
			);
		}
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
	 * @return array{exists:bool,confirmed_missing?:bool,error?:string,content_length?:int,content_type?:string}
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
			);
		} catch (\Throwable $e) {
			$confirmed_missing = $this->is_missing_object_error($e);
			if (!$confirmed_missing && defined('WP_DEBUG') && WP_DEBUG) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log('KAZCODE Universal Storage head_key transient error for key ' . $key . ': ' . $e->getMessage());
			}
			return array(
				'exists'            => false,
				'confirmed_missing' => $confirmed_missing,
				'error'             => $confirmed_missing ? '' : $e->getMessage(),
			);
		}
	}

	/**
	 * Download object to a local path.
	 *
	 * @param string $relative   Relative uploads path.
	 * @param string $local_path Destination filesystem path.
	 */
	public function download_relative(string $relative, string $local_path): void {
		$key = $this->keys->resolve($relative);
		$dir = dirname($local_path);
		if (!is_dir($dir) && !wp_mkdir_p($dir)) {
			throw new \RuntimeException('Unable to create local directory for download.');
		}

		$this->client()->getObject(
			array(
				'Bucket' => $this->bucket(),
				'Key'    => $key,
				'SaveAs' => $local_path,
			)
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
		if (in_array($code, array('NoSuchKey', 'NotFound', '404'), true)) {
			return true;
		}
		$msg = strtolower($e->getMessage());
		return str_contains($msg, 'nosuchkey') || str_contains($msg, 'not found');
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
}
