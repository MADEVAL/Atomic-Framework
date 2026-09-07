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
    public function discover(): array
    {
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

        $migrations = $this->resolve_framework_copies($framework, $app);

        $plugin_sources = [];
        foreach ($this->ordered_enabled_plugins() as $plugin) {
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

        $migrations = $this->without_unmodified_published_copies($migrations);
        $seen = [];
        foreach ($migrations as $migration) {
            $identity = $migration['source'] . "\0" . $migration['migration'];
            if (isset($seen[$identity])) {
                throw new \RuntimeException("Duplicate migration identity '{$migration['source']}:{$migration['migration']}'.");
            }
            $seen[$identity] = true;
        }
        return $migrations;
    }

    /** @return list<array{application: array, owner: array}> */
    public function modified_published_copies(): array
    {
        return $this->modified_published_copies;
    }

    /** @return list<Plugin> */
    public function ordered_enabled_plugins(): array
    {
        $ordered = [];
        $visiting = [];
        $visited = [];
        $visit = function (Plugin $plugin) use (&$visit, &$ordered, &$visiting, &$visited): void {
            $name = $plugin->get_plugin_name();
            if (isset($visited[$name]) || !$plugin->is_enabled()) {
                return;
            }
            if (isset($visiting[$name])) {
                throw new \RuntimeException("Plugin migration dependency cycle detected at '{$name}'.");
            }
            $visiting[$name] = true;
            foreach ($plugin->get_dependencies() as $dependency_class) {
                $dependency = $this->plugins->resolve_dependency($plugin, $dependency_class);
                if (!$dependency->is_enabled()) {
                    throw new \RuntimeException(
                        "Plugin '{$name}' migration dependency '{$dependency->get_plugin_name()}' is disabled."
                    );
                }
                $visit($dependency);
            }
            unset($visiting[$name]);
            $visited[$name] = true;
            $ordered[] = $plugin;
        };

        foreach ($this->plugins->all() as $plugin) {
            if ($plugin instanceof Plugin && $plugin->is_enabled()) {
                $visit($plugin);
            }
        }
        return $ordered;
    }

    /**
     * Published files remain the executable files. The framework checksum is
     * only a reference for first execution; applied files use ledger checksums.
     */
    private function resolve_framework_copies(array $framework, array $app): array
    {
        $rows = [];
        try {
            $rows = $this->ledger?->rows() ?? [];
        } catch (\Throwable) {
            // Discovery remains usable before the ledger table exists.
        }
        $published_owners = [];
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
                $published_owners[$owner['migration']] = true;
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

        // Initial migrations run from their published copies. Versioned updates
        // remain separate, in the numeric order supplied by the inventory.
        $updates = array_values(array_filter($framework, static fn(array $migration): bool =>
            $migration['group'] === FrameworkMigrationGroups::UPDATES
            && !isset($published_owners[$migration['migration']])
        ));
        return array_merge($app, $updates);
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
