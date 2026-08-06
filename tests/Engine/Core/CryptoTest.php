<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\Crypto;
use PHPUnit\Framework\TestCase;

class CryptoTest extends TestCase
{
    private static Crypto $crypto;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('sodium')) {
            self::markTestSkipped('Sodium extension not available');
        }
        $f3 = \Base::instance();
        $key = base64_encode(\sodium_crypto_secretbox_keygen());
        $f3->set('APP_ENCRYPTION_KEY', $key);
        self::$crypto = new Crypto();
    }

    public function test_encrypt_decrypt_roundtrip(): void
    {
        $plain = 'Hello, Atomic Framework!';
        $encrypted = self::$crypto->encrypt($plain);
        $this->assertIsString($encrypted);
        $this->assertNotSame($plain, $encrypted);

        $decrypted = self::$crypto->decrypt($encrypted);
        $this->assertSame('Hello, Atomic Framework!', $decrypted);
    }

    public function test_encrypt_empty_returns_false(): void
    {
        $this->assertFalse(self::$crypto->encrypt(''));
    }

    public function test_decrypt_empty_returns_false(): void
    {
        $this->assertFalse(self::$crypto->decrypt(''));
    }

    public function test_decrypt_invalid_base64_returns_false(): void
    {
        $this->assertFalse(self::$crypto->decrypt('not-valid-base64!!!'));
    }

    public function test_decrypt_tampered_data_returns_false(): void
    {
        $encrypted = self::$crypto->encrypt('test');
        $this->assertIsString($encrypted);
        $tampered = substr($encrypted, 0, -4) . 'XXXX';
        $this->assertFalse(self::$crypto->decrypt($tampered));
    }

    public function test_encrypt_produces_unique_ciphertexts(): void
    {
        $plain = 'same-text';
        $a = self::$crypto->encrypt($plain);
        $b = self::$crypto->encrypt($plain);
        $this->assertNotSame($a, $b);
    }

    public function test_generate_key_returns_valid_base64(): void
    {
        $key = Crypto::generate_key();
        $decoded = base64_decode($key, true);
        $this->assertNotFalse($decoded);
        $this->assertSame(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen($decoded));
    }

    public function test_encrypt_decrypt_unicode(): void
    {
        $plain = 'Привіт, 世界! 🚀';
        $encrypted = self::$crypto->encrypt($plain);
        $decrypted = self::$crypto->decrypt($encrypted);
        $this->assertSame($plain, $decrypted);
    }

    public function test_encrypt_decrypt_long_text(): void
    {
        $plain = str_repeat('A', 100000);
        $encrypted = self::$crypto->encrypt($plain);
        $decrypted = self::$crypto->decrypt($encrypted);
        $this->assertSame($plain, $decrypted);
    }

    public function test_invalid_key_length_throws(): void
    {
        $f3 = \Base::instance();
        $old = $f3->get('APP_ENCRYPTION_KEY');
        $f3->set('APP_ENCRYPTION_KEY', base64_encode('short'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid APP_ENCRYPTION_KEY length');
        try {
            new Crypto();
        } finally {
            $f3->set('APP_ENCRYPTION_KEY', $old);
        }
    }

    public function test_empty_key_throws(): void
    {
        $f3 = \Base::instance();
        $old = $f3->get('APP_ENCRYPTION_KEY');
        $f3->set('APP_ENCRYPTION_KEY', '');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('APP_ENCRYPTION_KEY not configured');
        try {
            new Crypto();
        } finally {
            $f3->set('APP_ENCRYPTION_KEY', $old);
        }
    }
}
