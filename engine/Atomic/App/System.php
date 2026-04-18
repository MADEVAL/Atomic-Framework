<?php
declare(strict_types=1);
namespace Engine\Atomic\App;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\App\Controller;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\Core\App;
use Engine\Atomic\CLI\CLI;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Queue\Managers\Manager;
use Engine\Atomic\Queue\Tests\Test;

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

    public function help(): void
    {
        $this->cli()->help();
    }

    public function cache_clear(): void
    {
        $out = new Output();
        $out->writeln('Clearing cache...');
        $atomic = App::instance();
        $atomic->reset();
        $out->writeln('Cache cleared');
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
        $queue_manager = new Manager();
        $cli = $this->cli();
        $args = $cli->get_cli_args();

        $queue_manager->push(
            [Test::class, $args[0]],
            [
                'smth' => 'example',
                'params' => ['id' => 123, 'type' => 'test'],
            ]
        );
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

    public function ws_test(): void
    {
        (new \Engine\Atomic\WebSockets\Test())->run();
    }

    public function redis_clear(): void
    {
        $out = new Output();
        $redis = ConnectionManager::instance()->get_redis();
        $it    = null;
        $total = 0;
        $redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
        while (($keys = $redis->scan($it, 'atomic.*', 500)) !== false) {
            if (!empty($keys)) {
                $redis->del($keys);
                $total += count($keys);
            }
        }
        $out->writeln("Cleared {$total} keys (pattern: atomic.*)");
    }
}
