<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class JobRun
{
    private const MS_PER_SECOND = 1000;

    public function __construct(
        public readonly JobOutcome $status,
        public readonly int $started_at,
        public readonly float $duration_ms,
        public readonly ?string $error,
    ) {}

    public function height(): int
    {
        return max(1, (int)round($this->duration_ms / self::MS_PER_SECOND));
    }

    /** @return array{s:string, h:int, at:int, ms:float, error:?string} */
    public function to_array(): array
    {
        return [
            's' => $this->status->value,
            'h' => $this->height(),
            'at' => $this->started_at,
            'ms' => $this->duration_ms,
            'error' => $this->error,
        ];
    }
}
