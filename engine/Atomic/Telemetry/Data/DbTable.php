<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class DbTable
{
    /**
     * @param list<DbColumn> $columns
     * @param list<DbIndex> $indexes
     * @param list<DbForeignKey> $references keys of this table
     * @param list<DbForeignKey> $referenced_by keys of other tables pointing here
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $engine,
        public readonly ?int $rows,
        public readonly int $size,
        public readonly ?string $collation,
        public readonly string $comment,
        public readonly array $columns,
        public readonly array $indexes,
        public readonly array $references,
        public readonly array $referenced_by,
    ) {}

    /** @param array<string, mixed> $table */
    public static function from_array(array $table): self
    {
        $list = static fn (string $key, callable $make): array => array_map($make, array_values((array)($table[$key] ?? [])));

        return new self(
            name: (string)($table['name'] ?? ''),
            engine: isset($table['engine']) ? (string)$table['engine'] : null,
            rows: isset($table['rows']) ? (int)$table['rows'] : null,
            size: (int)($table['size'] ?? 0),
            collation: isset($table['collation']) ? (string)$table['collation'] : null,
            comment: (string)($table['comment'] ?? ''),
            columns: $list('columns', DbColumn::from_array(...)),
            indexes: $list('indexes', DbIndex::from_array(...)),
            references: $list('references', DbForeignKey::from_array(...)),
            referenced_by: $list('referenced_by', DbForeignKey::from_array(...)),
        );
    }

    public function has_relations(): bool
    {
        return $this->references || $this->referenced_by;
    }

    public function schema_text(): string
    {
        return $this->name . "\n" . implode("\n", array_map(static fn (DbColumn $column): string => '  ' . $column->definition(), $this->columns))
            . ($this->indexes ? "\n\nIndexes\n" . implode("\n", array_map(static fn (DbIndex $index): string => $index->definition(), $this->indexes)) : '')
            . ($this->references ? "\n\nForeign keys\n" . implode("\n", array_map(static fn (DbForeignKey $key): string => $key->definition(), $this->references)) : '');
    }
}
