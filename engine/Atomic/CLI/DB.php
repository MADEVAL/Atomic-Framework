<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI;

if (!defined( 'ATOMIC_START' ) ) exit;

use DB\SQL\Schema;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Migrations;

trait DB {
    public function get_tables()
    {
        $db = App::instance()->get('DB');

        $schema = new \DB\SQL\Schema($db);
        $tables = $schema->getTables();
        if (is_array($tables)) {
            foreach ($tables as $table) {
                echo $table . "\n";
            }
        } else {
            echo "No tables found or unable to retrieve tables.\n";
        }
    }

    public function truncate_table() {
        $args = $this->get_cli_args();
        if (count($args) < 1) {
            echo "Usage: db/truncate <table_name>\n";
            return;
        }
        $table_name = $args[0];
        try {
            $db = App::instance()->get('DB');
            $schema = new Schema($db);
            $schema->truncateTable($table_name);
            echo "Table '$table_name' truncated.\n";
        } catch (\Throwable $e) {
            echo "Failed to truncate table '$table_name': " . $e->getMessage() . "\n";
        }
    }

    public function truncate_queue_table() {
        $tables = [
            'atomic_queue_jobs',
            'atomic_queue_jobs_completed',
            'atomic_queue_jobs_failed',
            'atomic_queue_telemetry'
        ];
        $db = App::instance()->get('DB');
        $schema = new Schema($db);
        foreach ($tables as $table) {
            try {
                $schema->truncateTable($table);
                echo "Table '$table' truncated.\n";
            } catch (\Throwable $e) {
                echo "Failed to truncate table '$table': " . $e->getMessage() . "\n";
            }
        }
    }

    public function db_sessions() {
        $atomic = App::instance();
        (new Migrations)->publish($atomic->get('MIGRATIONS_CORE') . 'atomic_create_session_table');
    }
    
    public function db_storage() {
        $atomic = App::instance();
        (new Migrations)->publish($atomic->get('MIGRATIONS_CORE') . 'atomic_create_storage_tables');
    }
    
    public function db_mutex() {
        $atomic = App::instance();
        (new Migrations)->publish($atomic->get('MIGRATIONS_CORE') . 'atomic_create_mutex_table');
    }

    public function db_users() {
        $atomic = App::instance();
        (new Migrations)->publish($atomic->get('MIGRATIONS_BUNDLED') . 'atomic_create_user_tables');
    }

    public function db_stores() {
        $atomic = App::instance();
        (new Migrations)->publish($atomic->get('MIGRATIONS_BUNDLED') . 'atomic_create_store_tables');
    }

    public function db_pages() {
        $atomic = App::instance();
        (new Migrations)->publish($atomic->get('MIGRATIONS_BUNDLED') . 'atomic_create_page_tables');
    }

    public function db_recent_activity() {
        $atomic = App::instance();
        (new Migrations)->publish($atomic->get('MIGRATIONS_BUNDLED') . 'atomic_create_recent_activity_tables');
    }

    public function db_coupons() {
        $atomic = App::instance();
        (new Migrations)->publish($atomic->get('MIGRATIONS_BUNDLED') . 'atomic_create_coupon_tables');
    }

    public function db_payments() {
        $atomic = App::instance();
        (new Migrations)->publish($atomic->get('MIGRATIONS_BUNDLED') . 'atomic_create_payment_tables');
    }

    public function db_tariffs() {
        $atomic = App::instance();
        (new Migrations)->publish($atomic->get('MIGRATIONS_BUNDLED') . 'atomic_create_tariff_tables');
    }
    
    public function db_orders() {
        $atomic = App::instance();
        (new Migrations)->publish($atomic->get('MIGRATIONS_BUNDLED') . 'atomic_create_order_tables');
    }
    
    public function db_tg_front_err() {
        $atomic = App::instance();
        (new Migrations)->publish($atomic->get('MIGRATIONS_BUNDLED') . 'atomic_create_frontend_error_log_tables');
    }
}