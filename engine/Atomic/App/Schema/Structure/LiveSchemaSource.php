<?php
declare(strict_types=1);
namespace Engine\Atomic\App\Schema\Structure;
if (!defined('ATOMIC_START')) exit;

use DB\Cortex\Schema\ColumnType;
use Engine\Atomic\Core\ConnectionManager;

/** Reads the same complete schema and cache entry as Cortex's SQL mapper. */
final class LiveSchemaSource
{
    private array $cache_keys = [];

    public function invalidate(): void
    {
        foreach ($this->cache_keys as $key => $mapper_key) {
            // These entries belong to F3, so invalidate its cache directly.
            \Cache::instance()->clear($key);
            unset(\DB\Cortex::$schema_cache[$mapper_key]);
        }
    }

    public function for_table(string $table, int $ttl = 60): SchemaStructure
    {
        $db = ConnectionManager::instance()->get_db();
        $dsn = (new \ReflectionProperty(\DB\SQL::class, 'dsn'))->getValue($db);
        $key = \Base::instance()->hash($dsn . $table) . '.schema';
        $this->cache_keys[$key] = $table . '_' . $db->uuid();
        // PostgreSQL/SQL Server report bare type names here; restore lengths and precision.
        $columns = ColumnType::withDeclaredTypes($db, $table, $db->schema($table, null, $ttl));
        return SchemaStructure::from_columns($table, $columns, $db->driver());
    }
}
