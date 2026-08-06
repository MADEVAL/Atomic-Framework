<?php
declare(strict_types=1);

namespace Tests\Engine\Cache;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\CacheManager;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\ID;
use Engine\Atomic\Cache\Drivers\DB as DBCache;
use Engine\Atomic\Tools\Transient;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;
use Tests\Support\TestConfig;
use Tests\Support\TempPath;
use Tests\Support\Wait;

class TransientTest extends TestCase
{
    private static bool $bootstrapped = false;
    private string $folder_path = '';
    private string $driver_error = '';

    public static function setUpBeforeClass(): void
    {
        if (self::$bootstrapped) {
            return;
        }

        $base = \Base::instance();
        TestConfig::apply($base, ['app_uuid' => ID::uuid_v4()]);
        try {
            if ($sql = TestConfig::open_configured_db($base)) {
                $base->set('DB', $sql);
                TestConfig::ensure_options_table($base);
            }
        } catch (\Throwable) {
        }

        self::$bootstrapped = true;
    }

    protected function setUp(): void
    {
        $base = \Base::instance();
        App::instance()->set('APP_UUID', ID::uuid_v4());

        $base->set('DB_CONFIG', TestConfig::db());
        try {
            if ($sql = TestConfig::open_configured_db($base)) {
                $base->set('DB', $sql);
            }
        } catch (\Throwable) {
        }

        $this->folder_path = TempPath::make_dir('atomic_transient_folder_');
        App::instance()->set('CACHE_CONFIG', TestConfig::cache([
            'path' => $this->folder_path,
            'prefix' => 'atomic_test_transient_' . bin2hex(random_bytes(4)),
        ]));
        ReflectionHelper::set(CacheManager::instance(), 'hive', []);
        ReflectionHelper::set(CacheManager::instance(), 'store', null);
    }

    protected function tearDown(): void
    {
        TempPath::remove($this->folder_path);
        ReflectionHelper::set(CacheManager::instance(), 'hive', []);
        ReflectionHelper::set(CacheManager::instance(), 'store', null);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function key(string $label): string
    {
        return 'phpunit_' . $this->name() . '_' . $label . '_' . uniqid('', true);
    }

    private function normalized_redis_prefix(): string
    {
        return rtrim(trim((string)App::instance()->get('REDIS.prefix')), '.');
    }

    private function redis_connection(): \Redis
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis not loaded');
        }

        try {
            $redis = ConnectionManager::instance()->get_redis();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis server unavailable: ' . $e->getMessage());
        }

