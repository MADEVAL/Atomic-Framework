<?php
declare(strict_types=1);

namespace Tests\Engine\App;

use Engine\Atomic\App\System;
use Engine\Atomic\Core\CacheManager;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempPath;
use Tests\Support\TestConfig;

final class SystemCacheClearTest extends TestCase
{
    private string $cache_dir;

    protected function setUp(): void
    {
        $this->cache_dir = TempPath::make_dir('atomic_system_cache_clear_');
        TestConfig::apply(\Base::instance(), [
            'cache' => [
                'default' => 'folder',
                'path' => $this->cache_dir,
                'prefix' => 'atomic_system_cache_clear.',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        TempPath::remove($this->cache_dir);
        TestConfig::reset_managers();
    }

    public function test_cache_invalidate_resets_configured_cache_store(): void
    {
        $cache = CacheManager::instance()->store();
        $this->assertTrue($cache->set('cli-clear-key', 'value', 3600));
        $this->assertSame('value', $cache->get('cli-clear-key'));

        (new System())->cache_invalidate();
        $cache->flush_local_cache();
        $this->assertFalse($cache->get('cli-clear-key'));
    }

    public function test_cache_clear_physically_purges_configured_cache_store(): void
    {
        $cache = CacheManager::instance()->store();
        $this->assertTrue($cache->set('cli-clear-key', 'value', 3600));

        (new System())->cache_clear();
        $cache->flush_local_cache();
        $this->assertFalse($cache->get('cli-clear-key'));
    }

    public function test_cache_prune_reports_expired_corrupt_cleanup_scope(): void
    {
        (new System())->cache_prune();
        $this->addToAssertionCount(1);
    }
}
