<?php
declare(strict_types=1);
namespace Engine\Atomic\App;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\App\Controller;
use Engine\Atomic\Core\App;
use Engine\Atomic\CLI\CLI;
use Engine\Atomic\Queue\Managers\Manager;
use Engine\Atomic\Queue\Tests\Test;

class System extends Controller
{
    private ?CLI $cli = null;

    protected function cli(): CLI
    {
        return $this->cli ??= new CLI();
    }

    public function appInit(): void
    {
        $this->cli()->init();
    }

    public function appInitKey(): void
    {
        $this->cli()->initKey();
    }

    public function logsRotate(): void
    {
        $this->cli()->logsRotate();
    }

    public function help(): void
    {
        $this->cli()->help();
    }

    public function cacheClear(): void
    {
        echo "Clearing cache...\n";
        $atomic = App::instance();
        $atomic->reset();
        echo "Cache cleared\n";
    }

    public function version(): void
    {
        $this->cli()->version();
    }

    public function routes(): void
    {
        $this->cli()->listRoutes();
    }

    public function classes(): void
    {
        $this->cli()->classes();
    }

    public function customHive(): void
    {
        $this->cli()->customHive();
    }

    public function dbTables(): void
    {
        $this->cli()->get_tables();
    }

    public function dbTruncate(): void
    {
        $this->cli()->truncate_table();
    }

    public function dbTruncateQueue(): void
    {
        $this->cli()->truncate_queue_table();
    }

    public function dbSessions(): void
    {
        $this->cli()->db_sessions();
    }

    public function dbUsers(): void
    {
        $this->cli()->db_users();
    }

    public function dbStores(): void
    {
        $this->cli()->db_stores();
    }

    public function dbStorage(): void
    {
        $this->cli()->db_storage();
    }

    public function dbOrders(): void
    {
        $this->cli()->db_orders();
    }
    
    public function migrationsCreate(): void {
        $this->cli()->migrations_create();
    }

    public function migrationsMigrate(): void {
        $this->cli()->migrations_migrate();
    }

    public function migrationsRollback(): void {
        $this->cli()->migrations_rollback();
    }
    
    public function migrationsStatus(): void {
        $this->cli()->migrations_status();
    }

    public function migrationsPublish(): void {
        $this->cli()->migrations_publish();
    }

    public function seedUsers(): void
    {
        $this->cli()->seed_users();
    }

    public function seedRoles(): void
    {
        $this->cli()->seed_roles();
    }

    public function seedPages(): void
    {
        $this->cli()->seed_pages();
    }
    
    public function seedProducts(): void
    {
        $this->cli()->seed_products();
    }

    public function seedCategories(): void
    {
        $this->cli()->seed_categories();
    }

    public function seedStores(): void
    {
        $this->cli()->seed_stores();
    }

    public function queueDb(): void
    {
        $this->cli()->queue_db();
    }

    public function queueWorker(): void
    {
        $this->cli()->queue_worker();
    }

    public function queueTest(): void
    {
        $queue_manager = new Manager('default');
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

    public function queueTestMonitor(): void
    {
        $this->cli()->queue_test_monitor();
    }

    public function queueMonitor(): void
    {
        $this->cli()->queue_monitor();
    }

    public function queueRetry(): void
    {
        $this->cli()->queue_retry();
    }

    public function queueDeleteJob(): void
    {
        $this->cli()->queue_delete_job();
    }

    public function fileCsv2Pdf(): void
    {
        $this->cli()->file_csv2pdf();
    }

    public function fileXls2Pdf(): void
    {
        $this->cli()->file_xls2pdf();
    }

    public function dbPages(): void
    {
        $this->cli()->db_pages();
    }

    public function dbRecentActivity(): void 
    {
        $this->cli()->db_recent_activity();
    }

    public function dbCoupons(): void 
    {
        $this->cli()->db_coupons();
    }

    public function dbPayments(): void
    {
        $this->cli()->db_payments();
    }

    public function dbTariffs(): void
    {
        $this->cli()->db_tariffs();
    }

    public function dbTgFrontErr(): void 
    {
        $this->cli()->db_tg_front_err();
    }

    public function dbMutex(): void 
    {
        $this->cli()->db_mutex();
    }

    public function scheduleRun(): void
    {
        $this->cli()->schedule_run();
    }

    public function scheduleWork(): void
    {
        $this->cli()->schedule_work();
    }

    public function scheduleList(): void
    {
        $this->cli()->schedule_list();
    }

    public function scheduleTest(): void
    {
        $this->cli()->schedule_test();
    }

    public function scheduleHelp(): void
    {
        $this->cli()->schedule_help();
    }

    public function wsTest(): void
    {
        (new \Engine\Atomic\WebSockets\Test())->run();
    }

    public function redisClear(): void
    {
        $redis = (new \Engine\Atomic\Core\ConnectionManager())->get_redis();
        $it    = null;
        $total = 0;
        $redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
        while (($keys = $redis->scan($it, 'atomic.*', 500)) !== false) {
            if (!empty($keys)) {
                $redis->del($keys);
                $total += count($keys);
            }
        }
        echo "Cleared {$total} keys (pattern: atomic.*)\n";
    }
}
