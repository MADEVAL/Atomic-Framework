<?php
declare(strict_types=1);

if (!defined('ATOMIC_START')) exit;

use DB\Cortex\Schema\Schema;
use DB\Cortex\Schema\TableCreator;
use Engine\Atomic\CLI\Console\CheckStatus;
use Engine\Atomic\Core\Migrations\Migration;

return new class extends Migration {
    private const TABLES = ['meta', 'options'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            $table_name = $this->table($name);
            if ($this->has_table($table_name)) {
                $this->output()->check(CheckStatus::SKIP, $table_name, 'table already exists');
                continue;
            }

            $table = $this->schema()->createTable($table_name);
            $this->define_columns($table, $name === 'options');
            $table->build();
            $this->output()->check(CheckStatus::OK, $table_name, 'table created');
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            $table_name = $this->table($name);
            if (!$this->has_table($table_name)) {
                $this->output()->check(CheckStatus::SKIP, $table_name, 'table does not exist');
                continue;
            }

            $this->schema()->dropTable($table_name);
            $this->output()->check(CheckStatus::OK, $table_name, 'table dropped');
        }
    }

    /** Options is meta plus expired_at. */
    private function define_columns(TableCreator $table, bool $expires): void
    {
        $table->addColumn('uuid')->type(Schema::DT_VARCHAR128)->nullable(false);
        $table->addColumn('key')->type(Schema::DT_VARCHAR128)->nullable(false);
        $table->addColumn('value')->type(Schema::DT_TEXT)->nullable(true);
        $table->addColumn('created_at')->type(Schema::DT_TIMESTAMP)->defaults(Schema::DF_CURRENT_TIMESTAMP);
        if ($expires) {
            $table->addColumn('expired_at')->type(Schema::DT_DATETIME)->nullable(true);
        }
        $table->addColumn('updated_at')->type(Schema::DT_TIMESTAMP)->defaults(Schema::DF_CURRENT_TIMESTAMP);
    }
};
