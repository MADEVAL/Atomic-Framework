<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class QuotaScope
{
    /**
     * @param array<string, int> $denials reason => denials in the range
     * @param list<int> $denied denials per point of the range
     * @param list<PacingWindow> $pacing
     * @param list<QuotaDenial> $recent
     */
    public function __construct(
        public readonly string $scope,
        public readonly string $tier,
        public readonly int $balance,
        public readonly int $credits,
        public readonly int $ttl,
        public readonly array $denials,
        public readonly array $denied,
        public readonly array $pacing,
        public readonly array $recent,
    ) {}

    /** @param array<string, mixed> $scope */
    public static function from_array(array $scope): self
    {
        return new self(
            scope: (string)($scope['scope'] ?? ''),
            tier: (string)($scope['tier'] ?? ''),
            balance: (int)($scope['balance'] ?? 0),
            credits: (int)($scope['credits'] ?? 0),
            ttl: (int)($scope['ttl'] ?? 0),
            denials: array_map('intval', (array)($scope['denials'] ?? [])),
            denied: array_map('intval', array_values((array)($scope['denied'] ?? []))),
            pacing: array_map(PacingWindow::from_array(...), array_values((array)($scope['pacing'] ?? []))),
            recent: array_map(QuotaDenial::from_array(...), array_values((array)($scope['recent'] ?? []))),
        );
    }

    public function denied_total(): int
    {
        return array_sum($this->denials);
    }

    public function share(): float
    {
        return $this->credits > 0 ? $this->balance / $this->credits : 0.0;
    }
}
