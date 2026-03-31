<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined('ATOMIC_START')) exit;

class Crypto extends \Prefab {
    private string $key;

    public function __construct() {
        $this->init();
    }

    private function init(): void
    {
        $app = App::instance();
        $key = $app->get('APP_ENCRYPTION_KEY');
        if (empty($key)) {
            throw new \RuntimeException('ENCRYPTION_KEY not configured');
        }
        $this->key = base64_decode($key);
        if (strlen($this->key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Invalid APP_ENCRYPTION_KEY length. Must be ' . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . ' bytes when decoded.');
        }
    }

    public static function generate_key(): string {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    public function encrypt(string $text): string|false
    {
        if (empty($text)) return false;

        try {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($text, $nonce, $this->key);
            $encoded = base64_encode($nonce . $ciphertext);
            sodium_memzero($text);
            return $encoded;
        } catch (\Exception $e) {
            throw new \RuntimeException('Encryption failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function decrypt(string $encoded): string|false
    {
        if (empty($encoded)) return false;

        try {
            $decoded = base64_decode($encoded, true);
            if ($decoded === false) {
                Log::warning('Crypto: Invalid base64 encoded data');
                return false;
            }
            $nonce = mb_substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
            $ciphertext = mb_substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');
            $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
            if ($plain === false) {
                Log::warning('Crypto: Decryption failed - data may be corrupted or tampered');
                return false;
            }
            sodium_memzero($ciphertext);
            return $plain;
        } catch (\Exception $e) {
            Log::error('Crypto: Decryption error - ' . $e->getMessage());
            return false;
        }
    }
}