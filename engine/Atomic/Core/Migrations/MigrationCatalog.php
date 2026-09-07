<?php
declare(strict_types=1);

namespace Engine\Atomic\Core\Migrations;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Plugin;
use Engine\Atomic\App\PluginManager;
use Engine\Atomic\Core\App;

class MigrationCatalog
{
    /** @var list<array{application: array, owner: array}> */
    private array $modified_published_copies = [];

    public function __construct(
        private readonly App $app,
        private readonly PluginManager $plugins,
        private readonly MigrationHistory $history,
        private readonly ?MigrationLedger $ledger = null,
        private readonly ?FrameworkMigrationGroups $groups = null,
    ) {}

    /** @return array<int, array{source: string, migration: string, path: string, checksum: string}> */
    public function discover(?array $rows = null): array
    {
        if ($rows === null) {
            try {
                $rows = $this->ledger?->rows() ?? [];
            } catch (\Throwable) {
                // Publishers also discover files before a ledger exists.
                $rows = [];
            }
        }
        $this->modified_published_copies = [];
        $migrations = [];
        $framework = [];
        $framework_dir = $this->resolve_directory((string)$this->app->get('MIGRATIONS_CORE'), true);
        $groups = $this->groups ?? new FrameworkMigrationGroups();
        if ($framework_dir !== null) {
            foreach (array_merge($groups->initial_inventory($framework_dir), $groups->update_inventory($framework_dir)) as $item) {
                $framework[] = $this->describe_file('framework', $item['path'], $item['migration']) + ['group' => $item['group']];
            }
        }

        $app = [];
        $app_dir = $this->resolve_directory((string)$this->app->get('MIGRATIONS'), false);
        if ($app_dir !== null) {
            $app = $this->discover_directory('app', $app_dir, true);
        }

        $migrations = $this->resolve_framework_copies($framework, $app, $rows);

        $plugin_sources = [];
        foreach ($this->plugins->ordered_enabled_plugins() as $plugin) {
            $path = $plugin->get_migrations_path();
            if ($path === null || !is_dir($path)) {
                continue;
            }
            $source = $plugin->get_migration_source();
            if (strlen($source) > 191 || !preg_match('/^plugin:[a-z0-9][a-z0-9._\/-]*$/', $source)) {
                throw new \RuntimeException("Plugin '{$plugin->get_plugin_name()}' returned invalid migration source '{$source}'.");
            }
            if (isset($plugin_sources[$source])) {
                throw new \RuntimeException(
                    "Plugins '{$plugin_sources[$source]}' and '{$plugin->get_plugin_name()}' use the same migration source '{$source}'."
                );
            }
            $plugin_sources[$source] = $plugin->get_plugin_name();
            $migrations = array_merge($migrations, $this->discover_directory($source, $path, false));
        }

        $migrations = $this->resolve_missing_plugin_copies($migrations, $rows);
        $migrations = $this->without_unmodified_published_copies($migrations);
        $seen = [];
        foreach ($migrations as $migration) {
            $source = $migration['source'];
            $name = $migration['migration'];
            if (isset($seen[$source][$name])) {
                throw new \RuntimeException("Duplicate migration identity '{$migration['source']}:{$migration['migration']}'.");
            }
            $seen[$source][$name] = true;
        }
        return $migrations;
    }

    /** @return list<array{application: array, owner: array}> */
    public function modified_published_copies(): array
    {
        return $this->modified_published_copies;
    }

    /**
     * Published files remain the executable files. The framework checksum is
     * only a reference for first execution; applied files use ledger checksums.
     */
    private function resolve_framework_copies(array $framework, array $app, array $rows): array
    {
        foreach ($app as &$copy) {
            foreach ($framework as $owner) {
                if (!$this->history->published_name_matches($copy['migration'], $owner['migration'])) {
                    continue;
                }
                if (isset($copy['framework_checksum'])) {
                    throw new \RuntimeException("Ambiguous framework original for '{$copy['migration']}'.");
                }
                $copy['source'] = 'framework';
                $copy['framework_checksum'] = $owner['checksum'];
                $copy['framework_path'] = $owner['path'];
                $copy['origin_migration'] = $owner['migration'];
                $copy['migration'] = $owner['migration'];
            }
            foreach ($rows as $row) {
                $source = (string)($row->source ?? '');
                if (!in_array($source, ['app', 'framework'], true)) {
                    continue;
                }
                if ((string)$row->migration === $copy['migration']) {
                    $copy['source'] = $source;
                    break;
                }
                // Compatibility with history already adopted under the core name.
                if ($source === 'framework' && $this->history->published_name_matches($copy['migration'], (string)$row->migration)) {
                    $copy['source'] = $source;
                    $copy['origin_migration'] = (string)$row->migration;
                    $copy['migration'] = (string)$row->migration;
                    break;
                }
            }
        }
        unset($copy);

        // Substitute published files in the numeric inventory, rather than
        // letting publication timestamps move an update ahead of prerequisites.
        $updates = [];
        foreach ($framework as $owner) {
            if ($owner['group'] !== FrameworkMigrationGroups::UPDATES) {
                continue;
            }
            $update = $owner;
            foreach ($app as $key => $copy) {
                if (($copy['origin_migration'] ?? null) === $owner['migration']) {
                    $update = $copy;
                    unset($app[$key]);
                    break;
                }
            }
            $updates[] = $update;
        }
        return array_merge($app, $updates);
    }

