<?php
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use DB\Cortex\Schema\Schema;

return [
    'up' => function () {
        $atomic = App::instance();
        $db     = ConnectionManager::instance()->get_db();
        $schema = new Schema($db);
        $prefix = (string)$atomic->get('DB_CONFIG.prefix');
        $payments_table = $prefix . 'payments';
        $history_table  = $prefix . 'payment_history';

        $t = $schema->createTable($payments_table);
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

        $tm = $schema->alterTable($payments_table);
        $tm->addIndex(['uuid']);
        $tm->addIndex(['invoice_id']);
        $tm->build();

        $h = $schema->createTable($history_table);
        $h->addColumn('payment')->type_int()->nullable(false);
        $h->addColumn('payment_uuid')->type_varchar(256)->nullable(false);
        $h->addColumn('status')->type_varchar(128)->nullable(false);
        $h->addColumn('raw_data')->type_text()->nullable(false);
        $h->addColumn('created_at')->type_timestamp(true)->nullable(false);
        $h->build();

        $hm = $schema->alterTable($history_table);
        $hm->addIndex(['payment']);
        $hm->addIndex(['payment_uuid']);
        $hm->build();

        $schema->addForeignKey($history_table, 'payment', $payments_table, 'id', 'CASCADE');
    },

    'down' => function () {
        $atomic = App::instance();
        $db     = ConnectionManager::instance()->get_db();
        $schema = new Schema($db);
        $prefix = (string)$atomic->get('DB_CONFIG.prefix');

        $schema->dropTable($prefix . 'payment_history');
        $schema->dropTable($prefix . 'payments');
    },
];
