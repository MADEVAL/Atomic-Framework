<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class RateKey
{
    public function __construct(
        public readonly string $key,
        public readonly RateStrategy $strategy,
        public readonly int $limit,
        public readonly int $used,
        public readonly int $reset_in,
        public readonly int $blocked_hour,
    ) {}

    public function is_limited(): bool
    {
        return $this->used >= $this->limit;
    }

    public function usage(): float
    {
        return $this->limit > 0 ? $this->used / $this->limit : 1.0;
    }

    /** @return array{key:string, strategy:string, limit:int, used:int, reset_in:int, blocked_hour:int} */
    public function to_array(): array
    {
        return [
            'key' => $this->key,
            'strategy' => $this->strategy->value,
            'limit' => $this->limit,
            'used' => $this->used,
            'reset_in' => $this->reset_in,
            'blocked_hour' => $this->blocked_hour,
        ];
    }
}
