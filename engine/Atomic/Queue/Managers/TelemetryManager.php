<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Managers;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Enums\LogChannel;
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
        $items = [];
        $total = 0;
        foreach ($this->drivers as $driver_name => $driver) {
            try {
                if (method_exists($driver, $method)) {
                    $result = call_user_func_array([$driver, $method], $args);
                    $items = array_merge($items, $result['items'] ?? []);
                    $total += $result['total'] ?? 0;
                } else {
                    Log::channel(LogChannel::QUEUE_WORKER)->warning("Method $method does not exist in queue driver $driver_name");
                }
            } catch (\Exception $e) {
                Log::channel(LogChannel::QUEUE_WORKER)->error("Error calling method $method in queue driver $driver_name: " . $e->getMessage());
            }
        }
        return ['items' => $items, 'total' => $total];
    }

    public function fetch_completed_jobs(string $queue = '*', int $page = 1, int $per_page = 50): array {
        return $this->call_all_drivers('fetch_completed_jobs', [$queue, $page, $per_page]);
    }

    public function fetch_failed_jobs(string $queue = '*', int $page = 1, int $per_page = 50): array {
        return $this->call_all_drivers('fetch_failed_jobs', [$queue, $page, $per_page]);
    }

    public function fetch_in_progress_jobs(string $queue = '*', int $page = 1, int $per_page = 50): array {
        return $this->call_all_drivers('fetch_in_progress_jobs', [$queue, $page, $per_page]);
    }

    public function fetch_all_jobs(string $queue = '*', array $filters = [], int $page = 1, int $per_page = 50): array {
        $in_progress = $this->fetch_in_progress_jobs($queue, $page, $per_page);
        $failed = $this->fetch_failed_jobs($queue, $page, $per_page);
        $completed = $this->fetch_completed_jobs($queue, $page, $per_page);

        $all_items = array_merge(
            $in_progress['items'],
            $failed['items'],
            $completed['items']
        );
        $total = $in_progress['total'] + $failed['total'] + $completed['total'];

        return ['items' => $all_items, 'total' => $total, 'page' => $page, 'per_page' => $per_page];
    }

    public function fetch_events(string $driver, string $queue, string $uuid): array {
        return $this->drivers[$driver]->fetch_events($queue, $uuid);
    }
}