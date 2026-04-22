<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Cache\DB;
use Engine\Atomic\Cache\Memcached;

class CacheManager extends \Prefab
{
    private const DRIVER_PRIORITY = ['redis', 'memcached', 'db'];

    protected array $hive = [];

    public function redis(array $config = []): \Cache 
    {
        $use_shared_instance = empty($config);
        if ($use_shared_instance && isset($this->hive['redis'])) {
            return $this->hive['redis'];
        }

        if (!extension_loaded('redis')) {
            throw new \RuntimeException('The redis PHP extension is not loaded.');
        }

        $atomic = App::instance();
        if (empty($config)) {
            $redis_config = (array) $atomic->get('REDIS');
            $config = [
                'server' => (string) ($redis_config['host'] ?? '127.0.0.1'),
                'port' => $redis_config['port'] ?? 6379,
                'password' => (string) ($redis_config['password'] ?? ''),
            ];
        }
        $dsn = "redis={$config['server']}:{$config['port']}";
        $login = trim((string)($config['login'] ?? ''));
        $password = trim((string)$config['password']);
        if (strtolower($login) === 'null') {
            $login = '';
        }
        if (strtolower($password) === 'null') {
            $password = '';
        }
        if ($login !== '' && $password !== '') {
            $dsn .= "?auth={$login}:{$password}";
        } elseif ($password !== '') {
            $dsn .= "?auth={$password}";
        }
        try {
            $cache = new \Cache($dsn);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Redis cache unavailable: ' . $e->getMessage(), 0, $e);
        }

        if ($use_shared_instance) {
            $this->hive['redis'] = $cache;
        }

        return $cache;
    }


    public function db(): DB {
        if (isset($this->hive['db'])) {
            return $this->hive['db'];
        }
        $this->hive['db'] = DB::instance();
        return $this->hive['db'];
    }

    public function memcached(array $config = []): Memcached
    {
        $use_shared_instance = empty($config);
        if ($use_shared_instance && isset($this->hive['memcached'])) {
            return $this->hive['memcached'];
        }

        if (!extension_loaded('memcached')) {
            throw new \RuntimeException('The memcached PHP extension is not loaded.');
        }

        $atomic = App::instance();

        if (empty($config)) {
            $mem_config = $atomic->get('MEMCACHED');
            $config = [
                'host' => $mem_config['host'],
                'port' => (int) $mem_config['port'],
            ];
        }

        $mc = new \Memcached();
        $mc->addServer($config['host'], $config['port']);

        $version = $mc->getVersion();
        $reachable = is_array($version) && $version !== [];
        if ($reachable) {
            foreach ($version as $server_version) {
                if ($server_version !== false && $server_version !== '') {
                    $reachable = true;
                    break;
                }
                $reachable = false;
            }
        }
        if (!$reachable) {
            throw new \RuntimeException('Memcached cache unavailable.');
        }

        $namespace = (string)$atomic->get('MEMCACHED.prefix');
        $cache = new Memcached($mc, $namespace);

        if ($use_shared_instance) {
            $this->hive['memcached'] = $cache;
        }

        return $cache;
    }

    public function cascade(): \Cache|DB
    {
        foreach (self::DRIVER_PRIORITY as $driver) {
            if (isset($this->hive[$driver])) {
                return $this->hive[$driver];
            }
        }

        foreach (self::DRIVER_PRIORITY as $driver) {
            try {
                $cache = null;
                $extension_required = null;

                switch ($driver) {
                    case 'redis':
                        $extension_required = 'redis';
                        if (extension_loaded($extension_required)) {
                            $cache = $this->redis();
                        }
                        break;
                    case 'memcached':
                        $extension_required = 'memcached';
                        if (extension_loaded($extension_required)) {
                            $cache = $this->memcached();
                        }
                        break;
                    case 'db':
                        $cache = $this->db();
                        break;
                }

                if ($cache !== null) {
                    $testKey = '_atomic_healthcheck';
                    $cache->set($testKey, '1', 3);
                    $val = $cache->get($testKey);
                    if ($val == '1') {
                        return $cache;
                    }
                }
            } catch (\Throwable $e) {
                Log::error(ucfirst($driver) . ' health check error: ' . $e->getMessage());
            }
        }

        return $this->db();
    }
}