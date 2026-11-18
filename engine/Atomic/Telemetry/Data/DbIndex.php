<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class DbIndex
{
    /** @param list<string> $columns */
    public function __construct(
        public readonly string $name,
        public readonly bool $unique,
        public readonly array $columns,
    ) {}

    /** @param array<string, mixed> $index */
    public static function from_array(array $index): self
    {
        return new self(
            (string)($index['name'] ?? ''),
            (bool)($index['unique'] ?? false),
            array_map('strval', array_values((array)($index['columns'] ?? []))),
        );
    }

    public function definition(): string
    {
        return '  ' . $this->name . ($this->unique ? ' UNIQUE' : '') . ' (' . implode(', ', $this->columns) . ')';
    }
}
