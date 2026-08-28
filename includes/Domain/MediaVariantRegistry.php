<?php
/**
 * Collects MediaVariantProviderInterface contributions for ManifestBuilder.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * In-process registry; modules register on boot.
 */
final class MediaVariantRegistry {

	/** @var list<MediaVariantProviderInterface> */
	private array $providers = array();

	public function register( MediaVariantProviderInterface $provider ): void {
		$this->providers[] = $provider;
	}

	/**
	 * @return list<array{relative:string,variant_type?:string,mime?:string}>
	 */
	public function contributions( int $attachment_id, ?array $metadata_override = null ): array {
		$items = array();
		foreach ( $this->providers as $provider ) {
			$chunk = $provider->contribute( $attachment_id, $metadata_override );
			if ( ! is_array( $chunk ) ) {
				continue;
			}
			foreach ( $chunk as $row ) {
				if ( is_array( $row ) && ! empty( $row['relative'] ) && is_string( $row['relative'] ) ) {
					$items[] = $row;
				}
			}
		}

		/**
		 * Allow modules to register variant providers at runtime.
		 *
		 * @param MediaVariantRegistry        $registry Registry instance.
		 * @param int                         $attachment_id Attachment ID.
		 * @param array<string, mixed>|null $metadata_override Metadata.
		 */
		do_action( 'kazus_register_variant_providers', $this, $attachment_id, $metadata_override );

		return $items;
	}
}
