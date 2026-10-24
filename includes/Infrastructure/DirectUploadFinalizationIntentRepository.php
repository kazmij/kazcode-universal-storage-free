<?php
/** Durable, Core-owned deletion proof for a Direct Upload attachment handoff. */
declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class DirectUploadFinalizationIntentRepository {
	/** @var array<string,array{binding_json:string,location_json:string}> test-only fallback when no wpdb exists. */
	private static array $memory = array();
	private function table(): string { global $wpdb; return $wpdb->prefix . 's3ms_du_finalization_intents'; }

	/** @return true|\WP_Error */
	public function record( string $marker, array $binding, array $location ) {
		if ( $marker === '' || ! $this->valid( $binding, $location ) ) return new \WP_Error( 'direct_upload_finalization_intent_invalid', 'Direct Upload finalization intent is incomplete.' );
		$binding_json = wp_json_encode( $binding );
		$location_json = wp_json_encode( $location );
		if ( ! is_string( $binding_json ) || ! is_string( $location_json ) ) return new \WP_Error( 'direct_upload_finalization_intent_invalid', 'Direct Upload finalization intent cannot be encoded.' );
		if ( ! $this->database_available() ) {
			$current = self::$memory[ $marker ] ?? null;
			if ( $current !== null && ( ! hash_equals( $binding_json, $current['binding_json'] ) || ! hash_equals( $location_json, $current['location_json'] ) ) ) return new \WP_Error( 'direct_upload_finalization_intent_conflict', 'Direct Upload finalization intent is already bound differently.' );
			self::$memory[ $marker ] = array( 'binding_json' => $binding_json, 'location_json' => $location_json );
			return true;
		}
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$result = $wpdb->query( $wpdb->prepare( "INSERT INTO {$this->table()} (recovery_marker, binding_json, location_json, created_at, updated_at) VALUES (%s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE updated_at = IF(BINARY binding_json = BINARY VALUES(binding_json) AND BINARY location_json = BINARY VALUES(location_json), VALUES(updated_at), updated_at)", $marker, $binding_json, $location_json, $now, $now ) );
		if ( $result === false ) return new \WP_Error( 'direct_upload_finalization_intent_write_failed', 'Direct Upload finalization intent could not be persisted.' );
		$row = $this->find( $marker );
		return is_array( $row ) && hash_equals( $binding_json, (string) $row['binding_json'] ) && hash_equals( $location_json, (string) $row['location_json'] )
			? true
			: new \WP_Error( 'direct_upload_finalization_intent_conflict', 'Direct Upload finalization intent is already bound differently.' );
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public function find( string $marker ) {
		if ( $marker === '' ) return null;
		if ( ! $this->database_available() ) return self::$memory[ $marker ] ?? null;
		global $wpdb;
		$previous_error = (string) $wpdb->last_error;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT binding_json, location_json FROM {$this->table()} WHERE recovery_marker = %s", $marker ), ARRAY_A );
		$failed = $wpdb->last_error !== '';
		$wpdb->last_error = $previous_error;
		if ( $failed ) return new \WP_Error( 'direct_upload_finalization_intent_read_failed', 'Direct Upload finalization intent lookup failed.' );
		return is_array( $row ) ? $row : null;
	}

	/** @return array{binding:array<string,mixed>,location:array<string,mixed>}|null|\WP_Error */
	public function evidence_for_attachment( int $attachment_id ) {
		$post = $attachment_id > 0 && function_exists( 'get_post' ) ? get_post( $attachment_id ) : null;
		$marker = is_object( $post ) && (string) ( $post->post_type ?? '' ) === 'attachment' ? (string) ( $post->post_name ?? '' ) : '';
		$row = $this->find( $marker );
		if ( is_wp_error( $row ) ) return $row;
		if ( ! is_array( $row ) ) return null;
		try { $binding = json_decode( (string) $row['binding_json'], true, 16, JSON_THROW_ON_ERROR ); $location = json_decode( (string) $row['location_json'], true, 16, JSON_THROW_ON_ERROR ); } catch ( \Throwable $error ) { return null; }
		if ( ! is_array( $binding ) || ! is_array( $location ) || ! $this->valid( $binding, $location ) ) return null;
		$binding['attachment_id'] = $attachment_id;
		$binding['profile_fingerprint'] = (string) $location['location_fingerprint'];
		$binding['cleanup_profile_id'] = (int) $location['profile_id'];
		$binding['cleanup_object_key'] = (string) $location['object_key'];
		$binding['cleanup_profile_fingerprint'] = (string) $location['location_fingerprint'];
		return array( 'binding' => $binding, 'location' => $location );
	}

	private function valid( array $binding, array $location ): bool {
		return (int) ( $binding['version'] ?? 0 ) === 1 && (int) ( $binding['blog_id'] ?? 0 ) > 0 && (string) ( $binding['owner_uuid'] ?? '' ) !== '' && (int) ( $binding['path_reservation_id'] ?? 0 ) > 0 && (int) ( $binding['key_reservation_id'] ?? 0 ) > 0 && (string) ( $binding['relative_path'] ?? '' ) !== '' && (int) ( $binding['profile_id'] ?? 0 ) > 0 && (string) ( $binding['object_key'] ?? '' ) !== '' && (string) ( $binding['direct_upload_session_uuid'] ?? '' ) !== '' && (string) ( $binding['direct_upload_attempt_uuid'] ?? '' ) !== '' && (int) ( $binding['direct_upload_attempt_generation'] ?? 0 ) > 0 && (string) ( $binding['direct_upload_profile_fingerprint'] ?? '' ) !== '' && (int) ( $location['version'] ?? 0 ) === 2 && (int) ( $location['profile_id'] ?? 0 ) > 0 && (string) ( $location['object_key'] ?? '' ) !== '' && (string) ( $location['location_fingerprint'] ?? '' ) !== '' && hash_equals( (string) $binding['relative_path'], (string) ( $location['relative_path'] ?? '' ) );
	}

	private function database_available(): bool {
		return isset( $GLOBALS['wpdb'] )
			&& is_object( $GLOBALS['wpdb'] )
			&& method_exists( $GLOBALS['wpdb'], 'query' )
			&& method_exists( $GLOBALS['wpdb'], 'prepare' )
			&& method_exists( $GLOBALS['wpdb'], 'get_row' );
	}
}
