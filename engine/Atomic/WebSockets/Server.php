<?php
declare(strict_types=1);
namespace Engine\Atomic\WebSockets;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Filesystem;
use Engine\Atomic\WebSockets\Connection;
use Workerman\Connection\TcpConnection;
use Workerman\Redis\Client as RedisClient;
use Workerman\Worker;

abstract class Server
{
    /** @var array<string,int> task_id -> socket_int */
    protected array $memory_map = [];
    /** @var array<int,array<string,true>> socket_int -> {task_id: true} */
    protected array $socket_tasks = [];
    /** @var array<int,Connection> socket_int -> Connection */
    protected array $connections = [];
    private ?string $pubsub_channel = null;
    protected ?RedisClient $async_redis = null;

    public function __construct(
        private string $listen,
        private int $worker_count = 1,
        private bool $daemonize = false
    ) {}

    protected function subscribe_to_channel(string $channel): void
    {
        $this->pubsub_channel = $channel;
    }

    protected function map_task(string $task_id, int $socket_int): void
    {
        $this->memory_map[$task_id] = $socket_int;
        $this->socket_tasks[$socket_int][$task_id] = true;
    }

    protected function get_socket_tasks(int $socket_int): array
    {
        return array_keys($this->socket_tasks[$socket_int] ?? []);
    }

    protected function unmap_task(string $task_id): void
    {
        $socket_int = $this->memory_map[$task_id] ?? null;
        unset($this->memory_map[$task_id]);
        if ($socket_int === null) return;
        unset($this->socket_tasks[$socket_int][$task_id]);
        if (empty($this->socket_tasks[$socket_int])) {
            unset($this->socket_tasks[$socket_int]);
        }
    }

    protected function init_async_redis(): void
    {
        $cfg  = (array)App::instance()->get('REDIS');
        $this->async_redis = new RedisClient($this->build_redis_uri($cfg));
    }

    protected function build_redis_uri(array $cfg): string
    {
        $host = (string)$cfg['host'];
        $port = (int)$cfg['port'];
        $password = trim((string)$cfg['password']);
        $db = (int)($cfg['db'] ?? 0);

        $auth = '';
        if ($password !== '' && strtolower($password) !== 'null') {
            $auth = ':' . rawurlencode($password) . '@';
        }

        $path = $db > 0 ? '/' . $db : '';

        return "redis://{$auth}{$host}:{$port}{$path}";
    }

    abstract protected function setup(): void;
    protected function on_worker_start(): void {}
    abstract protected function on_connect(Connection $conn): void;
    abstract protected function on_message(Connection $conn, string $data, int $op): void;
    abstract protected function on_disconnect(Connection $conn): void;

    public function run(): void
    {
        if ($this->worker_count < 1) {
            throw new \InvalidArgumentException('WebSocket worker count must be at least 1.');
        }

        $listen = preg_replace('#^tcp://#', 'websocket://', $this->listen);
        $worker = new Worker($listen);
        $worker->count = $this->worker_count;

        $logs_dir = rtrim((string)App::instance()->get('LOGS'), '/');
        if ($logs_dir === '') {
            throw new \RuntimeException('LOGS is not configured.');
        }
        $logs_dir .= '/ws';
        if (!is_dir($logs_dir)) {
            Filesystem::instance()->make_dir($logs_dir, 0755, true);
        }
        $server_tag = strtolower(str_replace('\\', '.', static::class));
        Worker::$pidFile = $logs_dir . '/workerman.' . $server_tag . '.pid';
        Worker::$logFile = $logs_dir . '/workerman.' . $server_tag . '.log';

        $this->setup();

        $self = $this;

        $worker->onWorkerStart = function() use ($self): void {
            $self->init_async_redis();
            $self->on_worker_start();
            if ($self->pubsub_channel !== null) {
                $self->start_pubsub($self->pubsub_channel);
            }
        };

        $worker->onConnect = function(TcpConnection $tcp) use ($self): void {
            $conn = new Connection($tcp);
            $self->connections[$conn->socket_int()] = $conn;
            $self->on_connect($conn);
        };

        $worker->onMessage = function(TcpConnection $tcp, string $data) use ($self): void {
            $socket_int = (int)$tcp->id;
            if (!isset($self->connections[$socket_int])) {
                $self->connections[$socket_int] = new Connection($tcp);
            }
            $self->on_message($self->connections[$socket_int], $data, 1);
        };

        $worker->onClose = function(TcpConnection $tcp) use ($self): void {
            $socket_int = (int)$tcp->id;
            $conn = $self->connections[$socket_int] ?? null;
            if ($conn !== null) {
                $self->on_disconnect($conn);
            }
            if (isset($self->socket_tasks[$socket_int])) {
                foreach ($self->socket_tasks[$socket_int] as $task_id => $_) {
                    unset($self->memory_map[$task_id]);
                }
                unset($self->socket_tasks[$socket_int]);
            }
            unset($self->connections[$socket_int]);
        };

        Worker::$daemonize = $this->daemonize;

        global $argv;
        $argv = $this->daemonize
            ? [$argv[0] ?? 'atomic', 'start', '-d']
            : [$argv[0] ?? 'atomic', 'start'];

        Worker::runAll();
    }

    public function start_pubsub(string $channel): void
    {
        $cfg   = (array)App::instance()->get('REDIS');
        $redis = new RedisClient($this->build_redis_uri($cfg));
        $self  = $this;

        $redis->subscribe([$channel], function(string $chan, string $payload) use ($self): void
        {
            $data = json_decode($payload, true);
            if (!is_array($data) || empty($data['task_id'])) return;

            $task_id    = $data['task_id'];
            $socket_int = $self->memory_map[$task_id] ?? null;
            if ($socket_int === null) return;

            $conn = $self->connections[$socket_int] ?? null;
            if ($conn === null) {
                $self->unmap_task($task_id);
                return;
            }

            $conn->send($payload);
            $self->unmap_task($task_id);
        });
    }
}

