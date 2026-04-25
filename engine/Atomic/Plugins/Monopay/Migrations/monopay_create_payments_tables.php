<?php
declare(strict_types=1);

if (!defined('ATOMIC_START')) exit;

use DB\Cortex\Schema\Schema;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\Plugins\Monopay\Monopay;
use Engine\Atomic\Plugins\Monopay\Models\Payment as FrameworkPayment;
use Engine\Atomic\Plugins\Monopay\Models\PaymentHistory;

return [
    'up' => function () {
        $out = new Output();
        try {
            $pay_conf = FrameworkPayment::resolveConfiguration();
            $db = $pay_conf['db'];
            $schema = new Schema($db);
            $tables = $schema->getTables();
            $pay_table = $pay_conf['table'];

            if (in_array($pay_table, $tables, true)) {
                $out->writeln("Table '{$pay_table}' already exists. Skipping creation.");
            } else {
                $t = $schema->createTable($pay_table);
                $t->addColumn('uuid')->type(Schema::DT_VARCHAR128)->nullable(false);
                $t->addColumn('invoice_id')->type(Schema::DT_VARCHAR256)->nullable(true);
                $t->addColumn('status')->type(Schema::DT_VARCHAR128)->nullable(false)->defaults('created');
                $t->addColumn('amount')->type(Schema::DT_DECIMAL)->nullable(false);
                $t->addColumn('final_amount')->type(Schema::DT_DECIMAL)->nullable(true);
                $t->addColumn('currency')->type(Schema::DT_INT)->nullable(false)->defaults(Monopay::CURRENCY_DEFAULT);
                $t->addColumn('destination')->type(Schema::DT_TEXT)->nullable(true);
                $t->addColumn('failure_reason')->type(Schema::DT_TEXT)->nullable(true);
                $t->addColumn('error_code')->type(Schema::DT_VARCHAR128)->nullable(true);
                $t->addColumn('created_at')->type(Schema::DT_TIMESTAMP)->nullable(true);
                $t->addColumn('updated_at')->type(Schema::DT_TIMESTAMP)->nullable(true);
                $t->addColumn('payment_info')->type(Schema::DT_TEXT)->nullable(true);
                $t->addColumn('cancel_list')->type(Schema::DT_TEXT)->nullable(true);
                $t->addColumn('wallet_data')->type(Schema::DT_TEXT)->nullable(true);
                $t->addColumn('tips_info')->type(Schema::DT_TEXT)->nullable(true);
                $t->addColumn('page_url')->type(Schema::DT_TEXT)->nullable(true);
                $t->addColumn('webhook_data')->type(Schema::DT_TEXT)->nullable(true);
                $t->addColumn('fulfilled_at')->type(Schema::DT_TIMESTAMP)->nullable(true);
                $t->build();

                $tm = $schema->alterTable($pay_table);
                $tm->addIndex(['uuid']);
                $tm->addIndex(['invoice_id']);
                $tm->build();

                $out->writeln("Table '{$pay_table}' created.");
            }

            $history_conf = PaymentHistory::resolveConfiguration();
            $history_table = $history_conf['table'];

            if (in_array($history_table, $tables, true)) {
                $out->writeln("Table '{$history_table}' already exists. Skipping creation.");
            } else {
                $h = $schema->createTable($history_table);
                $h->addColumn('payment')->type(Schema::DT_INT)->nullable(false);
                $h->addColumn('payment_uuid')->type(Schema::DT_VARCHAR256)->nullable(false);
                $h->addColumn('status')->type(Schema::DT_VARCHAR128)->nullable(false);
                $h->addColumn('raw_data')->type(Schema::DT_TEXT)->nullable(false);
                $h->addColumn('created_at')->type_timestamp(true)->nullable(false);
                $h->build();

                $hm = $schema->alterTable($history_table);
                $hm->addIndex(['payment']);
                $hm->addIndex(['payment_uuid']);
                $hm->build();

                $schema->addForeignKey($history_table, 'payment', $pay_table, 'id', 'CASCADE');
                $out->writeln("Table '{$history_table}' created.");
            }
        } catch (Exception $e) {
            $out->writeln('Error creating payments table: ' . $e->getMessage());
            return false;
        }
        return true;
    },

    'down' => function () {
        $out = new Output();
        try {
            $pay_conf = FrameworkPayment::resolveConfiguration();
            $db = $pay_conf['db'];
            $schema = new Schema($db);
            $tables = $schema->getTables();

            $history_table = PaymentHistory::resolveConfiguration()['table'];
            if (in_array($history_table, $tables, true)) {
                $schema->dropTable($history_table);
                $out->writeln("Table '{$history_table}' dropped.");
            }

            $pay_table = $pay_conf['table'];
            if (in_array($pay_table, $tables, true)) {
                $schema->dropTable($pay_table);
                $out->writeln("Table '{$pay_table}' dropped.");
            }
        } catch (Exception $e) {
            $out->writeln('Error dropping payments table: ' . $e->getMessage());
            return false;
        }
        return true;
    }
];
