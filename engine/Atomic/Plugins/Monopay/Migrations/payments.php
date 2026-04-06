<?php
use Engine\Atomic\Core\App;
use DB\Cortex\Schema\Schema;

return [
    'up' => function () {
        $atomic = App::instance();
        $db     = $atomic->get('DB');
        $schema = new Schema($db);
        $prefix = (string)$atomic->get('DB_CONFIG.ATOMIC_DB_PREFIX');
        $table  = $prefix . 'payments';

        $t = $schema->createTable($table);
        $t->addColumn('tariff')->type_int()->nullable(true);
        $t->addColumn('store')->type_int()->nullable(true);
        $t->addColumn('user')->type_int()->nullable(true);
        $t->addColumn('uuid')->type_varchar(128)->nullable(false);
        $t->addColumn('invoice_id')->type_varchar(256)->nullable(true);
        $t->addColumn('status')->type_varchar(128)->nullable(false)->defaults('created');
        $t->addColumn('amount')->type_decimal()->nullable(false);
        $t->addColumn('final_amount')->type_decimal()->nullable(true);
        $t->addColumn('currency')->type_int()->nullable(false)->defaults(980);
        $t->addColumn('destination')->type_text()->nullable(true);
        $t->addColumn('failure_reason')->type_text()->nullable(true);
        $t->addColumn('error_code')->type_varchar(128)->nullable(true);
        $t->addColumn('created_date')->type_timestamp()->nullable(true);
        $t->addColumn('modified_date')->type_timestamp()->nullable(true);
        $t->addColumn('payment_info')->type_text()->nullable(true);
        $t->addColumn('cancel_list')->type_text()->nullable(true);
        $t->addColumn('wallet_data')->type_text()->nullable(true);
        $t->addColumn('tips_info')->type_text()->nullable(true);
        $t->addColumn('page_url')->type_text()->nullable(true);
        $t->addColumn('webhook_data')->type_text()->nullable(true);
        $t->addColumn('fulfilled_at')->type_timestamp()->nullable(true);
        $t->build();

        $schema->alterTable($table, function($table) {
            $table->addIndex(['uuid']);
            $table->addIndex(['invoice_id']);
        });
    },

    'down' => function () {
        $atomic = App::instance();
        $db     = $atomic->get('DB');
        $schema = new Schema($db);
        $prefix = (string)$atomic->get('DB_CONFIG.ATOMIC_DB_PREFIX');

        $schema->dropTable($prefix . 'payments');
    },
];
