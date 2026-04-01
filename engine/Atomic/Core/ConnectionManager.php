<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Log;
use DB\SQL;

class ConnectionManager
{
    private const DEFAULT_DB_HEALTHCHECK_INTERVAL = 5.0;
    private const DEFAULT_REDIS_HEALTHCHECK_INTERVAL = 5.0;
    private const DEFAULT_MEMCACHED_HEALTHCHECK_INTERVAL = 5.0;

    private ?SQL $mysql = null;
    private ?\Redis $redis = null;
    private ?\Memcached $memcached = null;
    private float $mysql_last_used_at = 0.0;
    private float $redis_last_used_at = 0.0;
    private float $memcached_last_used_at = 0.0;

    public function __destruct()
    {
        $this->close();
    }

    private function now(): float
    {
        return \microtime(true);
    }

    private function should_health_check(float $last_used_at, float $interval): bool
    {
        if ($interval <= 0.0) {
            return false;
        }

        if ($last_used_at <= 0.0) {
            return true;
        }

        return ($this->now() - $last_used_at) >= $interval;
    }

    private function open_db(): array
    {
        $reconnected = false;

        if ($this->mysql) {
            if (!$this->should_health_check($this->mysql_last_used_at, self::DEFAULT_DB_HEALTHCHECK_INTERVAL)) {
                $this->mysql_last_used_at = $this->now();
                return [$this->mysql, false];
            }

            try {
                $res = $this->mysql->exec('SELECT 1');
                if (!isset($res[0]['1']) || (int)$res[0]['1'] !== 1) {
                    throw new \RuntimeException('MySQL ping returned unexpected result: ' . var_export($res, true));
                }
                $this->mysql_last_used_at = $this->now();
                return [$this->mysql, false];
            } catch (\Throwable $e) {
                Log::warning("MySQL ping failed, reconnecting: " . $e->getMessage());
                $this->mysql = null;
                $this->mysql_last_used_at = 0.0;
            }
        }
        
        $cfg = App::instance()->get('DB_CONFIG') ?? [];
        $username = $cfg['username'];
        $password = $cfg['password'];
        $host = $cfg['host'];
        $database = $cfg['database'];
        $charset = $cfg['charset'];
        $collation = $cfg['collation'];

        $dsn = "mysql:host={$host};dbname={$database}";
        if (!empty($charset)) {
            $dsn .= ";charset={$charset}";
        }
        if (!empty($cfg['unix_socket'])) {
            $dsn .= ";unix_socket={$cfg['unix_socket']}";
        } elseif (!empty($cfg['port'])) {
            $dsn .= ";port=" . (int)$cfg['port'];
        }

        $options = [];
        if (defined('Pdo\\Mysql::ATTR_INIT_COMMAND')) {
            $options[\Pdo\Mysql::ATTR_INIT_COMMAND] = "SET NAMES '{$charset}' COLLATE '{$collation}'";
        }

        try {
            $this->mysql = new \DB\SQL($dsn, $username, $password, $options);
            $this->mysql_last_used_at = $this->now();
            $reconnected = true;
            return [$this->mysql, $reconnected];
        } catch (\Throwable $e) {
            Log::error("ConnectionManager: MySQL connect failed: " . $e->getMessage());
            return [null, $reconnected];
        }
    }

    private function open_redis(): ?\Redis
    {
        if (!extension_loaded('redis')) {
            return null;
        }

        if ($this->redis) {
            if (!$this->should_health_check($this->redis_last_used_at, self::DEFAULT_REDIS_HEALTHCHECK_INTERVAL)) {
                $this->redis_last_used_at = $this->now();
                return $this->redis;
            }

            try {
                $this->redis->ping();
                $this->redis_last_used_at = $this->now();
                return $this->redis;
            } catch (\Throwable $e) {
                Log::warning("Redis ping failed, reconnecting: " . $e->getMessage());
                try {
                    $this->redis->close();
                } catch (\Throwable $_) {}
                $this->redis = null;
                $this->redis_last_used_at = 0.0;
            }
        }

        $cfg = App::instance()->get('REDIS') ?? [];
        $host = !empty($cfg['host']) ? $cfg['host'] : '127.0.0.1';
        $port = !empty($cfg['port']) ? (int)$cfg['port'] : 6379;

        try {
            $r = new \Redis();
            $r->connect($host, $port, 1.0);
            
            $this->redis = $r;
            $this->redis_last_used_at = $this->now();
            return $this->redis;
        } catch (\Throwable $e) {
            Log::error("ConnectionManager: Redis connect failed: " . $e->getMessage());
            return null;
        }
    }

