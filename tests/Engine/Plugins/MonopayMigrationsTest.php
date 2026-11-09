<?php
declare(strict_types=1);

namespace Tests\Engine\Plugins;

use Engine\Atomic\Plugins\Monopay\Models\Payment;
use Engine\Atomic\Plugins\Monopay\Models\PaymentHistory;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Migrations\Migration;
use Engine\Atomic\Core\Migrations\MigrationContext;
use Engine\Atomic\Core\Migrations\MigrationExecutor;
use PHPUnit\Framework\TestCase;
use Tests\Support\StreamCapture;

/**
 * Guards the schema upgrade path for installs that already ran the shipped
 * create migration before the idempotency columns existed.
 *
 * The four payment columns (invoice_pending_at, idempotency_key,
 * create_claim_at, reconciled_at) and the unique idempotency_key index MUST live in their
 * own migration: editing an already-applied create migration never reaches
 * existing databases, and Payment/Order would then fail at runtime.
 */
final class MonopayMigrationsTest extends TestCase
{
    private const CREATE_MIGRATION = 'monopay_create_payments_tables.php';
    private const UPGRADE_MIGRATION = 'monopay_upgrade_payments_idempotency.php';

    private const UPGRADED_COLUMNS = [
        'invoice_pending_at',
        'idempotency_key',
        'create_claim_at',
        'reconciled_at',
    ];

    protected function setUp(): void
    {
        if (\Base::instance()->get('DB') === null) {
            $this->markTestSkipped('MySQL is not available.');
        }

        $this->drop_plugin_tables();
    }

    protected function tearDown(): void
    {
        if (\Base::instance()->get('DB') === null) {
            return;
        }

        $this->drop_plugin_tables();
    }

    /**
     * State of an existing install: only the original create migration ran.
     */
    public function test_upgrade_adds_the_columns_missing_from_an_existing_install(): void
    {
        $table = $this->payments_table();

        $this->run_up(self::CREATE_MIGRATION);

        $columns = $this->column_names($table);
        foreach (self::UPGRADED_COLUMNS as $column) {
            $this->assertNotContains($column, $columns, "The shipped create migration must not contain '{$column}'.");
        }
        $this->assertFalse(
            $this->has_unique_index($table, ['idempotency_key']),
            'The shipped create migration must not create the idempotency_key unique index.'
        );

        $this->run_up(self::UPGRADE_MIGRATION);

        $columns = $this->column_names($table);
        foreach (self::UPGRADED_COLUMNS as $column) {
            $this->assertContains($column, $columns, "The upgrade migration must add '{$column}'.");
        }

        foreach (self::UPGRADED_COLUMNS as $column) {
            $this->assertTrue(
                $this->column_is_nullable($table, $column),
                "'{$column}' must be nullable so rows created before the upgrade stay valid."
            );
        }

        $this->assertTrue(
            $this->has_unique_index($table, ['idempotency_key']),
            'The upgrade migration must create a UNIQUE index on idempotency_key.'
        );
    }

    /**
     * Migrations must be retryable: a failed history write re-runs the
     * callback on the next attempt.
     */
    public function test_upgrade_is_safe_to_run_twice(): void
    {
        $table = $this->payments_table();

        $this->run_up(self::CREATE_MIGRATION);
        $this->run_up(self::UPGRADE_MIGRATION);
        $this->run_up(self::UPGRADE_MIGRATION);

        foreach (self::UPGRADED_COLUMNS as $column) {
            $this->assertContains($column, $this->column_names($table));
        }

        $this->assertSame(
            1,
            $this->unique_index_count($table, ['idempotency_key']),
            'Re-running the upgrade must not add a second idempotency_key index.'
        );
    }

    public function test_down_removes_the_upgraded_columns_and_index(): void
    {
        $table = $this->payments_table();

        $this->run_up(self::CREATE_MIGRATION);
        $this->run_up(self::UPGRADE_MIGRATION);

        $this->run_down(self::UPGRADE_MIGRATION);

        $columns = $this->column_names($table);
        foreach (self::UPGRADED_COLUMNS as $column) {
            $this->assertNotContains($column, $columns, "down() must drop '{$column}'.");
        }

        $this->assertFalse(
            $this->has_unique_index($table, ['idempotency_key']),
            'down() must drop the idempotency_key unique index.'
        );

        $this->assertContains('status', $columns, 'down() must only remove the upgrade, not the table.');
    }

    /**
     * Dev databases created from the edited (in-branch) create migration may
     * already carry some of the columns; the upgrade must only add the rest.
     */
    public function test_upgrade_adds_only_the_missing_columns_on_a_partially_migrated_table(): void
    {
        $table = $this->payments_table();

        $this->run_up(self::CREATE_MIGRATION);

        $db = \Base::instance()->get('DB');
        $db->exec("ALTER TABLE `{$table}` ADD COLUMN `create_claim_at` TIMESTAMP NULL");

        $this->run_up(self::UPGRADE_MIGRATION);

        $columns = $this->column_names($table);
        foreach (self::UPGRADED_COLUMNS as $column) {
            $this->assertContains($column, $columns);
        }

        $this->assertTrue($this->has_unique_index($table, ['idempotency_key']));
    }

