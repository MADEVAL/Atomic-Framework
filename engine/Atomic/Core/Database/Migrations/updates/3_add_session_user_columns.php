<?php
declare(strict_types=1);

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\CLI\Console\CheckStatus;
use Engine\Atomic\Core\Migrations\Migration;

/**
 * Promotes the session owner and creation time (written by AuthSessionService
 * into SESSION.user_uuid / SESSION.created_at) to real columns so the telemetry
 * security report can list sessions with indexed queries instead of decoding
 * the serialized payload at read time.
 */
return new class extends Migration {
    private const INDEX = 'sessions_user_uuid_idx';

    public function up(): void
    {
        $table = $this->table('sessions');
        // The sessions table is optional (published on demand via db/sessions,
        // which already creates these columns) - nothing to extend yet.
        if (!$this->has_table($table)) {
            $this->output()->check(CheckStatus::SKIP, $table, 'table does not exist');
            return;
        }

        $columns = $this->columns($table);
        $this->add_column($table, $columns, 'user_uuid', 'VARCHAR(128) DEFAULT NULL AFTER `session_id`');
        $this->add_column($table, $columns, 'created_at', 'INT UNSIGNED DEFAULT NULL AFTER `user_uuid`');

        if (!$this->db()->exec("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [self::INDEX])) {
            $this->db()->exec("ALTER TABLE `{$table}` ADD INDEX `" . self::INDEX . '` (`user_uuid`)');
            $this->output()->check(CheckStatus::OK, $table, 'index ' . self::INDEX . ' added');
        }
    }

    public function down(): void
    {
        $table = $this->table('sessions');
        if (!$this->has_table($table)) {
            $this->output()->check(CheckStatus::SKIP, $table, 'table does not exist');
            return;
        }

        $columns = $this->columns($table);
        if (isset($columns['created_at'])) {
            $this->db()->exec("ALTER TABLE `{$table}` DROP COLUMN `created_at`");
            $this->output()->check(CheckStatus::OK, $table, 'column created_at dropped');
        }
        if (isset($columns['user_uuid'])) {
            $this->db()->exec("ALTER TABLE `{$table}` DROP INDEX `" . self::INDEX . '`');
            $this->db()->exec("ALTER TABLE `{$table}` DROP COLUMN `user_uuid`");
            $this->output()->check(CheckStatus::OK, $table, 'column user_uuid dropped');
        }
    }

    /** @return array<string, true> */
    private function columns(string $table): array
    {
        $columns = [];
        foreach ($this->db()->exec("SHOW COLUMNS FROM `{$table}`") ?: [] as $column) {
            $columns[(string)($column['Field'] ?? '')] = true;
        }

        return $columns;
    }

    /** @param array<string, true> $columns */
    private function add_column(string $table, array $columns, string $column, string $definition): void
    {
        if (isset($columns[$column])) {
            $this->output()->check(CheckStatus::SKIP, $table, "column {$column} already exists");
            return;
        }

        $this->db()->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        $this->output()->check(CheckStatus::OK, $table, "column {$column} added");
    }
};
