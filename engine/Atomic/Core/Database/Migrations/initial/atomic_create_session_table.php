<?php
declare(strict_types=1);

if (!defined('ATOMIC_START')) exit;

use DB\Cortex\Schema\Schema;
use Engine\Atomic\CLI\Console\CheckStatus;
use Engine\Atomic\Core\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        $table = $this->table('sessions');
        if ($this->has_table($table)) {
            $this->output()->check(CheckStatus::SKIP, $table, 'table already exists');
            return;
        }

        $t = $this->schema()->createTable($table);
        $t->addColumn('session_id')->type(Schema::DT_VARCHAR256)->nullable(false)->index(true);
        // Owner + creation time, read by the telemetry security report.
        $t->addColumn('user_uuid')->type(Schema::DT_VARCHAR128)->nullable(true)->index();
        $t->addColumn('created_at')->type(Schema::DT_INT)->nullable(true);
        $t->addColumn('data')->type(Schema::DT_TEXT)->nullable(true);
        $t->addColumn('ip')->type(Schema::DT_VARCHAR128)->nullable(true);
        $t->addColumn('agent')->type(Schema::DT_VARCHAR512)->nullable(true);
        $t->addColumn('stamp')->type(Schema::DT_INT)->nullable(true);
        $t->build();
        $this->output()->check(CheckStatus::OK, $table, 'table created');
    }

    public function down(): void
    {
        $table = $this->table('sessions');
        if (!$this->has_table($table)) {
            $this->output()->check(CheckStatus::SKIP, $table, 'table does not exist');
            return;
        }

        $this->schema()->dropTable($table);
        $this->output()->check(CheckStatus::OK, $table, 'table dropped');
    }
};
