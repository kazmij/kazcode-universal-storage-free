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

		$profiles = new WpdbStorageProfileRepository();
		$profile  = $profiles->find( $profile_id );
		if ( $profile === null ) {
			return array(
				'success' => false,
				'message' => 'Source profile not found.',
			);
		}

		$gateway = new ProfileStorageGateway( $profile, Plugin::instance()->settings() );
		$gateway->delete_key( $key );

		global $wpdb;
		// $table is a fixed literal ('s3ms_objects') prefixed with $wpdb->prefix —
		// never user-controlled — and every value below is bound via
		// $wpdb->prepare()'s %s/%d placeholders.
		$table = $wpdb->prefix . 's3ms_objects';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- plugin-owned table (fixed name, not user input); values bound via prepare().
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET remote_status = %s, updated_at = %s WHERE storage_profile_id = %d AND object_key = %s", ObjectRemoteStatus::DELETED, gmdate( 'Y-m-d H:i:s' ), $profile_id, $key ) );

		return array(
			'success' => true,
			'message' => 'Source object deleted.',
		);
	}
}
