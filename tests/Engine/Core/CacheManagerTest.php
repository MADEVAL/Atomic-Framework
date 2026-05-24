<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\CacheManager;
use Engine\Atomic\Cache\Interfaces\CacheStoreInterface;
use Engine\Atomic\Cache\Drivers\Folder;
use Engine\Atomic\Cache\Drivers\Redis;
use Engine\Atomic\Core\ID;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;
use Tests\Support\TestConfig;
use Tests\Support\TempPath;

class CacheManagerTest extends TestCase
{
    private const DRIVER_REDIS = 'redis';
    private const DRIVER_MEMCACHED = 'memcached';
    private const DRIVER_FOLDER = 'folder';
    private const DRIVER_DB = 'db';

    private CacheManager $manager;
    private mixed $old_cache_config = null;
    private string $temp_path = '';

    protected function setUp(): void
    {
        $this->manager = CacheManager::instance();
        $app = \Engine\Atomic\Core\App::instance();
        $this->old_cache_config = $app->get('CACHE_CONFIG');
        $app->set('CACHE_CONFIG', null);
        $app->set('APP_UUID', ID::uuid_v4());
        $app->set('DB_CONFIG', TestConfig::db());
        try {
            if ($sql = TestConfig::open_configured_db(\Base::instance())) {
                $app->set('DB', $sql);
                TestConfig::ensure_options_table(\Base::instance());
            }
        } catch (\Throwable) {
        }
        $this->temp_path = TempPath::make_dir('atomic_cache_manager_');
        ReflectionHelper::set($this->manager, 'hive', []);
        ReflectionHelper::set($this->manager, 'store', null);
    }

    protected function tearDown(): void
    {
        $app = \Engine\Atomic\Core\App::instance();
        $app->set('CACHE_CONFIG', $this->old_cache_config);
        ReflectionHelper::set($this->manager, 'hive', []);
        ReflectionHelper::set($this->manager, 'store', null);
        TempPath::remove($this->temp_path);
    }

    public function test_instance(): void
    {
        $this->assertInstanceOf(CacheManager::class, $this->manager);
    }

    public function test_redis_returns_cache(): void
    {
        if (!extension_loaded(self::DRIVER_REDIS)) {
            $this->markTestSkipped('ext-redis not loaded');
        }
        $redis = $this->manager->redis();
        $this->assertInstanceOf(Redis::class, $redis);
        $this->assertInstanceOf(CacheStoreInterface::class, $redis);
    }

    public function test_redis_returns_same_instance(): void
    {
        if (!extension_loaded(self::DRIVER_REDIS)) {
            $this->markTestSkipped('ext-redis not loaded');
        }
        $r1 = $this->manager->redis();
        $r2 = $this->manager->redis();
        $this->assertSame($r1, $r2);
    }

    public function test_redis_uses_loaded_config(): void
    {
        if (!extension_loaded(self::DRIVER_REDIS)) {
            $this->markTestSkipped('ext-redis not loaded');
        }

        try {
            $redis = $this->manager->redis();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis cache not available: ' . $e->getMessage());
        }

        $this->assertInstanceOf(Redis::class, $redis);
    }

    public function test_db_returns_cache_db(): void
    {
        try {
            $db = $this->manager->db();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB cache not available: ' . $e->getMessage());
        }
        $this->assertInstanceOf(\Engine\Atomic\Cache\Drivers\DB::class, $db);
        $this->assertInstanceOf(CacheStoreInterface::class, $db);
    }

    public function test_db_returns_same_instance(): void
    {
        try {
            $d1 = $this->manager->db();
            $d2 = $this->manager->db();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB cache not available: ' . $e->getMessage());
        }
        $this->assertSame($d1, $d2);
    }

    public function test_folder_returns_cache(): void
    {
        \Engine\Atomic\Core\App::instance()->set('CACHE_CONFIG', [
            'path' => $this->temp_path,
            'prefix' => 'manager_test',
        ]);

        $folder = $this->manager->folder();

        $this->assertInstanceOf(Folder::class, $folder);
        $this->assertInstanceOf(CacheStoreInterface::class, $folder);
        $this->assertTrue($folder->set('manager', 'ok', 60));
        $this->assertSame('ok', $folder->get('manager'));
    }

