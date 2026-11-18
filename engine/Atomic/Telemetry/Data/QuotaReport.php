<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class QuotaReport
{
    /**
     * @param list<QuotaTier> $tiers
     * @param list<QuotaScope> $scopes
     */
    public function __construct(
        public readonly array $tiers,
        public readonly array $scopes,
        public readonly Lifetime $lifetime,
    ) {}

    /** @param array<string, mixed> $report */
    public static function from_array(array $report): self
    {
        return new self(
            array_map(QuotaTier::from_array(...), array_values((array)($report['tiers'] ?? []))),
            array_map(QuotaScope::from_array(...), array_values((array)($report['scopes'] ?? []))),
            Lifetime::from_array((array)($report['lifetime'] ?? [])),
        );
    }

    public function tier(string $name): ?QuotaTier
    {
        foreach ($this->tiers as $tier) {
            if ($tier->name === $name) {
                return $tier;
            }
        }

        return null;
    }

    /** @return list<int> denials per point, summed over every scope */
    public function denied(): array
    {
        $points = $this->scopes ? count($this->scopes[0]->denied) : 0;
        $total = array_fill(0, $points, 0);
        foreach ($this->scopes as $scope) {
            for ($i = 0; $i < $points; $i++) {
                $total[$i] += $scope->denied[$i] ?? 0;
            }
        }

        return $total;
    }

    /** @return array<string, int> reason label => denials, over every scope */
    public function by_reason(): array
    {
        $totals = [];
        foreach ($this->scopes as $scope) {
            foreach ($scope->denials as $reason => $count) {
                $label = DenialReason::label_of((string)$reason);
                $totals[$label] = ($totals[$label] ?? 0) + $count;
            }
        }

        return $totals;
    }
}
