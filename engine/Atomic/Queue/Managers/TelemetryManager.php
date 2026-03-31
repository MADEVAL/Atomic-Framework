<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Managers;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Queue\Drivers\DB;
use Engine\Atomic\Queue\Drivers\Redis;
use Engine\Atomic\Telemetry\Queue\Entry;

class TelemetryManager {
    public Redis|DB $driver;
    private array $drivers = [];

    public function __construct(){
        $atomic = App::instance();
        $driver_name = $atomic->get('QUEUE_DRIVER');
        $this->driver = match ($driver_name) {
            'redis'    => new Redis(),
            'database' => new DB(),
            default    => throw new \Exception("Unknown queue driver: " . $driver_name)
        };
        $this->drivers[$driver_name] = $this->driver;
    }

    public function push_telemetry(string $message = ''): bool {
        $atomic = App::instance();
        $current_uuid = $atomic->get('ATOMIC_QUEUE_CURRENT_UUID');
        $current_batch_uuid = $atomic->get('ATOMIC_QUEUE_CURRENT_BATCH_UUID');
        $current_queue_name = $atomic->get('ATOMIC_QUEUE_CURRENT_NAME');
        $current_event_type = $atomic->get('ATOMIC_QUEUE_CURRENT_EVENT_TYPE') ?? null;

        $driver_name = $atomic->get('QUEUE_DRIVER');
        $ttl = $atomic->get("QUEUE.{$driver_name}.queues.{$current_queue_name}.ttl", 0);

        $entry = (new Entry(
            $current_event_type,
            $current_queue_name,
            $current_batch_uuid,
            $current_uuid,
            $message,
            $ttl
        ))->get_struct();

        return $this->driver->push_telemetry($entry);
    }

    private function call_all_drivers(string $method, array $args = []): array {
        $results = [];
        foreach ($this->drivers as $driver_name => $driver) {
            try {
                if (method_exists($driver, $method)) {
                    $results = array_merge($results, call_user_func_array([$driver, $method], $args));
                } else {
                    Log::warning("Method $method does not exist in queue driver $driver_name");
                }
            } catch (\Exception $e) {
                Log::error("Error calling method $method in queue driver $driver_name: " . $e->getMessage());
            }
        }
        return $results;
    }

    public function fetch_completed_jobs(string $queue = '*'): array {
        return $this->call_all_drivers('fetch_completed_jobs', [$queue]);
    }

    public function fetch_failed_jobs(string $queue = '*'): array {
        return $this->call_all_drivers('fetch_failed_jobs', [$queue]);
    }

    public function fetch_in_progress_jobs(string $queue = '*'): array {
        return $this->call_all_drivers('fetch_in_progress_jobs', [$queue]);
    }

    public function fetch_all_jobs(string $queue = '*', array $filters = []): array {
        $in_progress = $this->fetch_in_progress_jobs($queue);
        $failed = $this->fetch_failed_jobs($queue);
        $completed = $this->fetch_completed_jobs($queue);
        $all_jobs = array_merge($in_progress, $failed, $completed);
        
        return $all_jobs;
    }

    public function fetch_events(string $driver, string $queue, string $uuid): array {
        return $this->drivers[$driver]->fetch_events($queue, $uuid);
    }
}