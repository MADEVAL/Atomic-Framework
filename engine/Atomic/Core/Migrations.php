<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined( 'ATOMIC_START' ) ) exit;

use DB\Cortex;
use DB\Cortex\Schema\Schema;
use Engine\Atomic\CLI\Style;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\App\PluginManager;
use Engine\Atomic\Core\ConnectionManager;

class Migrations 
{
    private readonly Output $output;

    public function __construct(?Output $output = null)
    {
        $this->output = $output ?? new Output();
    }

    private function out(string $message): void
    {
        $this->output->write($message);
    }

    private function outln(string $message = ''): void
    {
        $this->out($message . PHP_EOL);
    }

    private function errln(string $message): void
    {
        $this->output->err($message);
    }

    public function db(): bool {
        $atomic = App::instance();
        $db = ConnectionManager::instance()->get_db(false);
        if (!$db) {
            $this->errln(Style::errorLabel() . ' ' . Style::bold('Database is not ready.'));
            return false;
        }
        $schema = new Schema($db);
        $migrations_table = $atomic->get('DB_CONFIG.ATOMIC_DB_PREFIX') . 'migrations';

        try {
            $tables = $schema->getTables();
            if (is_array($tables) && in_array($migrations_table, $tables)) {
                return true;
            }
    
            $table = $schema->createTable($migrations_table);
            $table->addColumn('migration')->type_varchar(255)->nullable(false);
            $table->addColumn('batch_uuid')->type_varchar(36)->nullable(false);
            $table->addColumn('applied_at')->type_timestamp(true)->nullable(false);
            $table->build();
        } catch (\Throwable $e) {
            $this->errln(Style::errorLabel() . ' ' . Style::bold('Error creating migrations table:') . ' ' . $e->getMessage());
            return false;
        }
        return true;
    }

    public function create(string $name, string $template = ''): void {
        if (!$this->db()) {
            return;
        }
        $atomic = App::instance();
        $db = ConnectionManager::instance()->get_db();
        $migrations_table = $atomic->get('DB_CONFIG.ATOMIC_DB_PREFIX') . 'migrations';
        $mapper = new Cortex($db, $migrations_table);

        $timestamp = date('YmdHis');
        if ($name !== preg_replace('/[^a-zA-Z0-9_]/', '', $name)) {
            $this->errln(Style::warningLabel() . ' ' . Style::bold('Migration name contains invalid characters.') . ' Only numbers and letters are allowed.');
            return;
        }
        $file_name = $timestamp . '_' . $name . '.php';
        $migrations_dir = $atomic->get('MIGRATIONS');
        if (!is_dir($migrations_dir)) {
            mkdir($migrations_dir, 0777, true);
        }
        $file_path = $migrations_dir . $file_name;

        $migration_files = array_filter(
            glob($migrations_dir . '*.php'),
            fn($f) => basename($f) !== 'index.php'
        );
        foreach ($migration_files as $file) {
            $basename = basename($file, '.php');
            if (preg_match('/^(\d{14})_/', $basename, $matches)) {
                $file_timestamp = $matches[1];
                if ($file_timestamp >= $timestamp) {
                    $this->errln(Style::errorLabel() . ' ' . Style::bold("Migration file '{$basename}.php'") . ' has a timestamp equal to or greater than the new migration. Aborting to prevent collision or breaking migration order.');
                    return;
                }
            }
        }
        if (empty($template)) {
            $template = <<<PHP
            <?php
            use Engine\Atomic\Core\App;
            use Engine\Atomic\Core\ConnectionManager;
            use DB\Cortex\Schema\Schema;

            return [
                'up' => function () {
                    \$atomic = App::instance();
                    \$db = ConnectionManager::instance()->get_db();
                    \$schema = new Schema(\$db);
                },

                'down' => function () {
                    \$atomic = App::instance();
                    \$db = ConnectionManager::instance()->get_db();
                    \$schema = new Schema(\$db);
                }
            ];
            PHP;
        }

        $files_cnt = count($migration_files);
        $applied_cnt = $mapper->count(null, null, 0);
        if ($applied_cnt < $files_cnt) {
            $this->errln(Style::warningLabel() . ' ' . Style::bold((string)($files_cnt - $applied_cnt)) . ' unapplied migrations. Please run ' . Style::cyan('migrations/status', true) . ' to view them.');
        }

        file_put_contents($file_path, $template);
        $this->outln(Style::successLabel() . ' ' . Style::bold("Migration '{$file_name}'") . ' created successfully at ' . Style::bold($file_path) . '.');
    }

