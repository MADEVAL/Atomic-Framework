<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Reporters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Migrations;
use Engine\Atomic\Core\Migrations\MigrationsFactory;

class MigrationsReporter implements SnapshotProviderInterface
{
    /** @return array{legacy_mode:bool, entries:list<array<string, mixed>>} */
    public function report(): array
    {
        try {
            $migrations = self::build_manager();
            // entries() runs first: it ensures the ledger table exists, which
            // legacy_mode()'s schema probe depends on.
            $entries = $migrations->entries();
            $legacy_mode = $migrations->legacy_mode();
        } catch (\Throwable $e) {
            return ['legacy_mode' => false, 'entries' => []];
        }

        $out = [];
        foreach ($entries as $entry) {
            $row = $entry['row'];
            $out[] = [
                'source' => (string)$entry['source'],
                'name' => (string)$entry['name'],
                'file' => $entry['path'] !== null ? basename((string)$entry['path']) : null,
                'status' => (string)$entry['status'],
                'integrity' => (string)$entry['integrity'],
                'batch_uuid' => $row !== null ? (string)($row->batch_uuid ?? '') ?: null : null,
                'applied_at' => $row !== null ? ((string)($row->applied_at ?? '') ?: null) : null,
            ];
        }

        return ['legacy_mode' => $legacy_mode, 'entries' => $out];
    }

    private const SOURCE_MAX_BYTES = 262144;

    /**
     * Resolved from the catalog, never from the request, so only listed migration files can be read.
     *
     * @return array{file:string, code:string, truncated:bool}|null
     */
    public function source(string $source, string $name): ?array
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

            return [
                'file' => basename($path),
                'code' => $truncated ? substr($code, 0, self::SOURCE_MAX_BYTES) : $code,
                'truncated' => $truncated,
            ];
        }

        return null;
    }

    public function snapshot(): array
    {
        $data = $this->report();
        $pending = 0;
        $attention = 0;
        foreach ($data['entries'] as $entry) {
            if ($entry['status'] === 'pending') {
                $pending++;
            } elseif ($entry['integrity'] !== 'verified' && $entry['integrity'] !== 'not applied') {
                $attention++;
            }
        }

        return [
            'pending' => $pending,
            'attention' => $attention,
            'legacy_mode' => $data['legacy_mode'],
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
