<?php
/**
 * Reconcile stale resumable multipart transfer sessions.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Domain\TransferSessionStatus;
use Kazcode\WpStorage\Storage\StorageGatewayInterface;

final class StaleTransferCleanupService {

	private const STALE_AFTER_SECONDS = 7 * 24 * 60 * 60;

	/**
	 * @param object   $sessions TransferSessionRepository-compatible persistence object.
	 * @param callable $destination_gateway_factory Callable receiving profile id and returning StorageGatewayInterface.
	 */
	public function __construct(
		private object $sessions,
		private $destination_gateway_factory,
		private ?AuditLog $audit = null,
	) {
	}

	/**
	 * Scan and reconcile stale incomplete multipart sessions.
	 *
	 * @return array{scanned:int,completed:int,aborted:int,local_aborted:int,deferred:int}
	 */
	public function run( int $limit = 50 ): array {
		$summary = array(
			'scanned'       => 0,
			'completed'     => 0,
			'aborted'       => 0,
			'local_aborted' => 0,
			'deferred'      => 0,
		);
		$after_id = 0;
		$deadline = time() - self::STALE_AFTER_SECONDS;

		while ( $summary['scanned'] < max( 1, $limit ) ) {
			$rows = $this->sessions->list_stale_incomplete( $deadline, $after_id );
			if ( ! is_array( $rows ) || $rows === array() ) {
				break;
			}

			foreach ( $rows as $row ) {
				if ( $summary['scanned'] >= max( 1, $limit ) ) {
					break 2;
				}
				$after_id = max( $after_id, (int) ( $row['id'] ?? 0 ) );
				++$summary['scanned'];

				$result = $this->reconcile( $row );
				++$summary[ $result ];
			}
		}

		return $summary;
	}

	/** @param array<string,mixed> $session */
	private function reconcile( array $session ): string {
		$session_id = (int) ( $session['id'] ?? 0 );
		$profile_id = (int) ( $session['destination_profile_id'] ?? 0 );
		$key        = (string) ( $session['destination_key'] ?? '' );
		$upload_id  = (string) ( $session['multipart_upload_id'] ?? '' );
		$size       = (int) ( $session['size_bytes'] ?? -1 );

		if ( $session_id <= 0 || $profile_id <= 0 || $key === '' ) {
			return 'deferred';
		}

		if ( $upload_id === '' ) {
			$this->mark_aborted( $session, 'stale_missing_upload_id', 'Stale multipart session has no upload id; remote abort skipped.' );
			return 'local_aborted';
		}

		try {
			$gateway = $this->destination_gateway( $profile_id );
			$head    = $gateway->head_key( $key );
			if ( ! empty( $head['exists'] ) && (int) ( $head['content_length'] ?? -1 ) === $size ) {
				$this->sessions->update(
					$session_id,
					array(
						'status'       => TransferSessionStatus::COMPLETED,
						'completed_at' => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->record( 'multipart_completed', $session );
				return 'completed';
			}
			if ( empty( $head['confirmed_missing'] ) ) {
				return 'deferred';
			}

			$parts = $gateway->list_parts( $key, $upload_id );
			if ( ! is_array( $parts ) ) {
				return 'deferred';
			}

			$gateway->abort_multipart_upload( $key, $upload_id );
			$this->mark_aborted( $session, 'stale_multipart_aborted', 'Stale multipart upload was aborted after provider confirmed incomplete parts.' );
			$this->record( 'multipart_stale_aborted', $session );
			return 'aborted';
		} catch ( \Throwable ) {
			return 'deferred';
		}
	}

	/** @param array<string,mixed> $session */
	private function mark_aborted( array $session, string $code, string $message ): void {
		$this->sessions->update(
			(int) $session['id'],
			array(
				'status'             => TransferSessionStatus::ABORTED,
				'last_error_code'    => $code,
				'last_error_message' => $message,
			)
		);
	}

	private function destination_gateway( int $profile_id ): StorageGatewayInterface {
		$gateway = ( $this->destination_gateway_factory )( $profile_id );
		if ( ! $gateway instanceof StorageGatewayInterface ) {
			throw new \RuntimeException( 'Destination gateway factory must return a storage gateway.' );
		}
		return $gateway;
	}

	/** @param array<string,mixed> $session */
	private function record( string $action, array $session ): void {
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
