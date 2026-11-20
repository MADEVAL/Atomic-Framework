<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Reporters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Telemetry\Data\DatabaseSchema;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Telemetry\Stats\StatsConnection;

/** Reads information_schema only, never table rows. */
class DatabaseReporter
{
    public function schema(): DatabaseSchema
    {
        return DatabaseSchema::from_array($this->report());
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        $config = App::instance()->get('DB_CONFIG');
        $config = is_array($config) ? $config : [];
        $out = [
            'available' => false,
            'reason' => null,
            'driver' => (string)($config['driver'] ?? 'mysql'),
            'database' => null,
            'host' => isset($config['host']) ? (string)$config['host'] . (isset($config['port']) ? ':' . $config['port'] : '') : null,
            'prefix' => (string)($config['prefix'] ?? ''),
            'tables' => [],
        ];

        if ($out['driver'] !== 'mysql') {
            $out['reason'] = 'The schema view supports MySQL only.';
            return $out;
        }

        try {
            $db = ConnectionManager::instance()->get_db(false);
        } catch (\Throwable $e) {
            $db = null;
        }
        if (!$db instanceof \DB\SQL) {
            $out['reason'] = 'The database is not reachable.';
            return $out;
        }

        try {
            $schema = (string)$db->exec('SELECT DATABASE() AS db')[0]['db'];
            $out['database'] = $schema;
            $out['tables'] = $this->tables($db, $schema);
            $out['available'] = true;
        } catch (\Throwable $e) {
            $out['reason'] = 'Could not read the schema: ' . $e->getMessage();
        }

        return $out;
    }

    /** Tables the framework's own migrations create, named as config sets them. */
    private const FRAMEWORK_TABLES = ['migrations', 'jobs', 'jobs_completed', 'jobs_failed', 'telemetry'];
    private const FRAMEWORK_MODELS = [
        \Engine\Atomic\Session\Models\Session::class,
        \Engine\Atomic\App\Models\MutexLock::class,
        \Engine\Atomic\App\Models\Meta::class,
        \Engine\Atomic\App\Models\Options::class,
    ];

    /** @return list<string> */
    private static function framework_tables(string $prefix): array
    {
        $names = self::FRAMEWORK_TABLES;
        foreach ((new \ReflectionClass(StatsConnection::class))->getConstants() as $key => $value) {
            if (str_starts_with($key, 'TABLE_')) {
                $names[] = $value;
            }
        }
        foreach (self::FRAMEWORK_MODELS as $model) {
            // Read the class default, so no model is built or connected.
            $names[] = (string)(new \ReflectionProperty($model, 'table'))->getDefaultValue();
        }

        return array_map(static fn (string $name): string => $prefix . $name, $names);
    }

    /** @return list<array<string, mixed>> */
    private function tables(\DB\SQL $db, string $schema): array
    {
        $framework = self::framework_tables((string)App::instance()->get('DB_CONFIG.prefix'));
        $tables = [];
        foreach ($db->exec(
            'SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS row_estimate, DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes, TABLE_COLLATION AS collation, TABLE_COMMENT AS comment
             FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? ORDER BY TABLE_NAME',
            [$schema, 'BASE TABLE']
        ) as $row) {
            $tables[$row['name']] = [
                'name' => (string)$row['name'],
                'engine' => $row['engine'],
                'rows' => $row['row_estimate'] !== null ? (int)$row['row_estimate'] : null,
                'size' => (int)$row['data_bytes'] + (int)$row['index_bytes'],
                'collation' => $row['collation'],
                'comment' => (string)$row['comment'],
                'framework' => in_array((string)$row['name'], $framework, true),
                'columns' => [],
                'indexes' => [],
                'references' => [],
                'referenced_by' => [],
            ];
        }

        foreach ($db->exec(
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt, COLUMN_KEY AS col_key, EXTRA AS extra
             FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION',
            [$schema]
        ) as $row) {
            if (!isset($tables[$row['t']])) {
                continue;
            }
            $tables[$row['t']]['columns'][] = [
                'name' => (string)$row['name'],
                'type' => (string)$row['type'],
                'nullable' => $row['nullable'] === 'YES',
                // MariaDB reports a NULL default as the string 'NULL'.
                'default' => $row['dflt'] === 'NULL' ? null : $row['dflt'],
                'key' => (string)$row['col_key'],
                'extra' => (string)$row['extra'],
            ];
        }

        $indexes = [];
        foreach ($db->exec(
            'SELECT TABLE_NAME AS t, INDEX_NAME AS name, NON_UNIQUE AS non_unique, COLUMN_NAME AS col
             FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
            [$schema]
        ) as $row) {
            if (!isset($tables[$row['t']])) {
                continue;
            }
            $key = $row['t'] . "\0" . $row['name'];
            $indexes[$key] ??= ['t' => $row['t'], 'name' => (string)$row['name'], 'unique' => (int)$row['non_unique'] === 0, 'columns' => []];
            $indexes[$key]['columns'][] = (string)$row['col'];
        }
        foreach ($indexes as $index) {
            $t = $index['t'];
            unset($index['t']);
            $tables[$t]['indexes'][] = $index;
        }

        $fks = [];
        foreach ($db->exec(
            'SELECT k.TABLE_NAME AS t, k.CONSTRAINT_NAME AS name, k.COLUMN_NAME AS col, k.REFERENCED_TABLE_NAME AS ref_t, k.REFERENCED_COLUMN_NAME AS ref_col, r.UPDATE_RULE AS on_update, r.DELETE_RULE AS on_delete
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
             WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
            [$schema]
        ) as $row) {
            $key = $row['t'] . "\0" . $row['name'];
            $fks[$key] ??= [
                'table' => (string)$row['t'], 'name' => (string)$row['name'], 'columns' => [],
                'ref_table' => (string)$row['ref_t'], 'ref_columns' => [],
                'on_update' => (string)$row['on_update'], 'on_delete' => (string)$row['on_delete'],
            ];
            $fks[$key]['columns'][] = (string)$row['col'];
            $fks[$key]['ref_columns'][] = (string)$row['ref_col'];
        }
        foreach ($fks as $fk) {
            if (isset($tables[$fk['table']])) {
                $tables[$fk['table']]['references'][] = $fk;
            }
            if (isset($tables[$fk['ref_table']])) {
                $tables[$fk['ref_table']]['referenced_by'][] = $fk;
            }
        }

        return array_values($tables);
    }
}
