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

    public function cache_clear(?Output $out = null): void
    {
        $out ??= new Output();
        $store = CacheManager::instance()->store();

        $this->write_cache_header($out, 'cache/clear', $store);
        $out->writeln('Operation: physical clear.');
        $out->writeln('Effect: attempts to delete cache files or keys for the selected Atomic cache namespace across generations.');

        if (!$store instanceof PurgeableCacheStoreInterface) {
            $out->err('Result: failed.');
            $out->err($this->unsupported_purge_message($store));
            $this->exit_cli(1);
            return;
        }

        $deleted = $store->purge();

        $out->writeln('Deletion: physical files/keys were deleted; this is not generation invalidation.');
        $out->writeln('Deleted: ' . $deleted . ' files/keys.');
        $out->writeln('Result: success.');
    }

    public function cache_invalidate(?Output $out = null): void
    {
        $out ??= new Output();
        $store = CacheManager::instance()->store();

        $this->write_cache_header($out, 'cache/invalidate', $store);
        $out->writeln('Operation: generation invalidation.');
        $out->writeln('Effect: advances the namespace generation so old entries are unreachable immediately.');
        $out->writeln('Deletion: physical files/keys are not deleted and may remain until expiry, prune, or purge.');

        $result = $store->reset();
        $out->writeln('Result: ' . ($result ? 'success.' : 'failed.'));

        if (!$result) {
            $this->exit_cli(1);
        }
    }

    public function cache_prune(?Output $out = null): void
    {
        $out ??= new Output();
        $store = CacheManager::instance()->store();

        $this->write_cache_header($out, 'cache/prune', $store);
        $out->writeln('Operation: prune expired/corrupt entries.');
        $out->writeln('Effect: removes stale expired or corrupt cache entries only; valid cache entries are not cleared.');
        $out->writeln('Deletion: physical files/rows may be deleted only when they are stale or corrupt.');

        if (!$store instanceof PrunableCacheStoreInterface) {
            $out->err('Result: failed.');
            $out->err($this->unsupported_prune_message($store));
            $this->exit_cli(1);
            return;
        }

        $result = $store->prune();
        $out->writeln('Result: ' . ($result ? 'success.' : 'failed.'));

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

    public function redis_clear(?Output $out = null): void
    {
        $out ??= new Output();
        $store = CacheManager::instance()->redis();

        $this->write_cache_header($out, 'redis/clear', $store);
        $out->writeln('Compatibility: redis/clear is a compatibility alias for Redis physical purge; prefer cache/clear.');
        $out->writeln('Operation: physical clear.');
        $out->writeln('Effect: uses cursor-based SCAN with strict Atomic cache namespace matching; KEYS, FLUSHDB, and FLUSHALL are not used.');

        $deleted = $store->purge();
        $out->writeln('Deletion: physical Redis keys were deleted; this is not generation invalidation.');
        $out->writeln('Deleted: ' . $deleted . ' keys.');
        $out->writeln('Result: success.');
    }

    private function write_cache_header(Output $out, string $operation, object $store): void
    {
        $out->writeln('Operation name: ' . $operation);
        $out->writeln('Selected cache driver/class: ' . $store::class);
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