    public function publishFromPlugin(string $pluginName): void {
        $manager = PluginManager::instance();
        $plugin = $manager->get($pluginName);

        if ($plugin === null) {
            foreach ($manager->all() as $name => $p) {
                if (strtolower($name) === strtolower($pluginName)) {
                    $plugin = $p;
                    break;
                }
            }
        }

        if ($plugin === null) {
            $this->errln(Style::errorLabel() . ' ' . Style::bold("Plugin '{$pluginName}' not found.") . ' Available plugins:');
            foreach ($manager->all() as $name => $p) {
                $hasMigrations = $p->getMigrationsPath() !== null ? '(has migrations)' : '';
                $this->outln('  - ' . Style::bold($name) . ($hasMigrations !== '' ? ' ' . Style::cyan($hasMigrations, true) : ''));
            }
            return;
        }

        $migrationsPath = $plugin->getMigrationsPath();
        if ($migrationsPath === null) {
            $this->errln(Style::warningLabel() . ' ' . Style::bold("Plugin '{$plugin->getPluginName()}'") . ' has no Migrations directory.');
            return;
        }

        $files = array_filter(
            glob($migrationsPath . DIRECTORY_SEPARATOR . '*.php'),
            fn($f) => basename($f) !== 'index.php'
        );

        if (empty($files)) {
            $this->errln(Style::warningLabel() . ' ' . Style::bold('No migration files found') . ' in ' . Style::bold($migrationsPath) . '.');
            return;
        }

        $published = 0;
        foreach ($files as $file) {
            $name = basename($file, '.php');
            $this->out('Publishing ' . Style::bold($name) . '... ');
            $this->publish(substr($file, 0, -4));
            $published++;
        }

        $this->outln();
        $this->outln(Style::successLabel() . ' ' . Style::bold((string)$published) . ' migration(s) processed from plugin ' . Style::bold($plugin->getPluginName()) . '.');
    }

    public function publish(string $source_path): void {
        $name = basename($source_path, '.php');
        $atomic = App::instance();
        $migrations_dir = $atomic->get('MIGRATIONS');
        if (!is_dir($migrations_dir)) {
            mkdir($migrations_dir, 0777, true);
        }

        $migration_files = array_filter(
            glob($migrations_dir . '*.php'),
            fn($f) => basename($f) !== 'index.php'
        );
        foreach ($migration_files as $file) {
            $basename = basename($file, '.php');
            if (preg_match('/^\d{14}_(.+)$/', $basename, $matches)) {
                $migration_name = $matches[1];
                if ($migration_name === $name) {
                    $this->errln(Style::warningLabel() . ' ' . Style::bold("Migration '{$name}'") . ' already exists as ' . Style::bold($basename . '.php') . '. Skipping publish.');
                    return;
                }
            }
        }

        $source_path .= '.php';
        if (!file_exists($source_path)) {
            $this->errln(Style::errorLabel() . ' ' . Style::bold('Source migration file') . ' ' . Style::bold($source_path) . ' does not exist. Cannot publish.');
            return;
        }
        $content = file_get_contents($source_path);
        $this->create($name, $content);
    }

    public function migrate(?int $steps = null): void {
        if (!$this->db()) {
            return;
        }
        $atomic = App::instance();
        $db = ConnectionManager::instance()->get_db();
        $migrations_table = $atomic->get('DB_CONFIG.ATOMIC_DB_PREFIX') . 'migrations';
        $mapper = new Cortex($db, $migrations_table);

        $migrations_dir = $atomic->get('MIGRATIONS');
        $migration_files = array_filter(
            glob($migrations_dir . '*.php'),
            fn($f) => basename($f) !== 'index.php'
        );

        $applied = [];
        $rows = $mapper->find() ?: [];
        foreach ($rows as $row) {
            $applied[] = $row->migration;
        }

        $unloaded = [];
        foreach ($migration_files as $file) {
            $basename = basename($file, '.php');
            if (preg_match('/^(\d{14})_/', $basename, $matches)) {
                $timestamp = $matches[1];
                    if (!in_array($basename, $applied)) {
                    $unloaded[$timestamp] = $basename;
                }
            }
        }
        if (empty($unloaded)) {
            $this->outln(Style::successLabel() . ' ' . Style::bold('No new migrations to apply.'));
            return;
        }
        ksort($unloaded);

        $batch_uuid = ID::uuid_v4();
        $to_apply_cnt = $steps !== null ? min($steps, count($unloaded)) : count($unloaded);
        $to_apply = array_slice($unloaded, 0, $to_apply_cnt, true);
        $applied = [];
        try {
            foreach ($to_apply as $timestamp => $file_name) {
                $file_path = $this->resolve_migration_file($migrations_dir, $file_name);
                $migration = include $file_path;
                if (isset($migration['up']) && is_callable($migration['up'])) {
                    $migration['up']();
                    $this->outln(Style::successLabel() . ' ' . Style::bold("Migration '{$file_name}'") . ' applied successfully.');
                } else throw new \Exception("Invalid migration structure in $file_path.");
                $mapper->reset();
                    $mapper->migration = basename($file_name, '.php');
                $mapper->batch_uuid = $batch_uuid;
                $mapper->save();
                $applied[] = $file_name;
            }
        } catch (\Throwable $e) {
            $this->errln(Style::errorLabel() . ' ' . Style::bold("Error applying migration '{$file_name}':") . ' ' . $e->getMessage());
            return;
        }
    }

