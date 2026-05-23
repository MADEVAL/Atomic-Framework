<?php
declare(strict_types=1);

namespace Tests\Engine\Cache;

use Engine\Atomic\Cache\FatFreeCacheBridge;
use Engine\Atomic\Core\CacheManager;
use Engine\Atomic\Core\Config\ConfigLoader;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;
use Tests\Support\TempPath;

class FatFreeCacheBridgeParityWeb extends \Web
{
    private int $calls = 0;

    public function __construct()
    {
        $this->wrapper = 'stream';
    }

    protected function _stream($url, $options): array
    {
        $this->calls++;
        $conditional = !empty(preg_grep('/^If-Modified-Since:/i', $options['header']));

        if ($conditional) {
            return [
                'body' => '',
                'headers' => ['HTTP/1.1 304 Not Modified'],
                'engine' => 'test',
                'cached' => false,
                'error' => '',
            ];
        }

        return [
            'body' => 'web-body-' . $this->calls,
            'headers' => [
                'HTTP/1.1 200 OK',
                'Last-Modified: Mon, 01 Jan 2024 00:00:00 GMT',
                'Cache-Control: public, max-age=60',
            ],
            'engine' => 'test',
            'cached' => false,
            'error' => '',
        ];
    }

    public function calls(): int
    {
        return $this->calls;
    }
}

class FatFreeCacheBridgeParityMongoCursor
{
    public function __construct(private array $rows)
    {
    }

    public function toarray(): array
    {
        return $this->rows;
    }
}

class FatFreeCacheBridgeParityMongoCollection
{
    public function __construct(private array $rows)
    {
    }

    public function find($filter, $options): FatFreeCacheBridgeParityMongoCursor
    {
        $rows = array_values(array_filter($this->rows, function (array $row) use ($filter): bool {
            foreach ($filter ?: [] as $key => $value) {
                if (($row[$key] ?? null) !== $value) {
                    return false;
                }
            }

            return true;
        }));

        if (!empty($options['sort'])) {
            foreach (array_reverse($options['sort']) as $field => $direction) {
                usort($rows, fn(array $a, array $b): int => (($a[$field] ?? null) <=> ($b[$field] ?? null)) * ($direction < 0 ? -1 : 1));
            }
        }

        if (!empty($options['skip'])) {
            $rows = array_slice($rows, (int)$options['skip']);
        }
        if (!empty($options['limit'])) {
            $rows = array_slice($rows, 0, (int)$options['limit']);
        }

        return new FatFreeCacheBridgeParityMongoCursor($rows);
    }

    public function count($filter): int
    {
        return count($this->find($filter ?: [], [])->toarray());
    }

    public function replaceRows(array $rows): void
    {
        $this->rows = $rows;
    }
}

class FatFreeCacheBridgeParityMongoDb
{
    public function __construct(private FatFreeCacheBridgeParityMongoCollection $collection)
    {
    }

    public function selectcollection($collection): FatFreeCacheBridgeParityMongoCollection
    {
        return $this->collection;
    }
}

class FatFreeCacheBridgeTest extends TestCase
{
    private \Base $f3;
    private string $env_file;
    private string $cache_dir = '';
    private string $original_cache_setting = '';

    protected function setUp(): void
    {
        $this->f3 = \Base::instance();
        $this->original_cache_setting = (string)$this->f3->get('CACHE');
        $this->env_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_f3_cache_' . uniqid() . '.env';
        ReflectionHelper::set(CacheManager::instance(), 'hive', []);
        ReflectionHelper::set(CacheManager::instance(), 'store', null);
    }

    protected function tearDown(): void
    {
        \Cache::instance()->reset();
        if ($this->original_cache_setting !== '') {
            $this->f3->set('CACHE', $this->original_cache_setting);
        } else {
            $this->f3->clear('CACHE');
        }
        ReflectionHelper::set(CacheManager::instance(), 'hive', []);
        ReflectionHelper::set(CacheManager::instance(), 'store', null);

        if (getenv('ATOMIC_KEEP_F3_CACHE_TEST_FILES') === '1') {
            return;
        }

        if (is_file($this->env_file)) {
            @unlink($this->env_file);
        }
        if ($this->cache_dir !== '') {
            TempPath::remove($this->cache_dir);
        }
    }

    private function loadEnv(array $lines): void
    {
        file_put_contents($this->env_file, implode("\n", $lines) . "\n");
        (new ConfigLoader($this->f3))->load($this->env_file);
    }

    private function useNativeFolderCache(string $path, string $seed): \Cache
    {
        $cache = new \Cache('folder=' . $path);
        $cache->load('folder=' . $path, $seed);
        \Registry::set(\Cache::class, $cache);
        $this->f3->set('CACHE', 'folder=' . $path);

        return $cache;
    }

