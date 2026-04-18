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
        if (isset($this->hive['redis'])) {
            return $this->hive['redis'];
        }

        if (!extension_loaded('redis')) {
            throw new \RuntimeException('The redis PHP extension is not loaded.');
        }

        $atomic = App::instance();
        if (empty($config)) {
            $redis_config = $atomic->get('REDIS');
            $config = [
                'server' => $redis_config['host'],
                'port' => $redis_config['port'],
                'password' => $redis_config['password'],
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
        $this->hive['redis'] = new \Cache($dsn);
        return $this->hive['redis'];
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
        if (isset($this->hive['memcached'])) {
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

        $namespace = (string)$atomic->get('MEMCACHED.ATOMIC_MEMCACHED_PREFIX');

        $this->hive['memcached'] = new Memcached($mc, $namespace);

        return $this->hive['memcached'];
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
                $extensionRequired = null;

                switch ($driver) {
                    case 'redis':
                        $extensionRequired = 'redis';
                        if (extension_loaded($extensionRequired)) {
                            $cache = $this->redis();
                        }
                        break;
                    case 'memcached':
                        $extensionRequired = 'memcached';
                        if (extension_loaded($extensionRequired)) {
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