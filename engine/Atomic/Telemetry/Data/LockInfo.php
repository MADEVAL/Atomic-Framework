<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class LockInfo
{
    public function __construct(
        public readonly string $name,
        public readonly string $token,
        public readonly ?int $age,
        public readonly ?int $ttl,
        public readonly int $remaining,
    ) {}

    public function is_expired(): bool
    {
        return $this->remaining <= 0;
    }

    /** @return array{name:string, token:string, age:?int, ttl:?int, remaining:int} */
    public function to_array(): array
    {
        return [
            'name' => $this->name,
            'token' => $this->token,
            'age' => $this->age,
            'ttl' => $this->ttl,
            'remaining' => $this->remaining,
        ];
    }
}
