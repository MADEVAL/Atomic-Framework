<?php
declare(strict_types=1);

namespace Engine\Atomic\Core\Migrations;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Plugin;
use Engine\Atomic\App\PluginManager;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\CLI\Style;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Filesystem;

class MigrationPublisher
{
    public function __construct(
        private readonly App $app,
        private readonly PluginManager $plugins,
        private readonly Filesystem $files,
        private readonly MigrationLedger $ledger,
        private readonly Output $output,
        private readonly ?FrameworkMigrationGroups $groups = null,
    ) {}

    public function create(string $name, string $template = ''): void
    {
        if ($name !== preg_replace('/[^a-zA-Z0-9_]/', '', $name)) {
            $this->output->err(Style::warning_label() . ' ' . Style::bold('Migration name contains invalid characters.') . ' Only numbers and letters are allowed.');
            return;
        }
        $directory = (string)$this->app->get('MIGRATIONS');
        if (!is_dir($directory)) {
            $this->files->make_dir($directory, 0777, true);
        }
        if ($template === '') {
            $template = $this->default_template();
        }
        try {
            $pending = $this->pending_migration_count();
            if ($pending > 0) {
                $this->output->err(
                    Style::warning_label() . ' ' . Style::bold((string)$pending)
                    . ' unapplied migrations. Please run ' . Style::cyan('migrations/status', true) . ' to view them.'
                );
            }
        } catch (\Throwable) {
            // File generation remains usable before a database connection exists.
        }
        $file_name = $this->next_migration_timestamp($directory) . '_' . $name . '.php';
        $file_path = $directory . $file_name;
        $this->files->write($file_path, $template, false);
        $this->output->writeln(
            Style::success_label() . ' ' . Style::bold("Migration '{$file_name}'")
            . ' created successfully at ' . Style::bold($file_path) . '.'
        );
    }

    private function pending_migration_count(): int
    {
        $history = new MigrationHistory();
        $catalog = new MigrationCatalog(
            $this->app,
            $this->plugins,
            $history,
            $this->ledger,
            $this->groups ?? new FrameworkMigrationGroups(),
        );
        $migrations = $catalog->discover();
        $rows = $this->ledger->rows();

        return count(array_filter(
            $migrations,
            static fn(array $migration): bool => $history->find_applied_row($migration, $rows, $migrations) === null,
        ));
    }

    public function publish_from_framework(): void
    {
        $path = rtrim((string)$this->app->get('MIGRATIONS_CORE'), '/\\');
        if (!is_dir($path)) {
            $this->output->err(
                Style::error_label() . ' ' . Style::bold('Framework migrations directory not found: ') . Style::bold($path)
            );
            return;
        }

        $groups = $this->groups ?? new FrameworkMigrationGroups();
        $inventory = $groups->update_inventory($path);
        $this->output->writeln(Style::bold('Publishing framework updates') . '.');
        $published = 0;
        $skipped = 0;
        foreach ($inventory as $item) {
            $this->output->write('Publishing ' . Style::bold($item['migration']) . '... ');
            $result = $this->copy_published_source($item['path'], $item['migration']);
            if ($result === true) {
                $published++;
            } elseif ($result === false) {
                $skipped++;
            }
        }

        $this->output->writeln();
        $this->output->writeln(
            Style::success_label() . ' ' . Style::bold((string)$published)
            . ' migration(s) published for framework.'
        );
        if ($skipped > 0) {
            $this->output->writeln(
                Style::warning_label() . ' ' . Style::bold((string)$skipped)
                . ' migration(s) already published; skipped.'
            );
        }
    }

