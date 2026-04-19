<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\CacheManager;
use PHPUnit\Framework\TestCase;

class CacheManagerTest extends TestCase
{
    private CacheManager $manager;

    protected function setUp(): void
    {
        $this->manager = CacheManager::instance();
    }

    public function test_instance(): void
    {
        $this->assertInstanceOf(CacheManager::class, $this->manager);
    }

    public function test_redis_returns_cache(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis not loaded');
        }
        $redis = $this->manager->redis();
        $this->assertInstanceOf(\Cache::class, $redis);
    }

    public function test_redis_returns_same_instance(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis not loaded');
        }
        $r1 = $this->manager->redis();
        $r2 = $this->manager->redis();
        $this->assertSame($r1, $r2);
    }

    public function test_redis_throws_when_not_available(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->manager->redis([
            'server' => '127.0.0.1',
            'port' => 1,
            'password' => '',
        ]);
    }

    public function test_db_returns_cache_db(): void
    {
        try {
            $db = $this->manager->db();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB cache not available: ' . $e->getMessage());
        }
        $this->assertInstanceOf(\Engine\Atomic\Cache\DB::class, $db);
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

    public function test_memcached_throws_when_not_available(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->manager->memcached([
            'host' => '127.0.0.1',
            'port' => 1,
        ]);
    }

    public function test_cascade_returns_cache(): void
    {
        try {
            $cache = $this->manager->cascade();
        } catch (\Throwable $e) {
            $this->markTestSkipped('No cache driver available: ' . $e->getMessage());
        }
        $this->assertTrue(
            $cache instanceof \Cache || $cache instanceof \Engine\Atomic\Cache\DB
        );
    }
}
