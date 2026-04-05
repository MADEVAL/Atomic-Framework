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
        $prefix = $atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX');

        try {
            $tables = $schema->getTables();

            // --- jobs table ---
            $jobsTable = $prefix . 'jobs';
            if (in_array($jobsTable, $tables)) {
                echo "Table '{$jobsTable}' already exists. Skipping creation." . PHP_EOL;
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
                echo "Table '{$jobsTable}' created." . PHP_EOL;
            }

            // --- jobs_failed table ---
            $jobsFailedTable = $prefix . 'jobs_failed';
            if (in_array($jobsFailedTable, $tables)) {
                echo "Table '{$jobsFailedTable}' already exists. Skipping creation." . PHP_EOL;
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
                echo "Table '{$jobsFailedTable}' created successfully." . PHP_EOL;
            }

            // --- jobs_completed table ---
            $jobsCompletedTable = $prefix . 'jobs_completed';
            if (in_array($jobsCompletedTable, $tables)) {
                echo "Table '{$jobsCompletedTable}' already exists. Skipping creation." . PHP_EOL;
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
                echo "Table '{$jobsCompletedTable}' created successfully." . PHP_EOL;
            }

            // --- telemetry table ---
            $telemetryTable = $prefix . 'telemetry';
            if (in_array($telemetryTable, $tables)) {
                echo "Table '{$telemetryTable}' already exists. Skipping creation." . PHP_EOL;
            } else {
                $table = $schema->createTable($telemetryTable);
                $table->addColumn('uuid')->type(Schema::DT_VARCHAR128)->nullable(false);
                $table->addColumn('uuid_batch')->type(Schema::DT_VARCHAR128)->nullable(true);
                $table->addColumn('uuid_job')->type(Schema::DT_VARCHAR128)->nullable(true);
                $table->addColumn('event_type_id')->type(Schema::DT_INT)->nullable(true);
                $table->addColumn('message')->type(Schema::DT_TEXT)->nullable(false);
                $table->addColumn('created_at')->type(Schema::DT_INT)->nullable(false);
                $table->build();
                echo "Table '{$telemetryTable}' created successfully." . PHP_EOL;
            }
        } catch (\Throwable $e) {
            echo "Failed to create queue tables: " . $e->getMessage() . PHP_EOL;
        }
    },

    'down' => function () {
        $atomic = App::instance();
        $db = $atomic->get('DB');
        $schema = new Schema($db);
        $prefix = $atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX');

        try {
            $tables = $schema->getTables();

            foreach (['jobs', 'jobs_failed', 'jobs_completed', 'telemetry'] as $name) {
                $tableName = $prefix . $name;
                if (is_array($tables) && in_array($tableName, $tables)) {
                    $schema->dropTable($tableName);
                    echo "Table '{$tableName}' dropped." . PHP_EOL;
                } else {
                    echo "Table '{$tableName}' does not exist. Skipping drop." . PHP_EOL;
                }
            }
        } catch (\Throwable $e) {
            echo "Error during drop: " . $e->getMessage() . PHP_EOL;
        }
    }
];
