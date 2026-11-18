<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class QuotaTier
{
    private const DAY = 86400;

    /**
     * @param list<PacingWindow> $pacing
     * @param array<string, int> $operations operation => credit cost
     */
    public function __construct(
        public readonly string $name,
        public readonly int $credits,
        public readonly int $period,
        public readonly array $pacing,
        public readonly array $operations,
    ) {}

    /** @param array<string, mixed> $tier */
    public static function from_array(array $tier): self
    {
        $operations = [];
        foreach ((array)($tier['operations'] ?? []) as $name => $operation) {
            $operations[(string)$name] = (int)(((array)$operation)['cost'] ?? 0);
        }

        return new self(
            name: (string)($tier['name'] ?? ''),
            credits: (int)($tier['credits'] ?? 0),
            period: (int)($tier['period'] ?? 0),
            pacing: array_map(PacingWindow::from_array(...), array_values((array)($tier['pacing'] ?? []))),
            operations: $operations,
        );
    }

    public function days(): int
    {
        return (int)round($this->period / self::DAY);
    }

    public function pacing_text(): string
    {
        return implode(', ', array_map(static fn (PacingWindow $pace): string => $pace->text(), $this->pacing));
    }

    public function operations_text(): string
    {
        return implode(' · ', array_map(static fn (string $name, int $cost): string => $name . ' ' . $cost, array_keys($this->operations), $this->operations));
    }
}
