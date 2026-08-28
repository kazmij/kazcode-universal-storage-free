<?php
/**
 * Plugin settings stored in wp_options.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Core;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Domain\LocalStoragePolicy;

/**
 * Settings accessor and sanitizer (request-memoized).
 */
class Settings {

	public const OPTION_KEY           = 's3ms_settings';
	public const ENCRYPTED_SECRET_KEY = 's3ms_encrypted_secret';
	public const NETWORK_OPTION_KEY   = 's3ms_network_settings';

	/**
	 * Connection fields NetworkSettingsPage lets a site inherit — must mirror
	 * that form's field list exactly. Secrets (access_key_id, the encrypted
	 * secret) are deliberately excluded: "Per-site secrets still live on each
	 * site unless you use IAM roles."
	 */
	private const NETWORK_INHERITABLE_KEYS = array( 'bucket', 'region', 'object_prefix', 'endpoint', 'cdn_url', 'credential_mode' );

	/** @var array<string, mixed>|null */
	private ?array $cache = null;

	/** @var string|null Decrypted secret cache for this request. */
	private ?string $secret_cache = null;

	private bool $secret_resolved = false;

	/**
	 * Default option values.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'                   => false,
			'serve_from_s3'             => false,
			'access_key_id'             => '',
			'region'                    => 'us-east-1',
			'bucket'                    => '',
			'endpoint'                  => '',
			'force_path_style'          => false,
			'object_prefix'             => '',
			'public_base_url'           => '',
			'cdn_url'                   => '',
			'cdn_includes_prefix'       => false,
			'keep_local_files'          => true,
			'delete_local_after_upload' => false,
			'verify_before_delete'      => true,
			'local_storage_policy'      => LocalStoragePolicy::KEEP_ALL,
			'delete_remote_on_delete'   => true,
			'cache_control'             => 'public, max-age=31536000',
			// Product extensions.
			'provider_preset'           => 'aws',
			'credential_mode'           => 'keys', // keys|iam_role
			'private_media'             => false,
			'signed_url_ttl'            => 3600,
			'background_batch_size'     => 20,
			'inherit_network_settings'  => false,
			'setup_wizard_completed'    => false,
			'compat_elementor'          => true,
			'compat_acf'                => true,
			'compat_gutenberg'          => true,
		);
	}

	/**
	 * Ensure option exists.
	 */
	public function ensure_defaults(): void {
		if ( get_option( self::OPTION_KEY ) === false ) {
			add_option( self::OPTION_KEY, self::defaults(), '', false );
		}
	}

	/**
	 * Invalidate request cache after writes.
	 */
	public function flush_cache(): void {
		$this->cache           = null;
		$this->secret_cache    = null;
		$this->secret_resolved = false;
	}

	/**
	 * All settings merged with defaults (and optional network parent).
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( $this->cache !== null ) {
			return $this->cache;
		}
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$base = self::defaults();
		if ( is_multisite() && ! empty( $stored['inherit_network_settings'] ) ) {
			$network = get_site_option( self::NETWORK_OPTION_KEY, array() );
			if ( is_array( $network ) ) {
				$base = array_merge( $base, $network );
			}
		}

		$this->cache = array_merge( $base, $stored );
		$this->cache['local_storage_policy'] = LocalStoragePolicy::from_legacy_settings( $this->cache );
		$legacy                                 = LocalStoragePolicy::legacy_flags_for( $this->cache['local_storage_policy'] );
		$this->cache['keep_local_files']          = $legacy['keep_local_files'];
		$this->cache['delete_local_after_upload'] = $legacy['delete_local_after_upload'];
		$this->cache['verify_before_delete']      = true;
		return $this->cache;
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ): mixed {
		$all = $this->all();
		return $all[ $key ] ?? $default;
	}

	/**
	 * Persist settings (does not touch encrypted secret).
	 *
	 * @param array<string, mixed> $data Raw input.
	 */
	public function update( array $data ): void {
		$current = $this->all();
		$clean   = $this->sanitize( $data, $current );
		if ( is_multisite() && ! empty( $clean['inherit_network_settings'] ) ) {
			// The Settings form always posts every field, so a full-form save
			// would otherwise persist this site's (possibly blank) connection
			// fields and permanently shadow the network defaults in all()'s
			// array_merge($base, $stored) — making "Inherit network settings"
			// a no-op the moment it's saved. Omit these keys from what this
			// site stores so all() naturally falls through to the network
			// option instead.
			foreach ( self::NETWORK_INHERITABLE_KEYS as $key ) {
				unset( $clean[ $key ] );
			}
		}
		update_option( self::OPTION_KEY, $clean, false );
		$this->flush_cache();
	}