    public function rollback(int|string|null $mode = null): void {
        if (!$this->db()) {
            return;
        }
        if ($mode === null) {
            $mode = 1;
        } elseif (is_numeric($mode)) {
            $mode = (int)$mode;
        }

        $atomic = App::instance();
        $db = ConnectionManager::instance()->get_db();
        $migrations_table = $atomic->get('DB_CONFIG.ATOMIC_DB_PREFIX') . 'migrations';
        $mapper = new Cortex($db, $migrations_table);

        try {
            if (is_int($mode)) {
                $to_pop = $mapper->find([], ['order' => 'id DESC', 'limit' => $mode]) ?: [];
                if (empty($to_pop)) {
                    $this->errln(Style::warningLabel() . ' ' . Style::bold('No migrations found to pop.'));
                    return;
                }
                foreach ($to_pop as $migration) {
                    $migration_file = $this->resolve_migration_file((string)$atomic->get('MIGRATIONS'), (string)$migration->migration);
                    $migration_content = include $migration_file;
                    if (isset($migration_content['down']) && is_callable($migration_content['down'])) {
                        $migration_content['down']();
                        $mapper->erase(['id = ?', $migration->id]);
                        $this->outln(Style::successLabel() . ' ' . Style::bold("Migration '{$migration->migration}'") . ' popped back successfully.');
                    } else throw new \Exception("Invalid migration structure in $migration_file.");
                }
            } else {
                $count = 1;
                $latest = $mapper->findone([], ['order' => 'id DESC']);
                if ($latest) {
                    $batch_uuid = $latest->batch_uuid;
                    $count = $mapper->count(['batch_uuid = ?', $batch_uuid], null, 0);
                } else {
                    $this->errln(Style::warningLabel() . ' ' . Style::bold('No migrations found to pop.'));
                    return;
                }
                $this->rollback($count);
            }
        } catch (\Throwable $e) {
            $this->errln(Style::errorLabel() . ' ' . Style::bold('Error rolling back migrations:') . ' ' . $e->getMessage());
            return;
        }
    }

    public function status(): void {
        if (!$this->db()) {
            return;
        }
        $atomic = App::instance();
        $db = ConnectionManager::instance()->get_db();
        $migrations_table = $atomic->get('DB_CONFIG.ATOMIC_DB_PREFIX') . 'migrations';
        $mapper = new Cortex($db, $migrations_table);

        $migrations_dir = $atomic->get('MIGRATIONS');
        $migration_files = array_filter(
            glob($migrations_dir . '*.php'),
            fn($f) => basename($f) !== 'index.php'
        );
        $db_migrations = [];
        $rows = $mapper->find() ?: [];
        foreach ($rows as $row) {
            $db_migrations[$row->migration] = [
                'batch_uuid' => $row->batch_uuid,
                'applied_at' => $row->applied_at
            ];
        }

        $this->outln();
        $this->outln(Style::bold('Migration List:'));
        foreach ($migration_files as $file) {
            $basename = basename($file, '.php');
            $status = isset($db_migrations[$basename]) ? 'applied' : 'pending';
            $batch_uuid = $db_migrations[$basename]['batch_uuid'] ?? '-';
            $applied_at = $db_migrations[$basename]['applied_at'] ?? '-';
            $statusLabel = $status === 'applied' ? Style::successLabel() : Style::warningLabel();
            $this->outln(Style::bold('File:') . ' ' . Style::bold($basename));
            $this->outln('  ' . Style::bold('Status:') . ' ' . $statusLabel . ' ' . Style::bold($status));
            $this->outln('  ' . Style::bold('Batch UUID:') . ' ' . Style::bold((string)$batch_uuid));
            $this->outln('  ' . Style::bold('Applied At:') . ' ' . Style::bold((string)$applied_at));
            $this->outln();
        }
    }

    private function resolve_migration_file(string $migrations_dir, string $migration_name): string
    {
        $baseDir = realpath($migrations_dir);
        if ($baseDir === false || !is_dir($baseDir)) {
            throw new \RuntimeException("Migrations directory not found: {$migrations_dir}");
        }

        $candidate = $baseDir . DIRECTORY_SEPARATOR . $migration_name . '.php';
        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
            throw new \RuntimeException("Migration file not found or unreadable: {$candidate}");
        }

        if (!str_starts_with($resolved, $baseDir . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("Migration file escapes migrations directory: {$migration_name}");
        }

        return $resolved;
    }
}
