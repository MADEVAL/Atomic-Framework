<?php
declare(strict_types=1);
namespace Engine\Atomic\Plugins\WebSockets;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Middleware\MiddlewareStack;
use Workerman\Protocols\Http\Request;

if (!defined('ATOMIC_START')) exit;

class WebSocketDispatcher
{
    public function dispatch_connect(Connection $conn, Request $request): bool
    {
        $route = $this->match($conn->path());

        if ($route === null) {
            $conn->close();
            return false;
        }

        foreach ($route['connect_middleware'] as $name) {
            $middleware = MiddlewareStack::resolve_any($name);
            if (!$middleware instanceof WebSocketConnectMiddleware) {
                throw new \RuntimeException("Invalid WebSocket connect middleware: {$name}");
            }

            if (!$middleware->handle($conn, $request, $route['params'])) {
                $conn->close();
                return false;
            }
        }

        return true;
    }

    public function dispatch(Connection $conn, string $message, int $op = 1): void
    {
        $route = $this->match($conn->path());
        if ($route === null) {
            $conn->send(json_encode([
                'status' => 'failed',
                'error' => 'Unknown WebSocket route',
            ]));
            $conn->close();
            return;
        }

        foreach ($route['middleware'] as $name) {
            $middleware = MiddlewareStack::resolve_any($name);
            if (!$middleware instanceof WebSocketMiddleware) {
                throw new \RuntimeException("Invalid WebSocket middleware: {$name}");
            }

            if (!$middleware->handle($conn, $message, $route['params'])) {
                return;
            }
        }

        $this->call_handler($route['handler'], $conn, $message, $route['params']);
    }

    private function match(string $path): ?array
    {
        $routes = (array)App::instance()->get('WS_ROUTES');
        foreach ($routes as $pattern => $route) {
            $params = $this->match_path((string)$pattern, $path);
            if ($params === null) {
                continue;
            }

            return [
                'handler' => $route['handler'],
                'middleware' => $this->message_middleware((array)($route['middleware'] ?? [])),
                'connect_middleware' => $this->connect_middleware((array)($route['middleware'] ?? [])),
                'params' => $params,
            ];
        }

        return null;
    }

    private function match_path(string $pattern, string $path): ?array
    {
        $pattern_parts = explode('/', trim($pattern, '/'));
        $path_parts = explode('/', trim($path, '/'));
        if (count($pattern_parts) !== count($path_parts)) {
            return null;
        }

        $params = [];
        foreach ($pattern_parts as $i => $part) {
            if (str_starts_with($part, '@')) {
                $params[substr($part, 1)] = $path_parts[$i];
                continue;
            }

            if ($part !== $path_parts[$i]) {
                return null;
            }
        }

        return $params;
    }

    private function message_middleware(array $middleware): array
    {
        if (isset($middleware['message'])) {
            return (array)$middleware['message'];
        }

        if (isset($middleware['connect'])) {
            unset($middleware['connect']);
        }

        return array_values($middleware);
    }

    private function connect_middleware(array $middleware): array
    {
        return (array)($middleware['connect'] ?? []);
    }

    private function call_handler(string $handler, Connection $conn, string $message, array $params): void
    {
        if (!preg_match('/^(.+?)(?:->|::)(\w+)$/', $handler, $m)) {
            throw new \InvalidArgumentException("Invalid WebSocket handler: {$handler}");
        }

        $class = ltrim($m[1], '\\');
        $method = $m[2];
        if (str_contains($handler, '::')) {
            $class::$method($conn, $message, $params);
            return;
        }

        (new $class())->$method($conn, $message, $params);
    }
}
