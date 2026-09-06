<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI;

if (!defined( 'ATOMIC_START' ) ) exit;

use DB\Cortex\Schema\Schema;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Migrations;
use Engine\Atomic\Core\Migrations\FrameworkMigrationGroups;

trait DB {
    public function get_tables()
    {
        $db = ConnectionManager::instance()->get_db();

        $schema = new Schema($db);
        $tables = $schema->getTables();
        if (is_array($tables)) {
            foreach ($tables as $table) {
                $this->output->writeln($table);
            }
        } else {
            $this->output->err('No tables found or unable to retrieve tables.');
        }
    }

    public function truncate_table() {
        $args = $this->get_cli_args();
        if (count($args) < 1) {
            $this->output->usage('db/truncate');
            return;
        }
        $table_name = $args[0];
        try {
            $db = ConnectionManager::instance()->get_db();
            $schema = new Schema($db);
            $schema->truncateTable($table_name);
            $this->output->writeln("Table '{$table_name}' truncated.");
        } catch (\Throwable $e) {
            $this->output->err("Failed to truncate table '{$table_name}': " . $e->getMessage());
        }
    }

    public function truncate_queue_table() {
        $tables = [
            'atomic_queue_jobs',
            'atomic_queue_jobs_completed',
            'atomic_queue_jobs_failed',
            'atomic_queue_telemetry'
        ];
        $db = ConnectionManager::instance()->get_db();
        $schema = new Schema($db);
        foreach ($tables as $table) {
            try {
                $schema->truncateTable($table);
                $this->output->writeln("Table '{$table}' truncated.");
            } catch (\Throwable $e) {
                $this->output->err("Failed to truncate table '{$table}': " . $e->getMessage());
            }
        }
    }

    public function db_sessions() {
        $atomic = App::instance();
        (new Migrations($this->output))->publish_framework(FrameworkMigrationGroups::SESSIONS_MIGRATION);
    }
    
    public function db_storage() {
        $atomic = App::instance();
        (new Migrations($this->output))->publish_framework(FrameworkMigrationGroups::STORAGE_MIGRATION);
    }
    
    public function db_mutex() {
        $atomic = App::instance();
        (new Migrations($this->output))->publish_framework(FrameworkMigrationGroups::MUTEX_MIGRATION);
    }

    public function db_users() {
        $atomic = App::instance();
        (new Migrations($this->output))->publish($atomic->get('MIGRATIONS_BUNDLED') . 'atomic_create_user_tables');
    }

    public function db_pages() {
        $atomic = App::instance();
        (new Migrations($this->output))->publish($atomic->get('MIGRATIONS_BUNDLED') . 'atomic_create_page_tables');
    }
}
