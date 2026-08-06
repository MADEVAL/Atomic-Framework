<?php

declare(strict_types=1);

namespace Engine\Atomic\Core;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

final class Container implements ContainerInterface
{
    /** @var array<string, array{concrete: string|\Closure, shared: bool}> */
    private array $bindings = [];

    /** @var array<string, object|null> */
    private array $instances = [];

    /** @var array<string> */
    private array $aliases = [];

    /** @var array<string, list<string>> */
    private array $tags = [];

    /** @var array<string, array<int, \ReflectionParameter>> */
    private static array $resolverCache = [];

    private bool $flushed = false;

    // ── PSR-11 ──

    public function get(string $id): mixed
    {
        $id = $this->resolveAlias($id);

        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->bindings[$id])) {
            $binding = $this->bindings[$id];
            $instance = $this->resolve($binding['concrete']);

            if ($binding['shared']) {
                $this->instances[$id] = $instance;
            }

            return $instance;
        }

        throw new class("Container entry '{$id}' not found.") extends \RuntimeException implements NotFoundExceptionInterface {};
    }

    public function has(string $id): bool
    {
        $id = $this->resolveAlias($id);
        return isset($this->bindings[$id]) || isset($this->instances[$id]);
    }

    // ── Registration ──

    public function bind(string $abstract, string|\Closure $concrete, bool $shared = false): void
    {
        $this->bindings[$abstract] = ['concrete' => $concrete, 'shared' => $shared];
    }

    public function singleton(string $abstract, string|\Closure $concrete): void
    {
        $this->bind($abstract, $concrete, true);
    }

    public function instance(string $abstract, object $instance): void
    {
        $this->instances[$abstract] = $instance;
        $this->bindings[$abstract] = ['concrete' => $abstract, 'shared' => true];
    }

    public function alias(string $abstract, string $alias): void
    {
        $this->aliases[$alias] = $abstract;
    }

    public function tag(string|array $abstracts, string $tag): void
    {
        foreach ((array)$abstracts as $abstract) {
            $this->tags[$tag][] = $abstract;
        }
    }

    /** @return list<object> */
    public function tagged(string $tag): array
    {
        if (!isset($this->tags[$tag])) {
            return [];
        }

        return array_map(fn(string $id) => $this->get($id), $this->tags[$tag]);
    }

    // ── Autowiring ──

    /** @param array<string, mixed> $params */
    public function make(string $class, array $params = []): object
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        $args = $this->resolveParameters($class, $constructor, $params);

        return new $class(...$args);
    }

    /**
     * @param callable|array{0: object|string, 1: string} $callable
     * @param array<string, mixed> $params
     */
    public function call(callable|array $callable, array $params = []): mixed
    {
        if (is_array($callable)) {
            $className = is_object($callable[0]) ? $callable[0]::class : $callable[0];
            $ref = new \ReflectionMethod($callable[0], $callable[1]);
            $obj = is_object($callable[0]) ? $callable[0] : $this->make($callable[0]);
            $args = $this->resolveParameters($className . '::' . $callable[1], $ref, $params);
            return $ref->invokeArgs($obj, $args);
        }

        $ref = new \ReflectionFunction($callable);
        $args = $this->resolveParameters('Closure@' . spl_object_id($callable), $ref, $params);
        return $ref->invokeArgs($args);
    }

    // ── Lifecycle ──

    public function reset(): void
    {
        // Keep scoped instances (persistent), drop the rest
        $this->instances = [];
    }

    public function flush(): void
    {
        $this->bindings = [];
        $this->instances = [];
        $this->aliases = [];
        $this->tags = [];
        $this->flushed = true;
    }

    /**
     * @param array<string, string|\Closure> $scopedBindings
     */
    public function scoped(\Closure $callback, array $scopedBindings = []): mixed
    {
        $scope = new self();

        // Inherit parent bindings
        $scope->bindings = $this->bindings;
        $scope->instances = $this->instances;
        $scope->aliases = $this->aliases;
        $scope->tags = $this->tags;

        // Apply scoped-specific bindings
        foreach ($scopedBindings as $abstract => $concrete) {
            $scope->singleton($abstract, $concrete);
        }

        try {
            return $callback($scope);
        } finally {
            // Scope is discarded after callback
        }
    }

    // ── Introspection ──

    public function bound(string $abstract): bool
    {
        return $this->has($abstract);
    }

    public function resolved(string $abstract): bool
    {
        $id = $this->resolveAlias($abstract);
        return isset($this->instances[$id]);
    }

    // ── Internal ──

    private function resolveAlias(string $id): string
    {
        return $this->aliases[$id] ?? $id;
    }

    private function resolve(string|\Closure $concrete): object
    {
        if ($concrete instanceof \Closure) {
            return $concrete($this);
        }

        return $this->make($concrete);
    }

    /**
     * @param array<string, mixed> $params
     * @return list<mixed>
     */
    private function resolveParameters(string $cacheKey, \ReflectionFunctionAbstract $ref, array $params): array
    {
        $parameters = self::$resolverCache[$cacheKey] ??= $ref->getParameters();
        $args = [];

        foreach ($parameters as $param) {
            $type = $param->getType();
            $name = $param->getName();

            // User-provided param
            if (array_key_exists($name, $params)) {
                $args[] = $params[$name];
                continue;
            }

            // Typed parameter → resolve from container
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $className = $type->getName();
                if ($this->has($className)) {
                    $args[] = $this->get($className);
                    continue;
                }
            }

            // Default value
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            // Nullable
            if ($type !== null && $type->allowsNull()) {
                $args[] = null;
                continue;
            }

            throw new \RuntimeException(
                "Cannot resolve parameter '{$name}' for '{$cacheKey}'"
            );
        }

        return $args;
    }
}
