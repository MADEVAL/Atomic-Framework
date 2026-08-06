<?php
declare(strict_types=1);
namespace Engine\Atomic\Cache\Helpers;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;

final class Payload
{
    private const SIGNED_MARKER = '__atomic_cache_payload_v1';

    public static function pack(mixed $value, int $ttl): string
    {
        self::assert_supported_value($value);

        $data = json_encode([
            'value' => $value,
            'time' => microtime(true),
            'ttl' => max(0, $ttl),
        ], JSON_THROW_ON_ERROR);

        return json_encode([
            self::SIGNED_MARKER => true,
            'data' => $data,
            'hmac' => hash_hmac('sha256', $data, self::signing_key()),
        ], JSON_THROW_ON_ERROR);
    }

    public static function unpack(mixed $raw): array|false
    {
        if (!is_string($raw) || $raw === '') {
            return false;
        }

        try {
            $envelope = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (
            !is_array($envelope)
            || ($envelope[self::SIGNED_MARKER] ?? false) !== true
            || !is_string($envelope['data'] ?? null)
            || !is_string($envelope['hmac'] ?? null)
        ) {
            return false;
        }

        $data = $envelope['data'];
        if (!hash_equals($envelope['hmac'], hash_hmac('sha256', $data, self::signing_key()))) {
            return false;
        }

        try {
            $payload = json_decode($data, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (
            !is_array($payload)
            || !array_key_exists('value', $payload)
        ) {
            return false;
        }

        return [
            'value' => $payload['value'],
            'time' => (float)($payload['time'] ?? microtime(true)),
            'ttl' => (int)($payload['ttl'] ?? 0),
        ];
    }

    public static function is_expired(array $payload): bool
    {
        $ttl = (int)$payload['ttl'];
        return $ttl > 0 && ((float)$payload['time'] + $ttl) <= microtime(true);
    }

    public static function meta(array $payload): array
    {
        $ttl = (int)$payload['ttl'];
        if ($ttl > 0) {
            $remaining = max(0, (int)ceil(((float)$payload['time'] + $ttl) - microtime(true)));
            return [(float)$payload['time'], $remaining];
        }

        return [(float)$payload['time'], 0];
    }

    private static function signing_key(): string
    {
        $app = App::instance();
        foreach (['APP_ENCRYPTION_KEY', 'APP_KEY', 'SEED'] as $key) {
            $value = $app->get($key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        throw new \RuntimeException('Cache payload signing key is not configured.');
    }

    private static function assert_supported_value(mixed $value): void
    {
        if (is_null($value) || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            if (is_float($value) && !is_finite($value)) {
                throw new \InvalidArgumentException('Cache values must be JSON-compatible.');
            }
            return;
        }

        if (is_array($value)) {
            foreach ($value as $nested) {
                self::assert_supported_value($nested);
            }
            return;
        }

        throw new \InvalidArgumentException('Cache values must be JSON-compatible.');
    }
}
