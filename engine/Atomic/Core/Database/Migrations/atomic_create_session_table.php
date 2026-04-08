<?php
declare(strict_types=1);

if (!defined('ATOMIC_START')) exit;

use DB\Cortex\Schema\Schema;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\Session\Models\Session;

return [
    'up' => function () {
        $out = new Output();
        try {
            $conf = Session::resolveConfiguration();
            $db = $conf['db'];
            $table = $conf['table'];
            $schema = new Schema($db);
            $tables = $schema->getTables();

            if (in_array($table, $tables)) {
                $out->writeln("Table '{$table}' already exists. Skipping creation.");
            } else {
                $t = $schema->createTable($table);
                $t->addColumn('session_id')->type(Schema::DT_VARCHAR256)->nullable(false)->index(true);
                $t->addColumn('data')->type(Schema::DT_TEXT)->nullable(true);
                $t->addColumn('ip')->type(Schema::DT_VARCHAR128)->nullable(true);
                $t->addColumn('agent')->type(Schema::DT_VARCHAR512)->nullable(true);
                $t->addColumn('stamp')->type(Schema::DT_INT)->nullable(true);
                $t->build();
                $out->writeln("Table '{$table}' created.");
            }
        } catch (\Throwable $e) {
            $out->err('Error creating sessions table: ' . $e->getMessage());
        }
        $out->writeln('Migration completed.');
    },
    'down' => function () {
        $out = new Output();
        try {
            $conf = Session::resolveConfiguration();
            $schema = new Schema($conf['db']);
            $table = $conf['table'];
            $tables = $schema->getTables();

            if (in_array($table, $tables)) {
                $schema->dropTable($table);
                $out->writeln("Table '{$table}' dropped.");
            } else {
                $out->writeln("Table '{$table}' does not exist. Skipping drop.");
            }
        } catch (\Throwable $e) {
            $out->err('Error dropping sessions table: ' . $e->getMessage());
        }
        $out->writeln('Rollback completed.');
    }
];
