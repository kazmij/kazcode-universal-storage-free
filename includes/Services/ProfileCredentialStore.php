<?php
/**
 * Encrypted access-key credentials for storage profiles that opt out of the
 * site-wide (legacy_default) credential set — e.g. a Pro profile targeting a
 * different provider than the one configured in Settings.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\EncryptionService;

/**
 * One WP option holding a small map of credentials_ref => {access_key_id, secret}.
 * Mirrors Settings::set_secret_access_key()/get_secret_access_key() — same
 * EncryptionService envelope, same "blank secret on save means keep existing"
 * convention — just keyed per profile instead of a single site-wide value.
 */
final class ProfileCredentialStore {

	public const OPTION_KEY = 's3ms_profile_credentials';

	/**
	 * Store/replace one profile's credentials. Pass an empty $secret to keep
	 * whatever secret is already stored for this ref (only access_key_id changes).
	 */
	public function set( string $credentials_ref, string $access_key_id, string $secret ): void {
		if ( $credentials_ref === '' ) {
			return;
		}
		$all = $this->all();
		$row = $all[ $credentials_ref ] ?? array(
			'access_key_id' => '',
			'secret'        => null,
		);

		$row['access_key_id'] = $access_key_id;
		if ( $secret !== '' ) {
			$row['secret'] = ( new EncryptionService() )->encrypt( $secret );
		}

		$all[ $credentials_ref ] = $row;
		update_option( self::OPTION_KEY, $all, false );
	}

	public function get_access_key_id( string $credentials_ref ): string {
		return (string) ( $this->all()[ $credentials_ref ]['access_key_id'] ?? '' );
	}

	/**
	 * Decrypt the stored secret, or empty string if unset / undecryptable.
	 */
	public function get_secret( string $credentials_ref ): string {
		$payload = $this->all()[ $credentials_ref ]['secret'] ?? null;
		if ( ! is_array( $payload ) || empty( $payload['ciphertext'] ) ) {
			return '';
		}
		try {
			return ( new EncryptionService() )->decrypt( $payload );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	public function has_secret( string $credentials_ref ): bool {
		return $this->get_secret( $credentials_ref ) !== '';
	}

	public function has( string $credentials_ref ): bool {
		return isset( $this->all()[ $credentials_ref ] );
	}

	public function delete( string $credentials_ref ): void {
		$all = $this->all();
		if ( ! isset( $all[ $credentials_ref ] ) ) {
			return;
		}
		unset( $all[ $credentials_ref ] );
		update_option( self::OPTION_KEY, $all, false );
	}

	/**
	 * @return array<string, array{access_key_id:string, secret: array<string,mixed>|null}>
	 */
	private function all(): array {
		$value = get_option( self::OPTION_KEY, array() );
		return is_array( $value ) ? $value : array();
	}
}
