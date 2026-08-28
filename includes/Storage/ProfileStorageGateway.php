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
	 * @return array{exists:bool,confirmed_missing?:bool,error?:string,content_length?:int,content_type?:string}
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
		if ( in_array( $code, array( 'NoSuchKey', 'NotFound', '404' ), true ) ) {
			return true;
		}
		$msg = strtolower( $e->getMessage() );
		return str_contains( $msg, 'nosuchkey' ) || str_contains( $msg, 'not found' );
	}
}
