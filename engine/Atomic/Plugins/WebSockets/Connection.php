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
    private array $headers = [];
    private array $query = [];
    private string $method = 'GET';
    private string $uri = '/';
    private string $remote_ip = '';
    private int $remote_port = 0;
    private string $remote_address = '';

    public function __construct(private TcpConnection $tcp)
    {
        $this->id = (string)$tcp->id;
        $this->capture_tcp_info();
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

    public function capture_tcp_info(): void
    {
        $this->remote_ip = method_exists($this->tcp, 'getRemoteIp') ? (string)$this->tcp->getRemoteIp() : '';
        $this->remote_port = method_exists($this->tcp, 'getRemotePort') ? (int)$this->tcp->getRemotePort() : 0;
        $this->remote_address = method_exists($this->tcp, 'getRemoteAddress') ? (string)$this->tcp->getRemoteAddress() : '';
    }

    public function capture_request_info(object $request): void
    {
        $this->method = method_exists($request, 'method') ? (string)$request->method() : $this->method;
        $this->uri = method_exists($request, 'uri') ? (string)$request->uri() : $this->uri;
        $this->path = method_exists($request, 'path') ? (string)$request->path() : $this->path;
        $this->headers = $this->normalize_headers(method_exists($request, 'header') ? (array)$request->header() : []);
        $this->query = method_exists($request, 'get') ? (array)$request->get() : [];
    }

    public function method(): string
    {
        return $this->method;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function remote_ip(): string
    {
        return $this->remote_ip;
    }

    public function remote_port(): int
    {
        return $this->remote_port;
    }

    public function remote_address(): string
    {
        return $this->remote_address;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name, mixed $default = null): mixed
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }

        return $this->query[$key] ?? $default;
    }

    public function ip(): string
    {
        $forwarded = trim((string)$this->header('x-forwarded-for', ''));
        if ($forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }

        $real_ip = trim((string)$this->header('x-real-ip', ''));
        return $real_ip !== '' ? $real_ip : $this->remote_ip;
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

    private function normalize_headers(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string)$name)] = $value;
        }

        return $normalized;
    }
}