    public function publish_from_plugin(string $plugin_name): void
    {
        $plugin = $this->find_plugin($this->plugins, $plugin_name);
        if ($plugin === null) {
            $this->output->err(Style::error_label() . ' ' . Style::bold("Plugin '{$plugin_name}' not found.") . ' Available plugins:');
            foreach ($this->plugins->all() as $name => $candidate) {
                $suffix = $candidate->get_migrations_path() !== null ? ' ' . Style::cyan('(has migrations)', true) : '';
                $this->output->writeln('  - ' . Style::bold($name) . $suffix);
            }
            return;
        }

        $published = 0;
        $skipped = 0;
        $processed = [];
        if (!$this->publish_plugin_migrations($this->plugins, $plugin, $processed, [], $published, $skipped)) {
            return;
        }
        $this->output->writeln();
        $this->output->writeln(
            Style::success_label() . ' ' . Style::bold((string)$published)
            . ' migration(s) published for plugin ' . Style::bold($plugin->get_plugin_name()) . ' and dependencies.'
        );
        if ($skipped > 0) {
            $this->output->writeln(
                Style::warning_label() . ' ' . Style::bold((string)$skipped)
                . ' migration(s) already published; skipped.'
            );
        }
    }

    private function publish_plugin_migrations(
        PluginManager $manager,
        Plugin $plugin,
        array &$processed,
        array $stack,
        int &$published,
        int &$skipped = 0,
    ): bool {
        $plugin_name = $plugin->get_plugin_name();
        if (isset($processed[$plugin_name])) {
            return true;
        }
        if (in_array($plugin_name, $stack, true)) {
            $stack[] = $plugin_name;
            $this->output->err(
                Style::error_label() . ' ' . Style::bold('Plugin migration dependency cycle detected:')
                . ' ' . implode(' -> ', $stack)
            );
            return false;
        }

        $stack[] = $plugin_name;
        foreach ($plugin->get_dependencies() as $dependency_class) {
            try {
                $dependency = $manager->resolve_dependency($plugin, $dependency_class);
            } catch (\RuntimeException $e) {
                $this->output->err(Style::error_label() . ' ' . Style::bold($e->getMessage()));
                return false;
            }
            if (!$dependency->is_enabled()) {
                $this->output->err(
                    Style::error_label() . ' '
                    . Style::bold("Plugin '{$plugin_name}' requires '{$dependency_class}', but it is disabled.")
                );
                return false;
            }
            if (!$this->publish_plugin_migrations($manager, $dependency, $processed, $stack, $published, $skipped)) {
                return false;
            }
        }

        $path = $plugin->get_migrations_path();
        if ($path !== null) {
            $files = array_filter(
                glob($path . DIRECTORY_SEPARATOR . '*.php') ?: [],
                static fn(string $file): bool => basename($file) !== 'index.php'
            );
            sort($files);
            foreach ($files as $file) {
                $this->output->write('Publishing ' . Style::bold(basename($file, '.php')) . '... ');
                $result = $this->copy_published_source($file);
                if ($result === true) {
                    $published++;
                } elseif ($result === false) {
                    $skipped++;
                }
            }
        }
        $processed[$plugin_name] = true;
        return true;
    }

    public function publish_framework(string $migration_name): void
    {
        $core = rtrim((string)$this->app->get('MIGRATIONS_CORE'), '/\\');
        $groups = $this->groups ?? new FrameworkMigrationGroups();
        $located = $groups->locate_initial($core, $migration_name)
            ?? $groups->locate_update($core, $migration_name);
        if ($located === null) {
            $this->output->err(
                Style::error_label() . ' ' . Style::bold("Framework migration '{$migration_name}'")
                . ' was not found in the initial or updates directory.'
            );
            return;
        }
        $name = $located['migration'];
        $this->output->write('Publishing ' . Style::bold($name) . '... ');
        $this->copy_published_source($located['path'], $name);
    }

    public function publish(string $source_path): void
    {
        $file = str_ends_with($source_path, '.php') ? $source_path : $source_path . '.php';
        if ($this->is_framework_source($file) && !$this->framework_source_is_selectable($file)) {
            return;
        }
        $this->copy_published_source($file);
    }

