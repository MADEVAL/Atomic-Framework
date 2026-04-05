<?php
declare(strict_types=1);

if (!defined('ATOMIC_START')) exit;

use DB\Cortex\Schema\Schema;
use Engine\Atomic\App\Models\MutexLock;

return [
    'up' => function () {
        try {
            $conf = MutexLock::resolveConfiguration();
            $db = $conf['db'];
            $table = $conf['table'];
            $schema = new Schema($db);
            $tables = $schema->getTables();

            if (is_array($tables) && in_array($table, $tables)) {
                echo "Table '{$table}' already exists. Skipping creation." . PHP_EOL;
                return;
            }

            $t = $schema->createTable($table);
            $t->addColumn('name')->type(Schema::DT_VARCHAR256)->nullable(false)->index(true);
            $t->addColumn('token')->type(Schema::DT_VARCHAR128)->nullable(false);
            $t->addColumn('expires_at')->type(Schema::DT_INT)->nullable(false)->index();
            $t->addColumn('created_at')->type(Schema::DT_INT)->nullable(false);
            $t->build();

            echo "Table '{$table}' created successfully." . PHP_EOL;
        } catch (\Throwable $e) {
            echo "Failed to create table '{$table}': " . $e->getMessage() . PHP_EOL;
        }
    },

    'down' => function () {
        try {
            $conf = MutexLock::resolveConfiguration();
            $schema = new Schema($conf['db']);
            $table = $conf['table'];
            $tables = $schema->getTables();

            if (is_array($tables) && in_array($table, $tables)) {
                $schema->dropTable($table);
                echo "Table '{$table}' dropped successfully." . PHP_EOL;
            } else {
                echo "Table '{$table}' does not exist. Skipping drop." . PHP_EOL;
            }
        } catch (\Throwable $e) {
            echo "Failed to drop table: " . $e->getMessage() . PHP_EOL;
        }
    }
];
