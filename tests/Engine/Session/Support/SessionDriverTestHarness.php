<?php
declare(strict_types=1);

namespace Tests\Engine\Session\Support;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Tests\Support\TestConfig;

trait SessionDriverTestHarness
{
    private array $session_original_state = [];
    private bool $session_table_migrated = false;

    protected function backup_session_state(): void
    {
        $atomic = App::instance();
        $this->session_original_state = [
            'SESSION_CONFIG' => $atomic->get('SESSION_CONFIG'),
            'DB_CONFIG' => $atomic->get('DB_CONFIG'),
            'DB' => $atomic->get('DB'),
            'REDIS' => $atomic->get('REDIS'),
            'IP' => $atomic->get('IP'),
            'HEADERS' => $atomic->get('HEADERS'),
        ];
    }

    protected function restore_session_state(): void
    {
        ConnectionManager::instance()->close();
        $atomic = App::instance();
        foreach ($this->session_original_state as $key => $value) {
            $atomic->set($key, $value);
        }
    }

    protected function configure_session(string $driver, int $lifetime = 60): void
    {
        App::instance()->set('SESSION_CONFIG', [
            'driver' => $driver,
            'lifetime' => $lifetime,
            'cookie' => 'Atomic_Test_Session',
            'kill_on_suspect' => true,
            'cookie_expire' => $lifetime,
            'cookie_path' => '/',
            'cookie_domain' => '',
            'cookie_secure' => false,
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
        ]);
    }

    protected function configure_request_context(string $ip = '127.0.0.1', string $agent = 'Atomic Test Agent'): void
    {
        $atomic = App::instance();
        $atomic->set('IP', $ip);
        $atomic->set('HEADERS', ['User-Agent' => $agent]);
    }

    protected function connect_redis_or_skip(): array
    {
        if (!\class_exists(\Redis::class)) {
            $this->markTestSkipped('ext-redis not loaded - session Redis driver tests cannot run.');
        }

        $cfg = TestConfig::redis((array)(App::instance()->get('REDIS') ?? []));
        $host = $this->required_redis_config_value($cfg, 'host');
        $port = (int)$this->required_redis_config_value($cfg, 'port');
        $password = $this->redis_config_value($cfg, 'password');
        $db = (int)$this->required_redis_config_value($cfg, 'db');

        try {
            $redis = new \Redis();
            if ($redis->connect($host, $port, 0.5)) {
                if ($password !== '') {
                    $redis->auth($password);
                }
                $redis->select($db);
                return [$redis, $host];
            }
        } catch (\Throwable) {
        }

        $this->markTestSkipped('Redis server unavailable - session Redis driver tests cannot run.');
    }

    protected function configure_redis_for_session(\Redis $redis, string $host, string $prefix, int $lifetime = 60): void
    {
        $cfg = TestConfig::redis((array)(App::instance()->get('REDIS') ?? []));
        $this->cleanup_redis_prefix($redis, $prefix);
        App::instance()->set('REDIS', TestConfig::redis(\array_merge($cfg, [
            'host' => $host,
            'port' => (int)$this->required_redis_config_value($cfg, 'port'),
            'password' => $this->redis_config_value($cfg, 'password'),
            'db' => (int)$this->required_redis_config_value($cfg, 'db'),
            'prefix' => $prefix,
        ])));
        ConnectionManager::instance()->close_redis();
        $this->configure_session('redis', $lifetime);
    }

    protected function cleanup_redis_prefix(\Redis $redis, string $prefix): void
    {
        $keys = $redis->keys($prefix . '*');
        if (\is_array($keys) && $keys !== []) {
            $redis->del($keys);
        }
    }

    protected function required_redis_config_value(array $cfg, string $key): string
    {
        if (!\array_key_exists($key, $cfg) || $cfg[$key] === null || (string)$cfg[$key] === '') {
            $this->markTestSkipped("REDIS.{$key} is not configured - session Redis driver tests cannot run.");
        }

        return (string)$cfg[$key];
    }

    protected function redis_config_value(array $cfg, string $key): string
    {
        if (!\array_key_exists($key, $cfg) || $cfg[$key] === null) {
            $this->markTestSkipped("REDIS.{$key} is not configured - session Redis driver tests cannot run.");
        }

        return (string)$cfg[$key];
    }

