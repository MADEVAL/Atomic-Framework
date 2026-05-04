<?php
declare(strict_types=1);
namespace Engine\Atomic\Plugins\WebSockets;

if (!defined('ATOMIC_START')) exit;

use Workerman\Protocols\Http\Request;

class RoutedWebSocketServer extends Server
{
    protected WebSocketDispatcher $dispatcher;

    public function __construct(string $listen, int $worker_count = 1, bool $daemonize = false)
    {
        parent::__construct($listen, $worker_count, $daemonize);
        $this->dispatcher = new WebSocketDispatcher();
    }

    protected function setup(): void
    {
    }

    protected function on_websocket_connect(Connection $conn, Request $request): void
    {
        $this->dispatcher->dispatch_connect($conn, $request);
    }

    protected function on_message(Connection $conn, string $data, int $op): void
    {
        $this->dispatcher->dispatch($conn, $data, $op);
    }

    protected function on_disconnect(Connection $conn): void
    {
    }
}
