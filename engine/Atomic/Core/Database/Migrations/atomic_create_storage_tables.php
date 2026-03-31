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
            $tableNames = [];
            $tableNames[] = $atomic->get('DB_CONFIG.ATOMIC_DB_PREFIX') . 'meta';
            $tableNames[] = $atomic->get('DB_CONFIG.ATOMIC_DB_PREFIX') . 'options';
            $tables = $schema->getTables();

            foreach ($tableNames as $tableName) {
                if (in_array($tableName, $tables)) {
                    echo "Table '$tableName' already exists. Skipping creation." . PHP_EOL;
                } else {
                    $table = $schema->createTable($tableName);
                    // $table->addColumn('uuid')->type_varchar(36)->nullable(false);
                    // $table->addColumn('key')->type_varchar(128)->nullable(false);
                    // $table->addColumn('value')->type_text()->nullable(true);
                    // $table->addColumn('created_at')->type_timestamp(true)->nullable(false);
                    // if ($tableName === $atomic->get('DB_CONFIG.ATOMIC_DB_PREFIX') . 'options') {
                    //     $table->addColumn('expired_at')->type_datetime()->nullable(true);
                    // }
                    // $table->addColumn('updated_at')->type_timestamp(true)->nullable(false);

                    $table->addColumn('uuid')->type(Schema::DT_VARCHAR128)->nullable(false);
                    $table->addColumn('key')->type(Schema::DT_VARCHAR128)->nullable(false);
                    $table->addColumn('value')->type(Schema::DT_TEXT)->nullable(true);
                    $table->addColumn('created_at')->type(Schema::DT_TIMESTAMP, true)->defaults(Schema::DF_CURRENT_TIMESTAMP);
                    if ($tableName === $atomic->get('DB_CONFIG.ATOMIC_DB_PREFIX') . 'options') {
                        $table->addColumn('expired_at')->type(Schema::DT_DATETIME)->nullable(true);
                    }
                    $table->addColumn('updated_at')->type(Schema::DT_TIMESTAMP, true)->defaults(Schema::DF_CURRENT_TIMESTAMP);
                    $table->build();
                    echo "Table '$tableName' created." . PHP_EOL;
                }
            }
        } catch (\Throwable $e) {
            echo "Failed to create meta table: " . $e->getMessage() . PHP_EOL;
        }
    },
    'down' => function () {
        $atomic = App::instance();
        $db = $atomic->get('DB');
        $schema = new Schema($db);
        try {
            $tableNames = [];
            $tableNames[] = $atomic->get('DB_CONFIG.ATOMIC_DB_PREFIX') . 'meta';
            $tableNames[] = $atomic->get('DB_CONFIG.ATOMIC_DB_PREFIX') . 'options';
            $tables = $schema->getTables();
            foreach ($tableNames as $tableName) {
                if (in_array($tableName, $tables)) {
                    $schema->dropTable($tableName);
                    echo "Table '$tableName' dropped." . PHP_EOL;
                } else {
                    echo "Table '$tableName' does not exist. Skipping drop." . PHP_EOL;
                }
            }
        } catch (\Throwable $e) {
            echo "Error during drop: " . $e->getMessage() . PHP_EOL;
        }
    }
];