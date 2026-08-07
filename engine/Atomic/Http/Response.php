<?php

declare(strict_types=1);

namespace Engine\Atomic\Http;
if (!defined('ATOMIC_START')) exit;

class Response
{
    /** @param array<string, string> $headers */
    protected function __construct(
        protected string $body,
        protected int $status,
        protected array $headers = [],
        protected array $cookies = [],
    ) {}

    // в”Ђв”Ђ Factory methods в”Ђв”Ђ

    /** @param array<string, string> $headers */
    public static function html(string $body, int $status = 200, array $headers = []): static
    {
        return new static($body, $status, $headers);
    }

    /** @param array<string, string> $headers */
    public static function json(mixed $data, int $status = 200, array $headers = []): static
    {
        return new JsonResponse($data, $status, $headers);
    }

    /** @param array<string, string> $headers */
    public static function text(string $body, int $status = 200): static
    {
        return new static($body, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function redirect(string $url, int $status = 302): static
    {
        return new static('', $status, ['Location' => $url]);
    }

    public static function empty(int $status = 204): static
    {
        return new static('', $status);
    }

    public static function file(string $path, ?string $filename = null): static
    {
        $headers = ['Content-Type' => mime_content_type($path) ?: 'application/octet-stream'];
        if ($filename !== null) {
            $headers['Content-Disposition'] = 'attachment; filename="' . $filename . '"';
        }
        return new static((string)file_get_contents($path), 200, $headers);
    }

    /** @param array<string, string> $headers */
    public static function stream(\Closure $output, string $contentType = 'application/octet-stream'): static
    {
        ob_start();
        $output();
        $body = ob_get_clean();
        return new static($body, 200, ['Content-Type' => $contentType]);
    }

    // в”Ђв”Ђ Immutable modifiers в”Ђв”Ђ

    public function withHeader(string $name, string $value): static
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function withStatus(int $code): static
    {
        $clone = clone $this;
        $clone->status = $code;
        return $clone;
    }

    /** @param array<string, mixed> $options */
    public function withCookie(string $name, string $value, array $options = []): static
    {
        $clone = clone $this;
        $clone->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];
        return $clone;
    }

    public function withBody(string $body): static
    {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }

    // в”Ђв”Ђ Send в”Ђв”Ђ

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        foreach ($this->cookies as $cookie) {
            setcookie($cookie['name'], $cookie['value'], $cookie['options']);
        }
        echo $this->body;
    }

    // в”Ђв”Ђ Introspection в”Ђв”Ђ

    public function status(): int { return $this->status; }

    /** @return array<string, string> */
    public function headers(): array { return $this->headers; }

    public function header(string $name): ?string { return $this->headers[$name] ?? null; }

    public function body(): string { return $this->body; }

    /** @return list<array{name: string, value: string, options: array}> */
    public function cookies(): array { return $this->cookies; }

    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400 && isset($this->headers['Location']);
    }
}

final class JsonResponse extends Response
{
    /** @param array<string, string> $headers */
    public function __construct(mixed $data, int $status = 200, array $headers = [])
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        parent::__construct($json, $status, array_merge(
            ['Content-Type' => 'application/json; charset=utf-8'],
            $headers,
        ));
    }
}