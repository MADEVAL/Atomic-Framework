<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth\Adapters;

if (!defined('ATOMIC_START')) exit;

class PhpSessionAdapter
{
    public function id(): string
    {
        return session_id();
    }

    public function name(string $name): void
    {
        session_name($name);
    }

    public function status(): int
    {
        return session_status();
    }

    public function regenerate_id(bool $delete_old = false): bool
    {
        return session_regenerate_id($delete_old);
    }

    public function has_cookie(string $name): bool
    {
        return isset($_COOKIE[$name]);
    }
}
