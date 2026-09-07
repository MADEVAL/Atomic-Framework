<?php
declare(strict_types=1);

namespace Engine\Atomic\Core\Migrations;

if (!defined('ATOMIC_START')) exit;

class MigrationHistory
{
    public function find_applied_row(array $migration, array $rows, array $migrations): ?object
    {
        foreach ($rows as $row) {
            $source = (string)($row->source ?? '');
            if ($source === $migration['source'] && (string)$row->migration === $migration['migration']) {
                return $row;
            }
            if ($source === '' && $this->legacy_row_matches($row, $migration)) {
                return $row;
            }
        }

        if ($migration['source'] === FrameworkMigrationGroups::APP) {
            foreach ($migrations as $owner) {
                if ($owner['source'] === FrameworkMigrationGroups::APP || !$this->published_name_matches($migration['migration'], $owner['migration'])) {
                    continue;
                }
                foreach ($rows as $row) {
                    if (
                        (string)($row->source ?? '') === $owner['source']
                        && (string)$row->migration === $owner['migration']
                    ) {
                        return $row;
                    }
                }
            }
        }

        return null;
    }

    public function resolve_applied_migration(object $row, array $migrations): ?array
    {
        $source = (string)($row->source ?? '');
        foreach ($migrations as $migration) {
            if ($source !== '') {
                if ($source === $migration['source'] && (string)$row->migration === $migration['migration']) {
                    return $migration;
                }
                continue;
            }
            if ($this->legacy_row_matches($row, $migration)) {
                return $migration;
            }
        }
        return null;
    }

    public function resolve_legacy_migration(string $legacy_name, array $migrations): ?array
    {
        $published = $this->resolve_sourced_migration(FrameworkMigrationGroups::FRAMEWORK, $legacy_name, $migrations);
        if ($published !== null) {
            return $published;
        }
        $owned = array_values(array_filter(
            $migrations,
            fn(array $migration): bool => $migration['source'] !== FrameworkMigrationGroups::APP
                && $this->published_name_matches($legacy_name, $migration['migration']),
        ));
        if (count($owned) > 1) {
            $sources = implode(', ', array_column($owned, 'source'));
            throw new \RuntimeException("Legacy migration '{$legacy_name}' has ambiguous owners: {$sources}.");
        }
        if ($owned !== []) {
            return $owned[0];
        }

        return $this->resolve_sourced_migration(FrameworkMigrationGroups::APP, $legacy_name, $migrations);
    }

    public function resolve_sourced_migration(string $source, string $name, array $migrations): ?array
    {
        foreach ($migrations as $migration) {
            if ($migration['source'] === $source && $migration['migration'] === $name) {
                return $migration;
            }
        }
        return null;
    }

    /** @return list<array{row: object, migration: array}> */
    public function find_checksum_mismatches(array $migrations, array $rows): array
    {
        $mismatches = [];
        foreach ($rows as $row) {
            if ((string)($row->checksum ?? '') === '') {
                continue;
            }
            $migration = $this->resolve_applied_migration($row, $migrations);
            if ($migration === null) {
                if (in_array((string)($row->source ?? ''), [FrameworkMigrationGroups::FRAMEWORK, FrameworkMigrationGroups::APP], true)) {
                    throw new \RuntimeException("Migration file for '{$row->source}:{$row->migration}' is unavailable.");
                }
                continue;
            }
            if (!$this->checksum_matches($row, $migration)) {
                $mismatches[] = ['row' => $row, 'migration' => $migration];
            }
        }
        return $mismatches;
    }

    public function checksum_matches(object $row, array $migration): bool
    {
        $stored = (string)($row->checksum ?? '');
        return $stored === '' || hash_equals($stored, $migration['checksum']);
    }

    public function published_name_matches(string $published_name, string $owned_name): bool
    {
        return (bool)preg_match('/^\d{14}_' . preg_quote($owned_name, '/') . '$/', $published_name);
    }

    private function legacy_row_matches(object $row, array $migration): bool
    {
        $legacy_name = (string)$row->migration;
        if ($migration['source'] === FrameworkMigrationGroups::FRAMEWORK && $legacy_name === $migration['migration']) {
            return true;
        }
        return $migration['source'] === FrameworkMigrationGroups::APP
            ? $legacy_name === $migration['migration']
            : $this->published_name_matches($legacy_name, $migration['migration']);
    }
}
