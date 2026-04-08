<?php
declare(strict_types=1);
namespace Engine\Atomic\WebSockets;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\CLI\Console\Output;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Worker;

/**
 * Generic single-shot WebSocket test client.
 *
 * Connects to a URL, sends a JSON payload, prints every received message,
 * exits after the first terminal response (completed/failed/error) or when
 * the server closes the connection.
 *
 * Usage: php atomic ws/single/test [url] [json_payload]
 */
class Test
{
    private readonly Output $output;
    private string $url     = 'ws://127.0.0.1:8080';
    private string $payload = '{"event":"generate","prompt":"ws_test_ping"}';
    private bool   $stopped = false;

    public function __construct()
    {
        $this->output = new Output();
    }

    public function run(): void
    {
        $this->parse_args();

        $this->output->writeln("[ws_test] url={$this->url}");
        $this->output->writeln("[ws_test] payload={$this->payload}");
        $this->output->writeln();

        $worker = new Worker();
        $self   = $this;

        $worker->onWorkerStart = function () use ($self): void {
            $conn = new AsyncTcpConnection($self->url);

            $conn->onConnect = function (AsyncTcpConnection $c) use ($self): void {
                $self->output->writeln('[ws_test] connected');
                $c->send($self->payload);
                $self->output->writeln('[ws_test] sent');
            };

            $conn->onMessage = function (AsyncTcpConnection $c, string $data) use ($self): void {
                $self->output->writeln("[ws_test] recv: {$data}");
                $msg   = json_decode($data, true);
                $event = (string)($msg['event'] ?? $msg['status'] ?? '');
                if (in_array($event, ['completed', 'failed', 'error'], true)) {
                    $c->close();
                    $self->stop();
                }
            };

            $conn->onError = function (AsyncTcpConnection $c, $code, $msg) use ($self): void {
                $self->output->err("[ws_test] ERROR code={$code}: {$msg}");
                $self->stop();
            };

            $conn->onClose = function () use ($self): void {
                $self->output->writeln('[ws_test] closed');
                $self->stop();
            };

            $conn->connect();
        };

        $logs_dir = rtrim((string)App::instance()->get('LOGS'), '/');
        if ($logs_dir === '') {
            throw new \RuntimeException('LOGS is not configured.');
        }
        $logs_dir .= '/ws';
        if (!is_dir($logs_dir)) {
            @mkdir($logs_dir, 0755, true);
        }
        Worker::$pidFile = $logs_dir . '/workerman.ws_test.' . getmypid() . '.pid';
        Worker::$logFile = $logs_dir . '/workerman.ws_test.log';

        global $argv;
        $argv = [$argv[0] ?? 'atomic', 'start'];

        Worker::runAll();
    }

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;

        if (function_exists('posix_getppid') && function_exists('posix_kill')) {
            $pid = posix_getppid();
            if ($pid > 1) {
                @posix_kill($pid, SIGINT);
            }
        }
        Worker::stopAll();
    }

    private function parse_args(): void
    {
        global $argv;
        $args = array_slice($argv ?? [], 2);

        if (($args[0] ?? '') === '--help') {
            $this->output->writeln('Usage:');
            $this->output->writeln('  php atomic ws/single/test [url] [json_payload]');
            $this->output->writeln();
            $this->output->writeln('Examples:');
            $this->output->writeln('  php atomic ws/single/test');
            $this->output->writeln("  php atomic ws/single/test ws://127.0.0.1:8080 '{\"event\":\"generate\",\"prompt\":\"hello\"}'");
            exit(0);
        }

        $this->url     = (string)($args[0] ?? $this->url);
        $this->payload = (string)($args[1] ?? $this->payload);
    }
}
