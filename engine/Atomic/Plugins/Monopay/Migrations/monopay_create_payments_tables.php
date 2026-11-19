<?php
declare(strict_types=1);

if (!defined('ATOMIC_START')) exit;

use DB\Cortex\Schema\Schema;
use Engine\Atomic\CLI\Console\CheckStatus;
use Engine\Atomic\Core\Migrations\Migration;
use Engine\Atomic\Plugins\Monopay\Monopay;

return new class extends Migration {
    public function up(): void
    {
        $schema = $this->schema();
        $pay_table = $this->table('payments');
        $history_table = $this->table('payment_history');

        if ($this->has_table($pay_table)) {
            $this->output()->check(CheckStatus::SKIP, $pay_table, 'table already exists');
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

            $this->output()->check(CheckStatus::OK, $pay_table, 'table created');
        }

        if ($this->has_table($history_table)) {
            $this->output()->check(CheckStatus::SKIP, $history_table, 'table already exists');
            return;
        }

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
        $this->output()->check(CheckStatus::OK, $history_table, 'table created');
    }

    public function down(): void
    {
        // History first: it holds the foreign key to payments.
        foreach ([$this->table('payment_history'), $this->table('payments')] as $table) {
            if ($this->has_table($table)) {
                $this->schema()->dropTable($table);
                $this->output()->check(CheckStatus::OK, $table, 'table dropped');
            }
        }
    }
};
