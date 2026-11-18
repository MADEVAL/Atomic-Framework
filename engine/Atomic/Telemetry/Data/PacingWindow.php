<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class PacingWindow
{
    public const TIER = '_tier';

    public function __construct(
        public readonly int $limit,
        public readonly int $window,
        public readonly ?string $key = null,
        public readonly int $used = 0,
    ) {}

    /** @param array<string, mixed> $pace */
    public static function from_array(array $pace): self
    {
        return new self(
            (int)($pace['limit'] ?? 0),
            (int)($pace['window'] ?? 0),
            isset($pace['key']) ? (string)$pace['key'] : null,
            (int)($pace['used'] ?? 0),
        );
    }

    public function name(): string
    {
        return $this->key === self::TIER ? 'plan' : (string)$this->key;
    }

    public function is_full(): bool
    {
        return $this->used >= $this->limit;
    }

    public function usage(): float
    {
        return $this->limit > 0 ? $this->used / $this->limit : 1.0;
    }

    public function text(): string
    {
        return $this->limit . ' per ' . $this->window . 's';
    }
}
