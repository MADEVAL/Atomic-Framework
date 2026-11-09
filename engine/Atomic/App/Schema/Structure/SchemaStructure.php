<?php
declare(strict_types=1);
namespace Engine\Atomic\App\Schema\Structure;

if (!defined('ATOMIC_START')) exit;

use DB\Cortex\Schema\ColumnType;

/** Immutable view of live database columns, including primary-key metadata. */
final class SchemaStructure
{
    /** @var array<string, SchemaField> */
    private array $fields = [];
    private function __construct(private readonly string $table) {}

    public static function from_columns(string $table, array $columns, ?string $driver = null): self
    {
        $structure = new self($table);
        foreach ($columns as $name => $column) {
            try {
                $descriptor = ColumnType::describe((string) $column['type'], $table, (string) $name, $driver);
            } catch (\InvalidArgumentException) {
                $descriptor = ['type'=>'DOMAIN_UNTYPED', 'column_kind'=>'unknown', 'raw_type'=>(string) $column['type']];
            }
            if (isset($column['default'])) $descriptor['has_db_default'] = true;
            if (!empty($column['pkey'])) {
                $descriptor['primary_key'] = true;
                $descriptor['auto_increment'] = !empty($column['auto_inc']);
            }
            $type = $descriptor['type'];
            unset($descriptor['type']);
            // Defaults belong to the SQL mapper, which already reads this schema.
            // In particular, SQL expressions must never become literal model defaults.
            $structure->fields[$name] = new SchemaField((string) $name, $type, (bool) $column['nullable'], $descriptor);
        }
        return $structure;
    }

    public function table(): string
    {
        return $this->table;
    }

    /** @return array<string, SchemaField> */
    public function fields(): array
    {
        return $this->fields;
    }

    public function has(string $field): bool
    {
        return isset($this->fields[$field]);
    }

    public function field(string $field): ?SchemaField
    {
        return $this->fields[$field] ?? null;
    }
}
