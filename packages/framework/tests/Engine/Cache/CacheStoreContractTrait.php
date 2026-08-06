<?php
declare(strict_types=1);

namespace Tests\Engine\Cache;

use Engine\Atomic\Cache\Interfaces\CacheStoreInterface;
use Tests\Support\Wait;

trait CacheStoreContractTrait
{
    abstract protected function newCacheStore(string $namespace): CacheStoreInterface;

    protected function cacheNamespace(string $suffix = ''): string
    {
        return 'atomic_contract_' . bin2hex(random_bytes(6)) . $suffix;
    }

    public function test_contract_stores_and_reads_supported_value_types(): void
    {
        $cache = $this->newCacheStore($this->cacheNamespace());
        $values = [
            'string' => 'value',
            'int' => 123,
            'float' => 12.5,
            'bool-true' => true,
            'bool-false' => false,
            'null' => null,
            'array' => ['a' => 1, 'nested' => ['ok' => true]],
        ];

        foreach ($values as $key => $value) {
            $this->assertTrue($cache->set($key, $value, 60), "set should succeed for {$key}");
            $this->assertEquals($value, $cache->get($key), "get should return stored value for {$key}");

            $found = null;
            $meta = $cache->exists($key, $found);
            $this->assertIsArray($meta, "exists should return metadata for {$key}");
            $this->assertEquals($value, $found, "exists should fill stored value for {$key}");
            $this->assertIsFloat($meta[0]);
            $this->assertGreaterThan(0, $meta[1]);
        }
    }

    public function test_contract_exists_distinguishes_falsey_values_from_missing_keys(): void
    {
        $cache = $this->newCacheStore($this->cacheNamespace());
        $values = [
            'false' => false,
            'null' => null,
            'zero-int' => 0,
            'zero-string' => '0',
            'empty-string' => '',
            'empty-array' => [],
        ];

        foreach ($values as $key => $value) {
            $this->assertTrue($cache->set($key, $value, 60), "set should succeed for {$key}");

            $found = 'sentinel';
            $meta = $cache->exists($key, $found);

            $this->assertIsArray($meta, "exists should find {$key}");
            $this->assertSame($value, $found, "exists should preserve the exact falsey value for {$key}");
            $this->assertSame($value, $cache->get($key), "get should return the exact falsey value for {$key}");
        }

        $missing = 'sentinel';
        $this->assertFalse($cache->exists('missing', $missing));
        $this->assertSame('sentinel', $missing);
    }

    public function test_contract_metadata_for_non_expiring_entries_has_zero_remaining_ttl(): void
    {
        $cache = $this->newCacheStore($this->cacheNamespace());

        $this->assertTrue($cache->set('forever', 'value', 0));

        $found = null;
        $meta = $cache->exists('forever', $found);

        $this->assertIsArray($meta);
        $this->assertSame('value', $found);
        $this->assertIsFloat($meta[0]);
        $this->assertGreaterThan(0, $meta[0]);
        $this->assertSame(0, $meta[1]);
    }

    public function test_contract_missing_keys_are_false_and_do_not_mutate_reference_value(): void
    {
        $cache = $this->newCacheStore($this->cacheNamespace());
        $value = 'unchanged';

        $this->assertFalse($cache->get('missing'));
        $this->assertFalse($cache->exists('missing', $value));
        $this->assertSame('unchanged', $value);
        $this->assertFalse($cache->clear('missing'));
    }

    public function test_contract_clear_removes_only_the_requested_key(): void
    {
        $cache = $this->newCacheStore($this->cacheNamespace());
        $this->assertTrue($cache->set('one', '1', 60));
        $this->assertTrue($cache->set('two', '2', 60));

        $this->assertTrue($cache->clear('one'));
        $this->assertFalse($cache->get('one'));
        $this->assertSame('2', $cache->get('two'));
        $this->assertFalse($cache->clear('one'));
    }

    public function test_contract_ttl_zero_and_negative_values_do_not_expire(): void
    {
        $cache = $this->newCacheStore($this->cacheNamespace());

        $this->assertTrue($cache->set('zero', 'forever', 0));
        $this->assertTrue($cache->set('negative', 'forever', -5));

        $started = time();
        $this->assertTrue(Wait::until(
            fn (): bool => time() >= $started + 2
                && $cache->get('zero') === 'forever'
                && $cache->get('negative') === 'forever',
            4
        ));

        $this->assertSame('forever', $cache->get('zero'));
        $this->assertSame('forever', $cache->get('negative'));
    }

    public function test_contract_expired_entries_are_removed_when_read(): void
    {
        $cache = $this->newCacheStore($this->cacheNamespace());
        $this->assertTrue($cache->set('ttl', 'gone', 1));

        $this->assertTrue(Wait::until(fn (): bool => $cache->get('ttl') === false, 4));
        $this->assertFalse($cache->exists('ttl', $value));
        $this->assertNull($value);
        $this->assertFalse($cache->clear('ttl'));
    }