    protected function connect_pdo_or_skip(): array
    {
        if (!\extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('ext-pdo_mysql not loaded - session DB driver tests cannot run.');
        }

        $cfg = TestConfig::db((array)(App::instance()->get('DB_CONFIG') ?? []));
        $host = $this->required_db_config_value($cfg, 'host');
        $port = $this->required_db_config_value($cfg, 'port');
        $db = $this->required_db_config_value($cfg, 'db');
        $username = $this->required_db_config_value($cfg, 'username');
        $password = $this->required_db_config_value($cfg, 'password');
        $charset = $this->required_db_config_value($cfg, 'charset');

        try {
            $dsn = \sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $db, $charset);
            App::instance()->set('DB_CONFIG', TestConfig::db(\array_merge($cfg, [
                'host' => $host,
                'username' => $username,
                'password' => $password,
            ])));
            return [new \PDO($dsn, $username, $password), $host, $username, $password];
        } catch (\Throwable) {
        }

        $this->markTestSkipped('MySQL connection unavailable - session DB driver tests cannot run.');
    }

    protected function configure_db_for_session(string $host, string $prefix, int $lifetime = 60): void
    {
        $cfg = TestConfig::db((array)(App::instance()->get('DB_CONFIG') ?? []));
        $db = $this->required_db_config_value($cfg, 'db');
        $user = $this->required_db_config_value($cfg, 'username');
        $password = $this->required_db_config_value($cfg, 'password');
        $port = $this->required_db_config_value($cfg, 'port');
        $charset = $this->required_db_config_value($cfg, 'charset');

        App::instance()->set('DB_CONFIG', TestConfig::db(\array_merge($cfg, [
            'host' => $host,
            'prefix' => $prefix,
        ])));
        ConnectionManager::instance()->close_sql();

        $dsn = \sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $db, $charset);
        App::instance()->set('DB', new \DB\SQL($dsn, $user, $password));
        $this->configure_session('db', $lifetime);
    }

    protected function required_db_config_value(array $cfg, string $key): string
    {
        if (!\array_key_exists($key, $cfg) || $cfg[$key] === null || (string)$cfg[$key] === '') {
            $this->markTestSkipped("DB_CONFIG.{$key} is not configured - session DB driver tests cannot run.");
        }

        return (string)$cfg[$key];
    }

    protected function migrate_session_table_up(): void
    {
        $migration = require ATOMIC_ENGINE . 'Atomic/Core/Database/Migrations/atomic_create_session_table.php';
        \ob_start();
        try {
            $migration['up']();
        } finally {
            \ob_end_clean();
        }
        $this->session_table_migrated = true;
    }

    protected function migrate_session_table_down(): void
    {
        if (!$this->session_table_migrated) {
            return;
        }

        $migration = require ATOMIC_ENGINE . 'Atomic/Core/Database/Migrations/atomic_create_session_table.php';
        \ob_start();
        try {
            $migration['down']();
        } finally {
            \ob_end_clean();
        }
        $this->session_table_migrated = false;
    }

    protected function new_db_prefix(): string
    {
        return 'atomic_session_test_' . \bin2hex(\random_bytes(4)) . '_';
    }

    protected function new_redis_prefix(): string
    {
        return 'atomic_session_test_' . \bin2hex(\random_bytes(4)) . ':';
    }

    protected function session_table(): string
    {
        return (string)App::instance()->get('DB_CONFIG.prefix') . 'sessions';
    }

    protected function quoted_session_table(): string
    {
        return '`' . \str_replace('`', '``', $this->session_table()) . '`';
    }

    protected function insert_db_session(
        string $session_id,
        string $data,
        string $ip = '127.0.0.1',
        string $agent = 'Atomic Test Agent',
        ?int $stamp = null,
    ): void {
        $this->db()->exec(
            'INSERT INTO ' . $this->quoted_session_table() . ' (session_id, data, ip, agent, stamp) VALUES (?, ?, ?, ?, ?)',
            [$session_id, $data, $ip, $agent, $stamp ?? \time()]
        );
    }

    protected function db_session_row(string $session_id): ?array
    {
        $rows = $this->db()->exec(
            'SELECT session_id, data, ip, agent, stamp FROM ' . $this->quoted_session_table() . ' WHERE session_id = ?',
            [$session_id]
        );

        return $rows[0] ?? null;
    }

    protected function db_session_count(string $session_id): int
    {
        $rows = $this->db()->exec(
            'SELECT COUNT(*) AS cnt FROM ' . $this->quoted_session_table() . ' WHERE session_id = ?',
            [$session_id]
        );

        return (int)($rows[0]['cnt'] ?? 0);
    }

    protected function db(): \DB\SQL
    {
        $db = App::instance()->get('DB');
        $this->assertInstanceOf(\DB\SQL::class, $db);
        return $db;
    }
}