    private function resolve_missing_plugin_copies(array $migrations, array $rows): array
    {
        foreach ($rows as $row) {
            $source = (string)($row->source ?? '');
            if (!str_starts_with($source, 'plugin:')
                || $this->history->resolve_sourced_migration($source, (string)$row->migration, $migrations) !== null) {
                continue;
            }
            $copies = [];
            foreach ($migrations as $key => $candidate) {
                if ($candidate['source'] === 'app'
                    && $this->history->published_name_matches($candidate['migration'], (string)$row->migration)) {
                    $copies[] = $key;
                }
            }
            if (count($copies) > 1) {
                throw new \RuntimeException("Ambiguous published copies for '{$source}:{$row->migration}'. Restore the original migration file.");
            }
            if ($copies !== []) {
                $key = $copies[0];
                $migrations[$key]['source'] = $source;
                $migrations[$key]['migration'] = (string)$row->migration;
            }
        }
        return $migrations;
    }

    private function without_unmodified_published_copies(array $migrations): array
    {
        return array_values(array_filter($migrations, function (array $candidate) use ($migrations): bool {
            if ($candidate['source'] !== 'app') {
                return true;
            }
            foreach ($migrations as $owner) {
                if (
                    $owner['source'] !== 'app'
                    && $this->history->published_name_matches($candidate['migration'], $owner['migration'])
                ) {
                    if (hash_equals($candidate['checksum'], $owner['checksum'])) {
                        return false;
                    }
                    $this->modified_published_copies[] = [
                        'application' => $candidate,
                        'owner' => $owner,
                    ];
                }
            }
            return true;
        }));
    }

    private function discover_directory(string $source, string $directory, bool $timestamps_only): array
    {
        $files = array_filter(
            glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: [],
            static fn(string $file): bool => basename($file) !== 'index.php'
        );
        usort($files, static function (string $left, string $right): int {
            $left_timestamped = (bool)preg_match('/^\d{14}_/', basename($left));
            $right_timestamped = (bool)preg_match('/^\d{14}_/', basename($right));
            return $left_timestamped !== $right_timestamped
                ? ($left_timestamped ? 1 : -1)
                : strcmp(basename($left), basename($right));
        });

        $migrations = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if ($timestamps_only && !preg_match('/^\d{14}_/', $name)) {
                continue;
            }
            $migrations[] = $this->describe_file($source, $file);
        }
        return $migrations;
    }

    private function describe_file(string $source, string $file, ?string $migration_name = null): array
    {
        $resolved = realpath($file);
        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
            throw new \RuntimeException("Migration file is unreadable: {$file}");
        }
        $contents = file_get_contents($resolved);
        if ($contents === false) {
            throw new \RuntimeException("Cannot read migration file for checksum: {$resolved}");
        }
        return [
            'source' => $source,
            'migration' => $migration_name ?? basename($file, '.php'),
            'path' => $resolved,
            'checksum' => hash('sha256', str_replace(["\r\n", "\r"], "\n", $contents)),
        ];
    }

    private function resolve_directory(string $directory, bool $framework): ?string
    {
        if ($directory === '') {
            return null;
        }
        if (is_dir($directory)) {
            return realpath($directory) ?: $directory;
        }
        if ($framework && defined('ATOMIC_ENGINE')) {
            $candidate = rtrim((string)ATOMIC_ENGINE, '/\\') . DIRECTORY_SEPARATOR . ltrim($directory, '/\\');
            if (is_dir($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }
        return null;
    }
}
