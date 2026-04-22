<?php
declare(strict_types=1);

namespace Tests\Engine\Cache;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\CacheManager;
use Engine\Atomic\Core\ID;
use Engine\Atomic\Tools\Transient;
use PHPUnit\Framework\TestCase;

class TransientTest extends TestCase
{
    private static bool $bootstrapped = false;

    public static function setUpBeforeClass(): void
    {
        if (self::$bootstrapped) {
            return;
        }

        $base = \Base::instance();

        $redis_host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $redis_port = getenv('REDIS_PORT') ?: '6379';
        $redis_prefix = getenv('REDIS_PREFIX') ?: 'test.';

        $base->set('REDIS', [
            'host'                        => $redis_host,
            'port'                        => $redis_port,
            'password'                    => (string) (getenv('REDIS_PASSWORD') ?: ''),
            'db'                          => (int) (getenv('REDIS_DB') ?: 0),
            'prefix'                      => $redis_prefix,
        ]);

        $memcached_host = getenv('MEMCACHED_HOST') ?: '127.0.0.1';
        $memcached_port = getenv('MEMCACHED_PORT') ?: '11211';

        $base->set('MEMCACHED', [
            'host' => $memcached_host,
            'port' => $memcached_port,
        ]);

        $base->set('APP_UUID', ID::uuid_v4());

        $db_driver = getenv('DB_DRIVER') ?: 'mysql';
        $db_host = getenv('DB_HOST') ?: '127.0.0.1';
        $db_port = getenv('DB_PORT') ?: '3306';
        $db_name = getenv('DB_DB');
        $db_user = getenv('DB_USERNAME');
        $db_pass = getenv('DB_PASSWORD');
        $db_charset = getenv('DB_CHARSET') ?: 'utf8mb4';
        $db_collation = getenv('DB_COLLATION') ?: 'utf8mb4_general_ci';
        $db_prefix = getenv('DB_PREFIX') ?: 'atomic_';

        $base->set('DB_CONFIG', [
            'driver'                 => $db_driver,
            'host'                   => $db_host,
            'port'                   => $db_port,
            'db'                     => $db_name,
            'username'               => $db_user,
            'password'               => $db_pass,
            'unix_socket'            => '',
            'charset'                => $db_charset,
            'collation'              => $db_collation,
            'prefix'                 => $db_prefix,
        ]);

        try {
            $dsn = "{$db_driver}:host={$db_host};dbname={$db_name};charset={$db_charset};port={$db_port}";
            $sql = new \DB\SQL($dsn, $db_user, $db_pass, [
                \Pdo\Mysql::ATTR_INIT_COMMAND => "SET NAMES '{$db_charset}' COLLATE '{$db_collation}'",
            ]);
            $base->set('DB', $sql);
        } catch (\Throwable) {
        }

        App::instance($base);
        self::$bootstrapped = true;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function key(string $label): string
    {
        return 'phpunit_' . $this->name() . '_' . $label . '_' . uniqid('', true);
    }

    private function pingDriver(?string $driver): bool
    {
        try {
            $cache_manager = CacheManager::instance();
            $cache = match ($driver) {
                Transient::DRIVER_REDIS     => $cache_manager->redis(),
                Transient::DRIVER_MEMCACHED => $cache_manager->memcached(),
                Transient::DRIVER_DB        => $cache_manager->db(),
                default                     => $cache_manager->cascade(),
            };
            $ping = '_ping_' . uniqid('', true);
            $cache->set($ping, '1', 5);
            $cache_val = $cache->get($ping);
            $cache->clear($ping);
            return ($cache_val === '1');
        } catch (\Throwable) {
            return false;
        }
    }

    private function skipIfDriverUnavailable(?string $driver): void
    {
        if (!$this->pingDriver($driver)) {
            $label = $driver ?? 'cascade';
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

    // ── REDIS ─────────────────────────────────────────────────────────────────

    // [1] SET / GET
    public function test_redis_set_and_get_returns_stored_value(): void
    {
        $this->skipIfDriverUnavailable(Transient::DRIVER_REDIS);

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

    // [2] GET missing key
    public function test_redis_get_missing_key_returns_false(): void
    {
        $this->skipIfDriverUnavailable(Transient::DRIVER_REDIS);

        $this->assertFalse(Transient::get($this->key('missing'), Transient::DRIVER_REDIS));
    }

    // [3] DELETE
    public function test_redis_delete_removes_key(): void
    {
        $this->skipIfDriverUnavailable(Transient::DRIVER_REDIS);

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
        $this->skipIfDriverUnavailable(Transient::DRIVER_REDIS);

        $result = Transient::delete($this->key('noexist'), Transient::DRIVER_REDIS);
        $this->assertIsBool($result);
        $this->assertTrue($result);
    }

    // [5] DELETE_ALL
    public function test_redis_delete_all_removes_all_transient_keys(): void
    {
        $this->skipIfDriverUnavailable(Transient::DRIVER_REDIS);

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
        $this->skipIfDriverUnavailable(Transient::DRIVER_REDIS);

        $key = $this->key('expiry');

        $set_result = Transient::set($key, 'expire_me', 1, Transient::DRIVER_REDIS);
        $this->assertIsBool($set_result);
        $this->assertTrue($set_result);

        $this->assertNotFalse(
            Transient::get($key, Transient::DRIVER_REDIS),
            'Key must exist immediately after set'
        );

        $expired = false;
        for ($attempt = 0; $attempt < 4; $attempt++) {
            sleep(1);
            if (Transient::get($key, Transient::DRIVER_REDIS) === false) {
                $expired = true;
                break;
            }
        }

        $this->assertTrue($expired, 'Key must have expired after TTL elapsed');
    }

    // Extra: value is preserved precisely (no double-serialization)
    public function test_redis_preserves_string_with_special_characters(): void
    {
        $this->skipIfDriverUnavailable(Transient::DRIVER_REDIS);

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
        $this->skipIfDriverUnavailable(Transient::DRIVER_REDIS);

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
        $this->skipIfDriverUnavailable(Transient::DRIVER_REDIS);

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
        $this->skipIfDriverUnavailable(Transient::DRIVER_MEMCACHED);

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
        $this->skipIfDriverUnavailable(Transient::DRIVER_MEMCACHED);

        $this->assertFalse(Transient::get($this->key('missing'), Transient::DRIVER_MEMCACHED));
    }

    // [3] DELETE
    public function test_memcached_delete_removes_key(): void
    {
        $this->skipIfDriverUnavailable(Transient::DRIVER_MEMCACHED);

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
        $this->skipIfDriverUnavailable(Transient::DRIVER_MEMCACHED);

        $result = Transient::delete($this->key('noexist'), Transient::DRIVER_MEMCACHED);
        $this->assertIsBool($result);
        $this->assertTrue($result);
    }

    // [5] DELETE_ALL – generation-bump strategy
    public function test_memcached_delete_all_makes_all_keys_invisible(): void
    {
        $this->skipIfDriverUnavailable(Transient::DRIVER_MEMCACHED);

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
        $this->skipIfDriverUnavailable(Transient::DRIVER_MEMCACHED);

        $key = $this->key('expiry');

        $set_result = Transient::set($key, 'expire_me', 1, Transient::DRIVER_MEMCACHED);
        $this->assertIsBool($set_result);
        $this->assertTrue($set_result);

        $this->assertNotFalse(
            Transient::get($key, Transient::DRIVER_MEMCACHED),
            'Key must exist immediately after set'
        );

        sleep(2);

        $this->assertFalse(
            Transient::get($key, Transient::DRIVER_MEMCACHED),
            'Key must have expired after TTL elapsed'
        );
    }

    // [7] GENERATION COUNTER – Memcached-specific
    public function test_memcached_delete_all_increments_generation_by_one(): void
    {
        $this->skipIfDriverUnavailable(Transient::DRIVER_MEMCACHED);

        $wrapper = CacheManager::instance()->memcached();
        $wrapper->flush_local_cache();
        $gen_before = $wrapper->get_generation();

        $key = $this->key('gen');

        $set_result = Transient::set($key, 'gen_val', 60, Transient::DRIVER_MEMCACHED);
        $this->assertIsBool($set_result);
        $this->assertTrue($set_result);

        $this->assertSame('gen_val', Transient::get($key, Transient::DRIVER_MEMCACHED));

        $delete_all_result = Transient::delete_all(Transient::DRIVER_MEMCACHED);
        $this->assertIsBool($delete_all_result);
        $this->assertTrue($delete_all_result);

        $wrapper->flush_local_cache();
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
        $this->skipIfDriverUnavailable(Transient::DRIVER_MEMCACHED);

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
        $this->skipIfDriverUnavailable(Transient::DRIVER_DB);

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
        $this->skipIfDriverUnavailable(Transient::DRIVER_DB);

        $this->assertFalse(Transient::get($this->key('missing'), Transient::DRIVER_DB));
    }

    // [3] DELETE
    public function test_db_delete_removes_key(): void
    {
        $this->skipIfDriverUnavailable(Transient::DRIVER_DB);

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
        $this->skipIfDriverUnavailable(Transient::DRIVER_DB);

        $result = Transient::delete($this->key('noexist'), Transient::DRIVER_DB);
        $this->assertIsBool($result);
        $this->assertTrue($result);
    }

    // [5] DELETE_ALL
    public function test_db_delete_all_removes_all_transient_rows(): void
    {
        $this->skipIfDriverUnavailable(Transient::DRIVER_DB);

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
        $this->skipIfDriverUnavailable(Transient::DRIVER_DB);

        $key = $this->key('expiry');

        $set_result = Transient::set($key, 'expire_me', 1, Transient::DRIVER_DB);
        $this->assertIsBool($set_result);
        $this->assertTrue($set_result);

        $this->assertNotFalse(
            Transient::get($key, Transient::DRIVER_DB),
            'Key must exist immediately after set'
        );

        sleep(2);

        $this->assertFalse(
            Transient::get($key, Transient::DRIVER_DB),
            'Key must have expired after TTL elapsed'
        );
    }

    // Extra: overwrite sets new value while preserving TTL refresh
    public function test_db_overwrite_updates_value(): void
    {
        $this->skipIfDriverUnavailable(Transient::DRIVER_DB);

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

    // ── CASCADE (null driver) ─────────────────────────────────────────────────

    // [1] SET / GET
    public function test_cascade_set_and_get_returns_stored_value(): void
    {
        $this->skipIfDriverUnavailable(null);

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
        $this->skipIfDriverUnavailable(null);

        $this->assertFalse(Transient::get($this->key('missing'), null));
    }

    // [3] DELETE
    public function test_cascade_delete_removes_key(): void
    {
        $this->skipIfDriverUnavailable(null);

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
        $this->skipIfDriverUnavailable(null);

        $result = Transient::delete($this->key('noexist'), null);
        $this->assertIsBool($result);
        $this->assertTrue($result);
    }

    // [5] Cascade resolves to a concrete driver
    public function test_cascade_uses_consistent_driver_within_request(): void
    {
        $this->skipIfDriverUnavailable(null);

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