        return $redis;
    }

    private function ping_driver(?string $driver): bool
    {
        $this->driver_error = '';
        try {
            $cache_manager = CacheManager::instance();
            $cache = match ($driver) {
                Transient::DRIVER_REDIS     => $cache_manager->redis(),
                Transient::DRIVER_MEMCACHED => $cache_manager->memcached(),
                Transient::DRIVER_FOLDER    => $cache_manager->folder(),
                Transient::DRIVER_DB        => $cache_manager->db(),
                default                     => ReflectionHelper::invoke(Transient::class, 'get_cache_driver', [null]),
            };
            $ping = '_ping_' . uniqid('', true);
            $cache->set($ping, '1', 5);
            $cache_val = $cache->get($ping);
            $cache->clear($ping);
            return ($cache_val === '1');
        } catch (\Throwable $e) {
            $this->driver_error = $e::class . ': ' . $e->getMessage();
            return false;
        }
    }

    private function skip_if_driver_unavailable(?string $driver): void
    {
        if (!$this->ping_driver($driver)) {
            $label = $driver ?? 'cascade';
            if ($driver === Transient::DRIVER_DB) {
                $this->fail("Driver «{$label}» is not available in this environment. {$this->driver_error}");
            }
            $this->markTestSkipped("Driver «{$label}» is not available in this environment.");
        }
    }

    // ── Guard: invalid TTL ───────────────────────────────────────────────────

    public function test_set_throws_for_zero_ttl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Transient::set($this->key('ttl0'), 'value', 0);
    }

    public function test_set_throws_for_negative_ttl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Transient::set($this->key('ttlneg'), 'value', -5);
    }

    public function test_default_driver_priority_is_wordpress_like(): void
    {
        $priority = ReflectionHelper::constant(Transient::class, 'DEFAULT_DRIVER_PRIORITY');

        $this->assertSame([
            Transient::DRIVER_REDIS,
            Transient::DRIVER_MEMCACHED,
            Transient::DRIVER_DB,
            Transient::DRIVER_FOLDER,
        ], $priority);
    }

    // ── REDIS ─────────────────────────────────────────────────────────────────

    // [1] SET / GET
    public function test_redis_set_and_get_returns_stored_value(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_REDIS);

        $key   = $this->key('setget');
        $value = 'redis_value_' . uniqid();

        $set_result = Transient::set($key, $value, 60, Transient::DRIVER_REDIS);
        $this->assertIsBool($set_result);
        $this->assertTrue($set_result);

        $this->assertSame($value, Transient::get($key, Transient::DRIVER_REDIS));

        $delete_result = Transient::delete($key, Transient::DRIVER_REDIS);
        $this->assertIsBool($delete_result);
        $this->assertTrue($delete_result);
    }

    public function test_redis_transient_uses_atomic_prefix_without_f3_seed(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_REDIS);

        $key = $this->key('prefix');
        $cache = CacheManager::instance()->redis();
        $expected = $this->normalized_redis_prefix() . '.entry.' . $cache->get_generation() . '.transient.' . $key;

        $this->assertTrue(Transient::set($key, 'prefixed', 60, Transient::DRIVER_REDIS));

        $redis = $this->redis_connection();
        try {
            $this->assertSame(1, $redis->exists($expected));
            $this->assertSame([$expected], $redis->keys($expected));
        } finally {
            $redis->del($expected);
            ConnectionManager::instance()->close_redis();
        }
    }

    // [2] GET missing key
    public function test_redis_get_missing_key_returns_false(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_REDIS);

        $this->assertFalse(Transient::get($this->key('missing'), Transient::DRIVER_REDIS));
    }

    // [3] DELETE
    public function test_redis_delete_removes_key(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_REDIS);

        $key = $this->key('delete');

        $set_result = Transient::set($key, 'to_delete', 60, Transient::DRIVER_REDIS);
        $this->assertIsBool($set_result);
        $this->assertTrue($set_result);

        $this->assertNotFalse(Transient::get($key, Transient::DRIVER_REDIS), 'Key must exist before deletion');

        $delete_result = Transient::delete($key, Transient::DRIVER_REDIS);
        $this->assertIsBool($delete_result);
        $this->assertTrue($delete_result);

        $this->assertFalse(Transient::get($key, Transient::DRIVER_REDIS), 'Key must not exist after deletion');
    }

    // [4] DELETE safe (idempotent)
    public function test_redis_delete_nonexistent_key_is_safe(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_REDIS);

        $result = Transient::delete($this->key('noexist'), Transient::DRIVER_REDIS);
        $this->assertIsBool($result);
        $this->assertTrue($result);
    }

    // [5] DELETE_ALL
    public function test_redis_delete_all_removes_all_transient_keys(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_REDIS);

        $uid  = uniqid('', true);
        $keys = ["redis_dall_a_{$uid}", "redis_dall_b_{$uid}", "redis_dall_c_{$uid}"];

        foreach ($keys as $k) {
            $set_result = Transient::set($k, "val_{$k}", 60, Transient::DRIVER_REDIS);
            $this->assertIsBool($set_result);
            $this->assertTrue($set_result);
        }

        foreach ($keys as $k) {
            $this->assertNotFalse(
                Transient::get($k, Transient::DRIVER_REDIS),
                "Key «{$k}» must exist before delete_all"
            );
        }

        $delete_all_result = Transient::delete_all(Transient::DRIVER_REDIS);
        $this->assertIsBool($delete_all_result);
        $this->assertTrue($delete_all_result);

        foreach ($keys as $k) {
            $this->assertFalse(
                Transient::get($k, Transient::DRIVER_REDIS),
                "Key «{$k}» must not exist after delete_all"
            );
        }
    }

    // [6] EXPIRATION
    public function test_redis_key_expires_after_ttl(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_REDIS);

        $key = $this->key('expiry');

        $set_result = Transient::set($key, 'expire_me', 1, Transient::DRIVER_REDIS);
        $this->assertIsBool($set_result);
        $this->assertTrue($set_result);

        $this->assertNotFalse(
            Transient::get($key, Transient::DRIVER_REDIS),
            'Key must exist immediately after set'
        );

        $expired = Wait::until(
            fn (): bool => Transient::get($key, Transient::DRIVER_REDIS) === false,
            4
        );

        $this->assertTrue($expired, 'Key must have expired after TTL elapsed');
    }

    // Extra: value is preserved precisely (no double-serialization)
    public function test_redis_preserves_string_with_special_characters(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_REDIS);

        $key   = $this->key('special');
        $value = 'quotes:"foo" apostrophe:\'bar\' backslash:\\baz\\ null:\0end';

        $set_result = Transient::set($key, $value, 60, Transient::DRIVER_REDIS);
        $this->assertIsBool($set_result);
        $this->assertTrue($set_result);

        $this->assertSame($value, Transient::get($key, Transient::DRIVER_REDIS));

        $delete_result = Transient::delete($key, Transient::DRIVER_REDIS);
        $this->assertIsBool($delete_result);
        $this->assertTrue($delete_result);
    }

    // Extra: two different keys do not collide
    public function test_redis_multiple_keys_are_independent(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_REDIS);

        $key_1 = $this->key('k1');
        $key_2 = $this->key('k2');

        $set_1 = Transient::set($key_1, 'alpha', 60, Transient::DRIVER_REDIS);
        $this->assertIsBool($set_1);
        $this->assertTrue($set_1);

        $set_2 = Transient::set($key_2, 'beta', 60, Transient::DRIVER_REDIS);
        $this->assertIsBool($set_2);
        $this->assertTrue($set_2);

        $this->assertSame('alpha', Transient::get($key_1, Transient::DRIVER_REDIS));
        $this->assertSame('beta',  Transient::get($key_2, Transient::DRIVER_REDIS));

        $delete_1 = Transient::delete($key_1, Transient::DRIVER_REDIS);
        $this->assertIsBool($delete_1);
        $this->assertTrue($delete_1);

        $delete_2 = Transient::delete($key_2, Transient::DRIVER_REDIS);
        $this->assertIsBool($delete_2);
        $this->assertTrue($delete_2);
    }

    // Extra: overwriting a key stores new value
    public function test_redis_overwrite_updates_value(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_REDIS);

        $key = $this->key('overwrite');

        $set_1 = Transient::set($key, 'first',  60, Transient::DRIVER_REDIS);
        $this->assertIsBool($set_1);
        $this->assertTrue($set_1);

        $set_2 = Transient::set($key, 'second', 60, Transient::DRIVER_REDIS);
        $this->assertIsBool($set_2);
        $this->assertTrue($set_2);

        $this->assertSame('second', Transient::get($key, Transient::DRIVER_REDIS));

        $delete_result = Transient::delete($key, Transient::DRIVER_REDIS);
        $this->assertIsBool($delete_result);
        $this->assertTrue($delete_result);
    }

    // ── MEMCACHED ─────────────────────────────────────────────────────────────

    // [1] SET / GET
    public function test_memcached_set_and_get_returns_stored_value(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_MEMCACHED);

        $key   = $this->key('setget');
        $value = 'memcached_value_' . uniqid();

        $set_result = Transient::set($key, $value, 60, Transient::DRIVER_MEMCACHED);
        $this->assertIsBool($set_result);
        $this->assertTrue($set_result);

        $this->assertSame($value, Transient::get($key, Transient::DRIVER_MEMCACHED));

        $delete_result = Transient::delete($key, Transient::DRIVER_MEMCACHED);
        $this->assertIsBool($delete_result);
        $this->assertTrue($delete_result);
    }

    // [2] GET missing key
    public function test_memcached_get_missing_key_returns_false(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_MEMCACHED);

        $this->assertFalse(Transient::get($this->key('missing'), Transient::DRIVER_MEMCACHED));
    }

    // [3] DELETE
    public function test_memcached_delete_removes_key(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_MEMCACHED);

        $key = $this->key('delete');

        $set_result = Transient::set($key, 'to_delete', 60, Transient::DRIVER_MEMCACHED);
        $this->assertIsBool($set_result);
        $this->assertTrue($set_result);

        $this->assertNotFalse(Transient::get($key, Transient::DRIVER_MEMCACHED), 'Key must exist before deletion');

        $delete_result = Transient::delete($key, Transient::DRIVER_MEMCACHED);
        $this->assertIsBool($delete_result);
        $this->assertTrue($delete_result);

        $this->assertFalse(Transient::get($key, Transient::DRIVER_MEMCACHED), 'Key must not exist after deletion');
    }

    // [4] DELETE safe (idempotent)
    public function test_memcached_delete_nonexistent_key_is_safe(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_MEMCACHED);

        $result = Transient::delete($this->key('noexist'), Transient::DRIVER_MEMCACHED);
        $this->assertIsBool($result);
        $this->assertTrue($result);
    }

    // [5] DELETE_ALL – generation-bump strategy
    public function test_memcached_delete_all_makes_all_keys_invisible(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_MEMCACHED);

        $uid  = uniqid('', true);
        $keys = ["mc_dall_a_{$uid}", "mc_dall_b_{$uid}", "mc_dall_c_{$uid}"];

        foreach ($keys as $k) {
            $set_result = Transient::set($k, "val_{$k}", 60, Transient::DRIVER_MEMCACHED);
            $this->assertIsBool($set_result);
            $this->assertTrue($set_result);
        }

        foreach ($keys as $k) {
            $this->assertNotFalse(
                Transient::get($k, Transient::DRIVER_MEMCACHED),
                "Key «{$k}» must exist before delete_all"
            );
        }

        $delete_all_result = Transient::delete_all(Transient::DRIVER_MEMCACHED);
        $this->assertIsBool($delete_all_result);
        $this->assertTrue($delete_all_result);

        foreach ($keys as $k) {
            $this->assertFalse(
                Transient::get($k, Transient::DRIVER_MEMCACHED),
                "Key «{$k}» must be invisible after generation bump"
            );
        }
    }

    // [6] EXPIRATION
    public function test_memcached_key_expires_after_ttl(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_MEMCACHED);

        $key = $this->key('expiry');

        $set_result = Transient::set($key, 'expire_me', 1, Transient::DRIVER_MEMCACHED);
        $this->assertIsBool($set_result);
        $this->assertTrue($set_result);

        $this->assertNotFalse(
            Transient::get($key, Transient::DRIVER_MEMCACHED),
            'Key must exist immediately after set'
        );

        $this->assertTrue(
            Wait::until(
                fn (): bool => Transient::get($key, Transient::DRIVER_MEMCACHED) === false,
                4
            ),
            'Key must have expired after TTL elapsed'
        );
    }

    // [7] GENERATION COUNTER – Memcached-specific
    public function test_memcached_delete_all_increments_generation_by_one(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_MEMCACHED);

        $wrapper = CacheManager::instance()->memcached();
        $gen_before = $wrapper->get_generation();

        $key = $this->key('gen');

        $set_result = Transient::set($key, 'gen_val', 60, Transient::DRIVER_MEMCACHED);
        $this->assertIsBool($set_result);
        $this->assertTrue($set_result);

        $this->assertSame('gen_val', Transient::get($key, Transient::DRIVER_MEMCACHED));

        $delete_all_result = Transient::delete_all(Transient::DRIVER_MEMCACHED);
        $this->assertIsBool($delete_all_result);
        $this->assertTrue($delete_all_result);

        $gen_after = $wrapper->get_generation();

        $this->assertSame(
            $gen_before + 1,
            $gen_after,
            "Generation must increment exactly by 1 on delete_all (was {$gen_before}, got {$gen_after})"
        );

        $this->assertFalse(
            Transient::get($key, Transient::DRIVER_MEMCACHED),
            'Old-generation key must be invisible after generation bump'
        );
    }

    // Extra: value stored in old generation is hidden; new key in new generation is readable.
    public function test_memcached_new_key_readable_after_generation_bump(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_MEMCACHED);

        $old_key = $this->key('old');
        $new_key = $this->key('new');

        $set_old = Transient::set($old_key, 'old_val', 60, Transient::DRIVER_MEMCACHED);
        $this->assertIsBool($set_old);
        $this->assertTrue($set_old);

        $delete_all_result = Transient::delete_all(Transient::DRIVER_MEMCACHED);
        $this->assertIsBool($delete_all_result);
        $this->assertTrue($delete_all_result);

        $set_new = Transient::set($new_key, 'new_val', 60, Transient::DRIVER_MEMCACHED);
        $this->assertIsBool($set_new);
        $this->assertTrue($set_new);

        $this->assertFalse(Transient::get($old_key, Transient::DRIVER_MEMCACHED), 'Old key must be invisible');
        $this->assertSame('new_val', Transient::get($new_key, Transient::DRIVER_MEMCACHED), 'New key must be readable');

        $delete_result = Transient::delete($new_key, Transient::DRIVER_MEMCACHED);
        $this->assertIsBool($delete_result);
        $this->assertTrue($delete_result);
    }

    // ── DB ───────────────────────────────────────────────────────────────────

    // [1] SET / GET
    public function test_db_set_and_get_returns_stored_value(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_DB);

        $key   = $this->key('setget');
        $value = 'db_value_' . uniqid();

        $set_result = Transient::set($key, $value, 60, Transient::DRIVER_DB);
        $this->assertIsBool($set_result);
        $this->assertTrue($set_result);

        $this->assertSame($value, Transient::get($key, Transient::DRIVER_DB));

        $delete_result = Transient::delete($key, Transient::DRIVER_DB);
        $this->assertIsBool($delete_result);
        $this->assertTrue($delete_result);
    }

    // [2] GET missing key
    public function test_db_get_missing_key_returns_false(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_DB);

        $this->assertFalse(Transient::get($this->key('missing'), Transient::DRIVER_DB));
    }

    // [3] DELETE
    public function test_db_delete_removes_key(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_DB);

        $key = $this->key('delete');

        $set_result = Transient::set($key, 'to_delete', 60, Transient::DRIVER_DB);
        $this->assertIsBool($set_result);
        $this->assertTrue($set_result);

        $this->assertNotFalse(Transient::get($key, Transient::DRIVER_DB), 'Key must exist before deletion');

        $delete_result = Transient::delete($key, Transient::DRIVER_DB);
        $this->assertIsBool($delete_result);
        $this->assertTrue($delete_result);

        $this->assertFalse(Transient::get($key, Transient::DRIVER_DB), 'Key must not exist after deletion');
    }

    // [4] DELETE safe (idempotent)
    public function test_db_delete_nonexistent_key_is_safe(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_DB);

        $result = Transient::delete($this->key('noexist'), Transient::DRIVER_DB);
        $this->assertIsBool($result);
        $this->assertTrue($result);
    }

    // [5] DELETE_ALL
    public function test_db_delete_all_removes_all_transient_rows(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_DB);

        $uid  = uniqid('', true);
        $keys = ["db_dall_a_{$uid}", "db_dall_b_{$uid}", "db_dall_c_{$uid}"];

        foreach ($keys as $k) {
            $set_result = Transient::set($k, "val_{$k}", 60, Transient::DRIVER_DB);
            $this->assertIsBool($set_result);
            $this->assertTrue($set_result);
        }

        foreach ($keys as $k) {
            $this->assertNotFalse(
                Transient::get($k, Transient::DRIVER_DB),
                "Key «{$k}» must exist before delete_all"
            );
        }

        $delete_all_result = Transient::delete_all(Transient::DRIVER_DB);
        $this->assertIsBool($delete_all_result);
        $this->assertTrue($delete_all_result);

        foreach ($keys as $k) {
            $this->assertFalse(
                Transient::get($k, Transient::DRIVER_DB),
                "Key «{$k}» must not exist after delete_all"
            );
        }
    }

    // [6] EXPIRATION – Options model respects expired_at column
    public function test_db_key_expires_after_ttl(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_DB);

        $key = $this->key('expiry');

        $set_result = Transient::set($key, 'expire_me', 3, Transient::DRIVER_DB);
        $this->assertIsBool($set_result);
        $this->assertTrue($set_result);

        $this->assertNotFalse(
            Transient::get($key, Transient::DRIVER_DB),
            'Key must exist immediately after set'
        );

        $expired = Wait::until(
            fn (): bool => Transient::get($key, Transient::DRIVER_DB) === false,
            5
        );

        $this->assertTrue(
            $expired,
            'Key must have expired after TTL elapsed'
        );
    }

    // Extra: overwrite sets new value while preserving TTL refresh
    public function test_db_overwrite_updates_value(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_DB);

        $key = $this->key('overwrite');

        $set_1 = Transient::set($key, 'first',  60, Transient::DRIVER_DB);
        $this->assertIsBool($set_1);
        $this->assertTrue($set_1);

        $set_2 = Transient::set($key, 'second', 60, Transient::DRIVER_DB);
        $this->assertIsBool($set_2);
        $this->assertTrue($set_2);

        $this->assertSame('second', Transient::get($key, Transient::DRIVER_DB));

        $delete_result = Transient::delete($key, Transient::DRIVER_DB);
        $this->assertIsBool($delete_result);
        $this->assertTrue($delete_result);
    }

    // ── FOLDER ───────────────────────────────────────────────────────────────

    public function test_folder_set_and_get_returns_stored_value(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_FOLDER);

        $key = $this->key('folder_setget');

        $this->assertTrue(Transient::set($key, 'folder_value', 60, Transient::DRIVER_FOLDER));
        $this->assertSame('folder_value', Transient::get($key, Transient::DRIVER_FOLDER));
        $this->assertTrue(Transient::delete($key, Transient::DRIVER_FOLDER));
        $this->assertFalse(Transient::get($key, Transient::DRIVER_FOLDER));
    }

    public function test_folder_get_missing_key_returns_false(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_FOLDER);

        $this->assertFalse(Transient::get($this->key('folder_missing'), Transient::DRIVER_FOLDER));
    }

    public function test_folder_delete_all_makes_transients_invisible(): void
    {
        $this->skip_if_driver_unavailable(Transient::DRIVER_FOLDER);

        $key_1 = $this->key('folder_dall_1');
        $key_2 = $this->key('folder_dall_2');

        $this->assertTrue(Transient::set($key_1, 'one', 60, Transient::DRIVER_FOLDER));
        $this->assertTrue(Transient::set($key_2, 'two', 60, Transient::DRIVER_FOLDER));
        $this->assertSame('one', Transient::get($key_1, Transient::DRIVER_FOLDER));
        $this->assertSame('two', Transient::get($key_2, Transient::DRIVER_FOLDER));

        $this->assertTrue(Transient::delete_all(Transient::DRIVER_FOLDER));
        $this->assertFalse(Transient::get($key_1, Transient::DRIVER_FOLDER));
        $this->assertFalse(Transient::get($key_2, Transient::DRIVER_FOLDER));
    }

    // ── DEFAULT DRIVER (null driver) ──────────────────────────────────────────

    // [1] SET / GET
    public function test_cascade_set_and_get_returns_stored_value(): void
    {
        $this->skip_if_driver_unavailable(null);

        $key   = $this->key('setget');
        $value = 'cascade_value_' . uniqid();

        $set_result = Transient::set($key, $value, 60, null);
        $this->assertIsBool($set_result);
        $this->assertTrue($set_result);

        $this->assertSame($value, Transient::get($key, null));

        $delete_result = Transient::delete($key, null);
        $this->assertIsBool($delete_result);
        $this->assertTrue($delete_result);
    }

    // [2] GET missing key
    public function test_cascade_get_missing_key_returns_false(): void
    {
        $this->skip_if_driver_unavailable(null);

        $this->assertFalse(Transient::get($this->key('missing'), null));
    }

    // [3] DELETE
    public function test_cascade_delete_removes_key(): void
    {
        $this->skip_if_driver_unavailable(null);

        $key = $this->key('delete');

        $set_result = Transient::set($key, 'to_delete', 60, null);
        $this->assertIsBool($set_result);
        $this->assertTrue($set_result);

        $this->assertNotFalse(Transient::get($key, null), 'Key must exist before deletion');

        $delete_result = Transient::delete($key, null);
        $this->assertIsBool($delete_result);
        $this->assertTrue($delete_result);

        $this->assertFalse(Transient::get($key, null), 'Key must not exist after deletion');
    }

    // [4] DELETE safe (idempotent)
    public function test_cascade_delete_nonexistent_key_is_safe(): void
    {
        $this->skip_if_driver_unavailable(null);

        $result = Transient::delete($this->key('noexist'), null);
        $this->assertIsBool($result);
        $this->assertTrue($result);
    }

    // [5] Default driver resolves to a concrete store
    public function test_default_driver_is_consistent_within_request(): void
    {
        $this->skip_if_driver_unavailable(null);

        $key_1 = $this->key('consist_a');
        $key_2 = $this->key('consist_b');

        $set_1 = Transient::set($key_1, 'v1', 60, null);
        $this->assertIsBool($set_1);
        $this->assertTrue($set_1);

        $set_2 = Transient::set($key_2, 'v2', 60, null);
        $this->assertIsBool($set_2);
        $this->assertTrue($set_2);

        $this->assertSame('v1', Transient::get($key_1, null));
        $this->assertSame('v2', Transient::get($key_2, null));

        $delete_1 = Transient::delete($key_1, null);
        $this->assertIsBool($delete_1);
        $this->assertTrue($delete_1);

        $delete_2 = Transient::delete($key_2, null);
        $this->assertIsBool($delete_2);
        $this->assertTrue($delete_2);
    }
}
