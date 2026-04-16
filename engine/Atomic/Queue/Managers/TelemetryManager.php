<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Managers;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ID;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Enums\LogChannel;
use Engine\Atomic\Queue\Enums\Status;
use Engine\Atomic\Queue\Enums\Driver;
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
            Driver::REDIS->value => new Redis(),
            Driver::DATABASE->value => new DB(),
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

    public function fetch_pending_jobs(string $queue = '*', int $page = 1, int $per_page = 50): array {
        return $this->call_all_drivers('fetch_pending_jobs', [$queue, $page, $per_page]);
    }

    public function fetch_all_jobs(string $queue = '*', array $filters = [], int $page = 1, int $per_page = 50): array {
        $uuid_filter = isset($filters['uuid']) ? trim((string)$filters['uuid']) : '';
        if ($uuid_filter !== '') {
            if (!ID::is_valid_uuid_v4($uuid_filter)) {
                return [
                    'items' => [],
                    'total' => 0,
                    'page' => $page,
                    'per_page' => $per_page,
                    'status_totals' => Status::totals_template(),
                ];
            }
            $raw = $this->driver->search_jobs_by_uuid($queue, strtolower($uuid_filter));
            $items = $raw['items'];
            if (isset($filters['status']) && $filters['status'] !== '') {
                foreach ($items as $uid => $job) {
                    if (!\is_array($job)) {
                        unset($items[$uid]);
                        continue;
                    }
                    $job_status = (string)($job['status'] ?? '');
                    $matches = $filters['status'] === Status::PENDING->value
                        ? \in_array($job_status, Status::pending_like(), true)
                        : ($job_status === $filters['status']);
                    if (!$matches) {
                        unset($items[$uid]);
                    }
                }
            }
            $total = \count($items);
            $offset = ($page - 1) * $per_page;
            $keys = \array_keys($items);
            $page_keys = \array_slice($keys, $offset, $per_page);
            $paged = [];
            foreach ($page_keys as $k) {
                $paged[$k] = $items[$k];
            }
            $status_totals = Status::totals_template($total);
            foreach ($items as $job) {
                if (!\is_array($job)) {
                    continue;
                }
                $status = Status::aggregate((string)($job['status'] ?? $job['state'] ?? ''));
                if (isset($status_totals[$status])) {
                    $status_totals[$status]++;
                }
            }
            $status_totals[Status::PENDING->value] = $status_totals[Status::QUEUED->value];
            return [
                'items' => $paged,
                'total' => $total,
                'page' => $page,
                'per_page' => $per_page,
                'status_totals' => $status_totals,
            ];
        }

        $status_filter = isset($filters['status']) ? (string)$filters['status'] : '';
        $pending = $this->fetch_pending_jobs($queue, $page, $per_page);
        $in_progress = $this->fetch_in_progress_jobs($queue, $page, $per_page);
        $failed = $this->fetch_failed_jobs($queue, $page, $per_page);
        $completed = $this->fetch_completed_jobs($queue, $page, $per_page);

        if ($status_filter === Status::PENDING->value) {
            $all_items = $pending['items'];
            $total = (int)($pending['total'] ?? 0);
        } elseif ($status_filter === Status::FAILED->value) {
            $all_items = $failed['items'];
            $total = (int)($failed['total'] ?? 0);
        } elseif ($status_filter === Status::COMPLETED->value) {
            $all_items = $completed['items'];
            $total = (int)($completed['total'] ?? 0);
        } else {
            $all_items = array_merge(
                $in_progress['items'],
                $failed['items'],
                $completed['items']
            );
            $total = (int)($in_progress['total'] ?? 0) + (int)($failed['total'] ?? 0) + (int)($completed['total'] ?? 0);
        }

        $pending_total = (int)($pending['total'] ?? 0);
        $in_progress_total = (int)($in_progress['total'] ?? 0);
        $running_total = max(0, $in_progress_total - $pending_total);

        $status_totals = [
            Status::FAILED->value => (int)($failed['total'] ?? 0),
            Status::QUEUED->value => $pending_total,
            Status::PENDING->value => $pending_total,
            Status::RUNNING->value => $running_total,
            Status::COMPLETED->value => (int)($completed['total'] ?? 0),
            'total' => $in_progress_total + (int)($failed['total'] ?? 0) + (int)($completed['total'] ?? 0),
        ];

        return [
            'items' => $all_items,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'status_totals' => $status_totals,
        ];
    }

    public function fetch_events(string $driver, string $queue, string $uuid): array {
        return $this->drivers[$driver]->fetch_events($queue, $uuid);
    }
}
