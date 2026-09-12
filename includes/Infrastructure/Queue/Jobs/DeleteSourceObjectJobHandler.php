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
use Kazcode\WpStorage\Storage\ProfileStorageGateway;

final class DeleteSourceObjectJobHandler implements JobHandlerInterface {

	/** @var callable|null */
	private $gateway_factory;

	public function __construct(
		private ?object $objects = null,
		private ?object $profiles = null,
		?callable $gateway_factory = null
	) {
		$this->objects         = $objects ?? new ObjectRepository();
		$this->profiles        = $profiles ?? new WpdbStorageProfileRepository();
		$this->gateway_factory = $gateway_factory;
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

		$profile = $this->profiles->find( $profile_id );
		if ( $profile === null ) {
			return array(
				'success' => false,
				'message' => 'Source profile not found.',
			);
		}

		if ( $this->objects->has_active_reference_for_profile_key( $profile_id, $key ) ) {
			return array(
				'success' => false,
				'message' => 'Source object still has an active inventory reference.',
			);
		}

		$gateway = is_callable( $this->gateway_factory )
			? ( $this->gateway_factory )( $profile )
			: new ProfileStorageGateway( $profile, Plugin::instance()->settings() );
		$gateway->delete_key( $key );

		$this->objects->mark_deleted_by_profile_key( $profile_id, $key );

		return array(
			'success' => true,
			'message' => 'Source object deleted.',
		);
	}
}
