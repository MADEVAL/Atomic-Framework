<?php
declare(strict_types=1);

namespace Tests\Engine\Core\Database\Migrations;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use PHPUnit\Framework\TestCase;
use Tests\Support\MigrationRunner;
use Tests\Support\TestConfig;

/**
 * The framework `updates/` migrations must be idempotent (create-if-not-exists)
 * and reversible.
 *
 * Uses a fixed table prefix because Cortex's resolveConfiguration() memoizes
 * the resolved table name per process — a random prefix per test would leak
 * across tests through that static cache.
 */
final class UpdatesMigrationsTest extends TestCase
{
    private const PREFIX = 'atomic_updates_test_';

    private array $original_state = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!\extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('ext-pdo_mysql not loaded - migration tests cannot run.');
        }
        $cfg = TestConfig::db((array)(App::instance()->get('DB_CONFIG') ?? []));
        try {
            $dsn = \sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['db'], $cfg['charset']);
            new \PDO($dsn, (string)$cfg['username'], (string)$cfg['password']);
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL connection unavailable - migration tests cannot run.');
        }

        $atomic = App::instance();
        $this->original_state = ['DB_CONFIG' => $atomic->get('DB_CONFIG'), 'DB' => $atomic->get('DB')];

        App::instance()->set('DB_CONFIG', TestConfig::db(\array_merge($cfg, ['prefix' => self::PREFIX])));
        ConnectionManager::instance()->close_sql();
        $dsn = \sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['db'], $cfg['charset']);
        App::instance()->set('DB', new \DB\SQL($dsn, (string)$cfg['username'], (string)$cfg['password']));

        $this->drop_all();
        // The sessions migration extends the base sessions table.
        $this->run_file('atomic_create_session_table.php', 'up', 'initial');
    }

    protected function tearDown(): void
    {
        $this->drop_all();
        ConnectionManager::instance()->close_sql();
        foreach ($this->original_state as $key => $value) {
            App::instance()->set($key, $value);
        }
        parent::tearDown();
    }

    private function drop_all(): void
    {
        $db = ConnectionManager::instance()->get_db(false);
        if (!$db instanceof \DB\SQL) {
            return;
        }
        foreach ([
            'telemetry_quota_scopes',
            'telemetry_lockouts',
            'telemetry_rate_keys',
            'telemetry_exception_groups',
            'telemetry_events',
            'telemetry_totals',
            'telemetry_counters',
            'scheduler_tasks',
            'scheduler_runs',
            'sessions',
        ] as $table) {
            $db->exec('DROP TABLE IF EXISTS `' . self::PREFIX . $table . '`');
        }
    }

    private function run_file(string $file, string $direction, string $group = 'updates'): void
    {
        $path = ATOMIC_ENGINE . 'Atomic/Core/Database/Migrations/' . $group . '/' . $file;
        $direction === 'up' ? MigrationRunner::up($path) : MigrationRunner::down($path);
    }

    private function tables(): array
    {
        $db = ConnectionManager::instance()->get_db();
        $rows = (array)$db->exec('SHOW TABLES LIKE ?', [self::PREFIX . '%']);

        return array_map(static fn (array $row): string => (string)array_values($row)[0], $rows);
    }

    public function test_updates_migrations_create_expected_tables_and_are_idempotent(): void
    {
        $this->run_file('1_create_telemetry_stats_tables.php', 'up');
        $this->run_file('2_create_scheduler_stats_tables.php', 'up');

        foreach ([
            self::PREFIX . 'telemetry_counters',
            self::PREFIX . 'telemetry_totals',
            self::PREFIX . 'telemetry_events',
            self::PREFIX . 'telemetry_exception_groups',
            self::PREFIX . 'telemetry_rate_keys',
            self::PREFIX . 'telemetry_lockouts',
            self::PREFIX . 'telemetry_quota_scopes',
            self::PREFIX . 'scheduler_runs',
            self::PREFIX . 'scheduler_tasks',
        ] as $table) {
            self::assertContains($table, $this->tables(), "missing table {$table}");
        }

        // Re-running must be a no-op, not an error.
        $this->run_file('1_create_telemetry_stats_tables.php', 'up');
        $this->run_file('2_create_scheduler_stats_tables.php', 'up');
    }

    public function test_session_user_columns_migration_adds_and_drops_columns(): void
    {
        $db = ConnectionManager::instance()->get_db();
        $this->run_file('3_add_session_user_columns.php', 'up');

        $fields = [];
        foreach ((array)$db->exec('SHOW COLUMNS FROM `' . self::PREFIX . 'sessions`') as $column) {
            $fields[(string)$column['Field']] = true;
        }
        self::assertArrayHasKey('user_uuid', $fields);
        self::assertArrayHasKey('created_at', $fields);

        // Idempotent second run.
        $this->run_file('3_add_session_user_columns.php', 'up');
        self::assertArrayHasKey('user_uuid', $fields);

        $this->run_file('3_add_session_user_columns.php', 'down');
        $fields = [];
        foreach ((array)$db->exec('SHOW COLUMNS FROM `' . self::PREFIX . 'sessions`') as $column) {
            $fields[(string)$column['Field']] = true;
        }
        self::assertArrayNotHasKey('user_uuid', $fields);
        self::assertArrayNotHasKey('created_at', $fields);
    }

    public function test_session_user_columns_migration_skips_when_sessions_table_missing(): void
    {
        // Sessions table is optional (published on demand via db/sessions);
        // a fresh install without it must not fail the whole migrate run.
        $db = ConnectionManager::instance()->get_db();
        $db->exec('DROP TABLE IF EXISTS `' . self::PREFIX . 'sessions`');

        $this->run_file('3_add_session_user_columns.php', 'up');

        self::assertNotContains(self::PREFIX . 'sessions', $this->tables());
    }

    public function test_session_table_created_after_updates_has_user_columns(): void
    {
        // db/sessions can be run after the updates group was applied; the
        // freshly created table must already carry the telemetry columns.
        $db = ConnectionManager::instance()->get_db();
        $db->exec('DROP TABLE IF EXISTS `' . self::PREFIX . 'sessions`');
        $this->run_file('atomic_create_session_table.php', 'up', 'initial');

        $fields = [];
        foreach ((array)$db->exec('SHOW COLUMNS FROM `' . self::PREFIX . 'sessions`') as $column) {
            $fields[(string)$column['Field']] = true;
        }
        self::assertArrayHasKey('user_uuid', $fields);
        self::assertArrayHasKey('created_at', $fields);

        // One user can hold many sessions — the owner index must not be unique.
        $index = (array)$db->exec('SHOW INDEX FROM `' . self::PREFIX . 'sessions` WHERE Column_name = ?', ['user_uuid']);
        self::assertNotEmpty($index);
        self::assertSame(1, (int)$index[0]['Non_unique']);
    }
}
