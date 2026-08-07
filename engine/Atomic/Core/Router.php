<?php

declare(strict_types=1);

namespace Engine\Atomic\Core;
if (!defined('ATOMIC_START')) exit;

final class RouteGroup
{
    private array $middlewareAliases = [];

    /** @param string ...$aliases Middleware aliases */
    public function middleware(string ...$aliases): self
    {
        $this->middlewareAliases = array_merge($this->middlewareAliases, $aliases);
        return $this;
    }

    /** @return string[] */
    public function middlewareAliases(): array { return $this->middlewareAliases; }
}

final class Router
{
    /** @var array<string, Route> */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $namedRoutes = [];

    /** @var array<string, array{file: string, errorFile?: string}> */
    private array $routeTypes = [];

    private ?string $currentPrefix = null;

    public function __construct(
        private readonly Container $container,
    ) {}

    // в”Ђв”Ђ Request type detection в”Ђв”Ђ

    public function detectRequestType(?string $path = null): string
    {
        // When path is explicitly provided, parse it (ignore SAPI)
        if ($path !== null) {
            $path = trim((string)parse_url($path, PHP_URL_PATH), '/');
        } else {
            // Auto-detect from request
            if (PHP_SAPI === 'cli') {
                return 'cli';
            }
            $path = trim((string)parse_url($_SERVER['PATH_INFO'] ?? $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
        }

        $firstSegment = explode('/', $path)[0] ?? '';

        return match ($firstSegment) {
            'api' => 'api',
            'telemetry' => 'telemetry',
            'cli' => 'cli',
            '' => 'web',
            default => 'web',
        };
    }

    // в”Ђв”Ђ Fluent route definitions в”Ђв”Ђ

    public function add(string $methods, string $path, callable|string $handler): Route
    {
        $methodList = explode('|', $methods);
        $fullPath = $this->currentPrefix !== null
            ? rtrim($this->currentPrefix, '/') . '/' . ltrim($path, '/')
            : $path;

        $route = new Route($fullPath, $methodList, $handler);
        $this->routes[] = $route;
        return $route;
    }

    public function get(string $path, callable|string $handler): Route
    {
        return $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable|string $handler): Route
    {
        return $this->add('POST', $path, $handler);
    }

    public function put(string $path, callable|string $handler): Route
    {
        return $this->add('PUT', $path, $handler);
    }

    public function patch(string $path, callable|string $handler): Route
    {
        return $this->add('PATCH', $path, $handler);
    }

    public function delete(string $path, callable|string $handler): Route
    {
        return $this->add('DELETE', $path, $handler);
    }

    // в”Ђв”Ђ Backward-compat: F3-style routes в”Ђв”Ђ

    /** @param string[] $middleware */
    public function f3route(string $pattern, string $handler, array $middleware = [], int $ttl = 0): void
    {
        $route = new Route($pattern, ['F3'], $handler);
        if (!empty($middleware)) {
            $route->middleware(...$middleware);
        }
        $this->routes[] = $route;
    }

    /** Alias for f3route() */
    public function route(string $pattern, string $handler, array $middleware = [], int $ttl = 0): void
    {
        $this->f3route($pattern, $handler, $middleware, $ttl);
    }

    // в”Ђв”Ђ Group routing в”Ђв”Ђ

    public function group(?string $prefix, callable $callback): RouteGroup
    {
        $previousPrefix = $this->currentPrefix;

        if ($prefix !== null) {
            $this->currentPrefix = ($this->currentPrefix !== null)
                ? rtrim($this->currentPrefix, '/') . '/' . ltrim($prefix, '/')
                : $prefix;
        }

        $group = new RouteGroup();
        $callback();

        $this->currentPrefix = $previousPrefix;

        return $group;
    }

    // в”Ђв”Ђ Named routes в”Ђв”Ђ

    public function named(string $name): ?Route
    {
        foreach ($this->routes as $route) {
            if ($route->getName() === $name) {
                return $route;
            }
        }
        return null;
    }

    /** @return array<string, Route> */
    public function namedRoutes(): array
    {
        $result = [];
        foreach ($this->routes as $route) {
            if ($route->getName() !== null) {
                $result[$route->getName()] = $route;
            }
        }
        return $result;
    }

    // в”Ђв”Ђ Route types в”Ђв”Ђ

    public function registerRouteType(string $type, string $file, ?string $errorFile = null): void
    {
        $this->routeTypes[$type] = ['file' => $file, 'errorFile' => $errorFile];
    }

    public function hasRouteType(string $type): bool
    {
        return isset($this->routeTypes[$type]);
    }

    /** @return array<string, array{file: string, errorFile?: string}> */
    public function routeTypes(): array
    {
        return $this->routeTypes;
    }

    // в”Ђв”Ђ Introspection в”Ђв”Ђ

    /** @return Route[] */
    public function routes(): array
    {
        return $this->routes;
    }
}