    /**
     * Idempotency keys are opaque tokens: they must compare byte-exactly.
     * The connection default collation (utf8mb4_general_ci) folds case, so
     * the upgrade must pin the column to utf8mb4_bin.
     */
    public function test_upgrade_gives_idempotency_key_a_byte_exact_collation(): void
    {
        $table = $this->payments_table();

        $this->run_up(self::CREATE_MIGRATION);
        $this->run_up(self::UPGRADE_MIGRATION);

        $this->assertSame(
            'utf8mb4_bin',
            $this->column_collation($table, 'idempotency_key'),
            'idempotency_key must use utf8mb4_bin so keys differing only by case stay distinct.'
        );
        $this->assertTrue($this->has_unique_index($table, ['idempotency_key']));
    }

    /**
     * Dev databases already upgraded from this branch carry the column with
     * the connection's case-folding collation. The upgrade must convert it
     * (and keep the unique index) instead of only handling fresh installs.
     */
    public function test_upgrade_converts_an_existing_case_insensitive_idempotency_key_column(): void
    {
        $table = $this->payments_table();

        $this->run_up(self::CREATE_MIGRATION);

        $db = \Base::instance()->get('DB');
        $db->exec(
            "ALTER TABLE `{$table}` ADD COLUMN `idempotency_key` VARCHAR(255)"
            . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL'
        );
        $db->exec("ALTER TABLE `{$table}` ADD UNIQUE INDEX legacy_idempotency_key (`idempotency_key`)");
        $db->exec("INSERT INTO `{$table}` (uuid, amount, idempotency_key) VALUES (?, ?, ?)", ['existing-key-payment', '1.00', 'existing-key']);
        $this->assertSame('utf8mb4_general_ci', $this->column_collation($table, 'idempotency_key'));

        $this->run_up(self::UPGRADE_MIGRATION);
        $this->assertSame('existing-key', $db->exec("SELECT idempotency_key FROM `{$table}` WHERE uuid = ?", ['existing-key-payment'])[0]['idempotency_key']);
        $this->assertSame(1, $this->unique_index_count($table, ['idempotency_key']));

        $this->assertSame(
            'utf8mb4_bin',
            $this->column_collation($table, 'idempotency_key'),
            'an already-upgraded column with a case-folding collation must be converted.'
        );
        $this->assertTrue(
            $this->has_unique_index($table, ['idempotency_key']),
            'the unique index must survive the collation conversion.'
        );
    }

    public function test_fresh_database_runs_legacy_create_then_class_upgrade(): void
    {
        $this->assertInstanceOf(Migration::class, require $this->migration_path(self::UPGRADE_MIGRATION));
        $this->run_up(self::CREATE_MIGRATION);
        $this->run_up(self::UPGRADE_MIGRATION);

        $table = $this->payments_table();
        foreach (self::UPGRADED_COLUMNS as $column) {
            $this->assertContains($column, $this->column_names($table));
            $this->assertTrue($this->column_is_nullable($table, $column));
        }
        $this->assertSame('utf8mb4_bin', $this->column_collation($table, 'idempotency_key'));
        $this->assertSame(1, $this->unique_index_count($table, ['idempotency_key']));
        $this->assert_key_constraints($table);
    }

    public function test_upgrade_preserves_existing_payment_data_and_indexes(): void
    {
        $this->run_up(self::CREATE_MIGRATION);
        $table = $this->payments_table();
        $db = \Base::instance()->get('DB');
        $db->exec("INSERT INTO `{$table}` (uuid, invoice_id, amount) VALUES (?, ?, ?)", ['legacy-payment', 'legacy-invoice', '12.34']);
        $before = $db->exec("SELECT * FROM `{$table}`")[0];
        $indexes = $db->exec("SHOW INDEX FROM `{$table}`");
        $this->run_up(self::UPGRADE_MIGRATION);
        $after = $db->exec("SELECT * FROM `{$table}` WHERE uuid = ?", ['legacy-payment'])[0];
        foreach ($before as $column => $value) {
            $this->assertSame($value, $after[$column]);
        }
        foreach (self::UPGRADED_COLUMNS as $column) {
            $this->assertNull($after[$column]);
        }
        $after_indexes = $db->exec("SHOW INDEX FROM `{$table}`");
        foreach ($indexes as $index) {
            $this->assertContains($index['Key_name'], array_column($after_indexes, 'Key_name'));
        }
        $this->assert_key_constraints($table);
    }

