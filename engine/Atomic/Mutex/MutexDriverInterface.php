<?php
declare(strict_types=1);
namespace Engine\Atomic\Mutex;

if (!defined('ATOMIC_START')) exit;

// TODO renew method
interface MutexDriverInterface
{
    public function acquire(string $name, string $token, int $ttl): bool;
    public function release(string $name, string $token): bool;
    public function exists(string $name): bool;
    public function get_token(string $name): ?string;
    public function force_release(string $name): bool;
    public function get_name(): string;
    public function is_available(): bool;
}
