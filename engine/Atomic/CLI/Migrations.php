<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\Migrations as AM;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Container;
use Engine\Atomic\Core\Migrations\MigrationsFactory;

trait Migrations {

    public function migrations_init(): void {
        $migrations = $this->run_migration_command(function (AM $migrations): void {
            $migrations->db();
        });
        if (!$migrations->was_successful()) {
            $this->output->writeln(Style::error_label() . ' Could not initialize migrations table. Check DB credentials and connectivity.');
            return;
        }

        $this->output->writeln(Style::success_label() . ' Migrations table is ready.');
    }

    public function migrations_create() {
        $args = $this->get_cli_args();
        if (!isset($args[0])) {
            $this->output->usage('migrations/create');
            return;
        }
        $this->run_migration_command(function (AM $migrations) use ($args): void {
            $migrations->create($args[0]);
        });
    }

    public function migrations_rollback() {
        $args = $this->get_cli_args();
        if (isset($args[0]) && (!is_numeric($args[0]) && $args[0] != 'batch')) {
            $this->output->usage('migrations/rollback');
            return;
        }
        $this->run_migration_command(function (AM $migrations) use ($args): void {
            $migrations->rollback($args[0] ?? null);
        });
    }

    public function migrations_migrate() {
        $args = $this->get_cli_args();
        if (isset($args[0]) && !preg_match('/^[0-9]+$/D', $args[0])) {
            $this->output->usage('migrations/migrate');
            App::instance()->set_cli_exit_code(1);
            return;
        }
        $this->run_migration_command(function (AM $migrations) use ($args): void {
            $migrations->migrate(isset($args[0]) ? (int)$args[0] : null);
        });
    }

    public function migrations_status() {
        $this->run_migration_command(function (AM $migrations): void {
            $migrations->status();
        });
    }

    public function migrations_upgrade(): void {
        $args = $this->get_cli_args();
        $invalid = array_filter($args, static fn(string $arg): bool => $arg !== '--dry-run');
        if ($invalid !== []) {
            $this->output->usage('migrations/upgrade');
            return;
        }
        $this->run_migration_command(function (AM $migrations) use ($args): void {
            $migrations->upgrade(in_array('--dry-run', $args, true));
        });
    }


    private function run_migration_command(callable $operation): AM
    {
        $migrations = $this->migration_manager();

        try {
            $operation($migrations);
        } catch (\Throwable $e) {
            App::instance()->set_cli_exit_code(1);
            throw $e;
        }

        if (!$migrations->was_successful()) {
            App::instance()->set_cli_exit_code(1);
        }

        return $migrations;
    }

    public function migrations_publish() {
        $args = $this->get_cli_args();
        $positional = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '-')) {
                $this->output->usage('migrations/publish');
                return;
            }
            $positional[] = $arg;
        }
        if (!isset($positional[0])) {
            $this->output->usage('migrations/publish');
            $this->output->writeln('  ' . Style::bold('Publishes framework updates or migrations from the specified plugin.'));
            return;
        }
        if (strtolower($positional[0]) === 'framework') {
            $this->migration_manager()->publish_from_framework();
            return;
        }
        $this->run_migration_command(function (AM $migrations) use ($positional): void {
            $migrations->publish_from_plugin($positional[0]);
        });
    }

    protected function migration_manager(): AM
    {
        $container = Container::global();
        if ($container === null) {
            throw new \RuntimeException('The application container is not available.');
        }
        $input = isset($this->input) ? $this->input : null;
        return $container->get(MigrationsFactory::class)->create($this->output, $input);
    }
}
