<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined('ATOMIC_START')) exit;

class Hash
{
    public static function password(string $password, int $cost = 12): string
    {
        return password_hash($password, PASSWORD_DEFAULT, ['cost' => $cost]);
    }

    public static function verify_password(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function password_needs_rehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }
}
