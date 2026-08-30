<?php
/**
 * Result of resolving an attachment file to a profile-bound object.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Domain\StorageProfile;

/**
 * Explicit result object; callers must not collapse all failures to null.
 */
final class ProfileObjectLocation {

	public const FOUND                     = 'found';
	public const NOT_IN_INVENTORY          = 'not_in_inventory';
	public const AMBIGUOUS_OBJECT_LOCATION = 'ambiguous_object_location';
	public const PROFILE_MISSING           = 'profile_missing';
	public const OBJECT_KEY_MISSING        = 'object_key_missing';
	public const OBJECT_NOT_PRESENT        = 'object_not_present';

	/**
	 * @param array<string, mixed>|null $object_row
	 */
	private function __construct(
		public readonly string $status,
		public readonly ?array $object_row,
		public readonly ?StorageProfile $storage_profile,
		public readonly ?int $storage_profile_id,
		public readonly string $object_key,
		public readonly string $relative_path,
	) {
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function found( array $row, StorageProfile $profile, string $object_key, string $relative ): self {
		return new self(
			self::FOUND,
			$row,
			$profile,
			$profile->id,
			$object_key,
			$relative
		);
	}

	public static function not_in_inventory( string $relative ): self {
		return new self( self::NOT_IN_INVENTORY, null, null, null, '', $relative );
	}

	public static function ambiguous( string $relative ): self {
		return new self( self::AMBIGUOUS_OBJECT_LOCATION, null, null, null, '', $relative );
	}

	public static function profile_missing( int $profile_id, string $relative ): self {
		return new self( self::PROFILE_MISSING, null, null, $profile_id, '', $relative );
	}

	public static function object_key_missing( int $profile_id, string $relative ): self {
		return new self( self::OBJECT_KEY_MISSING, null, null, $profile_id, '', $relative );
	}

	public static function object_not_present( string $relative ): self {
		return new self( self::OBJECT_NOT_PRESENT, null, null, null, '', $relative );
	}

	public function is_found(): bool {
		return $this->status === self::FOUND;
	}
}
