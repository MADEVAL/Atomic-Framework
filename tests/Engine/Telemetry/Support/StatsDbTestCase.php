<?php
declare(strict_types=1);

namespace Tests\Engine\Telemetry\Support;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Scheduler\RunLogger;
use Engine\Atomic\Telemetry\Stats\StatsConnection;
use Engine\Atomic\Telemetry\Stats\StatsRecorder;
use PHPUnit\Framework\TestCase;
use Tests\Support\MigrationRunner;
use Tests\Support\TestConfig;

/**
 * DB-backed base for statistics tests: isolated random table prefix, the
 * telemetry statistics + scheduler migrations applied, and all per-process
 * stats caches reset. Skips when MySQL is unavailable.
 */
abstract class StatsDbTestCase extends TestCase
{
    private const UPDATES = ATOMIC_ENGINE . 'Atomic/Core/Database/Migrations/updates/';

    private array $original_state = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->backup_state();
        $this->connect_pdo_or_skip();
        $this->configure_db($this->new_db_prefix());
        $this->migrate_stats_up();
        $this->reset_stats_state();
    }

    protected function tearDown(): void
    {
        $this->migrate_stats_down();
        ConnectionManager::instance()->close_sql();
        $this->restore_state();
        $this->reset_stats_state();
        parent::tearDown();
    }

    protected function reset_stats_state(): void
    {
        StatsRecorder::instance()->reset();
        StatsConnection::reset();
        RunLogger::reset();
        App::instance()->set('TELEMETRY_STATS_ENABLED', true);
    }

    private function backup_state(): void
    {
        $atomic = App::instance();
        $this->original_state = [
            'DB_CONFIG' => $atomic->get('DB_CONFIG'),
            'DB' => $atomic->get('DB'),
        ];
    }

    private function restore_state(): void
    {
        $atomic = App::instance();
        foreach ($this->original_state as $key => $value) {
            $atomic->set($key, $value);
        }
    }

    private function connect_pdo_or_skip(): void
    {
        if (!\extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('ext-pdo_mysql not loaded - stats tests cannot run.');
        }
        $cfg = TestConfig::db((array)(App::instance()->get('DB_CONFIG') ?? []));
        try {
            $dsn = \sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['db'], $cfg['charset']);
            new \PDO($dsn, (string)$cfg['username'], (string)$cfg['password']);
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL connection unavailable - stats tests cannot run.');
        }
    }

    private function configure_db(string $prefix): void
    {
        $cfg = TestConfig::db((array)(App::instance()->get('DB_CONFIG') ?? []));
        App::instance()->set('DB_CONFIG', TestConfig::db(\array_merge($cfg, [
            'prefix' => $prefix,
        ])));
        ConnectionManager::instance()->close_sql();
        $dsn = \sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['db'], $cfg['charset']);
        App::instance()->set('DB', new \DB\SQL($dsn, (string)$cfg['username'], (string)$cfg['password']));
    }

    private function new_db_prefix(): string
    {
        return 'atomic_stats_test_' . \bin2hex(\random_bytes(4)) . '_';
    }

    protected function migrate_stats_up(): void
    {
        MigrationRunner::up(self::UPDATES . '1_create_telemetry_stats_tables.php');
        MigrationRunner::up(self::UPDATES . '2_create_scheduler_stats_tables.php');
    }

    protected function migrate_stats_down(): void
    {
        MigrationRunner::down(self::UPDATES . '2_create_scheduler_stats_tables.php');
        MigrationRunner::down(self::UPDATES . '1_create_telemetry_stats_tables.php');
    }
}