    public function test_rollback_can_be_retried_and_upgrade_can_run_again(): void
    {
        $this->run_up(self::CREATE_MIGRATION);
        $this->run_up(self::UPGRADE_MIGRATION);
        $this->run_down(self::UPGRADE_MIGRATION);
        $this->run_down(self::UPGRADE_MIGRATION);
        $this->run_up(self::UPGRADE_MIGRATION);
        $this->assertSame(1, $this->unique_index_count($this->payments_table(), ['idempotency_key']));
    }

    public function test_upgrade_and_rollback_skip_a_missing_table(): void
    {
        $this->run_up(self::UPGRADE_MIGRATION);
        $this->run_down(self::UPGRADE_MIGRATION);
        $tables = (new \DB\Cortex\Schema\Schema(\Base::instance()->get('DB')))->getTables();
        $this->assertNotContains($this->payments_table(), $tables);
    }

    private function assert_key_constraints(string $table): void
    {
        $db = \Base::instance()->get('DB');
        foreach (['Order-A', 'order-a', null, null] as $offset => $key) {
            $db->exec("INSERT INTO `{$table}` (uuid, amount, idempotency_key) VALUES (?, ?, ?)", ['key-test-' . $offset, '1.00', $key]);
        }
        $this->assertSame(4, (int)$db->exec("SELECT COUNT(*) AS n FROM `{$table}` WHERE uuid LIKE 'key-test-%'")[0]['n']);
        try {
            $db->exec("INSERT INTO `{$table}` (uuid, amount, idempotency_key) VALUES (?, ?, ?)", ['duplicate-key', '1.00', 'Order-A']);
            $this->fail('The database must reject a duplicate non-null idempotency key.');
        } catch (\PDOException $e) {
            $this->assertSame('23000', (string)$e->getCode());
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function payments_table(): string
    {
        return Payment::resolveConfiguration()['table'];
    }

    private function history_table(): string
    {
        return PaymentHistory::resolveConfiguration()['table'];
    }

    private function migration_path(string $file): string
    {
        return ATOMIC_ENGINE . 'Atomic/Plugins/Monopay/Migrations/' . $file;
    }

    private function executor(): MigrationExecutor
    {
        return new MigrationExecutor(new MigrationContext(
            App::instance(),
            ConnectionManager::instance(),
            new Output(StreamCapture::memory('w+b'), StreamCapture::memory('w+b')),
        ));
    }

    private function migration(string $file): array
    {
        $path = $this->migration_path($file);
        $this->assertFileExists($path);
        return ['source' => 'plugin:Monopay', 'migration' => basename($file, '.php'), 'path' => $path];
    }

    private function run_up(string $file): void
    {
        $this->executor()->up($this->migration($file));
    }

    private function run_down(string $file): void
    {
        $this->executor()->down($this->migration($file));
    }

    private function drop_plugin_tables(): void
    {
        $db = \Base::instance()->get('DB');
        if ($db === null) {
            return;
        }

        $db->exec('DROP TABLE IF EXISTS `' . $this->history_table() . '`');
        $db->exec('DROP TABLE IF EXISTS `' . $this->payments_table() . '`');
    }

    /** @return list<string> */
    private function column_names(string $table): array
    {
        $rows = (array)\Base::instance()->get('DB')->exec("SHOW COLUMNS FROM `{$table}`");

        return array_map(static fn(array $row): string => (string)$row['Field'], $rows);
    }

    private function column_is_nullable(string $table, string $column): bool
    {
        $rows = (array)\Base::instance()->get('DB')->exec("SHOW COLUMNS FROM `{$table}`");
        foreach ($rows as $row) {
            if ((string)$row['Field'] === $column) {
                return strtoupper((string)$row['Null']) === 'YES';
            }
        }

        return false;
    }

    private function column_collation(string $table, string $column): ?string
    {
        $rows = (array)\Base::instance()->get('DB')->exec(
            'SELECT COLLATION_NAME FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        $row = array_change_key_case((array)($rows[0] ?? []), CASE_LOWER);

        return isset($row['collation_name']) ? (string)$row['collation_name'] : null;
    }

    private function unique_index_count(string $table, array $columns): int
    {
        $count = 0;
        foreach ($this->unique_indexes($table) as $indexed_columns) {
            if ($indexed_columns === $columns) {
                $count++;
            }
        }

        return $count;
    }

    private function has_unique_index(string $table, array $columns): bool
    {
        return $this->unique_index_count($table, $columns) > 0;
    }

    /** @return list<list<string>> */
    private function unique_indexes(string $table): array
    {
        $rows = (array)\Base::instance()->get('DB')->exec("SHOW INDEX FROM `{$table}`");

        $indexes = [];
        foreach ($rows as $row) {
            if ((int)$row['Non_unique'] !== 0) {
                continue;
            }
            $indexes[(string)$row['Key_name']][(int)$row['Seq_in_index']] = (string)$row['Column_name'];
        }

        $result = [];
        foreach ($indexes as $index_columns) {
            ksort($index_columns);
            $result[] = array_values($index_columns);
        }

        return $result;
    }
}
