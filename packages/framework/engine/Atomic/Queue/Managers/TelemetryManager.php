<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Managers;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ID;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Enums\LogChannel;
use Engine\Atomic\Queue\Enums\State;
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
            Driver::DB->value    => new DB(),
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
        $ttl = (int)($atomic->get("QUEUE.{$driver_name}.queues.{$current_queue_name}.ttl", 0) ?? 0);

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

    public function fetch_active_jobs(string $queue = '*', int $page = 1, int $per_page = 50): array {
        return $this->call_all_drivers('fetch_active_jobs', [$queue, $page, $per_page]);
    }

    public function fetch_running_jobs(string $queue = '*', int $page = 1, int $per_page = 50): array {
        return $this->call_all_drivers('fetch_running_jobs', [$queue, $page, $per_page]);
    }

    public function fetch_pending_jobs(string $queue = '*', int $page = 1, int $per_page = 50): array {
        return $this->call_all_drivers('fetch_pending_jobs', [$queue, $page, $per_page]);
    }

    public function fetch_cancelled_jobs(string $queue = '*', int $page = 1, int $per_page = 50): array {
        return $this->call_all_drivers('fetch_cancelled_jobs', [$queue, $page, $per_page]);
    }

    public function fetch_cancel_requested_jobs(string $queue = '*', int $page = 1, int $per_page = 50): array {
        return $this->call_all_drivers('fetch_cancel_requested_jobs', [$queue, $page, $per_page]);
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
                    'state_totals' => State::state_totals_template(),
                ];
            }
            $raw = $this->driver->search_jobs_by_uuid($queue, strtolower($uuid_filter));
            $items = $raw['items'];
            if (isset($filters['state']) && $filters['state'] !== '') {
                foreach ($items as $uid => $job) {
                    if (!\is_array($job)) {
                        unset($items[$uid]);
                        continue;
                    }
                    $job_state = (string)($job['state'] ?? '');
                    $matches = $job_state === $filters['state'];
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
            $state_totals = State::state_totals_template($total);
            foreach ($items as $job) {
                if (!\is_array($job)) {
                    continue;
                }
                $state = State::aggregate((string)($job['state'] ?? ''));
                if (isset($state_totals[$state])) {
                    $state_totals[$state]++;
                }
            }
            return [
                'items' => $paged,
                'total' => $total,
                'page' => $page,
                'per_page' => $per_page,
                'state_totals' => $state_totals,
            ];
        }

        $state_filter = isset($filters['state']) ? (string)$filters['state'] : '';
        $pending = $this->fetch_pending_jobs($queue, $page, $per_page);
        $running = $this->fetch_running_jobs($queue, $page, $per_page);
        $cancel_requested = $this->fetch_cancel_requested_jobs($queue, $page, $per_page);
        $failed = $this->fetch_failed_jobs($queue, $page, $per_page);
        $completed = $this->fetch_completed_jobs($queue, $page, $per_page);
        $cancelled = $this->fetch_cancelled_jobs($queue, $page, $per_page);

        $jobs_by_state = [
            State::PENDING->value => $pending,
            State::RUNNING->value => $running,
            State::CANCEL_REQUESTED->value => $cancel_requested,
            State::CANCELLED->value => $cancelled,
            State::FAILED->value => $failed,
            State::COMPLETED->value => $completed,
        ];

        if (isset($jobs_by_state[$state_filter])) {
            $all_items = $jobs_by_state[$state_filter]['items'];
            $total = (int)($jobs_by_state[$state_filter]['total'] ?? 0);
        } else {
            $needed = \max(1, $page * $per_page);
            $jobs_by_state = [
                State::PENDING->value => $this->fetch_pending_jobs($queue, 1, $needed),
                State::RUNNING->value => $this->fetch_running_jobs($queue, 1, $needed),
                State::CANCEL_REQUESTED->value => $this->fetch_cancel_requested_jobs($queue, 1, $needed),
                State::CANCELLED->value => $this->fetch_cancelled_jobs($queue, 1, $needed),
                State::FAILED->value => $this->fetch_failed_jobs($queue, 1, $needed),
                State::COMPLETED->value => $this->fetch_completed_jobs($queue, 1, $needed),
            ];
            $total = \array_sum(
                \array_map(static fn(array $jobs): int => (int)($jobs['total'] ?? 0), $jobs_by_state)
            );
            $all_items = \array_merge(...\array_column($jobs_by_state, 'items'));
            \uasort($all_items, static function (array $a, array $b): int {
                return (int)($b['created_at'] ?? 0) <=> (int)($a['created_at'] ?? 0);
            });
            $offset = ($page - 1) * $per_page;
            $all_items = \array_slice($all_items, $offset, $per_page, true);
        }

        $pending_total = (int)($pending['total'] ?? 0);
        $cancel_requested_total = (int)($cancel_requested['total'] ?? 0);
        $running_total = (int)($running['total'] ?? 0);

        $state_totals = [
            State::FAILED->value => (int)($failed['total'] ?? 0),
            State::PENDING->value => $pending_total,
            State::RUNNING->value => $running_total,
            State::COMPLETED->value => (int)($completed['total'] ?? 0),
            State::CANCEL_REQUESTED->value => $cancel_requested_total,
            State::CANCELLED->value => (int)($cancelled['total'] ?? 0),
            'total' => $total,
        ];

        return [
            'items' => $all_items,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'state_totals' => $state_totals,
        ];
    }

    public function fetch_events(string $driver, string $queue, string $uuid): array {
        if (!isset($this->drivers[$driver])) {
            return [];
        }

        return $this->drivers[$driver]->fetch_events($queue, $uuid);
    }

}