    private function useAtomicBridgeCache(string $path, string $prefix): FatFreeCacheBridge
    {
        $this->f3->set('CACHE_CONFIG', [
            'default' => 'folder',
            'path' => $path,
            'prefix' => $prefix,
        ]);
        ReflectionHelper::set(CacheManager::instance(), 'hive', []);
        ReflectionHelper::set(CacheManager::instance(), 'store', null);
        CacheManager::instance()->resolve();

        $cache = new FatFreeCacheBridge(CacheManager::FAT_FREE_CACHE_BRIDGE_SENTINEL);
        \Registry::set(\Cache::class, $cache);
        $this->f3->set('CACHE', CacheManager::FAT_FREE_CACHE_BRIDGE_SENTINEL);

        return $cache;
    }

    private function assertCacheEntryMatches(\Cache $native, string $native_key, \Cache $bridge, string $bridge_key, string $label): void
    {
        $native_value = null;
        $bridge_value = null;
        $native_meta = $native->exists($native_key, $native_value);
        $bridge_meta = $bridge->exists($bridge_key, $bridge_value);

        $this->assertSame($native_meta !== false, $bridge_meta !== false, "{$label}: cache hit parity");
        $this->assertSame($native_value, $bridge_value, "{$label}: cached value parity");

        if ($native_meta === false || $bridge_meta === false) {
            return;
        }

        $this->assertSame($native_meta[1], $bridge_meta[1], "{$label}: TTL parity");
        $this->assertLessThanOrEqual(2.0, abs($native_meta[0] - $bridge_meta[0]), "{$label}: cache timestamp parity");
    }

    private function runF3CacheProducerWithNativeAndAtomic(string $label, callable $producer): void
    {
        $base_dir = TempPath::make_dir('atomic_f3_parity_' . preg_replace('/[^a-z0-9]+/i', '_', $label) . '_');
        $this->cache_dir = $base_dir;
        $native_dir = $base_dir . DIRECTORY_SEPARATOR . 'native' . DIRECTORY_SEPARATOR;
        $atomic_dir = $base_dir . DIRECTORY_SEPARATOR . 'atomic' . DIRECTORY_SEPARATOR;
        $prefix = 'f3.parity.' . bin2hex(random_bytes(4));

        $native = $this->useNativeFolderCache($native_dir, $prefix);
        $native_result = $producer($native_dir, $prefix);

        $bridge = $this->useAtomicBridgeCache($atomic_dir, $prefix);
        $bridge_result = $producer($atomic_dir, $prefix);

        $this->assertSame($native_result['output'], $bridge_result['output'], "{$label}: producer output parity");
        $this->assertCount(count($native_result['keys']), $bridge_result['keys'], "{$label}: key count parity");
        foreach ($native_result['keys'] as $index => $key) {
            $this->assertCacheEntryMatches($native, $key, $bridge, $bridge_result['keys'][$index], $label);
        }
    }

    public function test_folder_cache_setting_works_with_fat_free_cache_engine(): void
    {
        $this->cache_dir = TempPath::make_dir('atomic_f3_folder_cache_');
        $this->loadEnv([
            'CACHE_DRIVER=folder',
            'CACHE_PATH=' . $this->cache_dir,
        ]);

        $cache = \Cache::instance();
        $key = 'f3.folder.' . bin2hex(random_bytes(4));

        $this->assertSame(CacheManager::FAT_FREE_CACHE_BRIDGE_SENTINEL, $this->f3->get('CACHE'));
        $this->assertTrue($cache->set($key, ['value' => 'ok'], 60));
        $this->assertSame(['value' => 'ok'], $cache->get($key));
        $this->assertNotFalse($cache->exists($key, $value));
        $this->assertSame(['value' => 'ok'], $value);
        $this->assertTrue((bool)$cache->clear($key));
        $this->assertFalse($cache->get($key));
    }

    public function test_fat_free_cache_singleton_is_atomic_bridge(): void
    {
        $this->cache_dir = TempPath::make_dir('atomic_f3_folder_prefix_');
        $this->loadEnv([
            'CACHE_DRIVER=folder',
            'CACHE_PATH=' . $this->cache_dir,
            'CACHE_PREFIX=atomic.f3.',
        ]);

        $cache = \Cache::instance();
        $key = 'prefix.check.' . bin2hex(random_bytes(4));

        $this->assertInstanceOf(FatFreeCacheBridge::class, $cache);
        $this->assertTrue($cache->set($key, 'ok', 60));
        $this->assertSame('ok', $cache->get($key));
    }

    public function test_fat_free_cache_bridge_seed_cannot_override_atomic_prefix(): void
    {
        $this->cache_dir = TempPath::make_dir('atomic_f3_seed_ignored_');
        $this->loadEnv([
            'CACHE_DRIVER=folder',
            'CACHE_PATH=' . $this->cache_dir,
            'CACHE_PREFIX=atomic.bridge.',
        ]);

        $cache = \Cache::instance();
        $cache->load(CacheManager::FAT_FREE_CACHE_BRIDGE_SENTINEL, 'seed-prefix.');
        $key = 'seed.check.' . bin2hex(random_bytes(4));

        $this->assertTrue($cache->set($key, 'ok', 60));
        $this->assertSame('ok', $cache->get($key));

        // F3 uses load()'s seed as the cache prefix; Atomic must keep CACHE_PREFIX authoritative.
        $folder = CacheManager::instance()->folder();
        $path = ReflectionHelper::get($folder, 'path');
        $configured_namespace = hash('sha256', 'atomic.bridge');
        $seed_namespace = hash('sha256', 'seed-prefix');

        $this->assertStringContainsString($configured_namespace, $path);
        $this->assertStringNotContainsString($seed_namespace, $path);
    }

