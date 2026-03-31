<?php
declare(strict_types=1);
namespace Engine\Atomic\WebSockets;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
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
    private string $url     = 'ws://127.0.0.1:8080';
    private string $payload = '{"event":"generate","prompt":"ws_test_ping"}';
    private bool   $stopped = false;

    public function run(): void
    {
        $this->parse_args();

        echo "[ws_test] url={$this->url}\n";
        echo "[ws_test] payload={$this->payload}\n\n";

        $worker = new Worker();
        $self   = $this;

        $worker->onWorkerStart = function () use ($self): void {
            $conn = new AsyncTcpConnection($self->url);

            $conn->onConnect = function (AsyncTcpConnection $c) use ($self): void {
                echo "[ws_test] connected\n";
                $c->send($self->payload);
                echo "[ws_test] sent\n";
            };

            $conn->onMessage = function (AsyncTcpConnection $c, string $data) use ($self): void {
                echo "[ws_test] recv: {$data}\n";
                $msg   = json_decode($data, true);
                $event = (string)($msg['event'] ?? $msg['status'] ?? '');
                if (in_array($event, ['completed', 'failed', 'error'], true)) {
                    $c->close();
                    $self->stop();
                }
            };

            $conn->onError = function (AsyncTcpConnection $c, $code, $msg) use ($self): void {
                echo "[ws_test] ERROR code={$code}: {$msg}\n";
                $self->stop();
            };

            $conn->onClose = function () use ($self): void {
                echo "[ws_test] closed\n";
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
            echo "Usage:\n";
            echo "  php atomic ws/single/test [url] [json_payload]\n\n";
            echo "Examples:\n";
            echo "  php atomic ws/single/test\n";
            echo "  php atomic ws/single/test ws://127.0.0.1:8080 '{\"event\":\"generate\",\"prompt\":\"hello\"}'\n";
            exit(0);
        }

        $this->url     = (string)($args[0] ?? $this->url);
        $this->payload = (string)($args[1] ?? $this->payload);
    }
}
