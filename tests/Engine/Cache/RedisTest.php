<?php
declare(strict_types=1);

namespace Tests\Engine\Cache;

use Engine\Atomic\Cache\Drivers\Redis as RedisCache;
use Engine\Atomic\Cache\Interfaces\CacheStoreInterface;
use Engine\Atomic\Cache\Interfaces\PrunableCacheStoreInterface;
use Engine\Atomic\Cache\Interfaces\PurgeableCacheStoreInterface;
use Engine\Atomic\Core\CacheManager;
use Engine\Atomic\Core\ConnectionManager;
use PHPUnit\Framework\TestCase;
use Tests\Support\CapturingSystem;
use Tests\Support\ReflectionHelper;
use Tests\Support\StreamCapture;
use Tests\Support\Wait;

class RedisTest extends TestCase
{
    use CacheStoreContractTrait;

    private ?\Redis $redis = null;
    private string $prefix = '';

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis not loaded');
        }

        try {
            $this->redis = ConnectionManager::instance()->get_redis();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis server unavailable: ' . $e->getMessage());
        }

        $this->prefix = 'atomic_test_cache_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if ($this->redis instanceof \Redis && $this->prefix !== '') {
            $keys = $this->redis->keys($this->prefix . '*');
            if ($keys !== false && $keys !== []) {
                $this->redis->del($keys);
            }
            ConnectionManager::instance()->close_redis();
        }
    }

    public function test_set_and_get_scalar_and_array_values(): void
    {
        $cache = new RedisCache($this->redis(), $this->prefix);

        $this->assertTrue($cache->set('scalar', 'value', 60));
        $this->assertSame('value', $cache->get('scalar'));

        $payload = ['a' => 1, 'b' => ['nested' => true]];
        $this->assertTrue($cache->set('array', $payload, 60));
        $this->assertSame($payload, $cache->get('array'));
    }

    public function test_missing_key_returns_false(): void
    {
        $cache = new RedisCache($this->redis(), $this->prefix);

        $this->assertFalse($cache->get('missing'));
    }

    public function test_clear_removes_one_key(): void
    {
        $cache = new RedisCache($this->redis(), $this->prefix);
        $cache->set('one', '1', 60);
        $cache->set('two', '2', 60);

        $this->assertTrue($cache->clear('one'));
        $this->assertFalse($cache->get('one'));
        $this->assertSame('2', $cache->get('two'));
    }

    public function test_clear_missing_key_returns_false(): void
    {
        $cache = new RedisCache($this->redis(), $this->prefix);

        $this->assertFalse($cache->clear('missing'));
    }

    public function test_ttl_expires(): void
    {
        $cache = new RedisCache($this->redis(), $this->prefix);
        $cache->set('ttl', 'gone', 1);

        $this->assertTrue(Wait::until(fn (): bool => $cache->get('ttl') === false, 4));
    }

    public function test_exists_fills_value_and_returns_metadata(): void
    {
        $cache = new RedisCache($this->redis(), $this->prefix);
        $cache->set('exists', ['ok' => true], 60);

        $meta = $cache->exists('exists', $value);

        $this->assertIsArray($meta);
        $this->assertSame(['ok' => true], $value);
        $this->assertIsFloat($meta[0]);
        $this->assertGreaterThan(0, $meta[1]);
    }

    public function test_reset_advances_generation_for_namespace(): void
    {
        $cache = new RedisCache($this->redis(), $this->prefix);
        $cache->set('transient.one', 'one', 60);
        $cache->set('other.one', 'other', 60);

        $this->assertTrue($cache->reset());

        $this->assertFalse($cache->get('transient.one'));
        $this->assertFalse($cache->get('other.one'));
    }

    public function test_prefix_prevents_overlap_between_instances(): void
    {
        $a = new RedisCache($this->redis(), $this->prefix . '_a.');
        $b = new RedisCache($this->redis(), $this->prefix . '_b.');

        $a->set('same', 'a', 60);
        $b->set('same', 'b', 60);

        $this->assertSame('a', $a->get('same'));
        $this->assertSame('b', $b->get('same'));
    }

    public function test_colon_in_namespace_or_key_is_allowed(): void
    {
        $colon_namespace = new RedisCache($this->redis(), $this->prefix . ':test');
        $valid_namespace = new RedisCache($this->redis(), $this->prefix);

        $this->assertTrue($colon_namespace->set('value', 'namespace', 60));
        $this->assertSame('namespace', $colon_namespace->get('value'));

        $this->assertTrue($valid_namespace->set('transient:some:key', 'key', 60));
        $this->assertSame('key', $valid_namespace->get('transient:some:key'));
        $this->assertTrue($valid_namespace->clear('transient:some:key'));
    }

    public function test_overwrite_with_no_ttl_removes_previous_expiration(): void
    {
        $cache = new RedisCache($this->redis(), $this->prefix);
        $cache->set('ttl', 'short', 1);
        $cache->set('ttl', 'forever', 0);

        $started = time();
        $this->assertTrue(Wait::until(
            fn (): bool => time() >= $started + 2 && $cache->get('ttl') === 'forever',
            4
        ));

        $this->assertSame('forever', $cache->get('ttl'));
    }

    public function test_corrupt_payload_returns_false_and_is_cleaned_up(): void
    {
        $cache = new RedisCache($this->redis(), $this->prefix);
        $cache->set('corrupt', 'value', 60);

        $gen = $cache->get_generation();
        $real_key = $this->prefix . '.entry.' . $gen . '.corrupt';
        $this->redis->set($real_key, 'not-a-valid-payload');

        $val = 'unchanged';
        $this->assertFalse($cache->exists('corrupt', $val));
        $this->assertNull($val);

        $val = 'unchanged';
        $this->assertFalse($cache->get('corrupt'));
        $this->assertFalse($cache->exists('corrupt', $val));
        $this->assertSame('unchanged', $val);

        $this->assertTrue($cache->set('corrupt', 'fresh', 60));
        $this->assertSame('fresh', $cache->get('corrupt'));
    }

    public function test_redis_driver_is_not_manually_prunable(): void
    {
        $this->assertNotInstanceOf(PrunableCacheStoreInterface::class, new RedisCache($this->redis(), $this->prefix));
    }

    public function test_redis_driver_is_purgeable(): void
    {
        $this->assertInstanceOf(PurgeableCacheStoreInterface::class, new RedisCache($this->redis(), $this->prefix));
    }

    public function test_purge_deletes_only_atomic_namespace_keys(): void
    {
        $cache = new RedisCache($this->redis(), $this->prefix);
        $cache->set('old', 'old', 60);
        $this->assertTrue($cache->reset());
        $cache->set('new', 'new', 60);
        $this->redis()->set($this->prefix . '_neighbor.entry.1.key', 'keep');
        $this->redis()->set($this->prefix . '.session-id', 'session');
        $this->redis()->set($this->prefix . '.revoked.session-id', 'revoked');

        $deleted = $cache->purge();

        $this->assertSame(2, $deleted);
        $this->assertSame([], $this->redis()->keys($this->prefix . '.entry.*'));
        $this->assertSame('session', $this->redis()->get($this->prefix . '.session-id'));
        $this->assertSame('revoked', $this->redis()->get($this->prefix . '.revoked.session-id'));
        $this->assertSame('keep', $this->redis()->get($this->prefix . '_neighbor.entry.1.key'));
        $this->redis()->del($this->prefix . '_neighbor.entry.1.key');
    }

    public function test_purge_keeps_metadata_without_counting_it_as_cache_entries(): void
    {
        $cache = new RedisCache($this->redis(), $this->prefix);
        $cache->set('one', 'one', 60);
        $cache->set('two', 'two', 60);
        $this->redis()->set($this->prefix . '.meta.metadata', 'internal');
        $this->redis()->set($this->prefix . '.meta.metadata.cursor', 'internal');

        $this->assertSame(2, $cache->purge());
        $this->assertSame([], $this->redis()->keys($this->prefix . '.entry.*'));
        $this->assertSame('internal', $this->redis()->get($this->prefix . '.meta.metadata'));
        $this->assertSame('internal', $this->redis()->get($this->prefix . '.meta.metadata.cursor'));
        $this->assertSame(0, $cache->purge());
    }

    public function test_cache_clear_output_reports_redis_driver_and_user_entry_count_without_metadata(): void
    {
        $cache = new RedisCache($this->redis(), $this->prefix);
        $cache->set('one', 'one', 60);
        $cache->set('two', 'two', 60);
        $this->redis()->set($this->prefix . '.meta.metadata', 'internal');

        ReflectionHelper::set(CacheManager::instance(), 'store', $cache);

        try {
            [$system, $stream] = CapturingSystem::make();
            $system->cache_clear();
        } finally {
            ReflectionHelper::set(CacheManager::instance(), 'store', null);
            ReflectionHelper::set(CacheManager::instance(), 'hive', []);
        }

        $output = StreamCapture::read($stream, true);
        $this->assertStringContainsString('Cache driver: Engine\Atomic\Cache\Drivers\Redis', $output);
        $this->assertStringContainsString('Deleted: 2 cache entries', $output);
        $this->assertStringContainsString('[OK] Cache cleared.', $output);
        $this->assertSame([], $this->redis()->keys($this->prefix . '.entry.*'));
        $this->assertSame('internal', $this->redis()->get($this->prefix . '.meta.metadata'));
    }

    private function redis(): \Redis
    {
        $this->assertInstanceOf(\Redis::class, $this->redis);
        return $this->redis;
    }

    protected function newCacheStore(string $namespace): CacheStoreInterface
    {
        return new RedisCache($this->redis(), $namespace);
    }
}