    public function test_fat_free_cache_bridge_only_enables_for_atomic_sentinel(): void
    {
        $this->cache_dir = TempPath::make_dir('atomic_f3_strict_sentinel_');
        $this->loadEnv([
            'CACHE_DRIVER=folder',
            'CACHE_PATH=' . $this->cache_dir,
        ]);

        $cache = \Cache::instance();
        $key = 'strict.sentinel.' . bin2hex(random_bytes(4));

        $this->assertFalse($cache->load('folder=/tmp/f3-cache/'));
        $this->assertTrue($cache->set($key, 'ignored', 60));
        $this->assertFalse($cache->get($key));

        $this->assertSame(CacheManager::FAT_FREE_CACHE_BRIDGE_SENTINEL, $cache->load(CacheManager::FAT_FREE_CACHE_BRIDGE_SENTINEL));
        $this->assertTrue($cache->set($key, 'ok', 60));
        $this->assertSame('ok', $cache->get($key));
    }

    public function test_fat_free_cache_bridge_disabled_mode_matches_native_return_values(): void
    {
        $cache = new FatFreeCacheBridge();

        $this->assertFalse($cache->exists('disabled.key', $value));
        $this->assertNull($value);
        $this->assertTrue($cache->set('disabled.key', 'ignored', 60));
        $this->assertFalse($cache->get('disabled.key'));
        $this->assertNull($cache->clear('disabled.key'));
        $this->assertTrue($cache->reset());
        $this->assertFalse($cache->load(false));
    }

    public function test_set_existing_key_preserves_original_fat_free_ttl(): void
    {
        $this->cache_dir = TempPath::make_dir('atomic_f3_ttl_preserve_');
        $this->loadEnv([
            'CACHE_DRIVER=folder',
            'CACHE_PATH=' . $this->cache_dir,
        ]);

        $cache = \Cache::instance();
        $key = 'ttl.preserve.' . bin2hex(random_bytes(4));

        $this->assertTrue($cache->set($key, 'first', 90));
        $first_meta = $cache->exists($key, $value);
        $this->assertNotFalse($first_meta);
        $this->assertSame('first', $value);
        $this->assertSame(90, $first_meta[1]);

        $this->assertTrue($cache->set($key, 'second', 10));
        $second_meta = $cache->exists($key, $value);
        $this->assertNotFalse($second_meta);
        $this->assertSame('second', $value);
        $this->assertSame(90, $second_meta[1]);
    }

    public function test_reset_with_suffix_is_ignored_for_atomic_bridge(): void
    {
        $this->cache_dir = TempPath::make_dir('atomic_f3_folder_suffix_reset_');
        $this->loadEnv([
            'CACHE_DRIVER=folder',
            'CACHE_PATH=' . $this->cache_dir,
        ]);

        $cache = \Cache::instance();
        $key = 'suffix.reset.' . bin2hex(random_bytes(4));

        $this->assertTrue($cache->set($key, 'ok', 60));
        $this->assertTrue($cache->reset('suffix'));
        $this->assertSame('ok', $cache->get($key));
        $this->assertTrue($cache->reset('.@', 7200));
        $this->assertSame('ok', $cache->get($key));
        $this->assertTrue($cache->reset());
        $this->assertFalse($cache->get($key));
    }

    public function test_fat_free_hive_ttl_values_are_stored_in_atomic_cache(): void
    {
        $this->cache_dir = TempPath::make_dir('atomic_f3_hive_cache_');
        $this->loadEnv([
            'CACHE_DRIVER=folder',
            'CACHE_PATH=' . $this->cache_dir,
        ]);

        $this->f3->set('ATOMIC_F3_HIVE_VALUE', 'from-f3', 60);

        $key = $this->f3->hash('ATOMIC_F3_HIVE_VALUE') . '.var';
        $this->assertNotFalse(CacheManager::instance()->folder()->exists($key, $cached));
        $this->assertSame('from-f3', $cached);

        $get_hive = \Closure::bind(fn() => $this->hive, $this->f3, \Base::class);
        $set_hive = \Closure::bind(fn(array $h) => ($this->hive = $h), $this->f3, \Base::class);
        $hive = $get_hive();
        unset($hive['ATOMIC_F3_HIVE_VALUE']);
        $set_hive($hive);

        $this->assertSame('from-f3', $this->f3->get('ATOMIC_F3_HIVE_VALUE'));
    }

