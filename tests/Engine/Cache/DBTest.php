<?php
declare(strict_types=1);

namespace Tests\Engine\Cache;

use Engine\Atomic\Cache\Drivers\DB as DBCache;
use Engine\Atomic\Cache\Interfaces\CacheStoreInterface;
use Engine\Atomic\Cache\Interfaces\PrunableCacheStoreInterface;
use Engine\Atomic\Cache\Interfaces\PurgeableCacheStoreInterface;
use Engine\Atomic\App\Models\Options;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ID;
use PHPUnit\Framework\TestCase;
use Tests\Support\Wait;

class DBTest extends TestCase
{
    use CacheStoreContractTrait;

    private string $namespace = '';

    protected function setUp(): void
    {
        if (App::instance()->get('DB') === null) {
            $this->markTestSkipped('DB cache not available.');
        }

        App::instance()->set('APP_UUID', ID::uuid_v4());
        $this->namespace = 'atomic_test_db_' . bin2hex(random_bytes(6));
    }

    public function test_set_and_get_scalar_and_array_values(): void
    {
        $cache = new DBCache($this->namespace);

        $this->assertTrue($cache->set('scalar', 'value', 60));
        $this->assertSame('value', $cache->get('scalar'));

        $payload = ['a' => 1, 'b' => ['nested' => true]];
        $this->assertTrue($cache->set('array', $payload, 60));
        $this->assertSame($payload, $cache->get('array'));
    }

    public function test_missing_key_returns_false(): void
    {
        $cache = new DBCache($this->namespace);

        $this->assertFalse($cache->get('missing'));
    }

    public function test_clear_removes_one_key(): void
    {
        $cache = new DBCache($this->namespace);
        $cache->set('one', '1', 60);
        $cache->set('two', '2', 60);

        $this->assertTrue($cache->clear('one'));
        $this->assertFalse($cache->get('one'));
        $this->assertSame('2', $cache->get('two'));
    }

    public function test_clear_missing_key_returns_false(): void
    {
        $cache = new DBCache($this->namespace);

        $this->assertFalse($cache->clear('missing'));
    }

    public function test_ttl_expires(): void
    {
        $cache = new DBCache($this->namespace);
        $cache->set('ttl', 'gone', 1);

        $this->assertTrue(Wait::until(fn (): bool => $cache->get('ttl') === false, 4));
    }

    public function test_exists_fills_value_and_returns_metadata(): void
    {
        $cache = new DBCache($this->namespace);
        $cache->set('exists', ['ok' => true], 60);

        $meta = $cache->exists('exists', $value);

        $this->assertIsArray($meta);
        $this->assertSame(['ok' => true], $value);
        $this->assertIsFloat($meta[0]);
        $this->assertGreaterThan(0, $meta[1]);
    }

    public function test_reset_advances_generation_for_namespace(): void
    {
        $cache = new DBCache($this->namespace);
        $cache->set('transient.one', 'one', 60);
        $cache->set('other.one', 'other', 60);

        $this->assertTrue($cache->reset());

        $this->assertFalse($cache->get('transient.one'));
        $this->assertFalse($cache->get('other.one'));
    }

    public function test_reset_initializes_missing_generation_as_incremented_default(): void
    {
        $cache = new DBCache($this->namespace);

        $this->assertTrue($cache->reset());
        $this->assertSame(2, $cache->get_generation());
    }

    public function test_reset_normalizes_invalid_generation_before_incrementing(): void
    {
        Options::set_option($this->namespace . '.gen', 'bad');
        $cache = new DBCache($this->namespace);

        $this->assertTrue($cache->reset());
        $this->assertSame(2, $cache->get_generation());
    }

    public function test_overwrite_with_no_ttl_removes_previous_expiration(): void
    {
        $cache = new DBCache($this->namespace);
        $cache->set('ttl', 'short', 1);
        $cache->set('ttl', 'forever', 0);

        $started = time();
        $this->assertTrue(Wait::until(
            fn (): bool => time() >= $started + 2 && $cache->get('ttl') === 'forever',
            4
        ));

        $this->assertSame('forever', $cache->get('ttl'));
    }

    public function test_namespace_prevents_overlap_between_instances(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $a = new DBCache('atomic_test_db_a_' . $suffix);
        $b = new DBCache('atomic_test_db_b_' . $suffix);

        $a->set('same', 'a', 60);
        $b->set('same', 'b', 60);

        $this->assertSame('a', $a->get('same'));
        $this->assertSame('b', $b->get('same'));
    }

    public function test_corrupt_payload_at_current_generation_returns_false_and_is_cleaned_up(): void
    {
        $cache = new DBCache($this->namespace);
        $cache->set('corrupt', 'value', 60);

        $gen = $cache->get_generation();
        $real_key = $this->namespace . '.' . $gen . '.corrupt';
        Options::set_option($real_key, 'not-a-valid-payload');

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

    public function test_db_driver_is_prunable(): void
    {
        $this->assertInstanceOf(PrunableCacheStoreInterface::class, new DBCache($this->namespace));
    }

    public function test_db_driver_is_purgeable(): void
    {
        $this->assertInstanceOf(PurgeableCacheStoreInterface::class, new DBCache($this->namespace));
    }

    public function test_purge_removes_cache_entries_across_generations(): void
    {
        $cache = new DBCache($this->namespace);
        $cache->set('old', 'old', 60);
        $old_key = $this->namespace . '.1.old';
        $this->assertTrue($cache->reset());
        $cache->set('new', 'new', 60);
        $new_key = $this->namespace . '.2.new';

        $this->assertNotFalse(Options::has_option($old_key));
        $this->assertNotFalse(Options::has_option($new_key));
        $this->assertNotFalse(Options::has_option($this->namespace . '.gen'));

        $this->assertSame(3, $cache->purge());

        $this->assertFalse(Options::has_option($old_key));
        $this->assertFalse(Options::has_option($new_key));
        $this->assertFalse(Options::has_option($this->namespace . '.gen'));
    }

    public function test_prune_removes_expired_entries_only(): void
    {
        $cache = new DBCache($this->namespace);
        $cache->set('fresh', 'keep', 60);
        $cache->set('expired', 'gone', 1);
        Options::set_option($this->namespace . '.1.malformed', 'not-a-cache-payload');

        $this->assertTrue(Wait::until(fn (): bool => $cache->get('expired') === false, 4));

        $this->assertTrue($cache->prune());
        $this->assertSame('keep', $cache->get('fresh'));
        $this->assertFalse($cache->get('expired'));
        $this->assertNotFalse(Options::has_option($this->namespace . '.1.malformed'));
    }

    protected function newCacheStore(string $namespace): CacheStoreInterface
    {
        return new DBCache($namespace);
    }
}
