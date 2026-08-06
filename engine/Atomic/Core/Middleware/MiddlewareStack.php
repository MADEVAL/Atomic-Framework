<?php
declare(strict_types=1);
namespace Engine\Atomic\Core\Middleware;

if (!defined('ATOMIC_START')) exit;

class MiddlewareStack
{
    /** @var array<string, string> alias → class FQCN */
    private static array $aliases = [];

    /** @var array<string, string[]> url_pattern → middleware names */
    private static array $route_map = [];

    /**
     * Register a middleware alias (e.g. 'auth' → Authenticate::class).
     */
    public static function register_alias(string $name, string $class): void
    {
        self::$aliases[$name] = $class;
    }

    /**
     * Assign middleware names to a route pattern.
     * The URL pattern is extracted from the full F3 route string (e.g. "GET /path" → "/path").
     */
    public static function for_route(string $route_pattern, array $middleware_names): void
    {
        $url_pattern = self::extract_url_pattern($route_pattern);
        self::$route_map[$url_pattern] = array_merge(
            self::$route_map[$url_pattern] ?? [],
            $middleware_names
        );

        foreach (self::extract_methods($route_pattern) as $method) {
            $key = $method . ' ' . $url_pattern;
            self::$route_map[$key] = array_merge(
                self::$route_map[$key] ?? [],
                $middleware_names
            );
        }
    }

    /**
     * Run all middleware registered for the current request's PATTERN.
     * Returns true if all middleware passed, false if any aborted.
     */
    public static function run_for_route($atomic): bool
    {
        $pattern = $atomic->get('PATTERN');
        $method_pattern = strtoupper((string)$atomic->get('VERB')) . ' ' . $pattern;
        $middleware_names = self::$route_map[$method_pattern] ?? self::$route_map[$pattern] ?? [];

        foreach ($middleware_names as $name_with_params) {
            $middleware = self::resolve($name_with_params);
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
    public static function resolve(string $name_with_params): ?MiddlewareInterface
    {
        $instance = self::resolve_any($name_with_params);
        if (!($instance instanceof MiddlewareInterface)) {
            return null;
        }

        return $instance;
    }

    /**
     * Resolve a middleware alias without enforcing the HTTP middleware interface.
     * Used by middleware variants with a different runtime contract, such as WebSockets.
     */
    public static function resolve_any(string $name_with_params): ?object
    {
        [$name, $param] = array_pad(explode(':', $name_with_params, 2), 2, null);

        $class = self::$aliases[$name] ?? null;
        if ($class === null || !class_exists($class)) {
            return null;
        }

        return $param !== null ? new $class($param) : new $class();
    }

    /**
     * Extract URL pattern from F3 route string.
     * "GET|POST /account/store/@store_id/settings" → "/account/store/@store_id/settings"
     */
    private static function extract_url_pattern(string $route_pattern): string
    {
        return ltrim(preg_replace('/^[A-Z|]+\s+/', '', trim($route_pattern)));
    }

    /** @return string[] */
    private static function extract_methods(string $route_pattern): array
    {
        if (!preg_match('/^([A-Z|]+)\s+/', trim($route_pattern), $m)) {
            return [];
        }

        return array_values(array_filter(explode('|', $m[1])));
    }
}