	/**
	 * Persist network-wide defaults (multisite).
	 *
	 * @param array<string, mixed> $data Raw input.
	 */
	public function update_network( array $data ): void {
		$current = get_site_option( self::NETWORK_OPTION_KEY, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		$clean = $this->sanitize( $data, array_merge( self::defaults(), $current ) );
		// Network store should not force site-only flags.
		unset( $clean['inherit_network_settings'], $clean['setup_wizard_completed'] );
		update_site_option( self::NETWORK_OPTION_KEY, $clean );
		$this->flush_cache();
	}

	/**
	 * Sanitize settings input.
	 *
	 * @param array<string, mixed> $data Input.
	 * @param array<string, mixed> $current Current settings.
	 * @return array<string, mixed>
	 */
	public function sanitize( array $data, array $current ): array {
		$out = self::defaults();

		// Booleans: full settings form posts _s3ms_full_form (unchecked boxes = off).
		// Partial updates (wizard steps) omit that marker and preserve missing keys.
		$full = ! empty( $data['_s3ms_full_form'] );
		$bool = static function ( string $key, array $data, array $current, bool $full, bool $default = false ) : bool {
			if ( $full || array_key_exists( $key, $data ) ) {
				return ! empty( $data[ $key ] );
			}
			return ! empty( $current[ $key ] ?? $default );
		};

		$out['enabled']                   = $bool( 'enabled', $data, $current, $full );
		$out['serve_from_s3']             = $bool( 'serve_from_s3', $data, $current, $full );
		$out['access_key_id']             = isset( $data['access_key_id'] ) ? sanitize_text_field( (string) $data['access_key_id'] ) : (string) ( $current['access_key_id'] ?? '' );
		$out['region']                    = isset( $data['region'] ) ? sanitize_text_field( (string) $data['region'] ) : (string) ( $current['region'] ?? 'us-east-1' );
		$out['bucket']                    = isset( $data['bucket'] ) ? sanitize_text_field( (string) $data['bucket'] ) : (string) ( $current['bucket'] ?? '' );
		$out['endpoint']                  = isset( $data['endpoint'] ) ? esc_url_raw( (string) $data['endpoint'] ) : (string) ( $current['endpoint'] ?? '' );
		$out['force_path_style']          = $bool( 'force_path_style', $data, $current, $full );
		$out['object_prefix']             = isset( $data['object_prefix'] ) ? $this->sanitize_prefix( (string) $data['object_prefix'] ) : (string) ( $current['object_prefix'] ?? '' );
		$out['public_base_url']           = isset( $data['public_base_url'] ) ? esc_url_raw( untrailingslashit( (string) $data['public_base_url'] ) ) : (string) ( $current['public_base_url'] ?? '' );
		$out['cdn_url']                   = isset( $data['cdn_url'] ) ? esc_url_raw( untrailingslashit( (string) $data['cdn_url'] ) ) : (string) ( $current['cdn_url'] ?? '' );
		$out['cdn_includes_prefix']       = $bool( 'cdn_includes_prefix', $data, $current, $full );
		$out['keep_local_files']          = $bool( 'keep_local_files', $data, $current, $full, true );
		$out['delete_local_after_upload'] = $bool( 'delete_local_after_upload', $data, $current, $full );
		$out['verify_before_delete']      = true;

		if ( isset( $data['local_storage_policy'] ) && is_string( $data['local_storage_policy'] ) ) {
			$out['local_storage_policy'] = LocalStoragePolicy::normalize( $data['local_storage_policy'] );
		} elseif ( ! empty( $current['local_storage_policy'] ) ) {
			$out['local_storage_policy'] = LocalStoragePolicy::normalize( (string) $current['local_storage_policy'] );
		} else {
			$out['local_storage_policy'] = LocalStoragePolicy::from_legacy_settings(
				array_merge(
					$current,
					array(
						'keep_local_files'          => $out['keep_local_files'],
						'delete_local_after_upload' => $out['delete_local_after_upload'],
					)
				)
			);
		}

		$legacy = LocalStoragePolicy::legacy_flags_for( $out['local_storage_policy'] );
		$out['keep_local_files']          = $legacy['keep_local_files'];
		$out['delete_local_after_upload'] = $legacy['delete_local_after_upload'];
		$out['verify_before_delete']      = true;
		$out['delete_remote_on_delete']   = $bool( 'delete_remote_on_delete', $data, $current, $full, true );
		$out['cache_control']             = isset( $data['cache_control'] ) ? sanitize_text_field( (string) $data['cache_control'] ) : (string) ( $current['cache_control'] ?? 'public, max-age=31536000' );

		$preset = isset( $data['provider_preset'] ) ? sanitize_key( (string) $data['provider_preset'] ) : (string) ( $current['provider_preset'] ?? 'aws' );
		$out['provider_preset'] = ProviderPresets::get( $preset ) ? $preset : 'aws';

		$mode = isset( $data['credential_mode'] ) ? sanitize_key( (string) $data['credential_mode'] ) : (string) ( $current['credential_mode'] ?? 'keys' );
		$out['credential_mode'] = in_array( $mode, array( 'keys', 'iam_role' ), true ) ? $mode : 'keys';

		$out['private_media'] = $bool( 'private_media', $data, $current, $full );
		$ttl                             = isset( $data['signed_url_ttl'] ) ? (int) $data['signed_url_ttl'] : (int) ( $current['signed_url_ttl'] ?? 3600 );
		$out['signed_url_ttl']           = max( 60, min( 86400, $ttl ) );
		$bg                              = isset( $data['background_batch_size'] ) ? (int) $data['background_batch_size'] : (int) ( $current['background_batch_size'] ?? 20 );
		$out['background_batch_size']    = max( 1, min( 50, $bg ) );
		$out['inherit_network_settings'] = $bool( 'inherit_network_settings', $data, $current, $full );
		$out['setup_wizard_completed']   = ! empty( $current['setup_wizard_completed'] );
		if ( array_key_exists( 'setup_wizard_completed', $data ) ) {
			$out['setup_wizard_completed'] = ! empty( $data['setup_wizard_completed'] );
		}
		$out['compat_elementor'] = $bool( 'compat_elementor', $data, $current, $full, true );
		$out['compat_acf']       = $bool( 'compat_acf', $data, $current, $full, true );
		$out['compat_gutenberg'] = $bool( 'compat_gutenberg', $data, $current, $full, true );

		return $out;
	}

	/**
	 * Normalize object prefix (no leading slash, trailing slash if non-empty, no ..).
	 */
	public function sanitize_prefix( string $prefix ): string {
		$prefix = trim( str_replace( '\\', '/', $prefix ) );
		$prefix = ltrim( $prefix, '/' );
		if ( $prefix === '' ) {
			return '';
		}
		if ( str_contains( $prefix, '..' ) ) {
			return '';
		}
		return rtrim( $prefix, '/' ) . '/';
	}

	/**
	 * Whether the plugin is enabled.
	 */
	public function is_enabled(): bool {
		return (bool) $this->get( 'enabled', false );
	}

	/**
	 * Whether attachment URLs should point to S3/CDN.
	 */
	public function is_serve_enabled(): bool {
		return $this->is_enabled() && (bool) $this->get( 'serve_from_s3', false );
	}

	/**
	 * Credential mode.
	 */
	public function credential_mode(): string {
		$mode = (string) $this->get( 'credential_mode', 'keys' );
		return $mode === 'iam_role' ? 'iam_role' : 'keys';
	}

	/**
	 * Private media (signed URLs). A Free capability — serves offloaded files
	 * via time-limited signed GET URLs instead of public URLs.
	 */
	public function is_private_media(): bool {
		return (bool) $this->get( 'private_media', false );
	}

	/**
	 * Signed URL TTL seconds.
	 */
	public function signed_url_ttl(): int {
		return max( 60, min( 86400, (int) $this->get( 'signed_url_ttl', 3600 ) ) );
	}

	/**
	 * Whether public URL configuration can produce a non-empty URL.
	 */
	public function has_public_url_config(): bool {
		if ( $this->is_private_media() ) {
			return $this->is_aws_configured();
		}
		if ( (string) $this->get( 'cdn_url', '' ) !== '' ) {
			return true;
		}
		if ( (string) $this->get( 'public_base_url', '' ) !== '' ) {
			return true;
		}
		return (string) $this->get( 'bucket', '' ) !== '';
	}

	/**
	 * Active local retention policy (v2 Phase 5).
	 */
	public function local_storage_policy(): string {
		return LocalStoragePolicy::from_legacy_settings( $this->all() );
	}

	/**
	 * Whether any local cleanup may run after offload.
	 */
	public function should_delete_local(): bool {
		return LocalStoragePolicy::deletes_any_local( $this->local_storage_policy() );
	}

	/**
	 * Remote verify before local delete is always required (P5-T03).
	 */
	public function should_verify_before_delete(): bool {
		return true;
	}

	/**
	 * Whether to delete S3 objects when an attachment is deleted.
	 */
	public function should_delete_remote(): bool {
		return (bool) $this->get( 'delete_remote_on_delete', true );
	}

	/**
	 * Store encrypted secret access key.
	 *
	 * @param string $plaintext Secret Access Key.
	 */
	public function set_secret_access_key( string $plaintext ): void {
		$encryption = new EncryptionService();
		$payload    = $encryption->encrypt( $plaintext );
		update_option( self::ENCRYPTED_SECRET_KEY, $payload, false );
		$this->flush_cache();
	}

	/**
	 * Decrypt secret access key, or empty string if unset / undecryptable.
	 */
	public function get_secret_access_key(): string {
		if ( $this->secret_resolved ) {
			return (string) $this->secret_cache;
		}
		$this->secret_resolved = true;
		$payload               = get_option( self::ENCRYPTED_SECRET_KEY, null );
		if ( ! is_array( $payload ) || empty( $payload['ciphertext'] ) ) {
			$this->secret_cache = '';
			return '';
		}
		try {
			$this->secret_cache = ( new EncryptionService() )->decrypt( $payload );
		} catch ( \Throwable $e ) {
			$this->secret_cache = '';
		}
		return (string) $this->secret_cache;
	}

	/**
	 * Whether a secret is stored and decryptable.
	 */
	public function has_secret_access_key(): bool {
		return $this->get_secret_access_key() !== '';
	}

	/**
	 * Remove stored secret.
	 */
	public function clear_secret_access_key(): void {
		delete_option( self::ENCRYPTED_SECRET_KEY );
		$this->flush_cache();
	}

	/**
	 * Mark setup wizard completed.
	 */
	public function complete_wizard(): void {
		$all                           = $this->all();
		$all['setup_wizard_completed'] = true;
		update_option( self::OPTION_KEY, $all, false );
		$this->flush_cache();
	}

	/**
	 * Whether wizard should show.
	 */
	public function needs_wizard(): bool {
		return ! (bool) $this->get( 'setup_wizard_completed', false );
	}

	/**
	 * Basic AWS configuration completeness check.
	 */
	public function is_aws_configured(): bool {
		$all = $this->all();
		if ( $all['bucket'] === '' || $all['region'] === '' ) {
			return false;
		}
		if ( $this->credential_mode() === 'iam_role' ) {
			return true;
		}
		return $all['access_key_id'] !== '' && $this->has_secret_access_key();
	}
}
