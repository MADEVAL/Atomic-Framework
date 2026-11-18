<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class DbForeignKey
{
    /**
     * @param list<string> $columns
     * @param list<string> $ref_columns
     */
    public function __construct(
        public readonly string $table,
        public readonly string $name,
        public readonly array $columns,
        public readonly string $ref_table,
        public readonly array $ref_columns,
        public readonly string $on_update,
        public readonly string $on_delete,
    ) {}

    /** @param array<string, mixed> $key */
    public static function from_array(array $key): self
    {
        return new self(
            table: (string)($key['table'] ?? ''),
            name: (string)($key['name'] ?? ''),
            columns: array_map('strval', array_values((array)($key['columns'] ?? []))),
            ref_table: (string)($key['ref_table'] ?? ''),
            ref_columns: array_map('strval', array_values((array)($key['ref_columns'] ?? []))),
            on_update: (string)($key['on_update'] ?? ''),
            on_delete: (string)($key['on_delete'] ?? ''),
        );
    }

    public function definition(): string
    {
        return '  ' . $this->name . ' (' . implode(', ', $this->columns) . ') -> ' . $this->ref_table
            . ' (' . implode(', ', $this->ref_columns) . ') ON DELETE ' . $this->on_delete . ' ON UPDATE ' . $this->on_update;
    }
}
