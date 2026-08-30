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
use Kazcode\WpStorage\Domain\RemoteObservation;
use Kazcode\WpStorage\Domain\StorageProfile;

/**
 * Low-level Put/Head/Get/Copy/Delete for one storage profile.
 */
final class ProfileStorageGateway {

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
	 * `confirmed_missing` is true only for a definitive 404/NoSuchKey response — see
	 * S3Storage::head_key() for why callers that persist inventory state must check it.
	 *
	 * @return array{exists:bool,confirmed_missing?:bool,error?:string,error_class?:string,remote_status?:string,verification_level?:string,content_length?:int,content_type?:string}
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
				'exists'             => true,
				'remote_status'      => RemoteObservation::REMOTE_PRESENT,
				'verification_level' => RemoteObservation::EXISTS_VERIFIED,
				'content_length'     => (int) $result->get( 'ContentLength' ),
				'content_type'       => (string) $result->get( 'ContentType' ),
			);
		} catch ( \Throwable $e ) {
			$confirmed_missing = $this->is_missing_object_error( $e );
			$error_class       = $confirmed_missing ? '' : RemoteObservation::classify_exception( $e );
			if ( ! $confirmed_missing && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'KAZCODE Universal Storage head_key transient error for key ' . $key . ' (' . $error_class . ')' );
			}
			return array(
				'exists'             => false,
				'confirmed_missing'  => $confirmed_missing,
				'remote_status'      => $confirmed_missing ? RemoteObservation::REMOTE_CONFIRMED_MISSING : RemoteObservation::REMOTE_UNKNOWN,
				'verification_level' => RemoteObservation::NOT_VERIFIED,
				'error_class'        => $error_class,
				'error'              => $confirmed_missing ? '' : $e->getMessage(),
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

	public function upload_file_to_key( string $local_path, string $key, string $relative ): void {
		if ( ! is_readable( $local_path ) ) {
			throw new \RuntimeException( 'Local file is not readable.' );
		}

		$content_type = 'application/octet-stream';
		if ( function_exists( 'wp_check_filetype' ) ) {
			$check = wp_check_filetype( $relative );
			if ( ! empty( $check['type'] ) ) {
				$content_type = (string) $check['type'];
			}
		}

		$this->client()->putObject(
			array(
				'Bucket'       => $this->bucket(),
				'Key'          => $key,
				'SourceFile'   => $local_path,
				'ContentType'  => $content_type,
				'CacheControl' => (string) $this->settings->get( 'cache_control', 'public, max-age=31536000' ),
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

	public function download_key_to_local( string $key, string $local_path ): void {
		$dir = dirname( $local_path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new \RuntimeException( 'Unable to create local directory for download.' );
		}

		$this->client()->getObject(
			array(
				'Bucket' => $this->bucket(),
				'Key'    => $key,
				'SaveAs' => $local_path,
			)
		);
	}

	public function presigned_url_for_key( string $key, int $ttl = 3600 ): string {
		$ttl = max( 60, min( 86400, $ttl ) );
		$cmd = $this->client()->getCommand(
			'GetObject',
			array(
				'Bucket' => $this->bucket(),
				'Key'    => $key,
			)
		);
		$request = $this->client()->createPresignedRequest( $cmd, '+' . $ttl . ' seconds' );
		return (string) $request->getUri();
	}

	private function is_missing_object_error( \Throwable $e ): bool {
		$code = method_exists( $e, 'getAwsErrorCode' ) ? (string) $e->getAwsErrorCode() : '';
		if ( in_array( $code, array( 'NoSuchKey', 'NotFound', '404' ), true ) ) {
			return true;
		}
		$msg = strtolower( $e->getMessage() );
		return str_contains( $msg, 'nosuchkey' ) || str_contains( $msg, 'not found' );
	}
}
