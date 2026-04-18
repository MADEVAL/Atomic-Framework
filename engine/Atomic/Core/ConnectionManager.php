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

    private static ?self $instance = null;

    // TODO: multi-connection - arrays already keyed by name; to add a new connection
    // add config resolution in get_*_config() and call get_db('replica') etc.
    private array $mysql_connections = [];
    private array $redis_connections = [];
    private array $memcached_connections = [];
    private array $mysql_last_used_at = [];
    private array $redis_last_used_at = [];
    private array $memcached_last_used_at = [];

    private function __construct() {}

    public static function instance(): static
    {
        if (static::$instance === null) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function now(): float
    {
        return \microtime(true);
    }

    private function normalize_optional_string(mixed $value): ?string
    {
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        $value = trim((string)$value);
        if ($value === '' || strtolower($value) === 'null') {
            return null;
        }

        return $value;
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

    // TODO: multi-connection - resolve config by name; e.g. App::instance()->get("DB_CONFIG_{$name}") ?? []
    private function get_db_config(string $name): array
    {
        return App::instance()->get('DB_CONFIG') ?? [];
    }

    // TODO: multi-connection - resolve config by name; e.g. App::instance()->get("REDIS_{$name}") ?? []
    private function get_redis_config(string $name): array
    {
        return App::instance()->get('REDIS') ?? [];
    }

    // TODO: multi-connection - resolve config by name; e.g. App::instance()->get("MEMCACHED_{$name}") ?? []
    private function get_memcached_config(string $name): array
    {
        return App::instance()->get('MEMCACHED') ?? [];
    }

    private function open_db(string $name = 'default'): array
    {
        $reconnected = false;

        if (isset($this->mysql_connections[$name])) {
            if (!$this->should_health_check($this->mysql_last_used_at[$name] ?? 0.0, self::DEFAULT_DB_HEALTHCHECK_INTERVAL)) {
                $this->mysql_last_used_at[$name] = $this->now();
                return [$this->mysql_connections[$name], false];
            }

            try {
                $res = $this->mysql_connections[$name]->exec('SELECT 1');
                if (!isset($res[0]['1']) || (int)$res[0]['1'] !== 1) {
                    throw new \RuntimeException('MySQL ping returned unexpected result: ' . var_export($res, true));
                }
                $this->mysql_last_used_at[$name] = $this->now();
                return [$this->mysql_connections[$name], false];
            } catch (\Throwable $e) {
                Log::warning("MySQL ping failed, reconnecting: " . $e->getMessage());
                unset($this->mysql_connections[$name]);
                $this->mysql_last_used_at[$name] = 0.0;
            }
        }

        $cfg = $this->get_db_config($name);
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
            $this->mysql_connections[$name] = new \DB\SQL($dsn, $username, $password, $options);
            $this->mysql_last_used_at[$name] = $this->now();
            $reconnected = true;
            return [$this->mysql_connections[$name], $reconnected];
        } catch (\Throwable $e) {
            Log::error("ConnectionManager: MySQL connect failed: " . $e->getMessage());
            return [null, $reconnected];
        }
    }

    private function open_redis(string $name = 'default'): ?\Redis
    {
        if (!extension_loaded('redis')) {
            return null;
        }

        if (isset($this->redis_connections[$name])) {
            if (!$this->should_health_check($this->redis_last_used_at[$name] ?? 0.0, self::DEFAULT_REDIS_HEALTHCHECK_INTERVAL)) {
                $this->redis_last_used_at[$name] = $this->now();
                return $this->redis_connections[$name];
            }

            try {
                $this->redis_connections[$name]->ping();
                $this->redis_last_used_at[$name] = $this->now();
                return $this->redis_connections[$name];
            } catch (\Throwable $e) {
                Log::warning("Redis ping failed, reconnecting: " . $e->getMessage());
                try {
                    $this->redis_connections[$name]->close();
                } catch (\Throwable $_) {}
                unset($this->redis_connections[$name]);
                $this->redis_last_used_at[$name] = 0.0;
            }
        }

        $cfg = $this->get_redis_config($name);
        $host = (string)$cfg['host'];
        $port = (int)$cfg['port'];

        try {
            $r = new \Redis();
            $r->connect($host, $port, 1.0);
            $password = $this->normalize_optional_string($cfg['password']);
            if ($password !== null) {
                $r->auth($password);
            }
            $db = (int)$cfg['db'];
            if ($db !== 0) {
                $r->select($db);
            }

            $this->redis_connections[$name] = $r;
            $this->redis_last_used_at[$name] = $this->now();
            return $this->redis_connections[$name];
        } catch (\Throwable $e) {
            Log::error("ConnectionManager: Redis connect failed: " . $e->getMessage());
            return null;
        }
    }

    private function open_memcached(string $name = 'default'): ?\Memcached
    {
        if (isset($this->memcached_connections[$name])) {
            if (!$this->should_health_check($this->memcached_last_used_at[$name] ?? 0.0, self::DEFAULT_MEMCACHED_HEALTHCHECK_INTERVAL)) {
                $this->memcached_last_used_at[$name] = $this->now();
                return $this->memcached_connections[$name];
            }

            try {
                $version = $this->memcached_connections[$name]->getVersion();
                if ($version !== false) {
                    $this->memcached_last_used_at[$name] = $this->now();
                    return $this->memcached_connections[$name];
                }
                Log::warning("Memcached version check failed, reconnecting");
            } catch (\Throwable $e) {
                Log::warning("Memcached ping failed, reconnecting: " . $e->getMessage());
            }

            try {
                $this->memcached_connections[$name]->quit();
            } catch (\Throwable $_) {}
            unset($this->memcached_connections[$name]);
            $this->memcached_last_used_at[$name] = 0.0;
        }

        $cfg = $this->get_memcached_config($name);
        $host = (string)$cfg['host'];
        $port = (int)$cfg['port'];

        try {
            $m = new \Memcached();
            $username = $this->normalize_optional_string($cfg['username']);
            $password = $this->normalize_optional_string($cfg['password']);
            if ($username !== null && $password !== null) {
                $m->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
                $m->setSaslAuthData($username, $password);
            }
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

            $this->memcached_connections[$name] = $m;
            $this->memcached_last_used_at[$name] = $this->now();
            return $this->memcached_connections[$name];
        } catch (\Throwable $e) {
            Log::error("ConnectionManager: Memcached connect failed: " . $e->getMessage());
            return null;
        }
    }

    public function get_db(bool $required = true, bool $if_reconnected = false, string $name = 'default'): array|SQL
    {
        list($db, $reconnected) = $this->open_db($name);
        if ($db === null && $required) {
            throw new \RuntimeException('MySQL connection failed');
        }
        if ($if_reconnected) {
            return [$db, $reconnected];
        }
        return $db;
    }

    public function get_redis(bool $required = true, string $name = 'default'): ?\Redis
    {
        $r = $this->open_redis($name);
        if ($r === null && $required) {
            throw new \RuntimeException('Redis connection failed');
        }
        return $r;
    }

    public function get_memcached(bool $required = true, string $name = 'default'): ?\Memcached
    {
        $m = $this->open_memcached($name);
        if ($m === null && $required) {
            throw new \RuntimeException('Memcached connection failed');
        }
        return $m;
    }

    public function close_sql(string $name = 'default'): void
    {
        unset($this->mysql_connections[$name]);
        $this->mysql_last_used_at[$name] = 0.0;
    }

    public function close_redis(string $name = 'default'): void
    {
        if (isset($this->redis_connections[$name])) {
            try {
                $this->redis_connections[$name]->close();
            } catch (\Throwable $_) {}
            unset($this->redis_connections[$name]);
        }
        $this->redis_last_used_at[$name] = 0.0;
    }

    public function close_memcached(string $name = 'default'): void
    {
        if (isset($this->memcached_connections[$name])) {
            try {
                $this->memcached_connections[$name]->quit();
            } catch (\Throwable $_) {}
            unset($this->memcached_connections[$name]);
        }
        $this->memcached_last_used_at[$name] = 0.0;
    }

    public function close(): void
    {
        foreach (array_keys($this->mysql_connections) as $name) {
            $this->close_sql($name);
        }
        foreach (array_keys($this->redis_connections) as $name) {
            $this->close_redis($name);
        }
        foreach (array_keys($this->memcached_connections) as $name) {
            $this->close_memcached($name);
        }
    }

    public function open_all(): void
    {
        $atomic = App::instance();

        $db_cfg = $atomic->get('DB_CONFIG') ?? [];
        if (
            !empty($db_cfg['host']) &&
            !empty($db_cfg['database']) &&
            !empty($db_cfg['username']) &&
            !empty($db_cfg['password'])
        ) {
            $this->open_db();
        }

        if (\extension_loaded('redis') && !empty($atomic->get('REDIS'))) {
            $this->open_redis();
        }

        if (!empty($atomic->get('MEMCACHED'))) {
            $this->open_memcached();
        }
    }
}
