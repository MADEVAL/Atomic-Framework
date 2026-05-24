<?php
declare(strict_types=1);
namespace Engine\Atomic\App;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\App\Controller;
use Engine\Atomic\Cache\Drivers\Memcached as MemcachedCache;
use Engine\Atomic\Cache\Drivers\Redis as RedisCache;
use Engine\Atomic\Cache\Interfaces\PrunableCacheStoreInterface;
use Engine\Atomic\Cache\Interfaces\PurgeableCacheStoreInterface;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\Core\CacheManager;
use Engine\Atomic\CLI\CLI;

class System extends Controller
{
    private ?CLI $cli = null;

    protected function cli(): CLI
    {
        return $this->cli ??= new CLI();
    }

    protected function output(): Output
    {
        return new Output();
    }

    public function app_init(): void
    {
        $this->cli()->init();
    }

    public function app_init_key(): void
    {
        $this->cli()->init_key();
    }

    public function app_init_guide(): void
    {
        $this->cli()->init_guide();
    }

    public function logs_rotate(): void
    {
        $this->cli()->logs_rotate();
    }

    public function plugin_make(): void
    {
        $this->cli()->plugin_make();
    }

    public function plugin_deps_install(): void
    {
        $this->cli()->plugin_deps_install();
    }

    public function access_user_create(): void
    {
        $this->cli()->access_user_create();
    }

    public function access_user_reset_secret(): void
    {
        $this->cli()->access_user_reset_secret();
    }

    public function access_user_list(): void
    {
        $this->cli()->access_user_list();
    }

    public function help(): void
    {
        $this->cli()->help();
    }

    public function cache_clear(): void
    {
        $out = $this->output();
        $store = CacheManager::instance()->store();

        $out->section('Cache clear');
        $this->write_cache_header($out, 'cache/clear', $store);
        $out->field('Action', 'Physical cache clear');
        $out->field('Scope', 'All generations in the configured cache namespace');

        if (!$store instanceof PurgeableCacheStoreInterface) {
            $out->failure($this->unsupported_purge_message($store));
            $this->exit_cli(1);
            return;
        }

        $deleted = $store->purge();

        $out->field('Deleted', $deleted . ' cache ' . ($deleted === 1 ? 'entry' : 'entries'));
        $out->field('Generation', 'Unchanged');
        $out->success('Cache cleared.');
    }

    public function cache_invalidate(): void
    {
        $out = $this->output();
        $store = CacheManager::instance()->store();

        $out->section('Cache invalidate');
        $this->write_cache_header($out, 'cache/invalidate', $store);
        $out->field('Action', 'Generation invalidation');
        $out->field('Scope', 'Configured cache namespace');
        $out->field('Deleted', 'No files or keys were deleted');

        $result = $store->reset();
        if ($result) {
            $out->success('Cache invalidated.');
        } else {
            $out->failure('Cache could not be invalidated.');
        }

        if (!$result) {
            $this->exit_cli(1);
        }
    }

    public function cache_prune(): void
    {
        $out = $this->output();
        $store = CacheManager::instance()->store();

        $out->section('Cache prune');
        $this->write_cache_header($out, 'cache/prune', $store);
        $out->field('Action', 'Expired/corrupt cleanup');
        $out->field('Scope', 'Expired and corrupt entries only');
        $out->field('Fresh entries', 'Kept');

        if (!$store instanceof PrunableCacheStoreInterface) {
            $out->failure($this->unsupported_prune_message($store));
            $this->exit_cli(1);
            return;
        }

        $result = $store->prune();
        if ($result) {
            $out->success('Cache pruned.');
        } else {
            $out->failure('Cache could not be pruned.');
        }

        if (!$result) {
            $this->exit_cli(1);
        }
    }

    public function version(): void
    {
        $this->cli()->version();
    }

    public function routes(): void
    {
        $this->cli()->list_routes();
    }

    public function classes(): void
    {
        $this->cli()->classes();
    }

    public function custom_hive(): void
    {
        $this->cli()->custom_hive();
    }

    public function db_tables(): void
    {
        $this->cli()->get_tables();
    }

    public function db_truncate(): void
    {
        $this->cli()->truncate_table();
    }

    public function db_truncate_queue(): void
    {
        $this->cli()->truncate_queue_table();
    }

    public function db_sessions(): void
    {
        $this->cli()->db_sessions();
    }

