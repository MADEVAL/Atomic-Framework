<?php
declare(strict_types=1);

namespace Tests\Engine\Cache;

use Engine\Atomic\Cache\FatFreeCacheBridge;
use Engine\Atomic\Core\CacheManager;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;
use Tests\Support\TempPath;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FatFreeCacheBridgePersistenceRegressionTest extends TestCase
{
    private \Base $f3;
    private string $cache_dir = '';
    private string $original_cache = '';

    protected function setUp(): void
    {
        $this->f3 = \Base::instance();
        $this->original_cache = (string)$this->f3->get('CACHE');
        $this->cache_dir = TempPath::make_dir('atomic_f3_hive_order_');

        ReflectionHelper::set(CacheManager::instance(), 'hive', []);
        ReflectionHelper::set(CacheManager::instance(), 'store', null);
        $this->f3->set('CACHE_CONFIG', [
            'default' => 'folder',
            'path' => $this->cache_dir,
            'prefix' => 'atomic.f3.hive.order.' . bin2hex(random_bytes(4)),
            'f3_hive_ttl' => false,
        ]);

        $bridge = new FatFreeCacheBridge(CacheManager::FAT_FREE_CACHE_BRIDGE_SENTINEL);
        \Registry::set(\Cache::class, $bridge);
        $this->f3->set('CACHE', CacheManager::FAT_FREE_CACHE_BRIDGE_SENTINEL);
    }

    protected function tearDown(): void
    {
        \Cache::instance()->reset();
        $this->f3->set('CACHE', $this->original_cache !== '' ? $this->original_cache : false);
        ReflectionHelper::set(CacheManager::instance(), 'hive', []);
        ReflectionHelper::set(CacheManager::instance(), 'store', null);

        if ($this->cache_dir !== '') {
            TempPath::remove($this->cache_dir);
        }
    }

    public function test_hive_ttl_opt_out_is_stable_after_unrelated_bridge_lookup(): void
    {
        $key = 'F3_HIVE_ORDER_REGRESSION_' . bin2hex(random_bytes(4));
        $this->f3->set($key, 'persisted-from-prior-request', 60);

        $hive = \Closure::bind(fn() => $this->hive, $this->f3, \Base::class)();
        unset($hive[$key]);
        \Closure::bind(fn(array $value) => $this->hive = $value, $this->f3, \Base::class)($hive);

        $fresh = new FatFreeCacheBridge();
        $fresh->load(CacheManager::FAT_FREE_CACHE_BRIDGE_SENTINEL);
        \Registry::set(\Cache::class, $fresh);
        $this->f3->set('CACHE', CacheManager::FAT_FREE_CACHE_BRIDGE_SENTINEL);

        // With CACHE_F3_HIVE_TTL=false, this unresolved bridge lookup is
        // intentionally suppressed while the store is still unopened.
        $before_unrelated_lookup = $this->f3->get($key);

        // A route-cache lookup resolves the bridge store. The same public F3
        // lookup should keep the same result after that unrelated operation.
        $fresh->get($this->f3->hash('GET /unrelated-route') . '.url');

        $hive = \Closure::bind(fn() => $this->hive, $this->f3, \Base::class)();
        unset($hive[$key]);
        \Closure::bind(fn(array $value) => $this->hive = $value, $this->f3, \Base::class)($hive);
        $after_unrelated_lookup = $this->f3->get($key);

        $this->assertNull($before_unrelated_lookup);
        $this->assertSame(
            $before_unrelated_lookup,
            $after_unrelated_lookup,
            'Public F3 hive reads must not change after an unrelated bridge lookup when hive TTL persistence is disabled.'
        );
    }
}
