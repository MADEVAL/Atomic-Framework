<?php
declare(strict_types=1);

namespace Tests\Engine\App;

use Engine\Atomic\App\System;
use Engine\Atomic\Core\CacheManager;
use PHPUnit\Framework\TestCase;
use Tests\Support\OutputCapture;
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

    public function test_cache_clear_resets_configured_cache_store(): void
    {
        $cache = CacheManager::instance()->store();
        $this->assertTrue($cache->set('cli-clear-key', 'value', 3600));
        $this->assertSame('value', $cache->get('cli-clear-key'));

        $output = OutputCapture::capture(fn() => (new System())->cache_clear());

        $this->assertStringContainsString('Clearing cache...', $output);
        $this->assertStringContainsString('Cache cleared', $output);
        $cache->flush_local_cache();
        $this->assertFalse($cache->get('cli-clear-key'));
    }
}
