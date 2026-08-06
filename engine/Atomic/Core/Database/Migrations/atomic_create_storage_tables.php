<?php
declare(strict_types=1);

if (!defined('ATOMIC_START')) exit;

use DB\Cortex\Schema\Schema;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\App\Models\Meta;
use Engine\Atomic\App\Models\Options;

return [
    'up' => function () {
        $out = new Output();
        try {
            $metaConf = Meta::resolveConfiguration();
            $db = $metaConf['db'];
            $schema = new Schema($db);
            $tables = $schema->getTables();

            $metaTable = $metaConf['table'];
            $optionsTable = Options::resolveConfiguration()['table'];

            // --- meta table ---
            if (in_array($metaTable, $tables)) {
                $out->writeln("Table '{$metaTable}' already exists. Skipping creation.");
            } else {
                $table = $schema->createTable($metaTable);
                $table->addColumn('uuid')->type(Schema::DT_VARCHAR128)->nullable(false);
                $table->addColumn('key')->type(Schema::DT_VARCHAR128)->nullable(false);
                $table->addColumn('value')->type(Schema::DT_TEXT)->nullable(true);
                $table->addColumn('created_at')->type(Schema::DT_TIMESTAMP)->defaults(Schema::DF_CURRENT_TIMESTAMP);
                $table->addColumn('updated_at')->type(Schema::DT_TIMESTAMP)->defaults(Schema::DF_CURRENT_TIMESTAMP);
                $table->build();
                $out->writeln("Table '{$metaTable}' created.");
            }

            // --- options table (extends meta with expired_at) ---
            if (in_array($optionsTable, $tables)) {
                $out->writeln("Table '{$optionsTable}' already exists. Skipping creation.");
            } else {
                $table = $schema->createTable($optionsTable);
                $table->addColumn('uuid')->type(Schema::DT_VARCHAR128)->nullable(false);
                $table->addColumn('key')->type(Schema::DT_VARCHAR128)->nullable(false);
                $table->addColumn('value')->type(Schema::DT_TEXT)->nullable(true);
                $table->addColumn('created_at')->type(Schema::DT_TIMESTAMP)->defaults(Schema::DF_CURRENT_TIMESTAMP);
                $table->addColumn('expired_at')->type(Schema::DT_DATETIME)->nullable(true);
                $table->addColumn('updated_at')->type(Schema::DT_TIMESTAMP)->defaults(Schema::DF_CURRENT_TIMESTAMP);
                $table->build();
                $out->writeln("Table '{$optionsTable}' created.");
            }
        } catch (\Throwable $e) {
            $out->err('Failed to create storage tables: ' . $e->getMessage());
        }
    },
    'down' => function () {
        $out = new Output();
        try {
            $metaConf = Meta::resolveConfiguration();
            $schema = new Schema($metaConf['db']);
            $tables = $schema->getTables();

            $metaTable = $metaConf['table'];
            $optionsTable = Options::resolveConfiguration()['table'];

            foreach ([$metaTable, $optionsTable] as $tableName) {
                if (in_array($tableName, $tables)) {
                    $schema->dropTable($tableName);
                    $out->writeln("Table '{$tableName}' dropped.");
                } else {
                    $out->writeln("Table '{$tableName}' does not exist. Skipping drop.");
                }
            }
        } catch (\Throwable $e) {
            $out->err('Error during drop: ' . $e->getMessage());
        }
    }
];
