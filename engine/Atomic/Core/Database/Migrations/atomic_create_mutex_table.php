<?php
declare(strict_types=1);

if (!defined('ATOMIC_START')) exit;

use DB\Cortex\Schema\Schema;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\App\Models\MutexLock;

return [
    'up' => function () {
        $out = new Output();
        try {
            $conf = MutexLock::resolveConfiguration();
            $db = $conf['db'];
            $table = $conf['table'];
            $schema = new Schema($db);
            $tables = $schema->getTables();

            if (is_array($tables) && in_array($table, $tables)) {
                $out->writeln("Table '{$table}' already exists. Skipping creation.");
                return;
            }

            $t = $schema->createTable($table);
            $t->addColumn('name')->type(Schema::DT_VARCHAR256)->nullable(false)->index(true);
            $t->addColumn('token')->type(Schema::DT_VARCHAR128)->nullable(false);
            $t->addColumn('expires_at')->type(Schema::DT_INT)->nullable(false)->index();
            $t->addColumn('created_at')->type(Schema::DT_INT)->nullable(false);
            $t->build();

            $out->writeln("Table '{$table}' created successfully.");
        } catch (\Throwable $e) {
            $out->err("Failed to create table '{$table}': " . $e->getMessage());
        }
    },

    'down' => function () {
        $out = new Output();
        try {
            $conf = MutexLock::resolveConfiguration();
            $schema = new Schema($conf['db']);
            $table = $conf['table'];
            $tables = $schema->getTables();

            if (is_array($tables) && in_array($table, $tables)) {
                $schema->dropTable($table);
                $out->writeln("Table '{$table}' dropped successfully.");
            } else {
                $out->writeln("Table '{$table}' does not exist. Skipping drop.");
            }
        } catch (\Throwable $e) {
            $out->err('Failed to drop table: ' . $e->getMessage());
        }
    }
];
