<?php
declare(strict_types=1);

if (!defined('ATOMIC_START')) exit;

use DB\Cortex\Schema\Schema;
use DB\Cortex\Schema\TableCreator;
use Engine\Atomic\CLI\Console\CheckStatus;
use Engine\Atomic\Core\Migrations\Migration;

return new class extends Migration {
    private const TABLES = ['jobs', 'jobs_failed', 'jobs_completed', 'telemetry'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            $table_name = $this->table($name);
            if ($this->has_table($table_name)) {
                $this->output()->check(CheckStatus::SKIP, $table_name, 'table already exists');
                continue;
            }

            $table = $this->schema()->createTable($table_name);
            match ($name) {
                'jobs' => $this->define_jobs($table),
                'jobs_failed' => $this->define_jobs_failed($table),
                'jobs_completed' => $this->define_jobs_completed($table),
                'telemetry' => $this->define_telemetry($table),
            };
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

    private function define_job_columns(TableCreator $table): void
    {
        $table->addColumn('uuid')->type(Schema::DT_VARCHAR128)->nullable(false);
        $table->addColumn('queue')->type(Schema::DT_VARCHAR256)->nullable(false);
        $table->addColumn('priority')->type(Schema::DT_INT)->nullable(false);
        $table->addColumn('payload')->type(Schema::DT_TEXT)->nullable(false);
        $table->addColumn('max_attempts')->type(Schema::DT_INT)->nullable(false);
        $table->addColumn('attempts')->type(Schema::DT_INT)->nullable(false);
        $table->addColumn('timeout')->type(Schema::DT_INT)->nullable(false);
        $table->addColumn('retry_delay')->type(Schema::DT_INT)->nullable(false);
    }

    private function define_jobs(TableCreator $table): void
    {
        $this->define_job_columns($table);
        $table->addColumn('available_at')->type(Schema::DT_INT)->nullable(false);
        $table->addColumn('created_at')->type(Schema::DT_INT)->nullable(false);
        $table->addColumn('process_start_ticks')->type(Schema::DT_INT)->nullable(true);
        $table->addColumn('pid')->type(Schema::DT_INT)->nullable(true);
    }

    private function define_jobs_failed(TableCreator $table): void
    {
        $this->define_job_columns($table);
        $table->addColumn('exception')->type(Schema::DT_TEXT)->nullable(false);
        $table->addColumn('created_at')->type(Schema::DT_INT)->nullable(false);
    }

    private function define_jobs_completed(TableCreator $table): void
    {
        $this->define_job_columns($table);
        $table->addColumn('created_at')->type(Schema::DT_INT)->nullable(false);
    }

    private function define_telemetry(TableCreator $table): void
    {
        $table->addColumn('uuid')->type(Schema::DT_VARCHAR128)->nullable(false);
        $table->addColumn('uuid_batch')->type(Schema::DT_VARCHAR128)->nullable(true);
        $table->addColumn('uuid_job')->type(Schema::DT_VARCHAR128)->nullable(true);
        $table->addColumn('event_type_id')->type(Schema::DT_INT)->nullable(true);
        $table->addColumn('message')->type(Schema::DT_TEXT)->nullable(false);
        $table->addColumn('created_at')->type(Schema::DT_INT)->nullable(false);
    }
};
