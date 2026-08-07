<?php
declare(strict_types=1);

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use DB\SQL\Schema;

return [
    'up' => function () {
        $atomic = App::instance();
        $db = $atomic->get('DB');
        $schema = new Schema($db);
        $tables = $schema->getTables();
        $prefix = $atomic->get('DB_CONFIG.ATOMIC_DB_PREFIX');

        if (!in_array($prefix . 'users', $tables)) {
            $table = $schema->createTable($prefix . 'users');
            $table->addColumn('uuid')->type(Schema::DT_VARCHAR128)->nullable(false);
            $table->addColumn('name')->type(Schema::DT_VARCHAR256)->nullable(true);
            $table->addColumn('email')->type(Schema::DT_VARCHAR256)->nullable(false);
            $table->addColumn('password')->type(Schema::DT_VARCHAR256)->nullable(true);
            $table->addColumn('role')->type(Schema::DT_VARCHAR128)->nullable(true);
            $table->addColumn('email_verified_at')->type(Schema::DT_TIMESTAMP, true)->nullable(true);
            $table->addColumn('remember_token')->type(Schema::DT_VARCHAR128)->nullable(true);
            $table->addColumn('created_at')->type(Schema::DT_TIMESTAMP, true)->nullable(false)->defaults(Schema::DF_CURRENT_TIMESTAMP);
            $table->addColumn('updated_at')->type(Schema::DT_TIMESTAMP, true)->nullable(false)->defaults(Schema::DF_CURRENT_TIMESTAMP);
            $table->build();
            echo "Table '{$prefix}users' created." . PHP_EOL;
        } else {
            echo "Table '{$prefix}users' already exists. Skipping." . PHP_EOL;
        }
    },

    'down' => function () {
        $atomic = App::instance();
        $db = $atomic->get('DB');
        $schema = new Schema($db);
        $prefix = $atomic->get('DB_CONFIG.ATOMIC_DB_PREFIX');

        $schema->dropTable($prefix . 'users');
        echo "Table '{$prefix}users' dropped." . PHP_EOL;
    },
];
