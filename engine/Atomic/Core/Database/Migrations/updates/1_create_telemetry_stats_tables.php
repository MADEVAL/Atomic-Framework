<?php
declare(strict_types=1);

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\CLI\Console\CheckStatus;
use Engine\Atomic\Core\Migrations\Migration;
return new class extends Migration {
    public function up(): void
    {
        $prefix = (string)$this->app()->get('DB_CONFIG.prefix');
        $charset = (string)($this->app()->get('DB_CONFIG.charset') ?: 'utf8mb4');
        $collation = (string)($this->app()->get('DB_CONFIG.collation') ?: 'utf8mb4_unicode_ci');

        $tables = [
            $this->table('telemetry_counters') => "
                CREATE TABLE IF NOT EXISTS `{$prefix}telemetry_counters` (
                    `metric` VARCHAR(64) NOT NULL,
                    `tag_hash` CHAR(16) NOT NULL,
                    `tags` VARCHAR(512) NOT NULL DEFAULT '',
                    `bucket_start` INT UNSIGNED NOT NULL,
                    `value` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    PRIMARY KEY (`metric`, `tag_hash`, `bucket_start`),
                    KEY `{$prefix}tc_bucket_idx` (`bucket_start`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
            $this->table('telemetry_totals') => "
                CREATE TABLE IF NOT EXISTS `{$prefix}telemetry_totals` (
                    `metric` VARCHAR(64) NOT NULL,
                    `tag_hash` CHAR(16) NOT NULL,
                    `tags` VARCHAR(512) NOT NULL DEFAULT '',
                    `value` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    PRIMARY KEY (`metric`, `tag_hash`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
            $this->table('telemetry_events') => "
                CREATE TABLE IF NOT EXISTS `{$prefix}telemetry_events` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `metric` VARCHAR(64) NOT NULL,
                    `tag_hash` CHAR(16) NOT NULL DEFAULT '',
                    `tags` VARCHAR(512) NOT NULL DEFAULT '',
                    `data` TEXT NULL,
                    `created_at` INT UNSIGNED NOT NULL,
                    PRIMARY KEY (`id`),
                    KEY `{$prefix}te_metric_created_idx` (`metric`, `created_at`),
                    KEY `{$prefix}te_created_idx` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
            $this->table('telemetry_exception_groups') => "
                CREATE TABLE IF NOT EXISTS `{$prefix}telemetry_exception_groups` (
                    `fingerprint` CHAR(40) NOT NULL,
                    `class` VARCHAR(191) NOT NULL,
                    `message` TEXT NULL,
                    `file` VARCHAR(255) NOT NULL DEFAULT '',
                    `line` INT UNSIGNED NOT NULL DEFAULT 0,
                    `code` INT NOT NULL DEFAULT 0,
                    `level` VARCHAR(32) NOT NULL DEFAULT 'error',
                    `path` VARCHAR(255) NOT NULL DEFAULT '',
                    `dump_id` VARCHAR(64) DEFAULT NULL,
                    `trace` TEXT NULL,
                    `first_seen` INT UNSIGNED NOT NULL,
                    `last_seen` INT UNSIGNED NOT NULL,
                    `total` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    PRIMARY KEY (`fingerprint`),
                    KEY `{$prefix}teg_last_seen_idx` (`last_seen`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
            $this->table('telemetry_rate_keys') => "
                CREATE TABLE IF NOT EXISTS `{$prefix}telemetry_rate_keys` (
                    `key_hash` CHAR(16) NOT NULL,
                    `key_name` VARCHAR(191) NOT NULL,
                    `strategy` VARCHAR(32) NOT NULL DEFAULT 'fixed',
                    `rate_limit` INT UNSIGNED NOT NULL DEFAULT 0,
                    `window_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
                    `first_seen` INT UNSIGNED NOT NULL,
                    `last_seen` INT UNSIGNED NOT NULL,
                    PRIMARY KEY (`key_hash`),
                    KEY `{$prefix}trk_last_seen_idx` (`last_seen`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
            $this->table('telemetry_lockouts') => "
                CREATE TABLE IF NOT EXISTS `{$prefix}telemetry_lockouts` (
                    `identifier_hash` CHAR(16) NOT NULL,
                    `identifier` VARCHAR(191) NOT NULL,
                    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
                    `first_at` INT UNSIGNED DEFAULT NULL,
                    `locked_until` INT UNSIGNED DEFAULT NULL,
                    `updated_at` INT UNSIGNED NOT NULL,
                    PRIMARY KEY (`identifier_hash`),
                    KEY `{$prefix}tl_updated_idx` (`updated_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
            $this->table('telemetry_quota_scopes') => "
                CREATE TABLE IF NOT EXISTS `{$prefix}telemetry_quota_scopes` (
                    `scope` VARCHAR(191) NOT NULL,
                    `tier` VARCHAR(64) NOT NULL DEFAULT '',
                    `first_seen` INT UNSIGNED NOT NULL,
                    `last_seen` INT UNSIGNED NOT NULL,
                    PRIMARY KEY (`scope`)
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
            'telemetry_quota_scopes',
            'telemetry_lockouts',
            'telemetry_rate_keys',
            'telemetry_exception_groups',
            'telemetry_events',
            'telemetry_totals',
            'telemetry_counters',
        ] as $name) {
            $table = $this->table($name);
            $this->db()->exec("DROP TABLE IF EXISTS `{$table}`");
            $this->output()->check(CheckStatus::OK, $table, 'table dropped');
        }
    }
};
