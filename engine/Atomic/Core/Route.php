<?php

declare(strict_types=1);

namespace Engine\Atomic\Core;

final class Route
{
    /** @var string[] */
    private array $methods;

    /** @var string[] */
    private array $middlewareAliases = [];

    private ?string $name = null;

    public function __construct(
        private string $path,
        string|array $methods,
        private mixed $handler,
    ) {
        $this->methods = (array)$methods;
    }

    /** @return string[] */
    public function methods(): array { return $this->methods; }

    public function path(): string { return $this->path; }

    public function handler(): string|callable { return $this->handler; }

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getName(): ?string { return $this->name; }

    /** @param string ...$aliases Middleware aliases, optionally with params: 'name:param1,param2' */
    public function middleware(string ...$aliases): self
    {
        $this->middlewareAliases = array_merge($this->middlewareAliases, $aliases);
        return $this;
    }

    /** @return string[] */
    public function middlewareAliases(): array { return $this->middlewareAliases; }

    /** @param array<string, string> $params */
    public function url(array $params = []): string
    {
        $url = $this->path;
        foreach ($params as $key => $value) {
            $url = str_replace('{' . $key . '}', (string)$value, $url);
        }
        return $url;
    }
}
