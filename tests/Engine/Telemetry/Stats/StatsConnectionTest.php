<?php
declare(strict_types=1);

namespace Tests\Engine\Telemetry\Stats;

use Engine\Atomic\Core\App;
use Engine\Atomic\Telemetry\Stats\StatsConnection;
use Tests\Engine\Telemetry\Support\StatsDbTestCase;

final class StatsConnectionTest extends StatsDbTestCase
{
    public function test_needs_migration_is_false_when_tables_exist(): void
    {
        self::assertFalse(StatsConnection::needs_migration());
    }

    public function test_needs_migration_is_true_when_stats_tables_are_missing(): void
    {
        $atomic = App::instance();
        $prefix = $atomic->get('DB_CONFIG.prefix');
        try {
            $atomic->set('DB_CONFIG.prefix', 'nostats_' . bin2hex(random_bytes(4)) . '_');
            StatsConnection::reset();

            self::assertTrue(StatsConnection::needs_migration());
        } finally {
            $atomic->set('DB_CONFIG.prefix', $prefix);
            StatsConnection::reset();
        }
    }

    public function test_needs_migration_is_false_when_stats_are_disabled(): void
    {
        $atomic = App::instance();
        $prefix = $atomic->get('DB_CONFIG.prefix');
        try {
            $atomic->set('DB_CONFIG.prefix', 'nostats_' . bin2hex(random_bytes(4)) . '_');
            $atomic->set('TELEMETRY_STATS_ENABLED', false);
            StatsConnection::reset();

            self::assertFalse(StatsConnection::needs_migration());
        } finally {
            $atomic->set('DB_CONFIG.prefix', $prefix);
            StatsConnection::reset();
        }
    }
}
