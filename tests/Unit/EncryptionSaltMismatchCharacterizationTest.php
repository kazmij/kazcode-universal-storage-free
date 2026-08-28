<?php
/**
 * Characterization: encryption fails closed when key material changes.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\EncryptionService;

final class EncryptionSaltMismatchCharacterizationTest extends TestCase {

	public function test_decrypt_fails_when_ciphertext_not_authentic_under_current_salts(): void {
		$svc     = new EncryptionService();
		$payload = $svc->encrypt('aws-secret-value');
		$payload['ciphertext'] = base64_encode(random_bytes(64));

		$this->expectException(\RuntimeException::class);
		$svc->decrypt($payload);
	}

	public function test_decrypt_fails_for_random_payload_under_current_key(): void {
		$this->assertSame(32, strlen((new EncryptionService())->derive_key()));

		$svc = new EncryptionService();
		$this->expectException(\RuntimeException::class);
		$svc->decrypt(
			array(
				'version'    => EncryptionService::VERSION_SODIUM,
				'algorithm'  => 'sodium_secretbox',
				'nonce'      => base64_encode(str_repeat("\0", 24)),
				'ciphertext' => base64_encode(str_repeat('A', 32)),
			)
		);
	}

	public function test_settings_style_empty_on_decrypt_failure_contract(): void {
		// Mirrors Settings::get_secret_access_key catch → empty string (documented contract).
		$svc     = new EncryptionService();
		$payload = $svc->encrypt('secret');
		$payload['ciphertext'] = base64_encode(str_repeat('Z', 48));

		$secret = '';
		try {
			$secret = $svc->decrypt($payload);
		} catch (\Throwable $e) {
			$secret = '';
		}
		$this->assertSame('', $secret);
	}
}
