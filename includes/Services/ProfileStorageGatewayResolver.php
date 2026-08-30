<?php
/**
 * Request-local resolver for profile-scoped storage gateways.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Storage\ProfileStorageGateway;

final class ProfileStorageGatewayResolver {

	/** @var array<int, ProfileStorageGateway> */
	private array $gateways = array();

	/**
	 * @param callable(StorageProfile):ProfileStorageGateway|null $factory
	 */
	public function __construct(
		private Settings $settings,
		private $factory = null,
	) {
	}

	public function gateway_for_profile( StorageProfile $profile ): ProfileStorageGateway {
		$id = (int) $profile->id;
		if ( $id <= 0 ) {
			return $this->create( $profile );
		}
		if ( ! isset( $this->gateways[ $id ] ) ) {
			$this->gateways[ $id ] = $this->create( $profile );
		}
		return $this->gateways[ $id ];
	}

	private function create( StorageProfile $profile ): ProfileStorageGateway {
		if ( is_callable( $this->factory ) ) {
			$gateway = ( $this->factory )( $profile );
			if ( $gateway instanceof ProfileStorageGateway ) {
				return $gateway;
			}
		}
		return new ProfileStorageGateway( $profile, $this->settings );
	}
}
