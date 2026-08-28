<?php
/**
 * Storage profile persistence.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Repository for storage profiles.
 */
interface StorageProfileRepositoryInterface {

	/**
	 * @return list<StorageProfile>
	 */
	public function all(): array;

	public function find( int $id ): ?StorageProfile;

	public function find_by_uuid( string $uuid ): ?StorageProfile;

	public function find_default_upload_target(): ?StorageProfile;

	public function count(): int;

	/**
	 * Insert profile; returns new id.
	 */
	public function insert( StorageProfile $profile ): int;

	/**
	 * Update existing profile by id.
	 */
	public function update( StorageProfile $profile ): void;

	/**
	 * Mark one profile as default upload target (clears others).
	 */
	public function set_default_upload_target( int $profile_id ): void;

	/**
	 * Delete profile row by id.
	 */
	public function delete( int $profile_id ): void;
}
