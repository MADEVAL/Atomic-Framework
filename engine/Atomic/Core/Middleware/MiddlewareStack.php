<?php
declare(strict_types=1);
namespace Engine\Atomic\Core\Middleware;

if (!defined('ATOMIC_START')) exit;

class MiddlewareStack
{
    /** @var array<string, string> alias → class FQCN */
    private static array $aliases = [];

    /** @var array<string, string[]> url_pattern → middleware names */
    private static array $routeMap = [];

    /**
     * Register a middleware alias (e.g. 'auth' → Authenticate::class).
     */
    public static function registerAlias(string $name, string $class): void
    {
        self::$aliases[$name] = $class;
    }

    /**
     * Assign middleware names to a route pattern.
     * The URL pattern is extracted from the full F3 route string (e.g. "GET /path" → "/path").
     */
    public static function forRoute(string $routePattern, array $middlewareNames): void
    {
        $urlPattern = self::extractUrlPattern($routePattern);
        self::$routeMap[$urlPattern] = $middlewareNames;
    }

    /**
     * Run all middleware registered for the current request's PATTERN.
     * Returns true if all middleware passed, false if any aborted.
     */
    public static function runForRoute($atomic): bool
    {
        $pattern = $atomic->get('PATTERN');
        $middlewareNames = self::$routeMap[$pattern] ?? [];

        foreach ($middlewareNames as $nameWithParams) {
            $middleware = self::resolve($nameWithParams);
            if ($middleware === null) {
                continue;
            }
            if (!$middleware->handle($atomic)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve a middleware name (optionally with :parameter) into an instance.
     * Supports parameterized aliases like 'store:banned'.
     */
    public static function resolve(string $nameWithParams): ?MiddlewareInterface
    {
        [$name, $param] = array_pad(explode(':', $nameWithParams, 2), 2, null);

        $class = self::$aliases[$name] ?? null;
        if ($class === null || !class_exists($class)) {
            return null;
        }

        $instance = $param !== null ? new $class($param) : new $class();

        if (!($instance instanceof MiddlewareInterface)) {
            return null;
        }

        return $instance;
    }

    /**
     * Extract URL pattern from F3 route string.
     * "GET|POST /account/store/@store_id/settings" → "/account/store/@store_id/settings"
     */
    private static function extractUrlPattern(string $routePattern): string
    {
        return ltrim(preg_replace('/^[A-Z|]+\s+/', '', trim($routePattern)));
    }
}
