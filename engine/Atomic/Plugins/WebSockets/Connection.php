<?php
declare(strict_types=1);
namespace Engine\Atomic\Plugins\WebSockets;

if (!defined('ATOMIC_START')) exit;

use Workerman\Connection\TcpConnection;

class Connection
{
    private string $id;
    private string $path = '/';
    private array $attributes = [];

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

    public function set_path(string $path): void
    {
        $this->path = $path;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    public function unset(string $key): void
    {
        unset($this->attributes[$key]);
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
