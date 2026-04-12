<?php
declare(strict_types=1);

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use DB\Cortex\Schema\Schema;
use Engine\Atomic\CLI\Console\Output;

return [
    'up' => function () {
        $out = new Output();
        $atomic = App::instance();
        $db = ConnectionManager::instance()->get_db();
        $schema = new Schema($db);
        $prefix = $atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX');

        try {
            $tables = $schema->getTables();

            // --- jobs table ---
            $jobsTable = $prefix . 'jobs';
            if (in_array($jobsTable, $tables)) {
                $out->writeln("Table '{$jobsTable}' already exists. Skipping creation.");
            } else {
                $table = $schema->createTable($jobsTable);
                $table->addColumn('uuid')->type(Schema::DT_VARCHAR128)->nullable(false);
                $table->addColumn('queue')->type(Schema::DT_VARCHAR256)->nullable(false);
                $table->addColumn('priority')->type(Schema::DT_INT)->nullable(false);
                $table->addColumn('payload')->type(Schema::DT_TEXT)->nullable(false);
                $table->addColumn('max_attempts')->type(Schema::DT_INT)->nullable(false);
                $table->addColumn('attempts')->type(Schema::DT_INT)->nullable(false);
                $table->addColumn('timeout')->type(Schema::DT_INT)->nullable(false);
                $table->addColumn('retry_delay')->type(Schema::DT_INT)->nullable(false);
                $table->addColumn('available_at')->type(Schema::DT_INT)->nullable(false);
                $table->addColumn('created_at')->type(Schema::DT_INT)->nullable(false);
                $table->addColumn('process_start_ticks')->type(Schema::DT_INT)->nullable(true);
                $table->addColumn('pid')->type(Schema::DT_INT)->nullable(true);
                $table->build();
                $out->writeln("Table '{$jobsTable}' created.");
            }

            // --- jobs_failed table ---
            $jobsFailedTable = $prefix . 'jobs_failed';
            if (in_array($jobsFailedTable, $tables)) {
                $out->writeln("Table '{$jobsFailedTable}' already exists. Skipping creation.");
            } else {
                $table = $schema->createTable($jobsFailedTable);
                $table->addColumn('uuid')->type(Schema::DT_VARCHAR128)->nullable(false);
                $table->addColumn('queue')->type(Schema::DT_VARCHAR256)->nullable(false);
                $table->addColumn('priority')->type(Schema::DT_INT)->nullable(false);
                $table->addColumn('payload')->type(Schema::DT_TEXT)->nullable(false);
                $table->addColumn('max_attempts')->type(Schema::DT_INT)->nullable(false);
                $table->addColumn('attempts')->type(Schema::DT_INT)->nullable(false);
                $table->addColumn('timeout')->type(Schema::DT_INT)->nullable(false);
                $table->addColumn('retry_delay')->type(Schema::DT_INT)->nullable(false);
                $table->addColumn('exception')->type(Schema::DT_TEXT)->nullable(false);
                $table->addColumn('created_at')->type(Schema::DT_INT)->nullable(false);
                $table->build();
                $out->writeln("Table '{$jobsFailedTable}' created successfully.");
            }

            // --- jobs_completed table ---
            $jobsCompletedTable = $prefix . 'jobs_completed';
            if (in_array($jobsCompletedTable, $tables)) {
                $out->writeln("Table '{$jobsCompletedTable}' already exists. Skipping creation.");
            } else {
                $table = $schema->createTable($jobsCompletedTable);
                $table->addColumn('uuid')->type(Schema::DT_VARCHAR128)->nullable(false);
                $table->addColumn('queue')->type(Schema::DT_VARCHAR256)->nullable(false);
                $table->addColumn('priority')->type(Schema::DT_INT)->nullable(false);
                $table->addColumn('payload')->type(Schema::DT_TEXT)->nullable(false);
                $table->addColumn('max_attempts')->type(Schema::DT_INT)->nullable(false);
                $table->addColumn('attempts')->type(Schema::DT_INT)->nullable(false);
                $table->addColumn('timeout')->type(Schema::DT_INT)->nullable(false);
                $table->addColumn('retry_delay')->type(Schema::DT_INT)->nullable(false);
                $table->addColumn('created_at')->type(Schema::DT_INT)->nullable(false);
                $table->build();
                $out->writeln("Table '{$jobsCompletedTable}' created successfully.");
            }

            // --- telemetry table ---
            $telemetryTable = $prefix . 'telemetry';
            if (in_array($telemetryTable, $tables)) {
                $out->writeln("Table '{$telemetryTable}' already exists. Skipping creation.");
            } else {
                $table = $schema->createTable($telemetryTable);
                $table->addColumn('uuid')->type(Schema::DT_VARCHAR128)->nullable(false);
                $table->addColumn('uuid_batch')->type(Schema::DT_VARCHAR128)->nullable(true);
                $table->addColumn('uuid_job')->type(Schema::DT_VARCHAR128)->nullable(true);
                $table->addColumn('event_type_id')->type(Schema::DT_INT)->nullable(true);
                $table->addColumn('message')->type(Schema::DT_TEXT)->nullable(false);
                $table->addColumn('created_at')->type(Schema::DT_INT)->nullable(false);
                $table->build();
                $out->writeln("Table '{$telemetryTable}' created successfully.");
            }
        } catch (\Throwable $e) {
            $out->err('Failed to create queue tables: ' . $e->getMessage());
        }
    },

    'down' => function () {
        $out = new Output();
        $atomic = App::instance();
        $db = ConnectionManager::instance()->get_db();
        $schema = new Schema($db);
        $prefix = $atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX');

        try {
            $tables = $schema->getTables();

            foreach (['jobs', 'jobs_failed', 'jobs_completed', 'telemetry'] as $name) {
                $tableName = $prefix . $name;
                if (is_array($tables) && in_array($tableName, $tables)) {
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
