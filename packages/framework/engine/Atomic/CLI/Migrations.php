<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\Migrations as AM;

trait Migrations {

    public function migrations_init(): void {
        if (!(new AM($this->output))->db()) {
            $this->output->writeln(Style::error_label() . ' Could not initialize migrations table. Check DB credentials and connectivity.');
            return;
        }

        $this->output->writeln(Style::success_label() . ' Migrations table is ready.');
    }

    public function migrations_create() {
        $args = $this->get_cli_args();
        if (!isset($args[0])) {
            $this->output->writeln('Usage: ' . Style::bold('migrations/create <name>'));
            return;
        }
        (new AM($this->output))->create($args[0]);
    }

    public function migrations_rollback() {
        $args = $this->get_cli_args();
        if (isset($args[0]) && (!is_numeric($args[0]) && $args[0] != 'batch')) {
            $this->output->writeln(Style::bold('Usage:'));
            $this->output->writeln('  ' . Style::bold('migrations/rollback') . '         - to rollback the last migration');
            $this->output->writeln('  ' . Style::bold('migrations/rollback <steps>') . ' - to rollback a specific number of migrations');
            $this->output->writeln('  ' . Style::bold('migrations/rollback batch') . '   - to rollback the last batch of migrations');
            return;
        }
        (new AM($this->output))->rollback($args[0] ?? null);
    }

    public function migrations_migrate() {
        $args = $this->get_cli_args();
        if (isset($args[0]) && !is_numeric($args[0])) {
            $this->output->writeln('Usage: ' . Style::bold('migrations/migrate [steps]') . ' or ' . Style::bold('migrations/migrate') . ' to apply all migrations');
            return;
        }
        (new AM($this->output))->migrate($args[0] ?? null);
    }

    public function migrations_status() {
        (new AM($this->output))->status();
    }

    public function migrations_publish() {
        $args = $this->get_cli_args();
        if (!isset($args[0])) {
            $this->output->writeln('Usage: ' . Style::bold('migrations/publish <plugin-name>'));
            $this->output->writeln('  ' . Style::bold('Publishes all migrations from the specified plugin.'));
            return;
        }
        (new AM($this->output))->publish_from_plugin($args[0]);
    }
}