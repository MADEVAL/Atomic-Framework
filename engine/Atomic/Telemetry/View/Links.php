<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\View;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Telemetry\Data\MigrationEntry;

final class Links
{
    public function __construct(public readonly string $base) {}

    public function part(string $name, array $query = []): string
    {
        return $this->base . '/part/' . $name . ($query ? '?' . http_build_query($query) : '');
    }

    public function migration_source(MigrationEntry $entry): string
    {
        return $this->part('migration-source', ['source' => $entry->source, 'name' => $entry->name]);
    }
}
