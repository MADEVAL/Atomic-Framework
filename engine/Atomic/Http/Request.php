<?php
declare(strict_types=1);

namespace Engine\Atomic\Http;
if (!defined('ATOMIC_START')) exit;

final class Request
{
    /** @param array<string, string> $headers */
    /** @param array<string, mixed> $body */
    public function __construct(
        private readonly string $method,
        private readonly string $uri,
        private readonly array $headers = [],
        private readonly array $body = [],
        private readonly string $ip = '127.0.0.1',
    ) {}

    public function method(): string
    {
        return strtoupper($this->method);
    }

    public function path(): string
    {
        return $this->uri;
    }

    public function fullUrl(): string
    {
        return $this->uri;
    }

    public function ip(): string
    {
        return $this->ip;
    }

    public function header(string $name): ?string
    {
        // Case-insensitive header lookup
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    public function input(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->body;
        }
        return $this->body[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key);
        return $value !== null ? (string)$value : $default;
    }

    public function expectsJson(): bool
    {
        $accept = $this->header('Accept') ?? '';
        return str_contains($accept, '/json') || str_contains($accept, '+json');
    }

    public function isAjax(): bool
    {
        return strcasecmp($this->header('X-Requested-With') ?? '', 'XMLHttpRequest') === 0;
    }
}