<?php

declare(strict_types=1);

namespace Engine\Atomic\Mutex;

if (!defined('ATOMIC_START')) exit;

use DB\SQL;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Log;

class DatabaseMutexDriver implements MutexDriverInterface
{
    protected ConnectionManager $connectionManager;
    protected ?SQL $db;
    protected string $table;

    public function __construct()
    {
        $this->connectionManager = ConnectionManager::instance();
        $this->db = $this->connectionManager->get_db(false);

        $cfg = App::instance()->get('DB_CONFIG') ?? [];
        $prefix = $cfg['ATOMIC_DB_PREFIX'] ?? '';
        $this->table = (string)$prefix . 'mutex_locks';
    }

    private function ensure_db(): ?SQL
    {
        $this->db = $this->connectionManager->get_db(false);
        return $this->db;
    }

    private function quotekey(string $identifier): string
    {
        return ($this->db instanceof SQL) ? $this->db->quotekey($identifier) : $identifier;
    }

    public function acquire(string $name, string $token, int $ttl): bool
    {
        if ($ttl <= 0) return false;

        $db = $this->ensure_db();
        if ($db === null) return false;

        $table      = $this->quotekey($this->table);
        $nameCol    = $this->quotekey('name');
        $tokenCol   = $this->quotekey('token');
        $expiresCol = $this->quotekey('expires_at');
        $createdCol = $this->quotekey('created_at');

        try {
            $db->begin();

            $db->exec(
                "DELETE FROM {$table} WHERE {$nameCol} = ? AND {$expiresCol} <= UNIX_TIMESTAMP()",
                [$name]
            );

            $db->exec(
                "INSERT IGNORE INTO {$table} ({$nameCol}, {$tokenCol}, {$expiresCol}, {$createdCol})
                 VALUES (?, ?, UNIX_TIMESTAMP() + ?, UNIX_TIMESTAMP())",
                [$name, $token, $ttl]
            );

            $inserted = (int)$db->count();

            $db->commit();

            return $inserted === 1;
        } catch (\Throwable $e) {
            try { $db->rollback(); } catch (\Throwable $_) {}
            Log::error('[Mutex] Database acquire failed: ' . $e->getMessage());
            return false;
        }
    }

    public function release(string $name, string $token): bool
    {
        $db = $this->ensure_db();
        if ($db === null) return false;

        $table    = $this->quotekey($this->table);
        $nameCol  = $this->quotekey('name');
        $tokenCol = $this->quotekey('token');

        try {
            $db->exec(
                "DELETE FROM {$table} WHERE {$nameCol} = ? AND {$tokenCol} = ?",
                [$name, $token]
            );

            return (int)$db->count() > 0;
        } catch (\Throwable $e) {
            Log::error('[Mutex] Database release failed: ' . $e->getMessage());
            return false;
        }
    }

    public function exists(string $name): bool
    {
        $db = $this->ensure_db();
        if ($db === null) return false;

        $table      = $this->quotekey($this->table);
        $nameCol    = $this->quotekey('name');
        $expiresCol = $this->quotekey('expires_at');

        try {
            $rows = $db->exec(
                "SELECT 1 FROM {$table} WHERE {$nameCol} = ? AND {$expiresCol} > UNIX_TIMESTAMP() LIMIT 1",
                [$name]
            );

            return !empty($rows);
        } catch (\Throwable $e) {
            Log::error('[Mutex] Database exists check failed: ' . $e->getMessage());
            return false;
        }
    }

    public function get_token(string $name): ?string
    {
        $db = $this->ensure_db();
        if ($db === null) return null;

        $table      = $this->quotekey($this->table);
        $nameCol    = $this->quotekey('name');
        $expiresCol = $this->quotekey('expires_at');
        $tokenCol   = $this->quotekey('token');

        try {
            $rows = $db->exec(
                "SELECT {$tokenCol} AS token
                 FROM {$table}
                 WHERE {$nameCol} = ? AND {$expiresCol} > UNIX_TIMESTAMP()
                 LIMIT 1",
                [$name]
            );

            if (empty($rows)) return null;

            $val = $rows[0]['token'] ?? null;
            return is_string($val) ? $val : null;
        } catch (\Throwable $e) {
            Log::error('[Mutex] Database get_token failed: ' . $e->getMessage());
            return null;
        }
    }

    public function force_release(string $name): bool
    {
        $db = $this->ensure_db();
        if ($db === null) return false;

        $table   = $this->quotekey($this->table);
        $nameCol = $this->quotekey('name');

        try {
            $db->exec("DELETE FROM {$table} WHERE {$nameCol} = ?", [$name]);
            return true;
        } catch (\Throwable $e) {
            Log::error('[Mutex] Database force release failed: ' . $e->getMessage());
            return false;
        }
    }

    public function get_name(): string
    {
        return 'db';
    }

    public function is_available(): bool
    {
        try {
            $db = $this->ensure_db();
            if ($db === null) return false;

            $table = $this->quotekey($this->table);
            $db->exec("SELECT 1 FROM {$table} LIMIT 1");

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
