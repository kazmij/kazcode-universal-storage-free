<?php
/**
 * Encrypts Secret Access Key at rest.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Encryption using sodium_crypto_secretbox with AES-256-GCM OpenSSL fallback.
 */
final class EncryptionService {

	public const VERSION_SODIUM = 1;
	public const VERSION_OPENSSL = 2;

	/**
	 * Encrypt plaintext.
	 *
	 * @param string $plaintext Secret to encrypt.
	 * @return array{version:int,algorithm:string,nonce:string,ciphertext:string,tag?:string}
	 */
	public function encrypt(string $plaintext): array {
		$key = $this->derive_key();

		if ($this->sodium_available()) {
			$nonce      = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
			$ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
			return array(
				'version'    => self::VERSION_SODIUM,
				'algorithm'  => 'sodium_secretbox',
				'nonce'      => base64_encode($nonce),
				'ciphertext' => base64_encode($ciphertext),
			);
		}

		$iv  = random_bytes(12);
		$tag = '';
		$ciphertext = openssl_encrypt(
			$plaintext,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			16
		);

		if ($ciphertext === false) {
			throw new \RuntimeException('OpenSSL AES-256-GCM encryption failed.');
		}

		return array(
			'version'    => self::VERSION_OPENSSL,
			'algorithm'  => 'aes-256-gcm',
			'nonce'      => base64_encode($iv),
			'ciphertext' => base64_encode($ciphertext),
			'tag'        => base64_encode($tag),
		);
	}

	/**
	 * Decrypt payload produced by encrypt().
	 *
	 * @param array<string, mixed> $payload Stored blob.
	 */
	public function decrypt(array $payload): string {
		$key       = $this->derive_key();
		$algorithm = (string) ($payload['algorithm'] ?? '');
		$nonce_b64 = (string) ($payload['nonce'] ?? '');
		$cipher_b64 = (string) ($payload['ciphertext'] ?? '');

		$nonce      = base64_decode($nonce_b64, true);
		$ciphertext = base64_decode($cipher_b64, true);

		if ($nonce === false || $ciphertext === false) {
			throw new \RuntimeException('Invalid encrypted payload encoding.');
		}

		if ($algorithm === 'sodium_secretbox' || (int) ($payload['version'] ?? 0) === self::VERSION_SODIUM) {
			if (!$this->sodium_available()) {
				throw new \RuntimeException('sodium extension required to decrypt this secret.');
			}
			$plain = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
			if ($plain === false) {
				throw new \RuntimeException('Failed to decrypt secret (sodium).');
			}
			return $plain;
		}

		if ($algorithm === 'aes-256-gcm' || (int) ($payload['version'] ?? 0) === self::VERSION_OPENSSL) {
			$tag_b64 = (string) ($payload['tag'] ?? '');
			$tag     = base64_decode($tag_b64, true);
			if ($tag === false) {
				throw new \RuntimeException('Missing AES-GCM auth tag.');
			}
			$plain = openssl_decrypt(
				$ciphertext,
				'aes-256-gcm',
				$key,
				OPENSSL_RAW_DATA,
				$nonce,
				$tag
			);
			if ($plain === false) {
				throw new \RuntimeException('Failed to decrypt secret (OpenSSL).');
			}
			return $plain;
		}

		throw new \RuntimeException('Unknown encryption algorithm.');
	}

	/**
	 * Derive a 32-byte key from WordPress salts via HKDF-SHA256.
	 */
	public function derive_key(): string {
		$ikm = implode(
			'|',
			array(
				defined('AUTH_KEY') ? AUTH_KEY : 'auth-key',
				defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : 'secure-auth-key',
				defined('AUTH_SALT') ? AUTH_SALT : 'auth-salt',
				defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : 'secure-auth-salt',
			)
		);

		if (function_exists('hash_hkdf')) {
			return hash_hkdf('sha256', $ikm, 32, 'kazcode-universal-storage-secret', 's3ms');
		}

		// Fallback: HMAC-based expand.
		$prk = hash_hmac('sha256', $ikm, 's3ms', true);
		return substr(hash_hmac('sha256', 'kazcode-universal-storage-secret' . "\x01", $prk, true), 0, 32);
	}

	/**
	 * Whether sodium is available.
	 */
	public function sodium_available(): bool {
		return extension_loaded('sodium')
			&& function_exists('sodium_crypto_secretbox')
			&& defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES')
			&& defined('SODIUM_CRYPTO_SECRETBOX_KEYBYTES');
	}
}
