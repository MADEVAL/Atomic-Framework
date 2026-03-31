<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\Migrations as AM;

trait Migrations {

    public function migrations_create() {
        $args = $this->get_cli_args();
        if (!isset($args[0])) {
            echo "Usage: migrations/create <name>\n";
            return;
        }
        (new AM())->create($args[0]);
    }

    public function migrations_rollback() {
        $args = $this->get_cli_args();
        if (isset($args[0]) && (!is_numeric($args[0]) && $args[0] != 'batch')) {
            echo "Usage:\n";
            echo "  migrations/rollback         - to rollback the last migration\n";
            echo "  migrations/rollback <steps> - to rollback a specific number of migrations\n";
            echo "  migrations/rollback batch   - to rollback the last batch of migrations\n";
            return;
        }
        (new AM())->rollback($args[0] ?? null);
    }

    public function migrations_migrate() {
        $args = $this->get_cli_args();
        if (isset($args[0]) && !is_numeric($args[0])) {
            echo "Usage: migrations/migrate [steps] or migrations/migrate to apply all migrations\n";
            return;
        }
        (new AM())->migrate($args[0] ?? null);
    }

    public function migrations_status() {
        (new AM())->status();
    }
}