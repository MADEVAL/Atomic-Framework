<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI;

if (!defined( 'ATOMIC_START' ) ) exit;

use DB\Cortex;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Migrations;
use Engine\Atomic\Core\ID;
use Engine\Atomic\Queue\Managers\Manager;
use Engine\Atomic\Queue\Tests\Test;
use Engine\Atomic\Queue\Monitor\Monitor;
use Engine\Atomic\Queue\Worker\Worker;

trait Queue {
    private static int $MONITOR_TEST_PID_PLACEHOLDER = -1;
    private static int $MONITOR_TEST_DELAY = 0;
    private static int $MONITOR_TEST_PRIORITY = 1;
    private static int $MONITOR_TEST_TIMEOUT = 30;
    private static int $MONITOR_TEST_TTL = 3600;

    private function queue_dependency_hint(): string
    {
        $driver = (string) App::instance()->get('QUEUE_DRIVER');

        if ($driver === 'redis') {
            if (!\extension_loaded('redis')) {
                return "Queue driver 'redis' is unavailable: ext-redis is not loaded. Install/enable php-redis and ensure REDIS_HOST/REDIS_PORT are reachable.";
            }
            return "Queue driver 'redis' is unavailable: Redis service is not reachable or queue Redis config is incomplete. Check REDIS_HOST/REDIS_PORT and QUEUE.redis settings.";
        }

        if ($driver === 'db') {
            if (!\extension_loaded('pdo_mysql')) {
                return "Queue driver 'db' is unavailable: ext-pdo_mysql is not loaded. Install/enable pdo_mysql and verify DB_* settings.";
            }
            return "Queue driver 'db' is unavailable: database connection failed or queue tables are missing. Check DB_* and run 'php atomic queue/db'.";
        }

        return "Queue system is unavailable: unsupported QUEUE_DRIVER '{$driver}'.";
    }

    private function create_queue_manager_or_null(?string $queue_name = null): ?Manager
    {
        try {
            return new Manager($queue_name);
        } catch (\Throwable $th) {
            $this->output->err($this->queue_dependency_hint());
            $this->output->err('Details: ' . $th->getMessage());
            return null;
        }
    }

    public function db_queue() {
        $atomic = App::instance();
        (new Migrations($this->output))->publish($atomic->get('MIGRATIONS_CORE') . 'atomic_create_queue_tables');
    }
    
    public function queue_worker() {
        $args = $this->get_cli_args();
        if (!isset($args[0]) || empty($args[0])) {
            $this->output->err('Usage: php atomic queue/worker <queue_name>');
            return;
        }
        $queue_name = $args[0];
        $queue_manager = $this->create_queue_manager_or_null($queue_name);
        if ($queue_manager === null) {
            return;
        }
        $worker = new Worker($queue_manager);
        $worker->run();
    }

    public function queue_monitor() {
        try {
            $queue_monitor = new Monitor();
            $queue_monitor->run();
        } catch (\Throwable $th) {
            $this->output->err($this->queue_dependency_hint());
            $this->output->err('Details: ' . $th->getMessage());
        }
    }

    public function queue_test_monitor(): void
    {
        $atomic = App::instance();
        $args = $this->get_cli_args();
        $queue_name = $args[0] ?? (string)$atomic->get('QUEUE_NAME');

        $driver = (string) $atomic->get('QUEUE_DRIVER');
        if ($driver === 'db') {
            $this->seed_monitor_test_cases_db($queue_name);
            return;
        }

        if ($driver === 'redis') {
            $this->seed_monitor_test_cases_redis($queue_name);
            return;
        }

        $this->output->err("queue/test/monitor is not supported for queue driver '{$driver}'");
    }

