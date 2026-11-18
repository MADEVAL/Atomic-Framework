<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class MigrationsReport
{
    /** @param list<MigrationEntry> $entries */
    public function __construct(
        public readonly bool $legacy_mode,
        public readonly array $entries,
    ) {}

    /** @return list<MigrationEntry> */
    public function pending(): array
    {
        return array_values(array_filter($this->entries, static fn (MigrationEntry $entry): bool => $entry->is_pending()));
    }

    /** @return array{legacy_mode:bool, entries:list<array<string, mixed>>} */
    public function to_array(): array
    {
        return [
            'legacy_mode' => $this->legacy_mode,
            'entries' => array_map(static fn (MigrationEntry $entry): array => $entry->to_array(), $this->entries),
        ];
    }
}
