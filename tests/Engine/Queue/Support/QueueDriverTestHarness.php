<?php
declare(strict_types=1);

namespace Tests\Engine\Queue\Support;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Queue\Managers\Manager;
use Tests\Support\TestConfig;

trait QueueDriverTestHarness
{
    private array $queue_original_state = [];

    protected function backup_queue_state(): void
    {
        $atomic = App::instance();
        $this->queue_original_state = [
            'QUEUE_DRIVER' => $atomic->get('QUEUE_DRIVER'),
            'QUEUE_NAME' => $atomic->get('QUEUE_NAME'),
            'QUEUE' => $atomic->get('QUEUE'),
            'DB_CONFIG' => $atomic->get('DB_CONFIG'),
            'DB' => $atomic->get('DB'),
            'REDIS' => $atomic->get('REDIS'),
        ];
    }

    protected function restore_queue_state(): void
    {
        ConnectionManager::instance()->close();
        $atomic = App::instance();
        foreach ($this->queue_original_state as $key => $value) {
            $atomic->set($key, $value);
        }
    }

    protected function configure_queue(string $driver, string $queue, array $overrides = []): void
    {
        $defaults = [
            'delay' => 0,
            'priority' => 5,
            'timeout' => 10,
            'max_attempts' => 3,
            'retry_delay' => 0,
            'worker_cnt' => 1,
            'ttl' => 60,
        ];

        App::instance()->set('QUEUE_DRIVER', $driver);
        App::instance()->set('QUEUE_NAME', $queue);
        App::instance()->set('QUEUE', [
            'db' => ['queues' => [$queue => \array_merge($defaults, $overrides)]],
            'redis' => ['queues' => [$queue => \array_merge($defaults, $overrides)]],
        ]);
    }

    protected function new_queue_name(): string
    {
        return 'queue_test_' . \bin2hex(\random_bytes(4));
    }

    protected function new_uuid(): string
    {
        return \sprintf(
            '%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
            \random_int(0, 0xffff),
            \random_int(0, 0xffff),
            \random_int(0, 0xffff),
            \random_int(0, 0x0fff),
            \random_int(0, 0xffff),
            \random_int(0, 0xffff),
            \random_int(0, 0xffff),
            \random_int(0, 0xffff)
        );
    }

    protected function manager_driver(Manager $manager): object
    {
        $property = new \ReflectionProperty($manager, 'driver');
        return $property->getValue($manager);
    }

    protected function connect_redis_or_skip(): array
    {
        if (!\class_exists(\Redis::class)) {
            $this->markTestSkipped('ext-redis not loaded - queue Redis driver tests cannot run.');
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

        $this->markTestSkipped('Redis server unavailable - queue Redis driver tests cannot run.');
    }

    protected function configure_redis_for_queue(\Redis $redis, string $host, string $prefix, string $queue, array $overrides = []): void
    {
        $this->configure_redis_connection($redis, $host, $prefix);
        $this->configure_queue('redis', $queue, $overrides);
    }

    protected function configure_redis_connection(\Redis $redis, string $host, string $prefix): void
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
            $this->markTestSkipped("REDIS.{$key} is not configured - queue Redis driver tests cannot run.");
        }

        return (string)$cfg[$key];
    }

    protected function redis_config_value(array $cfg, string $key): string
    {
        if (!\array_key_exists($key, $cfg) || $cfg[$key] === null) {
            $this->markTestSkipped("REDIS.{$key} is not configured - queue Redis driver tests cannot run.");
        }

        return (string)$cfg[$key];
    }

    protected function connect_pdo_or_skip(): array
    {
        if (!\extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('ext-pdo_mysql not loaded - DB queue driver tests cannot run.');
        }

        $cfg = TestConfig::db((array)(App::instance()->get('DB_CONFIG') ?? []));
        $host = $this->required_db_config_value($cfg, 'host');
        $port = $this->required_db_config_value($cfg, 'port');
        $db = $this->required_db_config_value($cfg, 'db');
        $configuredUsername = $this->required_db_config_value($cfg, 'username');
        $configuredPassword = $this->required_db_config_value($cfg, 'password');
        $charset = $this->required_db_config_value($cfg, 'charset');
        try {
            $dsn = \sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $db, $charset);
            App::instance()->set('DB_CONFIG', TestConfig::db(\array_merge($cfg, [
                'host' => $host,
                'username' => $configuredUsername,
                'password' => $configuredPassword,
            ])));
            return [new \PDO($dsn, $configuredUsername, $configuredPassword), $host, $configuredUsername, $configuredPassword];
        } catch (\Throwable) {
        }

        $this->markTestSkipped('MySQL connection unavailable - DB queue driver tests cannot run.');
    }

    protected function configure_db_for_queue(string $host, string $prefix, string $queue, array $overrides = []): void
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
        $this->configure_queue('db', $queue, $overrides);
    }

    protected function required_db_config_value(array $cfg, string $key): string
    {
        if (!\array_key_exists($key, $cfg) || $cfg[$key] === null || (string)$cfg[$key] === '') {
            $this->markTestSkipped("DB_CONFIG.{$key} is not configured - DB queue driver tests cannot run.");
        }

        return (string)$cfg[$key];
    }

    protected function migrate_queue_tables_up(): void
    {
        $migration = require ATOMIC_ENGINE . 'Atomic/Core/Database/Migrations/atomic_create_queue_tables.php';
        $migration['up']();
    }

    protected function migrate_queue_tables_down(): void
    {
        $migration = require ATOMIC_ENGINE . 'Atomic/Core/Database/Migrations/atomic_create_queue_tables.php';
        $migration['down']();
    }
}