    private function open_memcached(): ?\Memcached
    {
        if ($this->memcached) {
            if (!$this->should_health_check($this->memcached_last_used_at, self::DEFAULT_MEMCACHED_HEALTHCHECK_INTERVAL)) {
                $this->memcached_last_used_at = $this->now();
                return $this->memcached;
            }

            try {
                $version = $this->memcached->getVersion();
                if ($version !== false) {
                    $this->memcached_last_used_at = $this->now();
                    return $this->memcached;
                }
                Log::warning("Memcached version check failed, reconnecting");
            } catch (\Throwable $e) {
                Log::warning("Memcached ping failed, reconnecting: " . $e->getMessage());
            }

            try {
                $this->memcached->quit();
            } catch (\Throwable $_) {}
            $this->memcached = null;
            $this->memcached_last_used_at = 0.0;
        }

        $cfg = App::instance()->get('MEMCACHED') ?? [];
        $host = !empty($cfg['host']) ? $cfg['host'] : '127.0.0.1';
        $port = !empty($cfg['port']) ? (int)$cfg['port'] : 11211;

        try {
            $m = new \Memcached();
            $m->setOptions([
                \Memcached::OPT_BINARY_PROTOCOL => true,
                \Memcached::OPT_CONNECT_TIMEOUT => 200,
                \Memcached::OPT_TCP_NODELAY => true,
            ]);
            $m->addServer($host, $port);
            
            $version = $m->getVersion();
            if ($version === false) {
                throw new \RuntimeException('Cannot connect to Memcached server');
            }
            
            $this->memcached = $m;
            $this->memcached_last_used_at = $this->now();
            return $this->memcached;
        } catch (\Throwable $e) {
            Log::error("ConnectionManager: Memcached connect failed: " . $e->getMessage());
            return null;
        }
    }

    public function get_db(bool $required = true, bool $if_reconnected = false): array|SQL
    {
        list($db, $reconnected) = $this->open_db();
        if ($db === null && $required) {
            throw new \RuntimeException('MySQL connection failed');
        }
        if ($if_reconnected) {
            return [$db, $reconnected];
        }
        return $db;
    }

    public function get_redis(bool $required = true): ?\Redis
    {
        $r = $this->open_redis();
        if ($r === null && $required) {
            throw new \RuntimeException('Redis connection failed');
        }
        return $r;
    }

    public function get_memcached(bool $required = true): ?\Memcached
    {
        $m = $this->open_memcached();
        if ($m === null && $required) {
            throw new \RuntimeException('Memcached connection failed');
        }
        return $m;
    }

    public function close_sql(): void
    {
        $this->mysql = null;
        $this->mysql_last_used_at = 0.0;
    }

    public function close_redis(): void
    {
        if ($this->redis) {
            try {
                $this->redis->close();
            } catch (\Throwable $_) {}
            $this->redis = null;
        }
        $this->redis_last_used_at = 0.0;
    }

    public function close_memcached(): void
    {
        if ($this->memcached) {
            try {
                $this->memcached->quit();
            } catch (\Throwable $_) {}
            $this->memcached = null;
        }
        $this->memcached_last_used_at = 0.0;
    }
    
    public function close(): void
    {
        $this->close_sql();
        $this->close_redis();
        $this->close_memcached();
    }
}
