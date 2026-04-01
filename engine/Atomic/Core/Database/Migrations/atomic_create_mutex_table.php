<?php
declare(strict_types=1);

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use DB\Cortex\Schema\Schema;

return [
    'up' => function () {
        $atomic = App::instance();
        $db = $atomic->get('DB');
        $schema = new Schema($db);
        
        $tableName = $atomic->get('DB_CONFIG.ATOMIC_DB_PREFIX') . 'mutex_locks';
        
        try {
            $tables = $schema->getTables();
            
            if (is_array($tables) && in_array($tableName, $tables)) {
                echo "Table '{$tableName}' already exists. Skipping creation." . PHP_EOL;
                return;
            }

            $table = $schema->createTable($tableName);

            $table->addColumn('name')->type(Schema::DT_VARCHAR256)->nullable(false)->index(true);
            $table->addColumn('token')->type(Schema::DT_VARCHAR128)->nullable(false);
            $table->addColumn('expires_at')->type(Schema::DT_INT)->nullable(false)->index();
            $table->addColumn('created_at')->type(Schema::DT_INT)->nullable(false);

            $table->build();

            echo "Table '{$tableName}' created successfully." . PHP_EOL;

        } catch (\Throwable $e) {
            echo "Failed to create table '{$tableName}': " . $e->getMessage() . PHP_EOL;
        }
    },
    
    'down' => function () {
        $atomic = App::instance();
        $db = $atomic->get('DB');
        $schema = new Schema($db);

        $tableName = $atomic->get('DB_CONFIG.ATOMIC_DB_PREFIX') . 'mutex_locks';

        try {
            $tables = $schema->getTables();

            if (is_array($tables) && in_array($tableName, $tables)) {
                $schema->dropTable($tableName);
                echo "Table '{$tableName}' dropped successfully." . PHP_EOL;
            } else {
                echo "Table '{$tableName}' does not exist. Skipping drop." . PHP_EOL;
            }

        } catch (\Throwable $e) {
            echo "Failed to drop table '{$tableName}': " . $e->getMessage() . PHP_EOL;
        }
    }
];