    public function test_fat_free_hive_devoid_and_clear_use_atomic_cache(): void
    {
        $this->cache_dir = TempPath::make_dir('atomic_f3_hive_clear_');
        $this->loadEnv([
            'CACHE_DRIVER=folder',
            'CACHE_PATH=' . $this->cache_dir,
        ]);

        $key = 'ATOMIC_F3_CLEAR_VALUE';
        $hash = $this->f3->hash($key) . '.var';

        $this->f3->set($key, '', 60);
        $this->assertTrue($this->f3->devoid($key, $value));
        $this->assertSame('', $value);
        $this->assertNotFalse(CacheManager::instance()->folder()->exists($hash, $cached));
        $this->assertSame('', $cached);

        $this->f3->clear($key);
        $this->assertFalse(CacheManager::instance()->folder()->exists($hash));
    }

    public function test_fat_free_route_cache_uses_atomic_cache(): void
    {
        $this->cache_dir = TempPath::make_dir('atomic_f3_route_cache_');
        $this->loadEnv([
            'CACHE_DRIVER=folder',
            'CACHE_PATH=' . $this->cache_dir,
        ]);

        $this->f3->clear('ROUTES');
        $this->f3->set('QUIET', true);
        $this->f3->set('HALT', false);
        $this->f3->set('ONERROR', fn() => true);

        $hits = 0;
        $this->f3->route('GET /atomic-f3-route-cache', function () use (&$hits) {
            $hits++;
            echo 'route-body-' . $hits;
        }, 60);

        $this->f3->mock('GET /atomic-f3-route-cache');
        $this->assertSame('route-body-1', $this->f3->get('RESPONSE'));

        $key = $this->f3->hash('GET /atomic-f3-route-cache') . '.url';
        $this->assertNotFalse(CacheManager::instance()->folder()->exists($key, $cached));
        $this->assertIsArray($cached);
        $this->assertSame('route-body-1', $cached[1]);

        $this->f3->mock('GET /atomic-f3-route-cache');
        $this->assertSame('route-body-1', $this->f3->get('RESPONSE'));
        $this->assertSame(1, $hits);
    }

    public function test_fat_free_sql_query_cache_uses_atomic_cache(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite not loaded.');
        }

        $this->cache_dir = TempPath::make_dir('atomic_f3_sql_cache_');
        $this->loadEnv([
            'CACHE_DRIVER=folder',
            'CACHE_PATH=' . $this->cache_dir,
        ]);

        $db = new \DB\SQL('sqlite::memory:');
        $db->exec('CREATE TABLE cache_items (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec('INSERT INTO cache_items (name) VALUES (?)', ['alpha']);

        $sql = 'SELECT name FROM cache_items WHERE id = ?';
        $result = $db->exec($sql, [1], 60);

        $key = $this->f3->hash('sqlite::memory:' . $sql . $this->f3->stringify([1 => 1])) . '.sql';
        $this->assertSame([['name' => 'alpha']], $result);
        $this->assertNotFalse(CacheManager::instance()->folder()->exists($key, $cached));
        $this->assertSame([['name' => 'alpha']], $cached);
    }

    public function test_fat_free_sql_schema_cache_uses_atomic_cache(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite not loaded.');
        }

        $this->cache_dir = TempPath::make_dir('atomic_f3_schema_cache_');
        $this->loadEnv([
            'CACHE_DRIVER=folder',
            'CACHE_PATH=' . $this->cache_dir,
        ]);

        $db = new \DB\SQL('sqlite::memory:');
        $db->exec('CREATE TABLE schema_items (id INTEGER PRIMARY KEY, name TEXT)');

        $schema = $db->schema('schema_items', null, 60);

