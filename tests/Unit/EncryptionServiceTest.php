<?php
/**
 * EncryptionService unit tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\EncryptionService;

final class EncryptionServiceTest extends TestCase {

	public function test_roundtrip_encrypt_decrypt(): void {
		$svc = new EncryptionService();
		$payload = $svc->encrypt('super-secret-key-value');
		$this->assertArrayHasKey('version', $payload);
		$this->assertArrayHasKey('algorithm', $payload);
		$this->assertArrayHasKey('nonce', $payload);
		$this->assertArrayHasKey('ciphertext', $payload);
		$this->assertSame('super-secret-key-value', $svc->decrypt($payload));
	}

	public function test_tampered_ciphertext_fails(): void {
		$svc = new EncryptionService();
		$payload = $svc->encrypt('secret');
		$raw = base64_decode($payload['ciphertext'], true);
		$raw[0] = chr(ord($raw[0]) ^ 0xff);
		$payload['ciphertext'] = base64_encode($raw);
		$this->expectException(\RuntimeException::class);
		$svc->decrypt($payload);
	}

	public function test_derive_key_length(): void {
		$key = (new EncryptionService())->derive_key();
		$this->assertSame(32, strlen($key));
	}
}
