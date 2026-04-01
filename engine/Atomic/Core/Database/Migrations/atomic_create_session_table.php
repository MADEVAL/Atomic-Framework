<?php
declare(strict_types=1);

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;
use DB\Cortex\Schema\Schema;

return [
    'up' => function () {
        $atomic = App::instance();
        $db = $atomic->get('DB');
        $schema = new Schema($db);
        try {
            $tables = $schema->getTables();
            $prefix = $atomic->get('DB_CONFIG.ATOMIC_DB_PREFIX');
            if (in_array($prefix . 'sessions', $tables)) {
                echo "Table '" . $prefix . "sessions' already exists. Skipping creation." . PHP_EOL;
            } else {
                $table = $schema->createTable($prefix . 'sessions');
                $table->addColumn('session_id')->type(Schema::DT_VARCHAR256)->nullable(false)->index(true);
                $table->addColumn('data')->type(Schema::DT_TEXT)->nullable(true);
                $table->addColumn('ip')->type(Schema::DT_VARCHAR128)->nullable(true);
                $table->addColumn('agent')->type(Schema::DT_VARCHAR512)->nullable(true);
                $table->addColumn('stamp')->type(Schema::DT_INT)->nullable(true);
                $table->build();
                echo "Table '" . $prefix . "sessions' created." . PHP_EOL;
            }

        } catch (Exception $e) {
            echo "Error creating sessions table: " . $e->getMessage() . PHP_EOL;
        }
        echo "Migration completed." . PHP_EOL;
    },
    'down' => function () {
        $atomic = App::instance();
        $db = $atomic->get('DB');
        $schema = new Schema($db);
        try {
            $tables = $schema->getTables();
            $prefix = $atomic->get('DB_CONFIG.ATOMIC_DB_PREFIX');
            if (in_array($prefix . 'sessions', $tables)) {
                $schema->dropTable($prefix . 'sessions');
                echo "Table '" . $prefix . "sessions' dropped." . PHP_EOL;
            }
        } catch (Exception $e) {
            echo "Error dropping sessions table: " . $e->getMessage() . PHP_EOL;
        }
        echo "Rollback completed." . PHP_EOL;
    }
];
