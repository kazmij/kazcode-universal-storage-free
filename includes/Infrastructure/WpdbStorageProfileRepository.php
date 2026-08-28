<?php
/**
 * wpdb-backed storage profile repository.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Domain\StorageProfileRepositoryInterface;

/**
 * Per-site table {$wpdb->prefix}s3ms_storage_profiles.
 */
final class WpdbStorageProfileRepository implements StorageProfileRepositoryInterface {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 's3ms_storage_profiles';
	}

	/**
	 * {@inheritdoc}
	 */
	public function all(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$this->table()} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = StorageProfile::from_row( $row );
		}
		return $out;
	}

	public function find( int $id ): ?StorageProfile {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		return is_array( $row ) ? StorageProfile::from_row( $row ) : null;
	}

	public function find_by_uuid( string $uuid ): ?StorageProfile {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE uuid = %s", $uuid ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		return is_array( $row ) ? StorageProfile::from_row( $row ) : null;
	}

	public function find_default_upload_target(): ?StorageProfile {
		global $wpdb;
		$row = $wpdb->get_row(
			"SELECT * FROM {$this->table()} WHERE is_default_upload_target = 1 ORDER BY id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		return is_array( $row ) ? StorageProfile::from_row( $row ) : null;
	}

	public function count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()}" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Pure data-access primitive — enforcing the one-profile Free product
	 * boundary is the caller's job (StorageProfileAdminService delegates
	 * creation of a 2nd+ profile to Pro; Pro's own service is what calls
	 * this for additional profiles), not this repository's.
	 */
	public function insert( StorageProfile $profile ): int {
		global $wpdb;
		$wpdb->insert( $this->table(), $profile->to_row() );
		return (int) $wpdb->insert_id;
	}

	public function update( StorageProfile $profile ): void {
		global $wpdb;
		if ( $profile->id === null ) {
			throw new \InvalidArgumentException( 'Cannot update profile without id.' );
		}
		$wpdb->update( $this->table(), $profile->to_row(), array( 'id' => $profile->id ) );
	}

	public function set_default_upload_target( int $profile_id ): void {
		global $wpdb;
		$wpdb->query( "UPDATE {$this->table()} SET is_default_upload_target = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->update(
			$this->table(),
			array(
				'is_default_upload_target' => 1,
				'updated_at'               => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $profile_id )
		);
	}

	public function delete( int $profile_id ): void {
		global $wpdb;
		$wpdb->delete( $this->table(), array( 'id' => $profile_id ), array( '%d' ) );
	}
}
