<?php
/**
 * Optional post-migration delete of source object key (P7-T05).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure\Queue\Jobs;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\Queue\JobHandlerInterface;
use Kazcode\WpStorage\Infrastructure\Queue\QueueJobType;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
use Kazcode\WpStorage\Plugin;
use Kazcode\WpStorage\Services\AuditLog;
use Kazcode\WpStorage\Storage\ProfileStorageGateway;

final class DeleteSourceObjectJobHandler implements JobHandlerInterface {

	public function __construct(
		private ?ObjectRepository $objects = null,
		private ?WpdbStorageProfileRepository $profiles = null,
		private $settings = null,
		private $gateway_factory = null,
	) {
		$this->objects  = $objects ?? new ObjectRepository();
		$this->profiles = $profiles ?? new WpdbStorageProfileRepository();
		$this->settings = $settings;
	}

	public function type(): string {
		return QueueJobType::DELETE_SOURCE_OBJECT;
	}

	/**
	 * @param array<string, mixed> $payload source_profile_id, object_key
	 */
	public function handle( array $payload ): array {
		$profile_id = (int) ( $payload['source_profile_id'] ?? 0 );
		$key        = (string) ( $payload['object_key'] ?? '' );
		if ( $profile_id <= 0 || $key === '' ) {
			return array(
				'success' => false,
				'message' => 'source_profile_id and object_key are required.',
			);
		}

		$profile  = $this->profiles->find( $profile_id );
		if ( $profile === null ) {
			return array(
				'success' => false,
				'message' => 'Source profile not found.',
			);
		}

		$rows = $this->objects->find_by_profile_and_key( $profile_id, $key );
		if ( ! $this->can_delete_source_key( $rows ) ) {
			$this->record_skip( $profile_id, $key, 'active_or_ambiguous_reference' );
			return array(
				'success' => true,
				'message' => 'Source object delete skipped: active or ambiguous inventory reference.',
			);
		}

		$gateway = $this->gateway_factory
			? ( $this->gateway_factory )( $profile )
			: new ProfileStorageGateway( $profile, $this->settings ?? Plugin::instance()->settings() );
		$gateway->delete_key( $key );

		$this->objects->mark_deleted_by_profile_key_if_stale( $profile_id, $key );

		return array(
			'success' => true,
			'message' => 'Source object deleted.',
		);
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function can_delete_source_key( array $rows ): bool {
		if ( $rows === array() ) {
			return false;
		}
		foreach ( $rows as $row ) {
			$status = (string) ( $row['remote_status'] ?? '' );
			if ( ! in_array( $status, array( ObjectRemoteStatus::STALE, ObjectRemoteStatus::DELETED ), true ) ) {
				return false;
			}
		}
		return true;
	}

	private function record_skip( int $profile_id, string $key, string $reason ): void {
		( new AuditLog() )->record(
			'remote_delete_skipped_source_cleanup',
			array(
				'storage_profile_id' => $profile_id,
				'object_key_hash'    => hash( 'sha256', $key ),
				'reason'             => $reason,
			)
		);
	}
}