    public function db_users(): void
    {
        $this->cli()->db_users();
    }

    public function db_storage(): void
    {
        $this->cli()->db_storage();
    }

    public function migrations_create(): void {
        $this->cli()->migrations_create();
    }

    public function migrations_init(): void {
        $this->cli()->migrations_init();
    }

    public function migrations_migrate(): void {
        $this->cli()->migrations_migrate();
    }

    public function migrations_rollback(): void {
        $this->cli()->migrations_rollback();
    }
    
    public function migrations_status(): void {
        $this->cli()->migrations_status();
    }

    public function migrations_publish(): void {
        $this->cli()->migrations_publish();
    }

    public function seed_users(): void
    {
        $this->cli()->seed_users();
    }

    public function seed_roles(): void
    {
        $this->cli()->seed_roles();
    }

    public function seed_pages(): void
    {
        $this->cli()->seed_pages();
    }
    
    public function db_queue(): void
    {
        $this->cli()->db_queue();
    }

    public function queue_worker(): void
    {
        $this->cli()->queue_worker();
    }

    public function queue_test(): void
    {
        $this->cli()->queue_test();
    }

    public function queue_test_monitor(): void
    {
        $this->cli()->queue_test_monitor();
    }

    public function queue_monitor(): void
    {
        $this->cli()->queue_monitor();
    }

    public function queue_retry(): void
    {
        $this->cli()->queue_retry();
    }

    public function queue_cancel(): void
    {
        $this->cli()->queue_cancel();
    }

    public function queue_delete_job(): void
    {
        $this->cli()->queue_delete_job();
    }

    public function file_csv2_pdf(): void
    {
        $this->cli()->file_csv2pdf();
    }

    public function file_xls2_pdf(): void
    {
        $this->cli()->file_xls2pdf();
    }

    public function db_pages(): void
    {
        $this->cli()->db_pages();
    }

    public function db_mutex(): void 
    {
        $this->cli()->db_mutex();
    }

    public function schedule_run(): void
    {
        $this->cli()->schedule_run();
    }

    public function schedule_work(): void
    {
        $this->cli()->schedule_work();
    }

    public function schedule_list(): void
    {
        $this->cli()->schedule_list();
    }

    public function schedule_test(): void
    {
        $this->cli()->schedule_test();
    }

    public function schedule_help(): void
    {
        $this->cli()->schedule_help();
    }

    public function redis_clear(): void
    {
        $out = $this->output();
        $store = CacheManager::instance()->redis();

        $out->section('Redis clear');
        $this->write_cache_header($out, 'redis/clear', $store);
        $out->warning('redis/clear is a compatibility alias; prefer cache/clear.');
        $out->field('Action', 'Physical Redis cache clear');
        $out->field('Scope', 'Only keys in the Atomic Redis cache namespace');
        $out->field('Redis safety', 'Uses SCAN; does not use KEYS, FLUSHDB, or FLUSHALL');

        $deleted = $store->purge();
        $out->field('Deleted', $deleted . ' cache ' . ($deleted === 1 ? 'key' : 'keys'));
        $out->success('Redis cache cleared.');
    }

    private function write_cache_header(Output $out, string $operation, object $store): void
    {
        $out->field('Command', $operation);
        $out->field('Cache driver', $store::class);
    }

    private function unsupported_purge_message(object $store): string
    {
        if ($store instanceof MemcachedCache) {
            return 'Physical clear is not supported by Memcached because Memcached cannot safely enumerate namespaced keys. Use cache/invalidate.';
        }

        return 'Physical clear is not supported by ' . $store::class . '. Use cache/invalidate.';
    }

    private function unsupported_prune_message(object $store): string
    {
        if ($store instanceof MemcachedCache) {
            return 'Prune is not supported by Memcached because Memcached does not safely expose namespaced expired/corrupt key enumeration. Use cache/invalidate.';
        }

        if ($store instanceof RedisCache) {
            return 'Prune is not supported by Redis cache because Redis TTL cleanup is handled by Redis itself. Use cache/clear for full physical cleanup or cache/invalidate for generation invalidation.';
        }

        return 'Prune is not supported by ' . $store::class . '.';
    }

    private function exit_cli(int $code): void
    {
        if (PHP_SAPI === 'cli') {
            exit($code);
        }
    }
}
