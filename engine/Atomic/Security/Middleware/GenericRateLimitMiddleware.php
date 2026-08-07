<?php

declare(strict_types=1);

namespace Engine\Atomic\Security\Middleware;

use Engine\Atomic\Core\Middleware\MiddlewareInterface;
use Engine\Atomic\Http\Response;
use Engine\Atomic\RateLimit\RateLimiter;

final class GenericRateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly int $maxAttempts = 60,
        private readonly int $windowSeconds = 60,
        private readonly string $keyPrefix = 'rate_limit:',
    ) {}

    public function handle(\Base $atomic): bool { return true; }

    public function process(mixed $request, callable $next): Response
    {
        $key = $this->keyPrefix . ($request->ip() ?? '127.0.0.1');

        $result = $this->limiter->acquire($key, $this->maxAttempts, $this->windowSeconds);

        $headers = [
            'X-RateLimit-Limit' => (string)$result->limit,
            'X-RateLimit-Remaining' => (string)$result->remaining,
        ];

        if (!$result->allowed) {
            $retryAfter = $result->retry_after > 0 ? $result->retry_after : $this->windowSeconds;
            $headers['Retry-After'] = (string)$retryAfter;

            return Response::json([
                'error' => 'Too many requests. Try again in ' . $retryAfter . ' seconds.',
            ], 429, $headers);
        }

        /** @var Response $response */
        $response = $next($request);

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, (string)$value);
        }

        return $response;
    }
}
