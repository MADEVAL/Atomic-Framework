<?php
declare(strict_types=1);

namespace Tests\Support;

/**
 * Named spend plans from docs/plans/quota-test-spec.md §3.2.
 *
 * @phpstan-type Tier array{
 *     credits: int,
 *     period: int,
 *     reservation_ttl: int,
 *     pacing: list<array{limit: int, window: int}>,
 *     operations: array<string, array{cost: int, pacing?: list<array{limit: int, window: int}>}>
 * }
 */
final class QuotaPlans
{
    public const OPERATIONS = ['spam_check', 'seo_headline'];

    /** @return list<string> */
    public static function operations(): array
    {
        return self::OPERATIONS;
    }

    /** Hive with no operations and no tiers. */
    public static function p0(): array
    {
        return ['operations' => [], 'quotas' => []];
    }

    /** @return Tier */
    public static function p1(): array
    {
        return [
            'credits' => 10,
            'period' => 3600,
            'reservation_ttl' => 300,
            'pacing' => [],
            'operations' => ['spam_check' => ['cost' => 1]],
        ];
    }

    /** @return Tier */
    public static function p2(): array
    {
        return [
            'credits' => 500,
            'period' => 2592000,
            'reservation_ttl' => 300,
            'pacing' => [['limit' => 20, 'window' => 60]],
            'operations' => ['spam_check' => ['cost' => 1]],
        ];
    }

    /** @return Tier */
    public static function p3(): array
    {
        return [
            'credits' => 20000,
            'period' => 2592000,
            'reservation_ttl' => 300,
            'pacing' => [
                ['limit' => 200, 'window' => 60],
                ['limit' => 2000, 'window' => 86400],
            ],
            'operations' => [
                'spam_check' => ['cost' => 1],
                'seo_headline' => [
                    'cost' => 5,
                    'pacing' => [['limit' => 30, 'window' => 3600]],
                ],
            ],
        ];
    }

    /** @return Tier */
    public static function p4(): array
    {
        return [
            'credits' => 1000,
            'period' => 7200,
            'reservation_ttl' => 300,
            'pacing' => [['limit' => 4, 'window' => 60]],
            'operations' => ['spam_check' => ['cost' => 2]],
        ];
    }

    /** @return Tier */
    public static function p5(): array
    {
        return [
            'credits' => 1000,
            'period' => 7200,
            'reservation_ttl' => 300,
            'pacing' => [['limit' => 100, 'window' => 60]],
            'operations' => [
                'spam_check' => [
                    'cost' => 1,
                    'pacing' => [['limit' => 1, 'window' => 3600]],
                ],
            ],
        ];
    }

    /** @return Tier */
    public static function p6(): array
    {
        return [
            'credits' => 0,
            'period' => 3600,
            'reservation_ttl' => 300,
            'pacing' => [],
            'operations' => ['spam_check' => ['cost' => 1]],
        ];
    }

    /** @return Tier */
    public static function p7(): array
    {
        return [
            'credits' => 100,
            'period' => 3600,
            'reservation_ttl' => 1,
            'pacing' => [['limit' => 10, 'window' => 60]],
            'operations' => ['spam_check' => ['cost' => 5]],
        ];
    }

    /** @return Tier */
    public static function p8(): array
    {
        return [
            'credits' => 100,
            'period' => 1,
            'reservation_ttl' => 300,
            'pacing' => [],
            'operations' => ['spam_check' => ['cost' => 40]],
        ];
    }

    /** @return Tier */
    public static function p9(): array
    {
        return [
            'credits' => 100,
            'period' => 3600,
            'reservation_ttl' => 300,
            'pacing' => [['limit' => 10, 'window' => 60]],
            'operations' => [
                'spam_check' => ['cost' => 3],
                'seo_headline' => ['cost' => 5],
            ],
        ];
    }

    /**
     * @param array<string, Tier> $quotas
     * @return array{operations: list<string>, quotas: array<string, Tier>}
     */
    public static function hive(array $quotas, ?array $operations = null): array
    {
        return [
            'operations' => $operations ?? self::OPERATIONS,
            'quotas' => $quotas,
        ];
    }

    /** Documented free + pro example. */
    public static function docs_hive(): array
    {
        return self::hive([
            'free' => self::p2(),
            'pro' => self::p3(),
        ]);
    }
}
