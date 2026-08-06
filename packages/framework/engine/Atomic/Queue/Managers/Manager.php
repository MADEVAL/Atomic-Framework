<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Managers;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\ID;
use Engine\Atomic\Enums\LogChannel;
use Engine\Atomic\Queue\Drivers\DB as DBDriver;
use Engine\Atomic\Queue\Drivers\Redis as RedisDriver;
use Engine\Atomic\Queue\Enums\State;
use Engine\Atomic\Telemetry\Queue\EventType;

class Manager
{
    protected DBDriver|RedisDriver $driver;
    public TelemetryManager $telemetry_manager;
    private ProcessManager $process_manager;

    /** @var array<string,true> */
    private static array $validated_handlers = [];

    /** @var array<string,\ReflectionMethod> */
    private static array $reflection_cache = [];

    protected string $queue = 'default';
    protected array $config_current;
    protected array $config_required = [
        'delay',
        'priority',
        'timeout',
        'max_attempts',
        'retry_delay',
        'ttl',
    ];

    public function __construct(
        ?string $queue = null,
    ) {
        $atomic = App::instance();
        $this->queue = $queue ?: (string)$atomic->get('QUEUE_NAME');

        $this->driver = match ($atomic->get('QUEUE_DRIVER')) {
            'redis' => new RedisDriver(),
            'db'    => new DBDriver(),
            default    => throw new \Exception("Unknown queue driver: " . $atomic->get('QUEUE_DRIVER'))
        };
        $this->telemetry_manager = new TelemetryManager();
        $this->process_manager = new ProcessManager(LogChannel::QUEUE_WORKER);
        $this->config_current = $this->load_config();
    }

    public function get_queue(): string {
        return $this->queue;
    }

    private function load_config(): array
    {
        $atomic = App::instance();
        $connection = $atomic->get('QUEUE')[$atomic->get('QUEUE_DRIVER')] ?? [];
        if (isset($connection['queues'][$this->queue])) {
            return $connection['queues'][$this->queue];
        } else {
            throw new \Exception("Queue {$this->queue} is not configured");
        }
    }

