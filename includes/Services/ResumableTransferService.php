<?php
/**
 * Crash-resumable transfer orchestration.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\SecretRedactor;
use Kazcode\WpStorage\Domain\TransferSessionStatus;
use Kazcode\WpStorage\Infrastructure\TemporaryFile;
use Kazcode\WpStorage\Storage\MultipartPlan;
use Kazcode\WpStorage\Storage\PathGuard;
use Kazcode\WpStorage\Storage\RetryClassifier;
use Kazcode\WpStorage\Storage\StorageGatewayInterface;

final class ResumableTransferService {

	private const DEFAULT_THRESHOLD_BYTES = 100 * 1024 * 1024;
	private const DEFAULT_PART_SIZE_BYTES = 64 * 1024 * 1024;
	private const MAX_PART_ATTEMPTS       = 5;

	/** @var callable */
	private $sleeper;

	/**
	 * @param object   $sessions                    TransferSessionRepository-compatible persistence object.
	 * @param callable $destination_gateway_factory Callable receiving destination profile id and returning StorageGatewayInterface.
	 * @param callable|null $sleeper                Callable receiving milliseconds.
	 * @param callable|null $source_gateway_factory Callable receiving source profile id and returning StorageGatewayInterface.
	 */
	public function __construct(
		private object $sessions,
		private $destination_gateway_factory,
		$sleeper = null,
		private $source_gateway_factory = null,
		private ?AuditLog $audit = null,
	) {
		$this->sleeper = $sleeper ?? static function ( int $milliseconds ): void {
			usleep( $milliseconds * 1000 );
		};
	}

	/**
	 * Upload a local uploads-relative file to a destination profile/key.
	 *
	 * @return array{success: bool, verified_complete: bool, session_uuid: string, resumed: bool, message: string}
	 */
	public function upload_local(
		int $attachment_id,
		int $destination_profile_id,
		string $relative_path,
		string $destination_key,
		string $content_type,
		string $cache_control
	): array {
		$source_path = $this->local_source_path( $relative_path );
		$size        = (int) filesize( $source_path );
		$mtime       = (int) filemtime( $source_path );
		$fingerprint = $this->local_source_fingerprint( $relative_path, $size, $mtime );
		$plan        = MultipartPlan::for_size(
			$size,
			$this->filtered_bytes( 'kazus_multipart_threshold_bytes', self::DEFAULT_THRESHOLD_BYTES ),
			$this->filtered_bytes( 'kazus_multipart_part_size_bytes', self::DEFAULT_PART_SIZE_BYTES )
		);
		$session_uuid = $this->uuid();
		$gateway      = $this->destination_gateway( $destination_profile_id );

		if ( ! $plan->uses_multipart() ) {
			$gateway->put_object_from_file( $destination_key, $source_path, $content_type, $cache_control );
			return $this->verify_destination( $gateway, $destination_key, $size, $session_uuid, false );
		}

		$resumed = false;
		$session = $this->sessions->find_resumable( 'local_upload', $destination_profile_id, $destination_key, $fingerprint );
		if ( is_array( $session ) ) {
			$resumed       = true;
			$session_id    = (int) $session['id'];
			$session_uuid  = (string) $session['uuid'];
			$upload_id     = (string) $session['multipart_upload_id'];
			$part_size     = (int) $session['part_size_bytes'];
			$part_count    = intdiv( $size + $part_size - 1, $part_size );
			if ( (string) ( $session['status'] ?? '' ) === TransferSessionStatus::COMPLETING ) {
				$result = $this->verify_destination( $gateway, $destination_key, $size, $session_uuid, true );
				if ( $result['verified_complete'] ) {
					$this->mark_completed( $session_id );
					return $result;
				}
			}
			$completed_parts = $this->normalize_parts( $gateway->list_parts( $destination_key, $upload_id ) );
			$this->sessions->update( $session_id, array( 'completed_parts' => $completed_parts ) );
			$this->record_multipart_event( 'multipart_resumed', $session );
		} else {
			$this->fail_changed_source_session( $destination_profile_id, $destination_key, $relative_path, $fingerprint );
			$upload_id  = $gateway->create_multipart_upload( $destination_key, $content_type, $cache_control );
			$part_size  = $plan->part_size_bytes();
			$part_count = $plan->part_count();
			$session_id = $this->sessions->create(
				array(
					'uuid'                   => $session_uuid,
					'kind'                   => 'local_upload',
					'attachment_id'          => $attachment_id,
					'destination_profile_id' => $destination_profile_id,
					'source_ref'             => $relative_path,
					'destination_key'        => $destination_key,
					'size_bytes'             => $size,
					'source_fingerprint'     => $fingerprint,
					'multipart_upload_id'    => $upload_id,
					'part_size_bytes'        => $part_size,
					'completed_parts'        => array(),
					'status'                 => TransferSessionStatus::UPLOADING,
					'started_at'             => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			$completed_parts = array();
			$this->record_multipart_event(
				'multipart_started',
				array(
					'uuid'                   => $session_uuid,
					'kind'                   => 'local_upload',
					'attachment_id'          => $attachment_id,
					'destination_profile_id' => $destination_profile_id,
					'destination_key'        => $destination_key,
					'size_bytes'             => $size,
				)
			);
		}

		$remaining = array();
		for ( $part_number = 1; $part_number <= $part_count; $part_number++ ) {
			if ( ! isset( $completed_parts[ $part_number ] ) ) {
				$remaining[] = $part_number;
			}
		}
		$execution_parts = $this->parts_for_execution( $remaining );

		foreach ( array_chunk( $execution_parts, $this->multipart_concurrency() ) as $window ) {
			foreach ( $window as $part_number ) {
				$current_part_size = $this->part_content_length( $size, $part_size, $part_number );
				try {
					$etag = $this->upload_part_with_retry( $gateway, $destination_key, $upload_id, $source_path, $part_size, $part_number, $current_part_size );
				} catch ( \Throwable $e ) {
					$this->sessions->update(
						$session_id,
						array(
							'status'             => TransferSessionStatus::UPLOADING,
							'completed_parts'    => $completed_parts,
							'attempt_count'      => self::MAX_PART_ATTEMPTS,
							'last_error_code'    => 'part_retry_exhausted',
							'last_error_message' => $this->bounded_message( $e ),
						)
					);
					$this->record_multipart_event(
						'multipart_failed',
						array(
							'uuid'                   => $session_uuid,
							'kind'                   => 'local_upload',
							'attachment_id'          => $attachment_id,
							'destination_profile_id' => $destination_profile_id,
							'destination_key'        => $destination_key,
							'size_bytes'             => $size,
						)
					);
					return array(
						'success'           => false,
						'verified_complete' => false,
						'session_uuid'      => $session_uuid,
						'resumed'           => $resumed,
						'message'           => 'Multipart part upload failed; session remains resumable.',
					);
				}
				$completed_parts[ $part_number ] = array(
					'etag' => $etag,
					'size' => $current_part_size,
				);
				ksort( $completed_parts );
				$this->sessions->update( $session_id, array( 'completed_parts' => $completed_parts ) );
			}
		}
		if ( count( $execution_parts ) < count( $remaining ) ) {
			return $this->execution_budget_result( $session_uuid, $resumed );
		}

		$this->sessions->update( $session_id, array( 'status' => TransferSessionStatus::COMPLETING ) );
		$gateway->complete_multipart_upload( $destination_key, $upload_id, $completed_parts );
		$result = $this->verify_destination( $gateway, $destination_key, $size, $session_uuid, $resumed );
		if ( $result['verified_complete'] ) {
			$this->mark_completed( $session_id );
			$this->record_multipart_event(
				'multipart_completed',
				array(
					'uuid'                   => $session_uuid,
					'kind'                   => 'local_upload',
					'attachment_id'          => $attachment_id,
					'destination_profile_id' => $destination_profile_id,
					'destination_key'        => $destination_key,
					'size_bytes'             => $size,
				)
			);
		} else {
			$this->sessions->update(
				$session_id,
				array(
					'status'             => TransferSessionStatus::COMPLETING,
					'last_error_code'    => 'destination_head_unverified',
					'last_error_message' => $result['message'],
				)
			);
		}
		return $result;
	}

	/**
	 * Copy a remote source profile/key to a destination profile/key.
	 *
	 * @return array{success: bool, verified_complete: bool, session_uuid: string, resumed: bool, message: string}
	 */
	public function copy_remote(
		int $attachment_id,
		int $source_profile_id,
		string $source_key,
		int $destination_profile_id,
		string $destination_key,
		string $content_type,
		string $cache_control,
		int $expected_size,
		?callable $multipart_created = null,
		bool $force_multipart = false
	): array {
		if ( $source_profile_id <= 0 || $destination_profile_id <= 0 || $source_key === '' || $destination_key === '' ) {
			throw new \InvalidArgumentException( 'Remote copy source and destination are required.' );
		}

		$source_gateway      = $this->source_gateway( $source_profile_id );
		$destination_gateway = $this->destination_gateway( $destination_profile_id );
		$source_head         = $this->remote_source_head( $source_gateway, $source_key, $expected_size );
		$size                = (int) $source_head['content_length'];
		$fingerprint         = $this->remote_source_fingerprint( $source_profile_id, $source_key, $size, $source_head );
		$threshold           = $this->filtered_bytes( 'kazus_multipart_threshold_bytes', self::DEFAULT_THRESHOLD_BYTES );
		$requested_part_size = $this->filtered_bytes( 'kazus_multipart_part_size_bytes', self::DEFAULT_PART_SIZE_BYTES );
		$plan                = $force_multipart
			? MultipartPlan::for_forced_multipart_size( $size, $threshold, $requested_part_size )
			: MultipartPlan::for_size( $size, $threshold, $requested_part_size );
		$session_uuid        = $this->uuid();

		if ( ! $plan->uses_multipart() && ! $force_multipart ) {
			$this->copy_remote_single( $source_gateway, $destination_gateway, $source_key, $destination_key, $content_type, $cache_control, $size );
			return $this->verify_destination( $destination_gateway, $destination_key, $size, $session_uuid, false );
		}

		$resumed = false;
		$session = $this->sessions->find_resumable( 'remote_copy', $destination_profile_id, $destination_key, $fingerprint );
		if ( is_array( $session ) ) {
			$resumed       = true;
			$session_id    = (int) $session['id'];
			$session_uuid  = (string) $session['uuid'];
			$upload_id     = (string) $session['multipart_upload_id'];
			$part_size     = (int) $session['part_size_bytes'];
			if ( $part_size <= 0 ) {
				$part_size = $plan->part_size_bytes();
				$this->sessions->update( $session_id, array( 'part_size_bytes' => $part_size ) );
			}
			$part_count    = intdiv( $size + $part_size - 1, $part_size );
			if ( (string) ( $session['status'] ?? '' ) === TransferSessionStatus::COMPLETING ) {
				$result = $this->verify_destination( $destination_gateway, $destination_key, $size, $session_uuid, true );
				if ( $result['verified_complete'] ) {
					$this->mark_completed( $session_id );
					return $result;
				}
			}
			$completed_parts = $this->normalize_parts( $destination_gateway->list_parts( $destination_key, $upload_id ) );
			$this->sessions->update( $session_id, array( 'completed_parts' => $completed_parts ) );
			$this->record_multipart_event( 'multipart_resumed', $session );
		} else {
			$this->fail_changed_remote_source_session( $destination_profile_id, $destination_key, $source_key, $fingerprint );
			$upload_id  = $destination_gateway->create_multipart_upload( $destination_key, $content_type, $cache_control );
			$part_size  = $plan->part_size_bytes();
			$part_count = $plan->part_count();
			$session_id = $this->sessions->create(
				array(
					'uuid'                   => $session_uuid,
					'kind'                   => 'remote_copy',
					'attachment_id'          => $attachment_id,
					'source_profile_id'      => $source_profile_id,
					'destination_profile_id' => $destination_profile_id,
					'source_ref'             => $source_key,
					'destination_key'        => $destination_key,
					'size_bytes'             => $size,
					'source_fingerprint'     => $fingerprint,
					'multipart_upload_id'    => $upload_id,
					'part_size_bytes'        => $part_size,
					'completed_parts'        => array(),
					'status'                 => TransferSessionStatus::UPLOADING,
					'started_at'             => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			if ( $multipart_created !== null && ! $multipart_created( $upload_id ) ) {
				$abort_confirmed = true;
				try {
					$destination_gateway->abort_multipart_upload( $destination_key, $upload_id );
				} catch ( \Throwable ) {
					// The provider outcome is unknown, but this session is no longer
					// authorized to resume. Keep its exact provider ID and terminal
					// outcome for cleanup/audit rather than selecting it blindly later.
					$abort_confirmed = false;
				}
				$this->sessions->update(
					$session_id,
					array(
						'status'             => TransferSessionStatus::ABORTED,
						'last_error_code'    => $abort_confirmed ? 'multipart_authority_rejected' : 'multipart_authority_rejected_abort_unknown',
						'last_error_message' => $abort_confirmed ? 'Migration multipart authority was rejected and the provider upload was aborted.' : 'Migration multipart authority was rejected; provider abort outcome is unknown.',
					)
				);
				throw new \RuntimeException( 'Migration multipart authority could not be persisted.' );
			}
			$completed_parts = array();
			$this->record_multipart_event(
				'multipart_started',
				array(
					'uuid'                   => $session_uuid,
					'kind'                   => 'remote_copy',
					'attachment_id'          => $attachment_id,
					'destination_profile_id' => $destination_profile_id,
					'destination_key'        => $destination_key,
					'size_bytes'             => $size,
				)
			);
		}

		$remaining = array();
		for ( $part_number = 1; $part_number <= $part_count; $part_number++ ) {
			if ( ! isset( $completed_parts[ $part_number ] ) ) {
				$remaining[] = $part_number;
			}
		}
		$execution_parts = $this->parts_for_execution( $remaining );

		foreach ( array_chunk( $execution_parts, $this->multipart_concurrency() ) as $window ) {
			foreach ( $window as $part_number ) {
				$current_part_size = $this->part_content_length( $size, $part_size, $part_number );
				try {
					$etag = $this->upload_remote_part_with_retry( $source_gateway, $destination_gateway, $source_key, $destination_key, $upload_id, $part_size, $part_number, $current_part_size );
				} catch ( \Throwable $e ) {
					$this->sessions->update(
						$session_id,
						array(
							'status'             => TransferSessionStatus::UPLOADING,
							'completed_parts'    => $completed_parts,
							'attempt_count'      => self::MAX_PART_ATTEMPTS,
							'last_error_code'    => 'part_retry_exhausted',
							'last_error_message' => $this->bounded_message( $e ),
						)
					);
					$this->record_multipart_event(
						'multipart_failed',
						array(
							'uuid'                   => $session_uuid,
							'kind'                   => 'remote_copy',
							'attachment_id'          => $attachment_id,
							'destination_profile_id' => $destination_profile_id,
							'destination_key'        => $destination_key,
							'size_bytes'             => $size,
						)
					);
					return array(
						'success'           => false,
						'verified_complete' => false,
						'session_uuid'      => $session_uuid,
						'resumed'           => $resumed,
						'message'           => 'Multipart part upload failed; session remains resumable.',
					);
				}
				$completed_parts[ $part_number ] = array(
					'etag' => $etag,
					'size' => $current_part_size,
				);
				ksort( $completed_parts );
				$this->sessions->update( $session_id, array( 'completed_parts' => $completed_parts ) );
			}
		}
		if ( count( $execution_parts ) < count( $remaining ) ) {
			return $this->execution_budget_result( $session_uuid, $resumed );
		}

		$this->sessions->update( $session_id, array( 'status' => TransferSessionStatus::COMPLETING ) );
		$destination_gateway->complete_multipart_upload( $destination_key, $upload_id, $completed_parts );
		$result = $this->verify_destination( $destination_gateway, $destination_key, $size, $session_uuid, $resumed );
		if ( $result['verified_complete'] ) {
			$this->mark_completed( $session_id );
			$this->record_multipart_event(
				'multipart_completed',
				array(
					'uuid'                   => $session_uuid,
					'kind'                   => 'remote_copy',
					'attachment_id'          => $attachment_id,
					'destination_profile_id' => $destination_profile_id,
					'destination_key'        => $destination_key,
					'size_bytes'             => $size,
				)
			);
		} else {
			$this->sessions->update(
				$session_id,
				array(
					'status'             => TransferSessionStatus::COMPLETING,
					'last_error_code'    => 'destination_head_unverified',
					'last_error_message' => $result['message'],
				)
			);
		}
		return $result;
	}

	private function destination_gateway( int $destination_profile_id ): StorageGatewayInterface {
		$gateway = ( $this->destination_gateway_factory )( $destination_profile_id );
		if ( ! $gateway instanceof StorageGatewayInterface ) {
			throw new \RuntimeException( 'Destination gateway factory must return a storage gateway.' );
		}
		return $gateway;
	}

	private function source_gateway( int $source_profile_id ): StorageGatewayInterface {
		$factory = $this->source_gateway_factory ?? $this->destination_gateway_factory;
		$gateway = ( $factory )( $source_profile_id );
		if ( ! $gateway instanceof StorageGatewayInterface ) {
			throw new \RuntimeException( 'Source gateway factory must return a storage gateway.' );
		}
		return $gateway;
	}

	/** @return array{exists:bool,content_length:int,etag?:string,version_id?:string} */
	private function remote_source_head( StorageGatewayInterface $source_gateway, string $source_key, int $expected_size ): array {
		$head = $source_gateway->head_key( $source_key );
		if ( empty( $head['exists'] ) || ! isset( $head['content_length'] ) ) {
			throw new \RuntimeException( 'Remote source size is unavailable.' );
		}
		if ( $expected_size > 0 && (int) $head['content_length'] !== $expected_size ) {
			throw new \RuntimeException( 'Remote source size does not match expected size.' );
		}
		$head['content_length'] = (int) $head['content_length'];
		return $head;
	}

	/** @param array<string,mixed> $head */
	private function remote_source_fingerprint( int $source_profile_id, string $source_key, int $size, array $head ): string {
		$identity = (string) ( $head['etag'] ?? $head['ETag'] ?? $head['version_id'] ?? $head['VersionId'] ?? '' );
		$base     = $source_profile_id . '|' . $source_key . '|' . $size;
		return hash( 'sha256', $identity === '' ? $base : $base . '|' . $identity );
	}

	private function copy_remote_single(
		StorageGatewayInterface $source_gateway,
		StorageGatewayInterface $destination_gateway,
		string $source_key,
		string $destination_key,
		string $content_type,
		string $cache_control,
		int $size
	): void {
		$body = $source_gateway->get_range( $source_key, 0, max( 0, $size - 1 ) );

		$tmp = TemporaryFile::create( basename( $destination_key ) );
		if ( ! is_string( $tmp ) || $tmp === '' ) {
			throw new \RuntimeException( 'Unable to create temporary remote-copy file.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- temporary file is created by TemporaryFile; binary stream is required to write an SDK response body before put_object_from_file().
		$out = fopen( $tmp, 'wb' );
		if ( $out === false ) {
			throw new \RuntimeException( 'Unable to write temporary remote-copy file.' );
		}

		try {
			$this->write_body_to_stream( $body, $out );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the binary stream opened for the temporary file above.
			fclose( $out );
			$this->close_body( $body );
		}

		try {
			$destination_gateway->put_object_from_file( $destination_key, $tmp, $content_type, $cache_control );
		} finally {
			wp_delete_file( $tmp );
		}
	}

	private function local_source_path( string $relative_path ): string {
		if ( $relative_path === '' || str_contains( $relative_path, "\0" ) || preg_match( '/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $relative_path ) || PathGuard::has_parent_segment( $relative_path ) ) {
			throw new \InvalidArgumentException( 'Relative source path is invalid.' );
		}

		$uploads = wp_upload_dir();
		$basedir = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
		if ( $basedir === '' ) {
			throw new \RuntimeException( 'Uploads directory is unavailable.' );
		}

		$base_real = realpath( $basedir );
		$path_real = realpath( trailingslashit( $basedir ) . ltrim( $relative_path, '/\\' ) );
		if ( $base_real === false || $path_real === false || ! str_starts_with( $path_real, trailingslashit( $base_real ) ) || ! is_readable( $path_real ) ) {
			throw new \RuntimeException( 'Local source file is not readable.' );
		}

		return $path_real;
	}

	private function filtered_bytes( string $hook, int $default ): int {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- callers pass only fixed kazus_multipart_* hook names declared in this class.
		return max( 1, (int) apply_filters( $hook, $default ) );
	}

	private function multipart_concurrency(): int {
		return max( 1, min( 4, (int) apply_filters( 'kazus_multipart_concurrency', 2 ) ) );
	}

	/**
	 * Test/queue orchestration budget. Zero preserves normal request behavior.
	 *
	 * @param list<int> $remaining
	 * @return list<int>
	 */
	private function parts_for_execution( array $remaining ): array {
		$max_parts = max( 0, (int) apply_filters( 'kazus_multipart_max_parts_per_execution', 0 ) );
		return $max_parts > 0 ? array_slice( $remaining, 0, $max_parts ) : $remaining;
	}

	/**
	 * @return array{success: bool, verified_complete: bool, session_uuid: string, resumed: bool, message: string}
	 */
	private function execution_budget_result( string $session_uuid, bool $resumed ): array {
		return array(
			'success'           => false,
			'verified_complete' => false,
			'session_uuid'      => $session_uuid,
			'resumed'           => $resumed,
			'message'           => 'Multipart execution budget reached; session remains resumable.',
		);
	}

	private function local_source_fingerprint( string $relative_path, int $size, int $mtime ): string {
		return hash( 'sha256', $relative_path . '|' . $size . '|' . $mtime );
	}

	private function fail_changed_source_session( int $destination_profile_id, string $destination_key, string $relative_path, string $current_fingerprint ): void {
		if ( ! method_exists( $this->sessions, 'find_resumable_by_source' ) ) {
			return;
		}
		$previous = $this->sessions->find_resumable_by_source( 'local_upload', $destination_profile_id, $destination_key, $relative_path );
		if ( ! is_array( $previous ) || (string) ( $previous['source_fingerprint'] ?? '' ) === $current_fingerprint ) {
			return;
		}

		$this->sessions->update(
			(int) $previous['id'],
			array(
				'status'             => TransferSessionStatus::FAILED,
				'last_error_code'    => 'source_changed',
				'last_error_message' => 'Local source fingerprint changed; previous multipart upload will not be resumed.',
			)
		);
	}

	private function fail_changed_remote_source_session( int $destination_profile_id, string $destination_key, string $source_key, string $current_fingerprint ): void {
		if ( ! method_exists( $this->sessions, 'find_resumable_by_source' ) ) {
			return;
		}
		$previous = $this->sessions->find_resumable_by_source( 'remote_copy', $destination_profile_id, $destination_key, $source_key );
		if ( ! is_array( $previous ) || (string) ( $previous['source_fingerprint'] ?? '' ) === $current_fingerprint ) {
			return;
		}

		$this->sessions->update(
			(int) $previous['id'],
			array(
				'status'             => TransferSessionStatus::FAILED,
				'last_error_code'    => 'source_changed',
				'last_error_message' => 'Remote source fingerprint changed; previous multipart upload will not be resumed.',
			)
		);
	}

	private function mark_completed( int $session_id ): void {
		$this->sessions->update(
			$session_id,
			array(
				'status'       => TransferSessionStatus::COMPLETED,
				'completed_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	private function part_content_length( int $size, int $part_size, int $part_number ): int {
		$offset = ( $part_number - 1 ) * $part_size;
		return max( 0, min( $part_size, $size - $offset ) );
	}

	private function part_stream( string $source_path, int $part_size, int $part_number, int $part_content_length ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- multipart uploads require a binary seekable stream for a bounded range of the validated local uploads file.
		$source = fopen( $source_path, 'rb' );
		if ( $source === false ) {
			throw new \RuntimeException( 'Unable to open local source part.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp supplies the seekable binary SDK request body; WP_Filesystem has no stream equivalent.
		$body = fopen( 'php://temp', 'w+b' );
		if ( $body === false ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the binary source stream opened above on the error path.
			fclose( $source );
			throw new \RuntimeException( 'Unable to create upload part stream.' );
		}

		fseek( $source, ( $part_number - 1 ) * $part_size );
		stream_copy_to_stream( $source, $body, $part_content_length );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the validated local binary source stream after copying its requested range.
		fclose( $source );
		rewind( $body );
		return $body;
	}

	private function upload_part_with_retry( StorageGatewayInterface $gateway, string $destination_key, string $upload_id, string $source_path, int $part_size, int $part_number, int $part_content_length ): string {
		for ( $attempt = 1; $attempt <= self::MAX_PART_ATTEMPTS; $attempt++ ) {
			$body = $this->part_stream( $source_path, $part_size, $part_number, $part_content_length );
			try {
				return $gateway->upload_part( $destination_key, $upload_id, $part_number, $body, $part_content_length );
			} catch ( \Throwable $e ) {
				if ( ! RetryClassifier::is_retryable( $e ) || $attempt >= self::MAX_PART_ATTEMPTS ) {
					throw $e;
				}
				$this->sleep_before_retry( $attempt );
			} finally {
				if ( is_resource( $body ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- releases the php://temp SDK request stream created for this upload attempt.
					fclose( $body );
				}
			}
		}

		throw new \RuntimeException( 'Multipart part upload failed.' );
	}

	private function upload_remote_part_with_retry(
		StorageGatewayInterface $source_gateway,
		StorageGatewayInterface $destination_gateway,
		string $source_key,
		string $destination_key,
		string $upload_id,
		int $part_size,
		int $part_number,
		int $part_content_length
	): string {
		$start = ( $part_number - 1 ) * $part_size;
		$end   = $start + $part_content_length - 1;
		for ( $attempt = 1; $attempt <= self::MAX_PART_ATTEMPTS; $attempt++ ) {
			$body = $source_gateway->get_range( $source_key, $start, $end );
			try {
				return $destination_gateway->upload_part( $destination_key, $upload_id, $part_number, $body, $part_content_length );
			} catch ( \Throwable $e ) {
				if ( ! RetryClassifier::is_retryable( $e ) || $attempt >= self::MAX_PART_ATTEMPTS ) {
					throw $e;
				}
				$this->sleep_before_retry( $attempt );
			} finally {
				$this->close_body( $body );
			}
		}

		throw new \RuntimeException( 'Multipart part upload failed.' );
	}

	private function write_body_to_stream( $body, $out ): void {
		if ( is_resource( $body ) ) {
			stream_copy_to_stream( $body, $out );
			return;
		}
		if ( is_object( $body ) && method_exists( $body, 'getContents' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writes an SDK response body to the wp_tempnam() binary stream; WP_Filesystem cannot consume SDK stream bodies.
			fwrite( $out, (string) $body->getContents() );
			return;
		}
		if ( is_object( $body ) && method_exists( $body, '__toString' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writes an SDK response body to the wp_tempnam() binary stream; WP_Filesystem cannot consume SDK stream bodies.
			fwrite( $out, (string) $body );
			return;
		}
		throw new \RuntimeException( 'Remote source range is unreadable.' );
	}

	private function close_body( $body ): void {
		if ( is_resource( $body ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- releases a binary range-response stream returned by the storage SDK.
			fclose( $body );
			return;
		}
		if ( is_object( $body ) && method_exists( $body, 'close' ) ) {
			$body->close();
		}
	}

	private function sleep_before_retry( int $failed_attempt ): void {
		$delay  = min( 8000, 500 * ( 2 ** max( 0, $failed_attempt - 1 ) ) );
		$jitter = random_int( 0, min( 250, $delay ) );
		( $this->sleeper )( $delay + $jitter );
	}

	private function bounded_message( \Throwable $e ): string {
		return SecretRedactor::redact( $e );
	}

	/**
	 * @param array<int|string,array{etag:string,size:int}> $parts
	 * @return array<int,array{etag:string,size:int}>
	 */
	private function normalize_parts( array $parts ): array {
		$normalized = array();
		foreach ( $parts as $part_number => $part ) {
			$number = (int) $part_number;
			if ( $number <= 0 ) {
				continue;
			}
			$normalized[ $number ] = array(
				'etag' => (string) $part['etag'],
				'size' => (int) $part['size'],
			);
		}
		ksort( $normalized );
		return $normalized;
	}

	/**
	 * @return array{success: bool, verified_complete: bool, session_uuid: string, resumed: bool, message: string}
	 */
	private function verify_destination( StorageGatewayInterface $gateway, string $destination_key, int $expected_size, string $session_uuid, bool $resumed ): array {
		$head = $gateway->head_key( $destination_key );
		if ( ! empty( $head['exists'] ) && (int) ( $head['content_length'] ?? -1 ) === $expected_size ) {
			return array(
				'success'           => true,
				'verified_complete' => true,
				'session_uuid'      => $session_uuid,
				'resumed'           => $resumed,
				'message'           => 'Destination verified.',
			);
		}

		return array(
			'success'           => false,
			'verified_complete' => false,
			'session_uuid'      => $session_uuid,
			'resumed'           => $resumed,
			'message'           => 'Destination verification failed.',
		);
	}

	private function uuid(): string {
		$bytes       = random_bytes( 16 );
		$bytes[6]    = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8]    = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		$hex         = bin2hex( $bytes );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 );
	}

	/** @param array<string,mixed> $session */
	private function record_multipart_event( string $action, array $session ): void {
		if ( $this->audit === null ) {
			return;
		}
		$this->audit->record(
			$action,
			array(
				'uuid'          => (string) ( $session['uuid'] ?? '' ),
				'kind'          => (string) ( $session['kind'] ?? '' ),
				'attachment_id' => (int) ( $session['attachment_id'] ?? 0 ),
				'profile_id'    => (int) ( $session['destination_profile_id'] ?? 0 ),
				'object_key'    => (string) ( $session['destination_key'] ?? '' ),
				'size_bytes'    => (int) ( $session['size_bytes'] ?? 0 ),
			)
		);
	}
}
