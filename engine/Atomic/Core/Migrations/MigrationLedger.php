<?php
declare(strict_types=1);

namespace Engine\Atomic\Core\Migrations;

if (!defined('ATOMIC_START')) exit;

use DB\Cortex;
use DB\Cortex\Schema\Schema;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;

class MigrationLedger
{
    public function __construct(
        private readonly App $app,
        private readonly ConnectionManager $connections,
    ) {}

    public function connection(bool $required = true): mixed
    {
        return $this->connections->get_db($required);
    }

    public function table(): string
    {
        return (string)$this->app->get('DB_CONFIG.prefix') . 'migrations';
    }

    public function ensure(): bool
    {
        $db = $this->connection(false);
        if (!$db) {
            return false;
        }

        $this->synchronized(function () use ($db): void {
            $schema = new Schema($db);
            $tables = $schema->getTables();
            if (!is_array($tables) || !in_array($this->table(), $tables, true)) {
                $table = $schema->createTable($this->table());
                $table->addColumn('source')->type_varchar(191)->nullable(false);
                $table->addColumn('migration')->type_varchar(255)->nullable(false);
                $table->addColumn('checksum')->type_varchar(64)->nullable(false);
                $table->addColumn('batch_uuid')->type_varchar(36)->nullable(false);
                $table->addColumn('applied_at')->type_timestamp(true)->nullable(false);
                $table->build();
            } else {
                $columns = $this->table_columns($db);
                if (!isset($columns['source'])) {
                    $db->exec('ALTER TABLE ' . $this->quoted_table() . ' ADD COLUMN `source` VARCHAR(191) NULL AFTER `id`');
                }
                if (!isset($columns['checksum'])) {
                    $db->exec('ALTER TABLE ' . $this->quoted_table() . ' ADD COLUMN `checksum` VARCHAR(64) NULL AFTER `migration`');
                }
            }

            if (!$this->has_identity_index($db)) {
                $db->exec(
                    'ALTER TABLE ' . $this->quoted_table()
                    . ' ADD UNIQUE INDEX `source_migration_unique` (`source`, `migration`)'
                );
            }
        });
        return true;
    }

    public function mapper(): Cortex
    {
        $db = $this->connection();
        $table = $this->table();
        // Ledger DDL can change columns between commands or within this process.
        // Refresh Cortex's table schema from uncached SQL; it overrides the
        // persistent F3 schema cache when Cortex initializes its mapper.
        Cortex::$schema_cache[$table . '_' . $db->uuid()] = $db->schema($table, null, 0);
        return new Cortex($db, $table);
    }

    public function exists(): bool
    {
        return in_array($this->table(), (array)(new Schema($this->connection()))->getTables(), true);
    }

    /** Read legacy history without creating or altering its schema. */
    public function preview_rows(): array
    {
        if (!$this->exists()) {
            return [];
        }
        return array_map(static fn(array $row): object => (object)$row,
            (array)$this->connection()->exec('SELECT * FROM ' . $this->quoted_table() . ' ORDER BY id'));
    }

    /** @return list<string> Schema work proposed by a read-only upgrade preview. */
    public function schema_upgrade_plan(): array
    {
        if (!$this->exists()) {
            return ['Create migration ledger with source/checksum columns and a unique source/migration index.'];
        }
        $db = $this->connection();
        $columns = $this->table_columns($db);
        $plan = [];
        foreach (['source', 'checksum'] as $column) {
            if (!isset($columns[$column])) {
                $plan[] = "Add nullable {$column} column.";
            }
        }
        if (!$this->has_identity_index($db)) {
            $plan[] = 'Add unique source/migration index.';
        }
        if ($this->legacy_schema_columns() !== []) {
            $plan[] = 'Finalize source/checksum as NOT NULL after successful adoption.';
        }
        return $plan;
    }

    public function rows(array $filter = [], array $options = []): array
    {
        return $this->normalize_rows($this->mapper()->find($filter, $options));
    }

    public function latest(): ?object
    {
        $row = $this->mapper()->findone([], ['order' => 'id DESC']);
        return $row ?: null;
    }

    public function count_batch(string $batch_uuid): int
    {
        return (int)$this->mapper()->count(['batch_uuid = ?', $batch_uuid], null, 0);
    }

    public function count(): int
    {
        return (int)$this->mapper()->count(null, null, 0);
    }

    public function is_legacy_mode(): bool
    {
        return $this->legacy_schema_columns() !== [];
    }

