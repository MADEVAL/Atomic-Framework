<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Reporters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Migrations;
use Engine\Atomic\Core\Migrations\MigrationsFactory;
use Engine\Atomic\Telemetry\Data\MigrationEntry;
use Engine\Atomic\Telemetry\Data\MigrationIntegrity;
use Engine\Atomic\Telemetry\Data\MigrationSource;
use Engine\Atomic\Telemetry\Data\MigrationsReport;

class MigrationsReporter implements SnapshotProviderInterface
{
    /** @return array{legacy_mode:bool, entries:list<array<string, mixed>>} */
    public function report(): array
    {
        return $this->migrations()->to_array();
    }

    public function migrations(): MigrationsReport
    {
        try {
            $migrations = self::build_manager();
            // entries() runs first: it ensures the ledger table exists, which
            // legacy_mode()'s schema probe depends on.
            $entries = $migrations->entries();
            $legacy_mode = $migrations->legacy_mode();
        } catch (\Throwable $e) {
            return new MigrationsReport(false, []);
        }

        return new MigrationsReport($legacy_mode, array_map(static function (array $entry): MigrationEntry {
            $row = $entry['row'];

            return new MigrationEntry(
                source: (string)$entry['source'],
                name: (string)$entry['name'],
                file: $entry['path'] !== null ? basename((string)$entry['path']) : null,
                applied: $entry['status'] === 'applied',
                integrity: MigrationIntegrity::from((string)$entry['integrity']),
                batch_uuid: $row !== null ? ((string)($row->batch_uuid ?? '') ?: null) : null,
                applied_at: $row !== null ? ((string)($row->applied_at ?? '') ?: null) : null,
            );
        }, $entries));
    }

    private const SOURCE_MAX_BYTES = 262144;

    /** Resolved from the catalog, never from the request, so only listed migration files can be read. */
    public function source(string $source, string $name): ?MigrationSource
    {
        try {
            $entries = self::build_manager()->entries();
        } catch (\Throwable $e) {
            return null;
        }

        foreach ($entries as $entry) {
            if ((string)$entry['source'] !== $source || (string)$entry['name'] !== $name) {
                continue;
            }
            $path = $entry['path'] !== null ? (string)$entry['path'] : '';
            if ($path === '' || !is_file($path) || !is_readable($path)) {
                return null;
            }
            $code = (string)file_get_contents($path, false, null, 0, self::SOURCE_MAX_BYTES + 1);
            $truncated = strlen($code) > self::SOURCE_MAX_BYTES;

            return new MigrationSource(
                name: $name,
                file: basename($path),
                code: $truncated ? substr($code, 0, self::SOURCE_MAX_BYTES) : $code,
                truncated: $truncated,
            );
        }

        return null;
    }

    public function snapshot(): array
    {
        $report = $this->migrations();
        $attention = array_filter($report->entries, static fn (MigrationEntry $entry): bool => $entry->needs_attention());

        return [
            'pending' => count($report->pending()),
            'attention' => count($attention),
            'legacy_mode' => $report->legacy_mode,
        ];
    }

    private static function build_manager(): Migrations
    {
        $container = \Engine\Atomic\Core\Container::global();
        $factory = null;
        if ($container !== null) {
            try {
                $factory = $container->get(MigrationsFactory::class);
            } catch (\Throwable) {
                $factory = null;
            }
        }
        $factory ??= new MigrationsFactory(
            \Engine\Atomic\Core\App::instance(),
            \Engine\Atomic\Core\ConnectionManager::instance(),
            \Engine\Atomic\App\PluginManager::instance(),
            \Engine\Atomic\Core\Filesystem::instance(),
        );

        return $factory->create();
    }
}