    public function test_contract_overwrite_with_no_ttl_removes_previous_expiration(): void
    {
        $cache = $this->newCacheStore($this->cacheNamespace());
        $this->assertTrue($cache->set('ttl', 'short', 1));
        $this->assertTrue($cache->set('ttl', 'forever', 0));

        $started = time();
        $this->assertTrue(Wait::until(
            fn (): bool => time() >= $started + 2 && $cache->get('ttl') === 'forever',
            4
        ));

        $this->assertSame('forever', $cache->get('ttl'));
    }

    public function test_contract_reset_makes_previous_generation_invisible(): void
    {
        $cache = $this->newCacheStore($this->cacheNamespace());
        $this->assertTrue($cache->set('one', '1', 60));
        $this->assertTrue($cache->set('two', '2', 60));

        $this->assertTrue($cache->reset());

        $this->assertFalse($cache->get('one'));
        $this->assertFalse($cache->get('two'));
        $this->assertTrue($cache->set('one', 'fresh', 60));
        $this->assertSame('fresh', $cache->get('one'));
    }

    public function test_contract_generation_is_cached_per_instance_until_flushed(): void
    {
        $namespace = $this->cacheNamespace();
        $reader = $this->newCacheStore($namespace);
        $resetter = $this->newCacheStore($namespace);

        $this->assertTrue($reader->set('shared', 'old', 60));
        $this->assertSame('old', $reader->get('shared'));

        $this->assertTrue($resetter->reset());

        $this->assertSame('old', $reader->get('shared'));
        $reader->flush_local_cache();
        $resetter->flush_local_cache();

        $this->assertFalse($reader->get('shared'));
        $this->assertTrue($reader->set('shared', 'fresh', 60));
        $this->assertSame('fresh', $resetter->get('shared'));
    }

    public function test_contract_namespaces_are_isolated(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $a = $this->newCacheStore($this->cacheNamespace('_a_' . $suffix));
        $b = $this->newCacheStore($this->cacheNamespace('_b_' . $suffix));

        $this->assertTrue($a->set('same', 'a', 60));
        $this->assertTrue($b->set('same', 'b', 60));

        $this->assertSame('a', $a->get('same'));
        $this->assertSame('b', $b->get('same'));
    }

    public function test_contract_namespaces_are_trimmed_and_trailing_dots_are_ignored(): void
    {
        $namespace = $this->cacheNamespace();
        $a = $this->newCacheStore('  ' . $namespace . '..  ');
        $b = $this->newCacheStore($namespace);

        $this->assertTrue($a->set('same', 'normalized', 60));
        $this->assertSame('normalized', $b->get('same'));
    }

    public function test_contract_empty_normalized_namespace_falls_back_to_atomic_namespace(): void
    {
        $cache = $this->newCacheStore('  ...  ');

        $this->assertTrue($cache->set('same', 'fallback', 60));
        $this->assertSame('fallback', $cache->get('same'));
        $this->assertTrue($cache->clear('same'));
    }

    public function test_contract_key_normalization_trims_leading_dots(): void
    {
        $cache = $this->newCacheStore($this->cacheNamespace());

        $this->assertTrue($cache->set('.same', 'value', 60));
        $this->assertSame('value', $cache->get('same'));
        $this->assertTrue($cache->clear('.same'));
        $this->assertFalse($cache->get('.same'));
    }

    public function test_contract_keys_that_normalize_to_empty_are_rejected(): void
    {
        $cache = $this->newCacheStore($this->cacheNamespace());
        $value = 'unchanged';

        $this->assertFalse($cache->set('', 'value', 60));
        $this->assertFalse($cache->set('.', 'value', 60));
        $this->assertFalse($cache->get(''));
        $this->assertFalse($cache->exists('.', $value));
        $this->assertSame('unchanged', $value);
        $this->assertFalse($cache->clear(''));
    }

    public function test_contract_colon_is_not_treated_as_a_separator(): void
    {
        $cache = $this->newCacheStore($this->cacheNamespace(':colon'));

        $this->assertTrue($cache->set(':same', 'colon', 60));
        $this->assertTrue($cache->set('same', 'plain', 60));

        $this->assertSame('colon', $cache->get(':same'));
        $this->assertSame('plain', $cache->get('same'));
    }

    public function test_contract_prune_removes_expired_entries_and_leaves_fresh_untouched(): void
    {
        $cache = $this->newCacheStore($this->cacheNamespace());
        if (!$cache instanceof \Engine\Atomic\Cache\Interfaces\PrunableCacheStoreInterface) {
            $this->assertFalse(
                $cache instanceof \Engine\Atomic\Cache\Interfaces\PrunableCacheStoreInterface,
                'Driver intentionally does not support manual pruning.'
            );
            return;
        }

        $this->assertTrue($cache->set('fresh', 'keep', 60));
        $this->assertTrue($cache->set('expired', 'gone', 1));

        $this->assertTrue(Wait::until(fn (): bool => $cache->get('expired') === false, 4));

        $this->assertTrue($cache->prune());
        $this->assertSame('keep', $cache->get('fresh'));
        $this->assertFalse($cache->get('expired'));
    }

}