    private function copy_published_source(string $file, ?string $published_name = null): ?bool
    {
        $name = $published_name ?? basename($file, '.php');
        $directory = (string)$this->app->get('MIGRATIONS');
        if (!is_dir($directory)) {
            $this->files->make_dir($directory, 0777, true);
        }
        foreach (glob($directory . '*.php') ?: [] as $existing) {
            $basename = basename($existing, '.php');
            if (preg_match('/^\d{14}_(.+)$/', $basename, $matches) && $matches[1] === $name) {
                $this->output->err(
                    Style::warning_label() . ' ' . Style::bold("Migration '{$name}'")
                    . ' already exists as ' . Style::bold($basename . '.php') . '. Skipping publish.'
                );
                return false;
            }
        }
        if (!file_exists($file)) {
            $this->output->err(
                Style::error_label() . ' ' . Style::bold('Source migration file') . ' '
                . Style::bold($file) . ' does not exist. Cannot publish.'
            );
            return null;
        }
        $this->create($name, $this->files->read($file));
        return true;
    }

    private function is_framework_source(string $file): bool
    {
        $core = realpath(rtrim((string)$this->app->get('MIGRATIONS_CORE'), '/\\'));
        $resolved = realpath($file);
        if ($core === false || $resolved === false) {
            $core_path = rtrim((string)$this->app->get('MIGRATIONS_CORE'), '/\\');
            $normalized_file = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file);
            $normalized_core = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $core_path);

            return $normalized_core !== '' && str_starts_with($normalized_file, $normalized_core . DIRECTORY_SEPARATOR);
        }

        return str_starts_with($resolved, $core . DIRECTORY_SEPARATOR);
    }

    private function framework_source_is_selectable(string $file): bool
    {
        $groups = $this->groups ?? new FrameworkMigrationGroups();
        $group = $groups->group_from_path($file);
        if ($group === null) {
            $this->output->err(
                Style::warning_label() . ' Ignoring ungrouped framework migration '
                . Style::bold(basename($file)) . '. Place it in a subsystem directory listed in the group manifest.'
            );
            return false;
        }
        return true;
    }

    private function find_plugin(PluginManager $manager, string $plugin_name): ?Plugin
    {
        $plugin = $manager->get($plugin_name);
        if ($plugin !== null) {
            return $plugin;
        }
        foreach ($manager->all() as $name => $candidate) {
            if (strtolower($name) === strtolower($plugin_name)) {
                return $candidate;
            }
        }
        return null;
    }

    private function next_migration_timestamp(string $directory): string
    {
        $timestamp = date('YmdHis');
        foreach (glob($directory . '*.php') ?: [] as $file) {
            $basename = basename($file, '.php');
            if (preg_match('/^(\d{14})_/', $basename, $matches) && $matches[1] >= $timestamp) {
                $date = \DateTimeImmutable::createFromFormat('YmdHis', $matches[1]);
                $timestamp = $date->modify('+1 second')->format('YmdHis');
            }
        }
        return $timestamp;
    }

    private function resolve_migration_file(string $directory, string $migration_name): string
    {
        $base = realpath($directory);
        if ($base === false || !is_dir($base)) {
            throw new \RuntimeException("Migrations directory not found: {$directory}");
        }
        $candidate = $base . DIRECTORY_SEPARATOR . $migration_name . '.php';
        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
            throw new \RuntimeException("Migration file not found or unreadable: {$candidate}");
        }
        if (!str_starts_with($resolved, $base . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("Migration file escapes migrations directory: {$migration_name}");
        }
        return $resolved;
    }

    private function default_template(): string
    {
        return <<<'PHP'
<?php
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use DB\Cortex\Schema\Schema;

return [
    'up' => function () {
        $atomic = App::instance();
        $db = ConnectionManager::instance()->get_db();
        $schema = new Schema($db);
    },

    'down' => function () {
        $atomic = App::instance();
        $db = ConnectionManager::instance()->get_db();
        $schema = new Schema($db);
    }
];
PHP;
    }
}
