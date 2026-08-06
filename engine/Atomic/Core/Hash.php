<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined('ATOMIC_START')) exit;

class Hash
{
    private const DEFAULT_ALGO = PASSWORD_ARGON2ID;
    private const DEFAULT_COST = ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1];

    public static function password(string $password, int $cost = 12): string
    {
        return password_hash($password, PASSWORD_DEFAULT, ['cost' => $cost]);
    }

    public static function password_argon2(string $value): string
    {
        return password_hash($value, self::DEFAULT_ALGO, self::DEFAULT_COST);
    }

    public static function verify_password(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function password_needs_rehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }

    /** HMAC-based hash for API tokens (faster than password_hash) */
    public static function hmac(string $data, string $key): string
    {
        return hash_hmac('sha256', $data, $key);
    }

    public static function verify_hmac(string $data, string $key, string $hash): bool
    {
        return hash_equals(
            self::hmac($data, $key),
            $hash
        );
    }

    /**
     * Dummy-операция для timing attack mitigation.
     * Использует тот же алгоритм Argon2id, что и password_argon2().
     */
    public static function dummy_timing_mitigation(): void
    {
        password_hash(random_bytes(32), self::DEFAULT_ALGO, self::DEFAULT_COST);
    }

    /** @deprecated Use dummy_timing_mitigation() instead */
    public static function dummy_hash_for_timing_mitigation(): string
    {
        self::dummy_timing_mitigation();
        return password_hash(base64_encode(random_bytes(32)), PASSWORD_BCRYPT);
    }
}
