<?php
declare(strict_types=1);

namespace Tests\Engine\App;

use Engine\Atomic\App\System;
use Engine\Atomic\Core\CacheManager;
use PHPUnit\Framework\TestCase;
use Tests\Support\CapturingSystem;
use Tests\Support\StreamCapture;
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

        [$system, $stream] = CapturingSystem::make();
        $system->cache_invalidate();
        fclose($stream);
        $cache->flush_local_cache();
        $this->assertFalse($cache->get('cli-clear-key'));
    }

    public function test_cache_clear_physically_purges_configured_cache_store(): void
    {
        $cache = CacheManager::instance()->store();
        $this->assertTrue($cache->set('cli-clear-key', 'value', 3600));

        [$system, $stream] = CapturingSystem::make();
        $system->cache_clear();
        fclose($stream);
        $cache->flush_local_cache();
        $this->assertFalse($cache->get('cli-clear-key'));
    }

    public function test_cache_clear_output_reports_driver_and_user_entry_count_without_metadata(): void
    {
        $cache = CacheManager::instance()->store();
        $this->assertTrue($cache->set('cli-clear-one', 'one', 3600));
        $this->assertTrue($cache->set('cli-clear-two', 'two', 3600));

        $meta_file = $this->folder_meta_file();
        $this->assertFileExists($meta_file);

        [$system, $stream] = CapturingSystem::make();
        $system->cache_clear();

        $output = StreamCapture::read($stream, true);
        $this->assertStringContainsString('Cache clear', $output);
        $this->assertStringContainsString('Command: cache/clear', $output);
        $this->assertStringContainsString('Cache driver: Engine\Atomic\Cache\Drivers\Folder', $output);
        $this->assertStringContainsString('Deleted: 2 cache entries', $output);
        $this->assertStringContainsString('[OK] Cache cleared.', $output);
        $this->assertFileExists($meta_file);
    }

    public function test_cache_invalidate_output_reports_driver_and_no_physical_deletion(): void
    {
        $cache = CacheManager::instance()->store();
        $this->assertTrue($cache->set('cli-invalidate-key', 'value', 3600));

        [$system, $stream] = CapturingSystem::make();
        $system->cache_invalidate();

        $output = StreamCapture::read($stream, true);
        $this->assertStringContainsString('Cache invalidate', $output);
        $this->assertStringContainsString('Command: cache/invalidate', $output);
        $this->assertStringContainsString('Cache driver: Engine\Atomic\Cache\Drivers\Folder', $output);
        $this->assertStringContainsString('Deleted: No files or keys were deleted', $output);
        $this->assertStringContainsString('[OK] Cache invalidated.', $output);
    }

    public function test_repeated_cache_clear_does_not_create_entries_to_delete(): void
    {
        $cache = CacheManager::instance()->store();
        $this->assertSame(0, $cache->purge());
        $this->assertTrue($cache->set('cli-clear-key', 'value', 3600));

        [$system, $stream] = CapturingSystem::make();
        $system->cache_clear();
        fclose($stream);
        $this->assertSame(0, $cache->purge());

        [$system, $stream] = CapturingSystem::make();
        $system->cache_clear();
        fclose($stream);
        $this->assertSame(0, $cache->purge());
    }

    public function test_cache_prune_reports_expired_corrupt_cleanup_scope(): void
    {
        [$system, $stream] = CapturingSystem::make();
        $system->cache_prune();

        $output = StreamCapture::read($stream, true);
        $this->assertStringContainsString('Cache prune', $output);
        $this->assertStringContainsString('Command: cache/prune', $output);
        $this->assertStringContainsString('Cache driver: Engine\Atomic\Cache\Drivers\Folder', $output);
        $this->assertStringContainsString('Scope: Expired and corrupt entries only', $output);
        $this->assertStringContainsString('[OK] Cache pruned.', $output);
    }

    private function folder_meta_file(): string
    {
        return $this->cache_dir . DIRECTORY_SEPARATOR . hash('sha256', 'atomic_system_cache_clear') . DIRECTORY_SEPARATOR . 'namespace.meta';
    }
}
