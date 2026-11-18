<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class ExceptionsReport
{
    /** @param list<ExceptionGroup> $groups */
    public function __construct(
        public readonly array $groups,
        public readonly Lifetime $lifetime,
    ) {}

    /** @param array<string, mixed> $report ExceptionsReporter::report(), redacted */
    public static function from_array(array $report): self
    {
        return new self(
            array_map(ExceptionGroup::from_array(...), array_values((array)($report['groups'] ?? []))),
            Lifetime::from_array((array)($report['lifetime'] ?? [])),
        );
    }

    /** @return list<int> occurrences per point, summed over every group */
    public function occurrences(): array
    {
        $points = $this->groups ? count($this->groups[0]->usage) : 0;
        $total = array_fill(0, $points, 0);
        foreach ($this->groups as $group) {
            for ($i = 0; $i < $points; $i++) {
                $total[$i] += $group->usage[$i] ?? 0;
            }
        }

        return $total;
    }
}
