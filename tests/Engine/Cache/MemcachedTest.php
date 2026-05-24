<?php
declare(strict_types=1);

namespace Tests\Engine\Cache;

use Engine\Atomic\Cache\Drivers\Memcached as MemcachedCache;
use Engine\Atomic\Cache\Interfaces\CacheStoreInterface;
use Engine\Atomic\Cache\Interfaces\PrunableCacheStoreInterface;
use Engine\Atomic\Cache\Interfaces\PurgeableCacheStoreInterface;
use Engine\Atomic\Core\ConnectionManager;
use PHPUnit\Framework\TestCase;
use Tests\Support\Wait;

class MemcachedTest extends TestCase
{
    use CacheStoreContractTrait;

    private ?\Memcached $memcached = null;
    private string $namespace = '';

    protected function setUp(): void
    {
        if (!extension_loaded('memcached')) {
            $this->markTestSkipped('ext-memcached not loaded');
        }

        try {
            $this->memcached = ConnectionManager::instance()->get_memcached();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Memcached server unavailable: ' . $e->getMessage());
        }

        $this->namespace = 'atomic_test_mem_' . bin2hex(random_bytes(6));
    }

    public function test_set_and_get_scalar_and_array_values(): void
    {
        $cache = new MemcachedCache($this->memcached(), $this->namespace);

        $this->assertTrue($cache->set('scalar', 'value', 60));
        $this->assertSame('value', $cache->get('scalar'));

        $payload = ['a' => 1, 'b' => ['nested' => true]];
        $this->assertTrue($cache->set('array', $payload, 60));
        $this->assertSame($payload, $cache->get('array'));
    }

    public function test_missing_key_returns_false(): void
    {
        $cache = new MemcachedCache($this->memcached(), $this->namespace);

        $this->assertFalse($cache->get('missing'));
    }

    public function test_clear_removes_one_key(): void
    {
        $cache = new MemcachedCache($this->memcached(), $this->namespace);
        $cache->set('one', '1', 60);
        $cache->set('two', '2', 60);

        $this->assertTrue($cache->clear('one'));
        $this->assertFalse($cache->get('one'));
        $this->assertSame('2', $cache->get('two'));
    }

    public function test_clear_missing_key_returns_false(): void
    {
        $cache = new MemcachedCache($this->memcached(), $this->namespace);

        $this->assertFalse($cache->clear('missing'));
    }

    public function test_ttl_expires(): void
    {
        $cache = new MemcachedCache($this->memcached(), $this->namespace);
        $cache->set('ttl', 'gone', 1);

        $this->assertTrue(Wait::until(fn (): bool => $cache->get('ttl') === false, 4));
    }

    public function test_namespace_prevents_overlap_between_instances(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $a = new MemcachedCache($this->memcached(), 'atomic_test_mem_a_' . $suffix);
        $b = new MemcachedCache($this->memcached(), 'atomic_test_mem_b_' . $suffix);

        $a->set('same', 'a', 60);
        $b->set('same', 'b', 60);

        $this->assertSame('a', $a->get('same'));
        $this->assertSame('b', $b->get('same'));
    }

    public function test_colon_in_namespace_or_key_is_allowed(): void
    {
        $colon_namespace = new MemcachedCache($this->memcached(), $this->namespace . ':test');
        $valid_namespace = new MemcachedCache($this->memcached(), $this->namespace);

        $this->assertTrue($colon_namespace->set('value', 'namespace', 60));
        $this->assertSame('namespace', $colon_namespace->get('value'));

        $this->assertTrue($valid_namespace->set('transient:some:key', 'key', 60));
        $this->assertSame('key', $valid_namespace->get('transient:some:key'));
        $this->assertTrue($valid_namespace->clear('transient:some:key'));
    }

    public function test_exists_fills_value_and_returns_metadata(): void
    {
        $cache = new MemcachedCache($this->memcached(), $this->namespace);
        $cache->set('exists', ['ok' => true], 60);

        $meta = $cache->exists('exists', $value);

        $this->assertIsArray($meta);
        $this->assertSame(['ok' => true], $value);
        $this->assertIsFloat($meta[0]);
        $this->assertGreaterThan(0, $meta[1]);
    }

    public function test_reset_advances_generation_for_namespace(): void
    {
        $cache = new MemcachedCache($this->memcached(), $this->namespace);
        $cache->set('transient.one', 'one', 60);
        $cache->set('other.one', 'other', 60);

        $this->assertTrue($cache->reset());

        $this->assertFalse($cache->get('transient.one'));
        $this->assertFalse($cache->get('other.one'));
    }

    public function test_false_generation_value_is_normalized(): void
    {
        $this->memcached()->set($this->namespace . '.gen', false, 60);

        $cache = new MemcachedCache($this->memcached(), $this->namespace);

        $this->assertSame(1, $cache->get_generation());
        $this->assertTrue($cache->set('scalar', 'value', 60));
        $this->assertSame('value', $cache->get('scalar'));
    }

    public function test_overwrite_with_no_ttl_removes_previous_expiration(): void
    {
        $cache = new MemcachedCache($this->memcached(), $this->namespace);
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
        $cache = new MemcachedCache($this->memcached(), $this->namespace);
        $cache->set('corrupt', 'value', 60);

        $gen = $cache->get_generation();
        $real_key = $this->namespace . '.' . $gen . '.corrupt';
        $this->memcached->set($real_key, 'not-a-valid-payload', 60);

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

    public function test_memcached_driver_is_not_manually_prunable(): void
    {
        $this->assertNotInstanceOf(PrunableCacheStoreInterface::class, new MemcachedCache($this->memcached(), $this->namespace));
    }

    public function test_memcached_driver_is_not_purgeable(): void
    {
        $this->assertNotInstanceOf(PurgeableCacheStoreInterface::class, new MemcachedCache($this->memcached(), $this->namespace));
    }

    private function memcached(): \Memcached
    {
        $this->assertInstanceOf(\Memcached::class, $this->memcached);
        return $this->memcached;
    }

    protected function newCacheStore(string $namespace): CacheStoreInterface
    {
        return new MemcachedCache($this->memcached(), $namespace);
    }
}
