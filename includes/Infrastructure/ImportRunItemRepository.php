<?php
/**
 * Durable per-object evidence for competitor import runs.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Import\ImportedObjectDescriptor;

/** Stores exact descriptor identity and its latest reconciliation result. */
final class ImportRunItemRepository {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 's3ms_import_run_items';
	}

	public function record( int $run_id, ImportedObjectDescriptor $descriptor, string $classification, ?string $detail = null, string $canonical_object_key = '' ): void {
		if ( $run_id <= 0 || $classification === '' ) {
			throw new \InvalidArgumentException( 'Import run and classification are required.' );
		}
		global $wpdb;
		$data = array(
			'import_run_id'        => $run_id,
			'attachment_id'        => $descriptor->attachment_id(),
			'local_relative_path'  => $descriptor->wp_relative_path(),
			'variant_type'         => $descriptor->variant_type(),
			'source_object_key'    => $descriptor->source_object_key(),
			'source_size_bytes'    => $descriptor->source_size(),
			'source_mime_type'     => $descriptor->source_mime(),
			'canonical_object_key' => $canonical_object_key,
			'classification'       => $classification,
			'detail'               => $detail,
			'updated_at'           => gmdate( 'Y-m-d H:i:s' ),
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned durable ledger; replace is idempotent on its exact unique descriptor identity.
		if ( $wpdb->replace( $this->table(), $data ) === false ) {
			throw new \RuntimeException( 'Unable to persist import reconciliation evidence.' );
		}
	}

	/** @return list<array<string,mixed>> */
	public function eligible_page( int $run_id, int $after_id, int $limit = 50 ): array {
		if ( $run_id <= 0 ) {
			return array();
		}
		$limit = max( 1, min( 200, $limit ) );
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed plugin-owned table name and prepared runtime values.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE import_run_id = %d AND id > %d AND classification IN (%s, %s) ORDER BY id ASC LIMIT %d", $run_id, max( 0, $after_id ), 'confirmed_missing', 'size_conflict', $limit ), ARRAY_A );
		return is_array( $rows ) ? array_values( $rows ) : array();
	}
}
