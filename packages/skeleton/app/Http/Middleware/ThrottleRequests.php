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
        $key = 'throttle:' . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        $cache = \Cache::instance();
        $attempts = (int)$cache->get($key);
        $attempts++;

        if ($attempts > $this->maxAttempts) {
            $ttl = $cache->ttl($key);
            $retryAfter = $ttl > 0 ? $ttl : $this->windowSeconds;

            header('Retry-After: ' . $retryAfter);
            \Engine\Atomic\Core\App::instance()->send_json_error(
                'Too many requests. Try again in ' . $retryAfter . ' seconds.',
                429,
            );
            return false;
        }

        $cache->set($key, $attempts, $this->windowSeconds);
        return true;
    }

    public function process(mixed $request, callable $next): HttpResponse
    {
        $key = 'throttle:' . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        $cache = \Cache::instance();
        $attempts = (int)$cache->get($key);
        $attempts++;

        if ($attempts > $this->maxAttempts) {
            $ttl = $cache->ttl($key);
            $retryAfter = $ttl > 0 ? $ttl : $this->windowSeconds;

            return HttpResponse::json([
                'error' => 'Too many requests. Try again in ' . $retryAfter . ' seconds.',
            ], 429)->withHeader('Retry-After', (string)$retryAfter);
        }

        $cache->set($key, $attempts, $this->windowSeconds);
        return $next($request);
    }
}