    private function validate_handler(array $payload): void
    {
        if (!isset($payload[0]) || !is_string($payload[0]) || !isset($payload[1]) || !is_string($payload[1])) {
            throw new \Exception('Invalid handler format. Expected [Class::class, "method"].');
        }

        $key = $payload[0] . '@' . $payload[1];
        if (isset(self::$validated_handlers[$key])) {
            return;
        }

        if (!class_exists($payload[0])) {
            throw new \Exception("Class '{$payload[0]}' not found.");
        }

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $payload[1])) {
            throw new \Exception("Invalid method name '{$payload[1]}'.");
        }

        if (!method_exists($payload[0], $payload[1])) {
            throw new \Exception("Method '{$payload[1]}' not found in class '{$payload[0]}'.");
        }

        $method = new \ReflectionMethod($payload[0], $payload[1]);
        if (!$method->isPublic()) {
            throw new \Exception("Method '{$payload[1]}' in class '{$payload[0]}' is not public.");
        }

        self::$validated_handlers[$key] = true;
    }

    private function validate_options(array &$options, array $payload = []): void
    {
        if (isset($options['cancel_handler'])) {
            $this->validate_cancel_handler($options['cancel_handler'], $payload[0] ?? null);
        }

        foreach ($this->config_required as $key) {
            if (!isset($options[$key])) {
                if (isset($this->config_current[$key])) {
                    $options[$key] = $this->config_current[$key];
                } else {
                    throw new \Exception("Required option parameter not set: $key");
                }
            }
        }

        $integer_rules = [
            'delay' => 0,
            'priority' => 0,
            'timeout' => 1,
            'max_attempts' => 1,
            'retry_delay' => 0,
            'ttl' => 0,
        ];

        foreach ($integer_rules as $key => $min) {
            if (!\is_numeric($options[$key]) || (int)$options[$key] < $min) {
                throw new \Exception("Invalid queue option {$key}. Expected integer >= {$min}.");
            }
            $options[$key] = (int)$options[$key];
        }
    }

    private function validate_cancel_handler(array|string $handler, ?string $default_class): void
    {
        if (\is_string($handler)) {
            if (!$default_class) {
                throw new \Exception('String cancel_handler requires queued handler class.');
            }
            $handler = [$default_class, $handler];
        }

        if (!\is_array($handler) || !isset($handler[0], $handler[1]) || !\is_string($handler[0]) || !\is_string($handler[1])) {
            throw new \Exception('Invalid cancel_handler format. Expected [Class::class, "method"] or "method".');
        }

        $this->validate_handler($handler);
    }

    private function set_telemetry_data(string $uuid, string $batch_uuid, string $name, EventType $event_type):void {
        $atomic = App::instance();
        $atomic->set('ATOMIC_QUEUE_CURRENT_UUID', $uuid);
        $atomic->set('ATOMIC_QUEUE_CURRENT_BATCH_UUID', $batch_uuid);
        $atomic->set('ATOMIC_QUEUE_CURRENT_NAME', $name);
        $atomic->set('ATOMIC_QUEUE_CURRENT_EVENT_TYPE', $event_type);
    }
    private function unset_telemetry_data(): void {
        $atomic = App::instance();
        $atomic->clear('ATOMIC_QUEUE_CURRENT_UUID');
        $atomic->clear('ATOMIC_QUEUE_CURRENT_BATCH_UUID');
        $atomic->clear('ATOMIC_QUEUE_CURRENT_NAME');
        $atomic->clear('ATOMIC_QUEUE_CURRENT_EVENT_TYPE');
    }

    public function retry(): void {
        $this->driver->retry($this->queue);
    }

    public function retry_by_uuid(string $uuid): bool {
        return $this->driver->retry_by_uuid($uuid);
    }

    public function delete_job(string $uuid): bool {
        return $this->driver->delete_job($uuid);
    }

    public function push(array $payload, array $data = [], array $options = [], string $uuid = ''): bool {
        $this->validate_handler($payload);
        
        $handler = $payload[0] . '@' . $payload[1];
        $internal_payload = [
            'handler' => $handler,
            'data' => $data
        ];
        
        $this->validate_options($options, $payload);
        if (isset($options['cancel_handler']) && \is_string($options['cancel_handler'])) {
            $options['cancel_handler'] = [$payload[0], $options['cancel_handler']];
        }

        $options['uuid'] = $uuid ?: ID::uuid_v4();
        $options['uuid_batch'] = ID::uuid_v4();
        $options['queue'] = $this->queue;

        $telemetry_event = $options['_telemetry_event'] ?? EventType::JOB_CREATED;
        if (!$telemetry_event instanceof EventType) {
            $telemetry_event = EventType::JOB_CREATED;
        }
        unset($options['_telemetry_event']);

        $push_res = $this->driver->push($internal_payload, $options);

        if ($push_res === true) {
            $options['payload']['uuid_batch'] = $options['uuid_batch'];
            $this->set_telemetry_data($options['uuid'], $options['uuid_batch'], $this->queue, $telemetry_event);
            $this->telemetry_manager->push_telemetry();
            $this->unset_telemetry_data();
        }

        return (bool)$push_res;
    }

    public function pop_batch(): array {
        $pop_res = $this->driver->pop_batch($this->queue, 1);

        if (\is_array($pop_res) && !empty($pop_res)) {
            foreach ($pop_res as $job) {
                $this->set_telemetry_data($job['uuid'], $job['payload']['uuid_batch'], $this->queue, EventType::JOB_FETCHED);
                $this->telemetry_manager->push_telemetry();
                $this->unset_telemetry_data();
            }
        }

        return $pop_res;
    }

    public function release(array $job, int $delay): bool {
        $release_res = $this->driver->release($job, $delay);

        if ($release_res === true) {
            $this->set_telemetry_data($job['uuid'], $job['payload']['uuid_batch'], $this->queue, EventType::JOB_FAILED);
            $this->telemetry_manager->push_telemetry();
            $this->unset_telemetry_data();
        }

        return $release_res;
    }

    public function mark_failed(array $job, \Throwable $exception): bool {
        $mark_failed_res = $this->driver->mark_failed($job, $exception);

        if ($mark_failed_res === true) {
            $this->set_telemetry_data($job['uuid'], $job['payload']['uuid_batch'], $this->queue, EventType::JOB_FAILED);
            $this->telemetry_manager->push_telemetry();
            $this->unset_telemetry_data();
        }

        return $mark_failed_res;
    }

    public function mark_cancelled(array $job, ?string $reason = null): bool {
        $this->assert_cancel_supported();

        $res = $this->driver->mark_cancelled($job, $reason);
        if ($res === true) {
            $this->set_telemetry_data($job['uuid'], $job['payload']['uuid_batch'] ?? '', $job['queue'] ?? $this->queue, EventType::JOB_CANCELLED);
            $this->telemetry_manager->push_telemetry();
            $this->unset_telemetry_data();
        }
        return $res;
    }

    public function cancel(string $uuid): bool {
        $this->assert_cancel_supported();

        $cancel = $this->driver->cancel($uuid);
        if (!$cancel) {
            return false;
        }

        $job = $cancel['job'];
        $action = $cancel['action'];

        if ($action === State::CANCELLED->value) {
            $this->set_telemetry_data($uuid, $job['payload']['uuid_batch'] ?? '', $job['queue'] ?? $this->queue, EventType::JOB_CANCELLED);
            $this->telemetry_manager->push_telemetry();
            $this->unset_telemetry_data();
            return true;
        }

        if ($action === State::CANCEL_REQUESTED->value) {
            $this->set_telemetry_data($uuid, $job['payload']['uuid_batch'] ?? '', $job['queue'] ?? $this->queue, EventType::JOB_CANCEL_REQUESTED);
            $this->telemetry_manager->push_telemetry();
            $this->unset_telemetry_data();
            $this->run_cancel_handler($job);
            $this->process_manager->send_cancellation_signal($job);
            return true;
        }

        return false;
    }

    public function is_cancel_requested(string $uuid): bool {
        $this->assert_cancel_supported();

        return $this->driver->is_cancel_requested($uuid);
    }

    public function mark_completed(array $job): bool {
        if ($this->supports_cancel() && $this->driver->is_cancel_requested($job['uuid'])) {
            return $this->mark_cancelled($job, 'cancel requested before completion');
        }
        $mark_completed_res = $this->driver->mark_completed($job);

        if ($mark_completed_res === true) {
            $this->set_telemetry_data($job['uuid'], $job['payload']['uuid_batch'], $this->queue, EventType::JOB_SUCCESS);
            $this->telemetry_manager->push_telemetry();
            $this->unset_telemetry_data();
        }

        return $mark_completed_res;
    }

    private function run_cancel_handler(array $job): void
    {
        $handler = $job['payload']['cancel_handler'] ?? null;
        if (!$handler) {
            return;
        }

        if (\is_string($handler)) {
            [$class] = \explode('@', $job['payload']['handler']);
            $handler = [$class, $handler];
        }

        if (!\is_array($handler) || !isset($handler[0], $handler[1])) {
            return;
        }

        $instance = new $handler[0]();
        $params = $job['payload']['data'] ?? [];
        $params['job'] = $job;
        \call_user_func_array([$instance, $handler[1]], $params);
    }

    public function supports_cancel(): bool
    {
        return $this->driver instanceof RedisDriver;
    }

    private function assert_cancel_supported(): void
    {
        if (!$this->supports_cancel()) {
            throw new \RuntimeException('Queue cancellation is not supported for the database queue driver. Use the redis queue driver for cancellation.');
        }
    }

    public function process_job(array $job)
    {
        $data = $job['payload'];
        if (!isset($data['handler'])) {
            throw new \Exception('Handler not specified');
        }
        $handler_parts = \explode('@', (string)$data['handler'], 2);
        if (\count($handler_parts) !== 2 || $handler_parts[0] === '' || $handler_parts[1] === '') {
            throw new \Exception('Invalid handler format. Expected "Class@method".');
        }
        [$class, $method] = $handler_parts;
        if (!\class_exists($class)) {
            throw new \Exception("Class $class not found");
        }
        $instance = new $class();
        if (!\method_exists($instance, $method)) {
            throw new \Exception("Method $method not found in class $class");
        }
        $reflection = new \ReflectionMethod($class, $method);
        if (!$reflection->isPublic()) {
            throw new \Exception("Method $method in class $class is not public");
        }

        $params = $data['data'] ?? [];

        if (empty($params) || \array_keys($params) === \range(0, \count($params) - 1)) {
            \call_user_func_array([$instance, $method], $params);
            return;
        }

        $cache_key = $class . '::' . $method;
        if (!isset(self::$reflection_cache[$cache_key])) {
            self::$reflection_cache[$cache_key] = $reflection;
        }
        $reflection = self::$reflection_cache[$cache_key];
        $orderedParams = [];
        foreach ($reflection->getParameters() as $param) {
            $param_name = $param->getName();
            if (isset($params[$param_name])) {
                $orderedParams[] = $params[$param_name];
            } elseif ($param->isDefaultValueAvailable()) {
                $orderedParams[] = $param->getDefaultValue();
            } else {
                throw new \Exception("Required job handler parameter '{$param_name}' not provided for {$class}@{$method}");
            }
        }
        \call_user_func_array([$instance, $method], $orderedParams);
    }

    public function set_pid(array $job): bool {
        return $this->driver->set_pid($job);
    }

    public function handle_incomplete_job(array $job): void {
        $res = $this->driver->handle_incomplete_job($job);

        if($res === true) {
            $this->set_telemetry_data($job['uuid'], $job['payload']['uuid_batch'], $this->queue, EventType::JOB_INCOMPLETE_HANDLED);
            $this->telemetry_manager->push_telemetry();
            $this->unset_telemetry_data();
        }
    }

    public function load_stuck_jobs(array $exclude, string $queue = '*'): array {
        return $this->driver->load_stuck_jobs($exclude, $queue);
    }

    public function load_active_jobs(string $queue = '*'): array {
        return $this->driver->load_active_jobs($queue);
    }

    public function exists_in_jobs_table(string $uuid, int $pid): bool {
        return $this->driver->exists_in_jobs_table($uuid, $pid);
    }

    public function close_all_connections(): void {
        $this->driver->reset_state();
        ConnectionManager::instance()->close();
    }

    public function open_all_connections(): void {
        ConnectionManager::instance()->open_all();
        $this->driver->init_state();
    }
}
