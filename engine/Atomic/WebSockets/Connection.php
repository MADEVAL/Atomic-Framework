<?php
declare(strict_types=1);
namespace Engine\Atomic\WebSockets;

if (!defined('ATOMIC_START')) exit;

use Workerman\Connection\TcpConnection;

class Connection
{
    private string $id;

    public function __construct(private TcpConnection $tcp)
    {
        $this->id = (string)$tcp->id;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function socket_int(): int
    {
        return (int)$this->tcp->id;
    }

    public function send(string $data): bool
    {
        return $this->tcp->send($data) !== false;
    }

    public function close(): void
    {
        $this->tcp->close();
    }
}
