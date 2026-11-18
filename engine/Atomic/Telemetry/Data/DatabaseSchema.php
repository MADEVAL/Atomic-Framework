<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class DatabaseSchema
{
    /** @param list<DbTable> $tables */
    public function __construct(
        public readonly bool $available,
        public readonly ?string $reason,
        public readonly ?string $database,
        public readonly ?string $host,
        public readonly string $prefix,
        public readonly array $tables,
    ) {}

    /** @param array<string, mixed> $report DatabaseReporter::report() */
    public static function from_array(array $report): self
    {
        return new self(
            available: (bool)($report['available'] ?? false),
            reason: isset($report['reason']) && $report['reason'] !== '' ? (string)$report['reason'] : null,
            database: isset($report['database']) ? (string)$report['database'] : null,
            host: isset($report['host']) && $report['host'] !== '' ? (string)$report['host'] : null,
            prefix: (string)($report['prefix'] ?? ''),
            tables: array_map(DbTable::from_array(...), array_values((array)($report['tables'] ?? []))),
        );
    }

    public function in_app(string $table): bool
    {
        return $this->prefix !== '' && str_starts_with($table, $this->prefix);
    }

    public function short_name(string $table): string
    {
        return $this->in_app($table) ? substr($table, strlen($this->prefix)) : $table;
    }

    /** @return list<DbTable> the tables named with the app prefix (none without a prefix) */
    public function app_tables(): array
    {
        return array_values(array_filter($this->tables, fn (DbTable $table): bool => $this->in_app($table->name)));
    }
}