    /** @return list<string> */
    public function legacy_schema_columns(): array
    {
        if (!$this->exists()) {
            return ['source', 'checksum'];
        }
        $columns = $this->table_columns($this->connection());
        $legacy = [];
        foreach (['source', 'checksum'] as $column) {
            if (!isset($columns[$column]) || strtoupper((string)($columns[$column]['Null'] ?? 'YES')) !== 'NO') {
                $legacy[] = $column;
            }
        }
        return $legacy;
    }

    public function finalize_schema(): void
    {
        $rows = $this->rows();
        foreach ($rows as $row) {
            if ((string)($row->source ?? '') === '' || (string)($row->checksum ?? '') === '') {
                throw new \RuntimeException('Cannot finalize migration history while source or checksum values are missing.');
            }
        }

        $db = $this->connection();
        $columns = $this->table_columns($db);
        $changes = [];
        if (strtoupper((string)($columns['source']['Null'] ?? 'YES')) !== 'NO') {
            $changes[] = 'MODIFY COLUMN `source` VARCHAR(191) NOT NULL';
        }
        if (strtoupper((string)($columns['checksum']['Null'] ?? 'YES')) !== 'NO') {
            $changes[] = 'MODIFY COLUMN `checksum` VARCHAR(64) NOT NULL';
        }
        if ($changes !== []) {
            $db->exec('ALTER TABLE ' . $this->quoted_table() . ' ' . implode(', ', $changes));
        }
    }

    public function record(array $migration, string $batch_uuid): void
    {
        $mapper = $this->mapper();
        $mapper->source = $migration['source'];
        $mapper->migration = $migration['migration'];
        $mapper->checksum = $migration['checksum'];
        $mapper->batch_uuid = $batch_uuid;
        $mapper->save();
    }

    public function erase(int $id): void
    {
        $this->mapper()->erase(['id = ?', $id]);
    }

    public function adopt(array $adoptions): void
    {
        $db = $this->connection();
        $db->begin();
        try {
            foreach ($adoptions as $adoption) {
                $db->exec(
                    'UPDATE ' . $this->quoted_table()
                    . ' SET `source` = ?, `migration` = ?, `checksum` = ? WHERE `id` = ?',
                    [$adoption['source'], $adoption['migration'], $adoption['checksum'], $adoption['id']]
                );
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    public function synchronized(callable $callback): mixed
    {
        $db = $this->connection();
        $config = (array)$this->app->get('DB_CONFIG');
        $lock_name = 'atomic_migrations_' . substr(
            hash('sha256', (string)($config['db'] ?? '') . "\0" . $this->table()),
            0,
            40
        );
        $result = $db->exec('SELECT GET_LOCK(?, 10) AS `migration_lock`', [$lock_name]);
        if ((int)($result[0]['migration_lock'] ?? 0) !== 1) {
            throw new \RuntimeException('Could not acquire the database migration lock.');
        }
        try {
            return $callback();
        } finally {
            try {
                $db->exec('SELECT RELEASE_LOCK(?)', [$lock_name]);
            } catch (\Throwable) {
            }
        }
    }

    private function normalize_rows(mixed $rows): array
    {
        if ($rows === false || $rows === null) {
            return [];
        }
        if (is_array($rows)) {
            return array_values($rows);
        }
        if ($rows instanceof \Traversable) {
            return array_values(iterator_to_array($rows, false));
        }
        throw new \RuntimeException('Migration ledger returned an unsupported row collection.');
    }

    private function table_columns(object $db): array
    {
        $columns = [];
        foreach ((array)$db->exec('SHOW COLUMNS FROM ' . $this->quoted_table()) as $row) {
            if (isset($row['Field'])) {
                $columns[(string)$row['Field']] = $row;
            }
        }
        return $columns;
    }

    private function has_identity_index(object $db): bool
    {
        $indexes = [];
        foreach ((array)$db->exec('SHOW INDEX FROM ' . $this->quoted_table()) as $row) {
            if ((int)($row['Non_unique'] ?? 1) !== 0) {
                continue;
            }
            $indexes[(string)($row['Key_name'] ?? '')][(int)($row['Seq_in_index'] ?? 0)]
                = (string)($row['Column_name'] ?? '');
        }
        foreach ($indexes as $columns) {
            ksort($columns);
            if (array_values($columns) === ['source', 'migration']) {
                return true;
            }
        }
        return false;
    }

    private function quoted_table(): string
    {
        return '`' . str_replace('`', '``', $this->table()) . '`';
    }
}