    private function seed_monitor_test_cases_db(string $queue_name): void
    {
        $atomic = App::instance();

        $queue_manager = $this->create_queue_manager_or_null($queue_name);
        if ($queue_manager === null) {
            return;
        }
        $connection_manager = ConnectionManager::instance();
        $sql = $connection_manager->get_db();
        $jobs_mapper = new Cortex($sql, $atomic->get('DB_CONFIG.ATOMIC_DB_QUEUE_PREFIX') . 'jobs');

        $now = \time();
        $monitor_cases = $this->build_monitor_test_cases($now);

        $created = 0;
        $failed = 0;

        foreach ($monitor_cases as $case_name => $case) {
            $result = $this->enqueue_monitor_test_case($queue_manager, $case_name, $case);
            $uuid = $result['uuid'];

            if (!$result['queued']) {
                $failed++;
                $this->output->err("Failed to queue monitor test job for case '{$case_name}'");
                continue;
            }

            $jobs_mapper->load(['uuid = ?', $uuid]);
            if ($jobs_mapper->dry()) {
                $failed++;
                $this->output->err("Queued UUID '{$uuid}' but could not reload row for case '{$case_name}'");
                continue;
            }

            $jobs_mapper->available_at = (int) $case['available_at'];
            $jobs_mapper->pid = (int) $case['pid'];
            $jobs_mapper->attempts = (int) $case['attempts'];
            $jobs_mapper->process_start_ticks = null;
            $jobs_mapper->save();

            $created++;
            $this->output->writeln("Queued monitor test case '{$case_name}' with UUID '{$uuid}'");
        }

        $connection_manager->close_sql();

        $this->output->writeln("queue/test/monitor completed for queue '{$queue_name}'. Created: {$created}, Failed: {$failed}");
        $this->output->writeln('Run: php atomic queue/monitor');
    }

    private function seed_monitor_test_cases_redis(string $queue_name): void
    {
        $atomic = App::instance();
        $queue_manager = $this->create_queue_manager_or_null($queue_name);
        if ($queue_manager === null) {
            return;
        }
        $connection_manager = ConnectionManager::instance();
        $redis = $connection_manager->get_redis();
        $prefix = (string) $atomic->get('REDIS.ATOMIC_REDIS_QUEUE_PREFIX');

        $pending_key = $prefix . $queue_name . '.idx.pending';
        $running_key = $prefix . $queue_name . '.idx.running';
        $pid_map_key = $prefix . 'meta.pid_map';

        $now = \time();
        $monitor_cases = $this->build_monitor_test_cases($now);

        $created = 0;
        $failed = 0;

        foreach ($monitor_cases as $case_name => $case) {
            $result = $this->enqueue_monitor_test_case($queue_manager, $case_name, $case);
            $uuid = $result['uuid'];

            if (!$result['queued']) {
                $failed++;
                $this->output->err("Failed to queue monitor test job for case '{$case_name}'");
                continue;
            }

            $registry_key = $prefix . 'registry.' . $uuid;
            if (!$redis->exists($registry_key)) {
                $failed++;
                $this->output->err("Queued UUID '{$uuid}' but missing registry entry for case '{$case_name}'");
                continue;
            }

            $available_at = (int) $case['available_at'];
            $pid = (int) $case['pid'];

            $redis->hMSet($registry_key, [
                'state' => 'running',
                'pid' => (string) $pid,
                'process_start_ticks' => '',
                'available_at' => (string) $available_at,
                'attempts' => (string) ((int) $case['attempts']),
                'max_attempts' => (string) ((int) $case['max_attempts']),
                'retry_delay' => (string) ((int) $case['retry_delay']),
                'updated_at' => (string) $now,
            ]);

            $redis->zRem($pending_key, $uuid);
            $redis->zAdd($running_key, $available_at * 1000, $uuid);

            if (!empty($case['map_pid'])) {
                $redis->hSet($pid_map_key, (string) $pid, $uuid);
            }

            $created++;
            $this->output->writeln("Queued monitor test case '{$case_name}' with UUID '{$uuid}'");
        }

        $connection_manager->close_redis();

        $this->output->writeln("queue/test/monitor completed for queue '{$queue_name}'. Created: {$created}, Failed: {$failed}");
        $this->output->writeln('Run: php atomic queue/monitor');
    }

