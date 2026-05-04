<?php
declare(strict_types=1);
namespace Engine\Atomic\WebSockets;

use Engine\Atomic\Core\App;

if (!defined('ATOMIC_START')) exit;

class WebSocketRouter
{
    public static function register(string $pattern, string $handler, array $middleware = []): void
    {
        $path = self::parse_pattern($pattern);
        $routes = (array)App::instance()->get('WS_ROUTES');
        $routes[$path] = [
            'handler' => $handler,
            'middleware' => $middleware,
        ];
        App::instance()->set('WS_ROUTES', $routes);
    }

    private static function parse_pattern(string $pattern): string
    {
        $pattern = trim($pattern);
        if ($pattern === '' || str_contains($pattern, ' ')) {
            throw new \InvalidArgumentException("Invalid WebSocket route pattern: {$pattern}");
        }

        return '/' . ltrim($pattern, '/');
    }
}
