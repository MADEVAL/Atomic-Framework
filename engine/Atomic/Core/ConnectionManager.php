<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\Traits\Singleton;
use DB\SQL;

class ConnectionManager
{
    use Singleton;

    private const DEFAULT_DB_HEALTHCHECK_INTERVAL = 5.0;
    private const DEFAULT_REDIS_HEALTHCHECK_INTERVAL = 5.0;
    private const DEFAULT_MEMCACHED_HEALTHCHECK_INTERVAL = 5.0;
    private const DEFAULT_REDIS_CONNECT_TIMEOUT = 1.0;
    private const DEFAULT_MEMCACHED_CONNECT_TIMEOUT_MS = 200;
    private const MEMCACHED_HEALTHCHECK_KEY = '__atomic_memcached_healthcheck__';
    private const REDIS_HEALTHCHECK_KEY = '__atomic_redis_healthcheck__';

    // TODO: multi-connection - arrays already keyed by name; to add a new connection
    // add config resolution in get_*_config() and call get_db('replica') etc.
    private array $mysql_connections = [];
    private array $redis_connections = [];
    private array $memcached_connections = [];
    private array $mysql_last_used_at = [];
    private array $redis_last_used_at = [];
    private array $memcached_last_used_at = [];

    private function __construct() {}

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
        return $this->require_config_array(App::instance()->get('DB_CONFIG'), 'DB_CONFIG');
    }

    // TODO: multi-connection - resolve config by name; e.g. App::instance()->get("REDIS_{$name}") ?? []
    private function get_redis_config(string $name): array
    {
        return $this->require_config_array(App::instance()->get('REDIS'), 'REDIS');
    }

    // TODO: multi-connection - resolve config by name; e.g. App::instance()->get("MEMCACHED_{$name}") ?? []
    private function get_memcached_config(string $name): array
    {
        return $this->require_config_array(App::instance()->get('MEMCACHED'), 'MEMCACHED');
    }

    private function require_config_array(mixed $config, string $path): array
    {
        if (!is_array($config)) {
            throw new \RuntimeException("Missing loaded config array '{$path}'");
        }

        return $config;
    }

    private function require_config_value(array $config, string $key, string $path): mixed
    {
        if (!array_key_exists($key, $config)) {
            throw new \RuntimeException("Missing loaded config value '{$path}.{$key}'");
        }

        return $config[$key];
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
        $db = $cfg['db'];
        $charset = $cfg['charset'];
        $collation = $cfg['collation'];

        $dsn = "mysql:host=" . $this->sanitize_dsn_value($host) . ";dbname=" . $this->sanitize_dsn_value($db);
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
                if ($this->redis_can_read($this->redis_connections[$name])) {
                    $this->redis_last_used_at[$name] = $this->now();
                    return $this->redis_connections[$name];
                }
                throw new \RuntimeException('Redis healthcheck read failed');
            } catch (\Throwable $e) {
                Log::warning("Redis healthcheck failed, reconnecting: " . $e->getMessage());
                try {
                    $this->redis_connections[$name]->close();
                } catch (\Throwable $_) {}
                unset($this->redis_connections[$name]);
                $this->redis_last_used_at[$name] = 0.0;
            }
        }

        try {
            $this->redis_connections[$name] = $this->create_redis($this->get_redis_config($name));
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
            try {
                $version = $this->memcached_connections[$name]->getVersion();
                if (
                    is_array($version)
                    && $version !== []
                    && !in_array(false, $version, true)
                    && $this->memcached_connections[$name]->getResultCode() === \Memcached::RES_SUCCESS
                    && $this->memcached_can_read($this->memcached_connections[$name])
                ) {
                    $this->memcached_last_used_at[$name] = $this->now();
                    return $this->memcached_connections[$name];
                }
                Log::warning("Memcached version check failed, reconnecting: " . $this->memcached_connections[$name]->getResultMessage());
            } catch (\Throwable $e) {
                Log::warning("Memcached ping failed, reconnecting: " . $e->getMessage());
            }

            try {
                $this->memcached_connections[$name]->quit();
            } catch (\Throwable $_) {}
            unset($this->memcached_connections[$name]);
            $this->memcached_last_used_at[$name] = 0.0;
        }

        try {
            $this->memcached_connections[$name] = $this->create_memcached($this->get_memcached_config($name));
            $this->memcached_last_used_at[$name] = $this->now();
            return $this->memcached_connections[$name];
        } catch (\Throwable $e) {
            Log::error("ConnectionManager: Memcached connect failed: " . $e->getMessage());
            return null;
        }
    }

    private function memcached_can_read(\Memcached $memcached): bool
    {
        $memcached->get(self::MEMCACHED_HEALTHCHECK_KEY);
        return in_array($memcached->getResultCode(), [\Memcached::RES_SUCCESS, \Memcached::RES_NOTFOUND], true);
    }

    private function redis_can_read(\Redis $redis): bool
    {
        $redis->get(self::REDIS_HEALTHCHECK_KEY);
        return true;
    }

    private function create_redis(array $config): \Redis
    {
        if (!extension_loaded('redis')) {
            throw new \RuntimeException('The redis PHP extension is not loaded.');
        }

        $host = (string)$this->require_config_value($config, 'host', 'REDIS');
        $port = (int)$this->require_config_value($config, 'port', 'REDIS');
        $redis = new \Redis();
        $redis->connect($host, $port, self::DEFAULT_REDIS_CONNECT_TIMEOUT);

        $login = array_key_exists('username', $config)
            ? $this->normalize_optional_string($config['username'])
            : null;
        $password = $this->normalize_optional_string($this->require_config_value($config, 'password', 'REDIS'));
        if ($login !== null && $login !== '' && $password !== null) {
            $redis->auth([$login, $password]);
        } elseif ($password !== null && $password !== '') {
            $redis->auth($password);
        }

        $db = (int)$this->require_config_value($config, 'db', 'REDIS');
        if ($db !== 0) {
            $redis->select($db);
        }

        return $redis;
    }

    private function create_memcached(array $config): \Memcached
    {
        if (!extension_loaded('memcached')) {
            throw new \RuntimeException('The memcached PHP extension is not loaded.');
        }

        $host = (string)$this->require_config_value($config, 'host', 'MEMCACHED');
        $port = (int)$this->require_config_value($config, 'port', 'MEMCACHED');
        $memcached = new \Memcached();

        $username = array_key_exists('username', $config)
            ? $this->normalize_optional_string($config['username'])
            : null;
        $password = array_key_exists('password', $config)
            ? $this->normalize_optional_string($config['password'])
            : null;
        if ($username !== null && $password !== null) {
            $memcached->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
            $memcached->setSaslAuthData($username, $password);
        }

        $memcached->setOptions([
            \Memcached::OPT_BINARY_PROTOCOL => true,
            \Memcached::OPT_CONNECT_TIMEOUT => self::DEFAULT_MEMCACHED_CONNECT_TIMEOUT_MS,
            \Memcached::OPT_TCP_NODELAY => true,
        ]);
        $memcached->addServer($host, $port);

        $version = $memcached->getVersion();
        if ($version === false || $version === []) {
            throw new \RuntimeException('Cannot connect to Memcached server');
        }

        return $memcached;
    }

    public function get_db(bool $required = true, bool $if_reconnected = false, string $name = 'default'): array|SQL|null
    {
        list($db, $reconnected) = $this->open_db($name);
        if ($db === null && $required) {
            throw new \RuntimeException('MySQL connection failed');
        }
        if ($db instanceof SQL && $name === 'default') {
            App::instance()->set('DB', $db);
        }
        if ($if_reconnected) {
            return [$db, $reconnected];
        }
        return $db;
    }

    public function get_redis(bool $required = true, string $name = 'default'): ?\Redis
    {
        $redis = $this->open_redis($name);
        if ($redis === null && $required) {
            throw new \RuntimeException('Redis connection failed');
        }
        return $redis;
    }

    public function get_memcached(bool $required = true, string $name = 'default'): ?\Memcached
    {
        $memcached = $this->open_memcached($name);
        if ($memcached === null && $required) {
            throw new \RuntimeException('Memcached connection failed');
        }
        return $memcached;
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

        $db_cfg = $atomic->get('DB_CONFIG');
        if (
            is_array($db_cfg) &&
            !empty($db_cfg['host']) &&
            !empty($db_cfg['db']) &&
            !empty($db_cfg['username'])
        ) {
            $db = $this->get_db(false);
            if ($db instanceof SQL) {
                $atomic->set('DB', $db);
            }
        }
    }

    private function sanitize_dsn_value(string $value): string
    {
        return preg_replace('/[^a-zA-Z0-9._\-\/:\[\]]/', '', $value);
    }
}
