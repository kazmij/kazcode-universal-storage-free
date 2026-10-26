<?php
/**
 * Profile-scoped S3 operations for cross-profile migration.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Storage;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\SecretRedactor;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Core\SafeMode;
use Kazcode\WpStorage\Domain\StorageProfile;

/**
 * Low-level Put/Head/Get/Copy/Delete for one storage profile.
 */
final class ProfileStorageGateway implements PresignedUploadPartStorageGatewayInterface {

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

	/**
	 * List one bounded page of object keys in this exact profile's bucket.
	 *
	 * This is intentionally a read-only primitive: callers decide whether a
	 * listed key is actionable. In particular, it must not be paired with a
	 * delete operation for orphan reporting.
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
		if ( (bool) ( $result->get( 'IsTruncated' ) ?? false ) ) {
			$token = $result->get( 'NextContinuationToken' );
			$next  = is_string( $token ) && $token !== '' ? $token : null;
		}

		return array(
			'keys'       => $keys,
			'next_token' => $next,
		);
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

	/** Create a short-lived URL only for a server-authorized exact upload part. */
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
	 * The provider API lists by prefix, so matches for neighbouring keys are
	 * excluded. An incomplete pagination cursor is an unknown provider outcome,
	 * not evidence that an attempt has no open multipart upload.
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
				error_log( 'KAZCODE Universal Storage head_key transient error for key ' . $key . ': ' . SecretRedactor::redact( $e ) );
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

