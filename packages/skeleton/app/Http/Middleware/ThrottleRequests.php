<?php
declare(strict_types=1);
namespace App\Http\Middleware;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Middleware\MiddlewareInterface;
use Engine\Atomic\Http\Response as HttpResponse;

class ThrottleRequests implements MiddlewareInterface
{
    private int $maxAttempts;
    private int $windowSeconds;

    public function __construct(?string $params = null)
    {
        if ($params !== null) {
            $parts = explode(',', $params);
            $this->maxAttempts = (int)($parts[0] ?? 60);
            $this->windowSeconds = (int)($parts[1] ?? 60);
        } else {
            $this->maxAttempts = 60;
            $this->windowSeconds = 60;
        }
    }

    public function handle(\Base $atomic): bool
    {
        $key = 'throttle:' . ($atomic->get('IP') ?: '127.0.0.1');

        $cache = \Engine\Atomic\Core\CacheManager::instance()->cascade();
        $attempts = (int)$cache->get($key);
        $attempts++;

        if ($attempts > $this->maxAttempts) {
            $retryAfter = $this->windowSeconds;

            header('Retry-After: ' . $retryAfter);
            \Engine\Atomic\Core\Response::instance()->send_json_error(
                'Too many requests. Try again in ' . $retryAfter . ' seconds.',
                429,
                [],
                false,
            );
            return false;
        }

        $cache->set($key, $attempts, $this->windowSeconds);
        return true;
    }

    public function process(mixed $request, callable $next): HttpResponse
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $key = 'throttle:' . $ip;

        $cache = \Engine\Atomic\Core\CacheManager::instance()->cascade();
        $attempts = (int)$cache->get($key);
        $attempts++;

        if ($attempts > $this->maxAttempts) {
            $retryAfter = $this->windowSeconds;

            return HttpResponse::json([
                'error' => 'Too many requests. Try again in ' . $retryAfter . ' seconds.',
            ], 429)->withHeader('Retry-After', (string)$retryAfter);
        }

        $cache->set($key, $attempts, $this->windowSeconds);
        return $next($request);
    }
}
