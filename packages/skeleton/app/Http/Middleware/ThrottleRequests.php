<?php
declare(strict_types=1);
namespace App\Http\Middleware;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Middleware\MiddlewareInterface;

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

        // Simple fixed-window check using F3 cache
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

    public function process(mixed $request, callable $next): \Engine\Atomic\Http\Response
    {
        throw new \RuntimeException('Not yet migrated to process() pattern');
    }
}