	public function copy_object( string $source_bucket, string $source_key, string $dest_key ): void {
		$this->guard_write( 'copy_object' );
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
		$destination->assert_writable( 'stream_to' );
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

	/**
	 * Download an exact object key to a PathGuard-jailed local path.
	 *
	 * Temp → optional size validate → atomic rename. Does not invent local
	 * files on client/provider failure — any partial SaveAs artifact is
	 * removed before rethrowing. Preexisting destination is left untouched
	 * until a validated install succeeds.
	 *
	 * @param string   $key           Exact remote object key.
	 * @param string   $local_path    Absolute destination under uploads basedir.
	 * @param int|null $expected_size Trustworthy byte length, or null when unknown.
	 *                                Zero is a valid expected size and is validated.
	 */
	public function download_key_to_local( string $key, string $local_path, ?int $expected_size = null ): void {
		if ( $key === '' ) {
			throw new \InvalidArgumentException( 'Object key is required for download.' );
		}
		if ( ! $this->is_local_path_under_uploads( $local_path ) ) {
			throw new \RuntimeException( 'Download destination is outside uploads basedir.' );
		}

		try {
			AtomicLocalDownload::install(
				$local_path,
				function ( string $temp_path ) use ( $key ): void {
					if ( ! $this->is_local_path_under_uploads( $temp_path ) ) {
						throw new \RuntimeException( 'Download temp path is outside uploads basedir.' );
					}
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
		} catch ( \Throwable $e ) {
			if ( $e instanceof \RuntimeException
				&& ( str_contains( $e->getMessage(), 'Unable to install' )
					|| str_contains( $e->getMessage(), 'Downloaded size does not match' )
					|| str_contains( $e->getMessage(), 'outside uploads' )
					|| str_contains( $e->getMessage(), 'Unable to create local directory' ) )
			) {
				throw $e;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- callers catch Throwable; never echoed as HTML.
			throw new \RuntimeException( 'Download failed for key: ' . $key . ' (' . SecretRedactor::redact( $e ) . ')', 0, $e );
		}
	}

	public function delete_key( string $key ): void {
		$this->guard_write( 'delete_key' );
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

	/**
	 * Whether an absolute local path is confined under the uploads basedir.
	 *
	 * Walks to the nearest existing ancestor and requires its realpath to stay
	 * under basedir before allowing mkdir of missing segments. Lexical prefix
	 * checks alone are insufficient when an existing symlink inside uploads
	 * resolves outside basedir and a not-yet-created child would be created
	 * through that symlink.
	 *
	 * When the configured uploads basedir itself is missing, mirrors
	 * PathGuard::absolute_under_uploads(): accept a lexical basedir whose
	 * nearest existing ancestor is real, so wp_mkdir_p can recreate the
	 * uploads tree during disaster-recovery restore.
	 */
	private function is_local_path_under_uploads( string $local_path ): bool {
		$local_path = str_replace( '\\', '/', $local_path );
		if ( $local_path === '' || str_contains( $local_path, "\0" ) ) {
			return false;
		}

		$uploads       = wp_upload_dir();
		$basedir_raw   = rtrim( str_replace( '\\', '/', (string) $uploads['basedir'] ), '/' );
		$basedir_real  = realpath( $basedir_raw );
		$basedir       = $basedir_real !== false
			? rtrim( str_replace( '\\', '/', $basedir_real ), '/' )
			: $basedir_raw;
		if ( $basedir === '' ) {
			return false;
		}

		$cursor  = $local_path;
		$missing = array();
		while ( $cursor !== '' && $cursor !== '/' && ! file_exists( $cursor ) ) {
			// Dangling symlink under uploads: file_exists is false but SaveAs would
			// follow the link and write outside basedir — reject before mkdir.
			if ( is_link( $cursor ) ) {
				return false;
			}
			$base = basename( $cursor );
			if ( $base === '' || $base === '.' || $base === '..' ) {
				return false;
			}
			$missing[] = $base;
			$parent    = dirname( $cursor );
			if ( $parent === $cursor ) {
				return false;
			}
			$cursor = $parent;
		}

		$existing = realpath( $cursor );
		if ( $existing === false ) {
			return false;
		}
		$existing = rtrim( str_replace( '\\', '/', $existing ), '/' );

		foreach ( $missing as $segment ) {
			if ( $segment === '' || $segment === '.' || $segment === '..' || str_contains( $segment, "\0" ) ) {
				return false;
			}
		}

		if ( $basedir_real !== false ) {
			return $existing === $basedir || str_starts_with( $existing . '/', $basedir . '/' );
		}

		// Basedir missing: destination must sit under the lexical basedir.
		if ( $local_path !== $basedir && ! str_starts_with( $local_path . '/', $basedir . '/' ) ) {
			return false;
		}

		// Map lexical basedir onto resolved ancestors (symlink parents like
		// /var/www/wp-content → /mnt/.../wp-content) before comparing $existing.
		$basedir_cursor  = $basedir;
		$basedir_missing = array();
		while ( $basedir_cursor !== '' && $basedir_cursor !== '/' && ! file_exists( $basedir_cursor ) ) {
			if ( is_link( $basedir_cursor ) ) {
				return false;
			}
			$seg = basename( $basedir_cursor );
			if ( $seg === '' || $seg === '.' || $seg === '..' ) {
				return false;
			}
			$basedir_missing[] = $seg;
			$parent            = dirname( $basedir_cursor );
			if ( $parent === $basedir_cursor ) {
				return false;
			}
			$basedir_cursor = $parent;
		}
		$basedir_anchor = realpath( $basedir_cursor );
		if ( $basedir_anchor === false ) {
			return false;
		}
		$basedir_anchor = rtrim( str_replace( '\\', '/', $basedir_anchor ), '/' );
		$resolved_basedir = $basedir_anchor;
		foreach ( array_reverse( $basedir_missing ) as $seg ) {
			$resolved_basedir .= '/' . $seg;
		}

		// Nearest existing ancestor of the destination must be on the chain that
		// will recreate resolved_basedir (ancestor of or equal to that path).
		return $existing === $resolved_basedir
			|| str_starts_with( $resolved_basedir . '/', $existing . '/' );
	}

	private function is_missing_object_error( \Throwable $e ): bool {
		$code = method_exists( $e, 'getAwsErrorCode' ) ? (string) $e->getAwsErrorCode() : '';
		// Bucket-level / location failures are not object-level confirmed missing.
		if ( in_array( $code, array( 'NoSuchBucket', 'InvalidBucketName', 'PermanentRedirect', 'AuthorizationHeaderMalformed' ), true ) ) {
			return false;
		}
		if ( in_array( $code, array( 'NoSuchKey', 'NotFound' ), true ) ) {
			return true;
		}
		$status = method_exists( $e, 'getStatusCode' ) ? (int) $e->getStatusCode() : 0;
		// Bare HTTP 404 with no AWS object/bucket code — treat as object absence (S3-compatible quirk).
		if ( $status === 404 && ( $code === '' || $code === '404' ) ) {
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

	/**
	 * Safe Mode (#61): refuse writes on a cloned site or a namespace owned by another site.
	 */
	/**
	 * Lets another gateway of the same class check this destination before writing to it.
	 */
	public function assert_writable( string $operation ): void {
		$this->guard_write( $operation );
	}

	private function guard_write( string $operation ): void {
		SafeMode::guard_write(
			$operation,
			$this->bucket(),
			$this->profile->prefix,
			function (): ?array {
				try {
					$result = $this->client()->getObject(
						array(
							'Bucket' => $this->bucket(),
							'Key'    => SafeMode::marker_key( $this->profile->prefix ),
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
						'Key'         => SafeMode::marker_key( $this->profile->prefix ),
						'Body'        => (string) wp_json_encode( $payload ),
						'ContentType' => 'application/json',
					)
				);
			}
		);
	}
}
