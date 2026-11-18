<?php
declare(strict_types=1);

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\CLI\Console\CheckStatus;
use Engine\Atomic\Core\Migrations\Migration;

/**
 * Scheduler run persistence: one row per executed task (the Scheduled telemetry
 * tab) plus the task registry that carries the persisted enabled flag and owner.
 */
return new class extends Migration {
    public function up(): void
    {
        $prefix = (string)$this->app()->get('DB_CONFIG.prefix');
        $charset = (string)($this->app()->get('DB_CONFIG.charset') ?: 'utf8mb4');
        $collation = (string)($this->app()->get('DB_CONFIG.collation') ?: 'utf8mb4_unicode_ci');

        $tables = [
            $this->table('scheduler_runs') => "
                CREATE TABLE IF NOT EXISTS `{$prefix}scheduler_runs` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `task_key` CHAR(40) NOT NULL,
                    `name` VARCHAR(255) DEFAULT NULL,
                    `cron` VARCHAR(64) NOT NULL DEFAULT '',
                    `status` VARCHAR(16) NOT NULL DEFAULT 'ok',
                    `duration_ms` DECIMAL(12,2) NOT NULL DEFAULT 0,
                    `error` TEXT NULL,
                    `started_at` INT UNSIGNED NOT NULL,
                    `finished_at` INT UNSIGNED DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `{$prefix}sr_task_started_idx` (`task_key`, `started_at`),
                    KEY `{$prefix}sr_started_idx` (`started_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
            $this->table('scheduler_tasks') => "
                CREATE TABLE IF NOT EXISTS `{$prefix}scheduler_tasks` (
                    `task_key` CHAR(40) NOT NULL,
                    `name` VARCHAR(255) DEFAULT NULL,
                    `cron` VARCHAR(64) NOT NULL DEFAULT '',
                    `owner` VARCHAR(191) DEFAULT NULL,
                    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                    `first_seen` INT UNSIGNED NOT NULL,
                    `last_seen` INT UNSIGNED NOT NULL,
                    PRIMARY KEY (`task_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
        ];

        foreach ($tables as $table => $ddl) {
            $this->db()->exec($ddl);
            $this->output()->check(CheckStatus::OK, $table, 'table ensured');
        }
    }

    public function down(): void
    {
        foreach ([
            'scheduler_tasks',
            'scheduler_runs',
        ] as $name) {
            $table = $this->table($name);
            $this->db()->exec("DROP TABLE IF EXISTS `{$table}`");
            $this->output()->check(CheckStatus::OK, $table, 'table dropped');
        }
    }
};
