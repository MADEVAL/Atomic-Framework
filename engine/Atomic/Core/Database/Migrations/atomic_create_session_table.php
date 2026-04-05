<?php
declare(strict_types=1);

if (!defined('ATOMIC_START')) exit;

use DB\Cortex\Schema\Schema;
use Engine\Atomic\Session\Models\Session;

return [
    'up' => function () {
        try {
            $conf = Session::resolveConfiguration();
            $db = $conf['db'];
            $table = $conf['table'];
            $schema = new Schema($db);
            $tables = $schema->getTables();

            if (in_array($table, $tables)) {
                echo "Table '{$table}' already exists. Skipping creation." . PHP_EOL;
            } else {
                $t = $schema->createTable($table);
                $t->addColumn('session_id')->type(Schema::DT_VARCHAR256)->nullable(false)->index(true);
                $t->addColumn('data')->type(Schema::DT_TEXT)->nullable(true);
                $t->addColumn('ip')->type(Schema::DT_VARCHAR128)->nullable(true);
                $t->addColumn('agent')->type(Schema::DT_VARCHAR512)->nullable(true);
                $t->addColumn('stamp')->type(Schema::DT_INT)->nullable(true);
                $t->build();
                echo "Table '{$table}' created." . PHP_EOL;
            }
        } catch (\Throwable $e) {
            echo "Error creating sessions table: " . $e->getMessage() . PHP_EOL;
        }
        echo "Migration completed." . PHP_EOL;
    },
    'down' => function () {
        try {
            $conf = Session::resolveConfiguration();
            $schema = new Schema($conf['db']);
            $table = $conf['table'];
            $tables = $schema->getTables();

            if (in_array($table, $tables)) {
                $schema->dropTable($table);
                echo "Table '{$table}' dropped." . PHP_EOL;
            } else {
                echo "Table '{$table}' does not exist. Skipping drop." . PHP_EOL;
            }
        } catch (\Throwable $e) {
            echo "Error dropping sessions table: " . $e->getMessage() . PHP_EOL;
        }
        echo "Rollback completed." . PHP_EOL;
    }
];
