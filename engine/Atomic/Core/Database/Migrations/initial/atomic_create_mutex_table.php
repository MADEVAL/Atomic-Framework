<?php
declare(strict_types=1);

if (!defined('ATOMIC_START')) exit;

use DB\Cortex\Schema\Schema;
use Engine\Atomic\CLI\Console\CheckStatus;
use Engine\Atomic\Core\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        $table = $this->table('mutex_locks');
        if ($this->has_table($table)) {
            $this->output()->check(CheckStatus::SKIP, $table, 'table already exists');
            return;
        }

        $t = $this->schema()->createTable($table);
        $t->addColumn('name')->type(Schema::DT_VARCHAR256)->nullable(false)->index(true);
        $t->addColumn('token')->type(Schema::DT_VARCHAR128)->nullable(false);
        $t->addColumn('expires_at')->type(Schema::DT_INT)->nullable(false)->index();
        $t->addColumn('created_at')->type(Schema::DT_INT)->nullable(false);
        $t->build();
        $this->output()->check(CheckStatus::OK, $table, 'table created');
    }

    public function down(): void
    {
        $table = $this->table('mutex_locks');
        if (!$this->has_table($table)) {
            $this->output()->check(CheckStatus::SKIP, $table, 'table does not exist');
            return;
        }

        $this->schema()->dropTable($table);
        $this->output()->check(CheckStatus::OK, $table, 'table dropped');
    }
};
