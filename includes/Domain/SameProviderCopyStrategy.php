<?php
/**
 * Detect when server-side CopyObject is safe between profiles.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * P7-T02 spike: same bucket + compatible provider config → CopyObject, else stream.
 */
final class SameProviderCopyStrategy {

	public static function can_copy_within_bucket( StorageProfile $source, StorageProfile $destination ): bool {
		if ( $source->bucket === '' || $source->bucket !== $destination->bucket ) {
			return false;
		}
		return self::same_provider_identity( $source, $destination );
	}

	public static function same_provider_identity( StorageProfile $a, StorageProfile $b ): bool {
		return $a->provider_type === $b->provider_type
			&& $a->region === $b->region
			&& $a->endpoint === $b->endpoint
			&& $a->path_style === $b->path_style
			&& $a->credential_mode === $b->credential_mode
			&& $a->credentials_ref === $b->credentials_ref;
	}
}