    private function build_monitor_test_cases(int $now): array
    {
        return [
            'stuck_pid_placeholder' => [
                'available_at' => $now - 30,
                'pid' => self::$MONITOR_TEST_PID_PLACEHOLDER,
                'attempts' => 1,
                'max_attempts' => 3,
                'retry_delay' => 2,
                'map_pid' => false,
            ],
            'stuck_invalid_pid' => [
                'available_at' => $now - 30,
                'pid' => 0,
                'attempts' => 1,
                'max_attempts' => 3,
                'retry_delay' => 2,
                'map_pid' => false,
            ],
            'stuck_inactive_release' => [
                'available_at' => $now - 30,
                'pid' => 999991,
                'attempts' => 1,
                'max_attempts' => 3,
                'retry_delay' => 2,
                'map_pid' => false,
            ],
            'stuck_inactive_fail' => [
                'available_at' => $now - 30,
                'pid' => 999992,
                'attempts' => 3,
                'max_attempts' => 3,
                'retry_delay' => 2,
                'map_pid' => false,
            ],
            'in_progress_pid_placeholder' => [
                'available_at' => $now + 90,
                'pid' => self::$MONITOR_TEST_PID_PLACEHOLDER,
                'attempts' => 1,
                'max_attempts' => 3,
                'retry_delay' => 2,
                'map_pid' => true,
            ],
            'in_progress_inactive' => [
                'available_at' => $now + 90,
                'pid' => 999993,
                'attempts' => 1,
                'max_attempts' => 3,
                'retry_delay' => 2,
                'map_pid' => true,
            ],
        ];
    }

    private function enqueue_monitor_test_case(Manager $queue_manager, string $case_name, array $case): array
    {
        $uuid = ID::uuid_v4();
        $queued = $queue_manager->push(
            [Test::class, 'success'],
            [
                'params' => [
                    'id' => 123,
                    'type' => 'monitor',
                    'monitor_case' => $case_name,
                ],
                'smth' => 'monitor-test',
            ],
            [
                'delay' => self::$MONITOR_TEST_DELAY,
                'priority' => self::$MONITOR_TEST_PRIORITY,
                'timeout' => self::$MONITOR_TEST_TIMEOUT,
                'max_attempts' => (int) $case['max_attempts'],
                'retry_delay' => (int) $case['retry_delay'],
                'ttl' => self::$MONITOR_TEST_TTL,
            ],
            $uuid
        );

        return [
            'uuid' => $uuid,
            'queued' => (bool) $queued,
        ];
    }

    public function queue_retry() {
        $args = $this->get_cli_args();
        $queue_manager = $this->create_queue_manager_or_null();
        if ($queue_manager === null) {
            return;
        }

        if (!isset($args[0]) || empty($args[0])) {
            try {
                $queue_manager->retry();
                $this->output->writeln('Retried failed jobs');
            } catch (\Throwable $th) {
                $this->output->err('Error retrying failed jobs: ' . $th->getMessage());
            }
            return;
        }

        $arg = $args[0];

        if (ID::is_valid_uuid_v4($arg)) {
            try {
                $result = $queue_manager->retry_by_uuid($arg);
                if ($result) {
                    $this->output->writeln("Successfully retried failed job with UUID '{$arg}'");
                } else {
                    $this->output->err("Could not retry failed job with UUID '{$arg}' - it may not exist");
                }
            } catch (\Throwable $th) {
                $this->output->err('Error retrying job by UUID: ' . $th->getMessage());
            }
            return;
        }

        $queue_name = $arg;
        try {
            $queue_manager_by_name = $this->create_queue_manager_or_null($queue_name);
            if ($queue_manager_by_name === null) {
                return;
            }
            $queue_manager_by_name->retry();
            $this->output->writeln("Retried failed jobs for queue '{$queue_name}'");
        } catch (\Throwable $th) {
            $this->output->err("Could not retry queue '{$queue_name}': " . $th->getMessage());
        }
    }

    public function queue_delete_job() 
    {
        $args = $this->get_cli_args();
        if (!isset($args[0]) || empty($args[0])) {
            $this->output->err('Usage: php atomic queue/delete <job_uuid>');
            return;
        }
        $uuid = $args[0];
        $queue_manager = $this->create_queue_manager_or_null();
        if ($queue_manager === null) {
            return;
        }
        try {
            $deleted = $queue_manager->delete_job($uuid);
            if ($deleted) {
                $this->output->writeln("Successfully deleted job with UUID '{$uuid}'");
            } else {
                $this->output->err("Could not delete job with UUID '{$uuid}' - it may not exist or it may be currently running");
            }
        } catch (\Throwable $th) {
            $this->output->err('Error deleting job: ' . $th->getMessage());
        }
    }
}