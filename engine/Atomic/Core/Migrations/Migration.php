<?php
declare(strict_types=1);

namespace Engine\Atomic\Core\Migrations;

if (!defined('ATOMIC_START')) exit;

use DB\Cortex\Schema\Schema;
use DB\SQL;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\Core\App;

abstract class Migration
{
    private ?MigrationContext $context = null;

    abstract public function up(): void;

    abstract public function down(): void;

    /**
     * Runs the requested direction with the runtime context provided by the migration runner.
     */
    final public function run(MigrationDirection $direction, MigrationContext $context): void
    {
        $this->context = $context;

        if ($direction === MigrationDirection::UP) {
            $this->up();
            return;
        }

        $this->down();
    }

    final protected function app(): App
    {
        return $this->context()->app();
    }

    final protected function db(string $connection = 'default'): SQL
    {
        return $this->context()->db($connection);
    }

    final protected function schema(string $connection = 'default'): Schema
    {
        return $this->context()->schema($connection);
    }

    /**
     * Returns the table name with the configured DB_CONFIG.prefix prepended.
     */
    final protected function table(string $name): string
    {
        return (string)$this->app()->get('DB_CONFIG.prefix') . $name;
    }

    /**
     * Checks for an exact table name; pass a name built with table() when the table is prefixed.
     */
    final protected function has_table(string $table, string $connection = 'default'): bool
    {
        $tables = $this->schema($connection)->getTables();

        return is_array($tables) && in_array($table, $tables, true);
    }

    final protected function output(): Output
    {
        return $this->context()->output();
    }

    private function context(): MigrationContext
    {
        if ($this->context === null) {
            throw new \LogicException('Migration context is not available; run the migration through the migration runner.');
        }

        return $this->context;
    }
}
