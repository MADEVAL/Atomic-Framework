<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class QueueTotals
{
    /** @param array<string, int> $counts state value => jobs */
    private function __construct(private readonly array $counts) {}

    /** @param array<string, mixed> $snapshot QueueReporter::snapshot() */
    public static function from_snapshot(array $snapshot): self
    {
        $counts = [];
        foreach (JobState::cases() as $state) {
            $counts[$state->value] = (int)($snapshot[$state->value] ?? 0);
        }

        return new self($counts);
    }

    public function count(JobState $state): int
    {
        return $this->counts[$state->value];
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }
}