    public function test_memcached_uses_loaded_config(): void
    {
        if (!extension_loaded(self::DRIVER_MEMCACHED)) {
            $this->markTestSkipped('ext-memcached not loaded');
        }

        try {
            $memcached = $this->manager->memcached();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Memcached cache not available: ' . $e->getMessage());
        }

        $this->assertInstanceOf(\Engine\Atomic\Cache\Drivers\Memcached::class, $memcached);
    }

    public function test_cascade_returns_cache(): void
    {
        try {
            $cache = $this->manager->cascade();
        } catch (\Throwable $e) {
            $this->markTestSkipped('No cache driver available: ' . $e->getMessage());
        }
        $this->assertTrue(
            $cache instanceof CacheStoreInterface
        );
    }

    public function test_cascade_honors_folder_default(): void
    {
        $app = \Engine\Atomic\Core\App::instance();
        $app->set('CACHE_CONFIG', [
            'default' => self::DRIVER_FOLDER,
            'path' => $this->temp_path,
            'prefix' => 'manager_test',
        ]);

        $this->assertInstanceOf(Folder::class, $this->manager->cascade());
    }

    public function test_configured_returns_selected_driver_without_cascade_health_check(): void
    {
        $app = \Engine\Atomic\Core\App::instance();
        $app->set('CACHE_CONFIG', [
            'default' => self::DRIVER_FOLDER,
            'path' => $this->temp_path,
            'prefix' => 'manager_test',
        ]);

        $this->assertInstanceOf(Folder::class, $this->manager->configured());
    }

    public function test_resolve_stores_selected_cache_for_app_consumers(): void
    {
        $app = \Engine\Atomic\Core\App::instance();
        $app->set('CACHE_CONFIG', [
            'default' => self::DRIVER_FOLDER,
            'path' => $this->temp_path,
            'prefix' => 'manager_test',
        ]);

        $resolved = $this->manager->resolve();

        $this->assertInstanceOf(Folder::class, $resolved);
        $this->assertSame($resolved, $this->manager->store());
    }

    public function test_cascade_honors_db_default(): void
    {
        \Engine\Atomic\Core\App::instance()->set('CACHE_CONFIG', [
            'default' => self::DRIVER_DB,
        ]);

        try {
            $db = $this->manager->db();
            $db->set('_atomic_healthcheck', '1', 3);
            if ($db->get('_atomic_healthcheck') != '1') {
                $this->markTestSkipped('DB cache health check failed.');
            }

            $cache = $this->manager->cascade();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB cache not available: ' . $e->getMessage());
        }

        $this->assertInstanceOf(\Engine\Atomic\Cache\Drivers\DB::class, $cache);
    }

    public function test_cascade_priority_excludes_db_unless_configured(): void
    {
        $drivers = ReflectionHelper::invoke($this->manager, 'cascade_drivers');

        $this->assertSame([self::DRIVER_REDIS, self::DRIVER_MEMCACHED, self::DRIVER_FOLDER], $drivers);
        $this->assertNotContains(self::DRIVER_DB, $drivers);
    }

    public function test_cascade_uses_next_driver_when_first_health_check_fails(): void
    {
        $failing = $this->createMock(CacheStoreInterface::class);
        $failing->expects($this->once())
            ->method('set')
            ->with('_atomic_healthcheck', '1', 3)
            ->willReturn(false);
        $failing->expects($this->never())
            ->method('get')
            ->with('_atomic_healthcheck');
        $failing->expects($this->once())
            ->method('clear')
            ->with('_atomic_healthcheck')
            ->willReturn(false);

        $healthy = $this->createMock(CacheStoreInterface::class);
        $healthy->expects($this->once())
            ->method('set')
            ->with('_atomic_healthcheck', '1', 3)
            ->willReturn(true);
        $healthy->expects($this->once())
            ->method('get')
            ->with('_atomic_healthcheck')
            ->willReturn('1');
        $healthy->expects($this->once())
            ->method('clear')
            ->with('_atomic_healthcheck')
            ->willReturn(true);

        $manager = $this->getMockBuilder(CacheManager::class)
            ->onlyMethods(['cascade_drivers', 'driver'])
            ->getMock();

        $manager->expects($this->once())
            ->method('cascade_drivers')
            ->willReturn([self::DRIVER_REDIS, self::DRIVER_FOLDER]);

        $manager->expects($this->exactly(2))
            ->method('driver')
            ->willReturnMap([
                [self::DRIVER_REDIS, $failing],
                [self::DRIVER_FOLDER, $healthy],
            ]);

        $this->assertSame($healthy, $manager->cascade());
    }
}
