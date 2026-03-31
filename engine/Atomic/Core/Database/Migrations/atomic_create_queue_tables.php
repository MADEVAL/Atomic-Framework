<?php
declare(strict_types=1);

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;
use DB\SQL\Schema;

return [
    'up' => function () {
        $atomic = App::instance();
        $db = $atomic->get('DB');
        $schema = new Schema($db);
        try {
            $tables = $schema->getTables();

            if (in_array($atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs', $tables)) {
                echo "Table '" . $atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . "jobs' already exists. Skipping creation." . PHP_EOL;
            } else {
                $table = $schema->createTable($atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs');
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
                echo "Table '" . $atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . "jobs' created." . PHP_EOL;
            }

            if (in_array($atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs_failed', $tables)) {
                echo "Table '" . $atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . "jobs_failed' already exists. Skipping creation." . PHP_EOL;
            } else {
                $table = $schema->createTable($atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs_failed');
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
                echo "Table '" . $atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . "jobs_failed' was created successfully." . PHP_EOL;
            }

            if (in_array($atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs_completed', $tables)) {
                echo "Table '" . $atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . "jobs_completed' already exists. Skipping creation." . PHP_EOL;
            } else {
                $table = $schema->createTable($atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs_completed');
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
                echo "Table '" . $atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . "jobs_completed' was created successfully." . PHP_EOL;
            }

            // TELEMETRY

			if (is_array($tables) && in_array(App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'telemetry', $tables)) {
				echo "Table '" . App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'telemetry' . "' already exists. Skipping creation." . PHP_EOL;
                return;
			}

            $table = $schema->createTable(App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'telemetry');
            $table->addColumn('uuid')->type(Schema::DT_VARCHAR128)->nullable(false);
            $table->addColumn('uuid_batch')->type(Schema::DT_VARCHAR128)->nullable(true);
            $table->addColumn('uuid_job')->type(Schema::DT_VARCHAR128)->nullable(true);
            $table->addColumn('event_type_id')->type(Schema::DT_INT)->nullable(true);
            $table->addColumn('message')->type(Schema::DT_TEXT)->nullable(false);
            // TODO mb later
            // $table->addColumn('attempt')->type(Schema::DT_INT)->nullable(true);
            // $table->addColumn('duration')->type(Schema::DT_INT)->nullable(true);
            $table->addColumn('created_at')->type(Schema::DT_INT)->nullable(false);
            $table->build();

			echo "Table '" . App::instance()->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'telemetry' . "' was created successfully." . PHP_EOL;
		} catch (\Throwable $e) {
            echo "Failed to create telemetry table: " . $e->getMessage() . PHP_EOL;
		}
    },

    'down' => function () {
        $atomic = App::instance();
        $db = $atomic->get('DB');
        $schema = new Schema($db);

        try {
            $tables = $schema->getTables();
    
            if (is_array($tables) && in_array($atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs', $tables)) {
                $schema->dropTable($atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs');
                echo "Table '" . $atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . "jobs' dropped." . PHP_EOL;
            } else {
                echo "Table '" . $atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . "jobs' does not exist. Skipping drop." . PHP_EOL;
            }
    
            if (is_array($tables) && in_array($atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs_failed', $tables)) {
                $schema->dropTable($atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs_failed');
                echo "Table '" . $atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . "jobs_failed' dropped." . PHP_EOL;
            } else {
                echo "Table '" . $atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . "jobs_failed' does not exist. Skipping drop." . PHP_EOL;
            }
            
            if (is_array($tables) && in_array($atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs_completed', $tables)) {
                $schema->dropTable($atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs_completed');
                echo "Table '" . $atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . "jobs_completed' dropped." . PHP_EOL;
            } else {
                echo "Table '" . $atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . "jobs_completed' does not exist. Skipping drop." . PHP_EOL;
            }
    
            // TELEMETRY
    
            if (is_array($tables) && in_array($atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'telemetry', $tables)) {
                $schema->dropTable($atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'telemetry');
                echo "Table '" . $atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'telemetry' . "' dropped successfully." . PHP_EOL;
            } else {
                echo "Table '" . $atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'telemetry' . "' does not exist. Skipping drop." . PHP_EOL;
            }
        } catch (\Throwable $e) {
            echo "Error during pop: " . $e->getMessage() . PHP_EOL;
        }
    }
];