<?php
use Engine\Atomic\Core\App;
use DB\Cortex\Schema\Schema;

return [
    'up' => function () {
        $atomic = App::instance();
        $db     = $atomic->get('DB');
        $schema = new Schema($db);
        $prefix = (string)$atomic->get('DB_CONFIG.ATOMIC_DB_PREFIX');
        $table  = $prefix . 'payment_history';

        $t = $schema->createTable($table);
        $t->addColumn('payment_uuid')->type_varchar(256)->nullable(false);
        $t->addColumn('status')->type_varchar(128)->nullable(false);
        $t->addColumn('raw_data')->type_text()->nullable(false);
        $t->addColumn('created_at')->type_timestamp(true)->nullable(false);
        $t->build();

        $schema->alterTable($table, function($table) {
            $table->addIndex(['payment_uuid']);
        });
    },

    'down' => function () {
        $atomic = App::instance();
        $db     = $atomic->get('DB');
        $schema = new Schema($db);
        $prefix = (string)$atomic->get('DB_CONFIG.ATOMIC_DB_PREFIX');

        $schema->dropTable($prefix . 'payment_history');
    },
];