        $key = $this->f3->hash('sqlite::memory:schema_items') . '.schema';
        $this->assertArrayHasKey('name', $schema);
        $this->assertNotFalse(CacheManager::instance()->folder()->exists($key, $cached));
        $this->assertArrayHasKey('name', $cached);
    }

    public function test_fat_free_view_cache_uses_atomic_cache(): void
    {
        $this->cache_dir = TempPath::make_dir('atomic_f3_view_cache_');
        $ui_dir = $this->cache_dir . 'ui' . DIRECTORY_SEPARATOR;
        mkdir($ui_dir, 0755, true);
        file_put_contents($ui_dir . 'plain.php', 'view <?php echo $name; ?>');

        $this->loadEnv([
            'CACHE_DRIVER=folder',
            'CACHE_PATH=' . $this->cache_dir,
        ]);
        $this->f3->set('UI', $ui_dir);
        $this->f3->set('name', 'atomic');

        $rendered = \View::instance()->render('plain.php', 'text/html', null, 60);

        $key = $this->f3->hash($ui_dir . 'plain.php');
        $this->assertSame('view atomic', $rendered);
        $this->assertNotFalse(CacheManager::instance()->folder()->exists($key, $cached));
        $this->assertSame('view atomic', $cached);
    }

    public function test_fat_free_preview_cache_uses_atomic_cache(): void
    {
        $this->cache_dir = TempPath::make_dir('atomic_f3_preview_cache_');
        $ui_dir = $this->cache_dir . 'ui' . DIRECTORY_SEPARATOR;
        mkdir($ui_dir, 0755, true);
        file_put_contents($ui_dir . 'template.htm', 'preview {{ @name }}');

        $this->loadEnv([
            'CACHE_DRIVER=folder',
            'CACHE_PATH=' . $this->cache_dir,
        ]);
        $this->f3->set('UI', $ui_dir);
        $this->f3->set('name', 'atomic');

        $rendered = \Preview::instance()->render('template.htm', 'text/html', null, 60);

        $key = $this->f3->hash($ui_dir . 'template.htm');
        $this->assertSame('preview atomic', $rendered);
        $this->assertNotFalse(CacheManager::instance()->folder()->exists($key, $cached));
        $this->assertSame('preview atomic', $cached);
    }

    public function test_fat_free_cache_backed_session_uses_atomic_cache_calls(): void
    {
        $this->cache_dir = TempPath::make_dir('atomic_f3_session_cache_');
        $this->loadEnv([
            'CACHE_DRIVER=folder',
            'CACHE_PATH=' . $this->cache_dir,
        ]);
        $this->f3->set('JAR.expire', 60);

        $session = ReflectionHelper::new_without_constructor(\Session::class);
        ReflectionHelper::set($session, '_cache', \Cache::instance());
        ReflectionHelper::set($session, '_ip', (string)$this->f3->get('IP'));
        ReflectionHelper::set($session, '_agent', '');
        ReflectionHelper::set($session, '_data', []);

        $id = 'atomic-f3-session-' . bin2hex(random_bytes(4));

        $this->assertTrue($session->open('', 'ATOMICSESSID'));
        $this->assertTrue($session->write($id, 'payload'));
        $this->assertSame('payload', $session->read($id));
        $this->assertIsInt($session->stamp());
        $this->assertNotFalse(CacheManager::instance()->folder()->exists($id . '.@', $cached));
        $this->assertSame('payload', $cached['data']);

        $this->assertTrue($session->gc(60));
        $this->assertSame('payload', $session->read($id));
        $this->assertTrue($session->destroy($id));
        $this->assertSame('', $session->read($id));
        $this->assertTrue($session->close());
    }

    /**
     * @group f3-cache-parity
     */
    public function test_atomic_bridge_matches_native_fat_free_cache_output_for_framework_cache_producers(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite not loaded.');
        }

        $route_id = 'atomic-f3-parity-' . bin2hex(random_bytes(4));
        $this->runF3CacheProducerWithNativeAndAtomic('route cache', function () use ($route_id): array {
            $this->f3->clear('ROUTES');
            $this->f3->set('QUIET', true);
            $this->f3->set('HALT', false);
            $this->f3->set('ONERROR', fn() => true);

            $hits = 0;
            $path = '/' . $route_id;
            $this->f3->route('GET ' . $path, function () use (&$hits) {
                $hits++;
                echo 'route-body-' . $hits;
            }, 60);

            $this->f3->mock('GET ' . $path);
            $first = $this->f3->get('RESPONSE');
            $this->f3->mock('GET ' . $path);

            return [
                'output' => [
                    'first' => $first,
                    'second' => $this->f3->get('RESPONSE'),
                    'hits' => $hits,
                ],
                'keys' => [$this->f3->hash('GET ' . $path) . '.url'],
            ];
        });

        $hive_key = 'ATOMIC_F3_PARITY_HIVE_' . bin2hex(random_bytes(4));
        $this->runF3CacheProducerWithNativeAndAtomic('hive cache', function () use ($hive_key): array {
            $key = $hive_key;
            $hash = $this->f3->hash($key) . '.var';

            $this->f3->set($key, ['value' => 'from-f3'], 60);
            $first = $this->f3->get($key);
            $get_hive = \Closure::bind(fn() => $this->hive, $this->f3, \Base::class);
            $set_hive = \Closure::bind(fn(array $h) => ($this->hive = $h), $this->f3, \Base::class);
            $hive = $get_hive();
            unset($hive[$key]);
            $set_hive($hive);

            return [
                'output' => [
                    'first' => $first,
                    'cached' => $this->f3->get($key),
                ],
                'keys' => [$hash],
            ];
        });

        $this->runF3CacheProducerWithNativeAndAtomic('sql query cache', function (): array {
            $db = new \DB\SQL('sqlite::memory:');
            $db->exec('CREATE TABLE cache_items (id INTEGER PRIMARY KEY, name TEXT)');
            $db->exec('INSERT INTO cache_items (name) VALUES (?)', ['alpha']);

            $sql = 'SELECT name FROM cache_items WHERE id = ?';
            $args = [1];
            $first = $db->exec($sql, $args, 60);
            $db->exec('UPDATE cache_items SET name = ? WHERE id = ?', ['beta', 1]);
            $second = $db->exec($sql, $args, 60);

            return [
                'output' => ['first' => $first, 'second' => $second],
                'keys' => [$this->f3->hash('sqlite::memory:' . $sql . $this->f3->stringify([1 => 1])) . '.sql'],
            ];
        });

        $this->runF3CacheProducerWithNativeAndAtomic('tagged sql query cache', function (): array {
            $db = new \DB\SQL('sqlite::memory:');
            $db->exec('CREATE TABLE tagged_cache_items (id INTEGER PRIMARY KEY, name TEXT)');
            $db->exec('INSERT INTO tagged_cache_items (name) VALUES (?)', ['alpha']);

            $sql = 'SELECT name FROM tagged_cache_items WHERE id = ?';
            $args = [1];
            $first = $db->exec($sql, $args, [60, 'tagged']);
            $db->exec('UPDATE tagged_cache_items SET name = ? WHERE id = ?', ['beta', 1]);
            $second = $db->exec($sql, $args, [60, 'tagged']);

            return [
                'output' => ['first' => $first, 'second' => $second],
                'keys' => [$this->f3->hash('sqlite::memory:' . $sql . $this->f3->stringify([1 => 1])) . '.tagged.sql'],
            ];
        });

        $this->runF3CacheProducerWithNativeAndAtomic('schema cache', function (): array {
            $db = new \DB\SQL('sqlite::memory:');
            $db->exec('CREATE TABLE schema_items (id INTEGER PRIMARY KEY, name TEXT)');
            $first = $db->schema('schema_items', null, 60);
            $db->exec('ALTER TABLE schema_items ADD COLUMN added TEXT');
            $second = $db->schema('schema_items', null, 60);

            return [
                'output' => [
                    'first_keys' => array_keys($first),
                    'second_keys' => array_keys($second),
                ],
                'keys' => [$this->f3->hash('sqlite::memory:schema_items') . '.schema'],
            ];
        });

        $this->runF3CacheProducerWithNativeAndAtomic('schema field cache', function (): array {
            $db = new \DB\SQL('sqlite::memory:');
            $db->exec('CREATE TABLE schema_field_items (id INTEGER PRIMARY KEY, name TEXT, hidden TEXT)');
            $fields = ['name'];
            $first = $db->schema('schema_field_items', $fields, 60);
            $db->exec('ALTER TABLE schema_field_items ADD COLUMN added TEXT');
            $second = $db->schema('schema_field_items', $fields, 60);

            return [
                'output' => [
                    'first_keys' => array_keys($first),
                    'second_keys' => array_keys($second),
                ],
                'keys' => [$this->f3->hash('sqlite::memory:schema_field_items' . implode(',', $fields)) . '.schema'],
            ];
        });

        $this->runF3CacheProducerWithNativeAndAtomic('jig mapper cache', function (string $dir): array {
            $jig_dir = $dir . 'jig' . DIRECTORY_SEPARATOR;
            $db = new \DB\Jig($jig_dir);
            $mapper = new \DB\Jig\Mapper($db, 'items');
            $db->write('items', [
                'alpha-id' => ['name' => 'alpha', 'rank' => 2],
                'beta-id' => ['name' => 'beta', 'rank' => 1],
            ]);

            $filter = ['@name=?', 'alpha'];
            $options = ['order' => 'rank', 'limit' => 0, 'offset' => 0, 'group' => null];
            $first = array_map(fn($row) => $row->cast(), $mapper->find($filter, $options, [60, 'tagged']));
            $data = &$db->read('items');
            foreach ($data as &$row) {
                if (($row['name'] ?? null) === 'alpha') {
                    $row['name'] = 'changed';
                }
            }
            unset($row);
            $db->write('items', $data);
            $second = array_map(fn($row) => $row->cast(), $mapper->find($filter, $options, [60, 'tagged']));

            return [
                'output' => ['first' => $first, 'second' => $second, 'count' => $mapper->count($filter, $options, [60, 'tagged'])],
                'keys' => [$this->f3->hash($db->dir() . $this->f3->stringify([$filter, $options])) . '.tagged.jig'],
            ];
        });

        $this->runF3CacheProducerWithNativeAndAtomic('mongo mapper cache', function (): array {
            $collection = new FatFreeCacheBridgeParityMongoCollection([
                ['_id' => '1', 'name' => 'alpha', 'rank' => 2],
                ['_id' => '2', 'name' => 'beta', 'rank' => 1],
            ]);
            $db = ReflectionHelper::new_without_constructor(\DB\Mongo::class);
            ReflectionHelper::set($db, 'dsn', 'mongodb://cache-parity/');
            ReflectionHelper::set($db, 'legacy', false);
            ReflectionHelper::set($db, 'db', new FatFreeCacheBridgeParityMongoDb($collection));

            $fields = ['name' => 1, 'rank' => 1];
            $mapper = new \DB\Mongo\Mapper($db, 'items', $fields);
            $filter = ['name' => 'alpha'];
            $options = ['group' => null, 'order' => ['rank' => 1], 'limit' => 0, 'offset' => 0];

            $first = array_map(fn($row) => $row->cast(), $mapper->find($filter, $options, [60, 'tagged']));
            $first_count = $mapper->count($filter, [], [60, 'counted']);
            $collection->replaceRows([
                ['_id' => '1', 'name' => 'changed', 'rank' => 2],
                ['_id' => '2', 'name' => 'beta', 'rank' => 1],
            ]);
            $second = array_map(fn($row) => $row->cast(), $mapper->find($filter, $options, [60, 'tagged']));
            $second_count = $mapper->count($filter, [], [60, 'counted']);

            return [
                'output' => [
                    'first' => $first,
                    'second' => $second,
                    'first_count' => $first_count,
                    'second_count' => $second_count,
                ],
                'keys' => [
                    $this->f3->hash('mongodb://cache-parity/' . $this->f3->stringify([$fields, $filter, $options])) . '.tagged.mongo',
                    $this->f3->hash($this->f3->stringify([$filter])) . '.counted.mongo',
                ],
            ];
        });

        $this->runF3CacheProducerWithNativeAndAtomic('lexicon cache', function (string $dir): array {
            $locale_dir = $dir . 'locales' . DIRECTORY_SEPARATOR;
            mkdir($locale_dir, 0755, true);
            file_put_contents($locale_dir . 'en.php', '<?php return ["hello" => "Hello"];');
            $this->f3->set('LANGUAGE', 'en');

            $first = $this->f3->lexicon($locale_dir, 60);
            file_put_contents($locale_dir . 'en.php', '<?php return ["hello" => "Changed"];');
            $second = $this->f3->lexicon($locale_dir, 60);

            return [
                'output' => ['first' => $first, 'second' => $second],
                'keys' => [$this->f3->hash('en' . $locale_dir) . '.dic'],
            ];
        });

        $this->runF3CacheProducerWithNativeAndAtomic('view cache', function (string $dir): array {
            $ui_dir = $dir . 'ui' . DIRECTORY_SEPARATOR;
            mkdir($ui_dir, 0755, true);
            file_put_contents($ui_dir . 'plain.php', 'view <?php echo $name; ?>');
            $this->f3->set('UI', $ui_dir);
            $this->f3->set('name', 'atomic');

            $first = \View::instance()->render('plain.php', 'text/html', null, 60);
            $this->f3->set('name', 'changed');
            $second = \View::instance()->render('plain.php', 'text/html', null, 60);

            return [
                'output' => ['first' => $first, 'second' => $second],
                'keys' => [$this->f3->hash($ui_dir . 'plain.php')],
            ];
        });

        $this->runF3CacheProducerWithNativeAndAtomic('preview cache', function (string $dir): array {
            $ui_dir = $dir . 'ui' . DIRECTORY_SEPARATOR;
            mkdir($ui_dir, 0755, true);
            file_put_contents($ui_dir . 'template.htm', 'preview {{ @name }}');
            $this->f3->set('UI', $ui_dir);
            $this->f3->set('name', 'atomic');

            $first = \Preview::instance()->render('template.htm', 'text/html', null, 60);
            $this->f3->set('name', 'changed');
            $second = \Preview::instance()->render('template.htm', 'text/html', null, 60);

            return [
                'output' => ['first' => $first, 'second' => $second],
                'keys' => [$this->f3->hash($ui_dir . 'template.htm')],
            ];
        });

        $this->runF3CacheProducerWithNativeAndAtomic('preview resolve cache', function (): array {
            $node = 'resolve {{ @name }}';
            $first = \Preview::instance()->resolve($node, ['name' => 'atomic'], 60);
            $second = \Preview::instance()->resolve($node, ['name' => 'changed'], 60);

            return [
                'output' => ['first' => $first, 'second' => $second],
                'keys' => [$this->f3->hash($this->f3->serialize($node))],
            ];
        });

        $web_url = 'http://example.test/cache-parity-' . bin2hex(random_bytes(4));
        $this->runF3CacheProducerWithNativeAndAtomic('web request cache', function () use ($web_url): array {
            $web = new FatFreeCacheBridgeParityWeb();
            $first = $web->request($web_url);
            $second = $web->request($web_url);

            return [
                'output' => [
                    'first_body' => $first['body'],
                    'first_cached' => $first['cached'],
                    'second_body' => $second['body'],
                    'second_cached' => $second['cached'],
                    'calls' => $web->calls(),
                ],
                'keys' => [$this->f3->hash('GET ' . $web_url) . '.url'],
            ];
        });

        $this->runF3CacheProducerWithNativeAndAtomic('minify cache', function (string $dir): array {
            $ui_dir = $dir . 'ui' . DIRECTORY_SEPARATOR;
            mkdir($ui_dir, 0755, true);
            file_put_contents($ui_dir . 'app.css', "body {\n    color: red;\n}\n");
            file_put_contents($ui_dir . 'app.js', "const value = 1; // first\n");
            $this->f3->set('UI', $ui_dir);

            $first_css = \Web::instance()->minify('app.css', 'text/css', false, $ui_dir);
            $first_js = \Web::instance()->minify('app.js', 'application/x-javascript', false, $ui_dir);
            file_put_contents($ui_dir . 'app.css', "body {\n    color: blue;\n}\n");
            file_put_contents($ui_dir . 'app.js', "const value = 2; // second\n");
            touch($ui_dir . 'app.css', time() - 60);
            touch($ui_dir . 'app.js', time() - 60);
            $second_css = \Web::instance()->minify('app.css', 'text/css', false, $ui_dir);
            $second_js = \Web::instance()->minify('app.js', 'application/x-javascript', false, $ui_dir);

            return [
                'output' => [
                    'first_css' => $first_css,
                    'second_css' => $second_css,
                    'first_js' => $first_js,
                    'second_js' => $second_js,
                ],
                'keys' => [
                    $this->f3->hash($ui_dir . 'app.css') . '.css',
                    $this->f3->hash($ui_dir . 'app.js') . '.js',
                ],
            ];
        });

        $session_id = 'atomic-f3-parity-session-' . bin2hex(random_bytes(4));
        $this->runF3CacheProducerWithNativeAndAtomic('session cache', function () use ($session_id): array {
            $this->f3->set('JAR.expire', 60);
            $session = ReflectionHelper::new_without_constructor(\Session::class);
            ReflectionHelper::set($session, '_cache', \Cache::instance());
            ReflectionHelper::set($session, '_ip', (string)$this->f3->get('IP'));
            ReflectionHelper::set($session, '_agent', '');
            ReflectionHelper::set($session, '_data', []);

            $this->assertTrue($session->open('', 'ATOMICSESSID'));
            $this->assertTrue($session->write($session_id, 'payload'));

            return [
                'output' => [
                    'read' => $session->read($session_id),
                    'stamp_type' => gettype($session->stamp()),
                    'close' => $session->close(),
                ],
                'keys' => [$session_id . '.@'],
            ];
        });
    }

    public function test_atomic_bridge_matches_native_fat_free_folder_cache_api_results(): void
    {
        $this->cache_dir = TempPath::make_dir('atomic_f3_native_parity_');
        $native_dir = $this->cache_dir . 'native' . DIRECTORY_SEPARATOR;
        $atomic_dir = $this->cache_dir . 'atomic' . DIRECTORY_SEPARATOR;

        $this->loadEnv([
            'CACHE_DRIVER=folder',
            'CACHE_PATH=' . $atomic_dir,
        ]);

        $native = new \Cache('folder=' . $native_dir);
        $bridge = new FatFreeCacheBridge(CacheManager::FAT_FREE_CACHE_BRIDGE_SENTINEL);
        $key = 'native.parity.' . bin2hex(random_bytes(4));

        $this->assertSame(\Cache::class, $native::class);
        $this->assertNotInstanceOf(FatFreeCacheBridge::class, $native);
        $this->assertInstanceOf(FatFreeCacheBridge::class, $bridge);

        $native_missing_value = 'unchanged';
        $bridge_missing_value = 'unchanged';
        $this->assertSame($native->exists($key, $native_missing_value), $bridge->exists($key, $bridge_missing_value));
        $this->assertSame($native_missing_value, $bridge_missing_value);
        $this->assertSame($native->get($key), $bridge->get($key));
        $this->assertSame($native->clear($key), $bridge->clear($key));

        $this->assertSame((bool)$native->set($key, ['value' => 'first'], 120), (bool)$bridge->set($key, ['value' => 'first'], 120));
        $native_first_value = null;
        $bridge_first_value = null;
        $native_first_meta = $native->exists($key, $native_first_value);
        $bridge_first_meta = $bridge->exists($key, $bridge_first_value);

        $this->assertIsArray($native_first_meta);
        $this->assertIsArray($bridge_first_meta);
        $this->assertSame($native_first_value, $bridge_first_value);
        $this->assertSame($native_first_meta[1], $bridge_first_meta[1]);
        $this->assertSame($native->get($key), $bridge->get($key));

        $this->assertSame((bool)$native->set($key, ['value' => 'second'], 30), (bool)$bridge->set($key, ['value' => 'second'], 30));
        $native_second_value = null;
        $bridge_second_value = null;
        $native_second_meta = $native->exists($key, $native_second_value);
        $bridge_second_meta = $bridge->exists($key, $bridge_second_value);

        $this->assertIsArray($native_second_meta);
        $this->assertIsArray($bridge_second_meta);
        $this->assertSame($native_second_value, $bridge_second_value);
        $this->assertSame(['value' => 'second'], $bridge_second_value);
        $this->assertSame(120, $native_second_meta[1]);
        $this->assertSame($native_second_meta[1], $bridge_second_meta[1]);

        $this->assertSame((bool)$native->clear($key), (bool)$bridge->clear($key));
        $this->assertSame($native->get($key), $bridge->get($key));

        $this->assertSame((bool)$native->set($key . '.reset', 'reset-me', 60), (bool)$bridge->set($key . '.reset', 'reset-me', 60));
        $this->assertSame($native->reset(), $bridge->reset());
        $this->assertSame($native->get($key . '.reset'), $bridge->get($key . '.reset'));
    }
}
