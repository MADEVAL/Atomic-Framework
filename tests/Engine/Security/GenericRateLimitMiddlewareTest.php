<?php

declare(strict_types=1);

namespace Tests\Engine\Security;

use Engine\Atomic\Core\Middleware\MiddlewareInterface;
use Engine\Atomic\Http\Response;
use Engine\Atomic\RateLimit\RateLimiter;
use Engine\Atomic\RateLimit\RateLimitResult;
use Engine\Atomic\Security\Middleware\GenericRateLimitMiddleware;
use PHPUnit\Framework\TestCase;

final class GenericRateLimitMiddlewareTest extends TestCase
{
    public function test_allows_within_limit(): void
    {
        $mw = new GenericRateLimitMiddleware(
            new RateLimiter(new GenericRateLimitTest_Store(0)),
            5,
            60,
        );

        $response = $mw->process(new GenericRateLimitTest_Request, fn() => Response::text('ok'));

        $this->assertSame(200, $response->status());
        $this->assertSame('ok', $response->body());
    }

    public function test_blocks_when_exceeded(): void
    {
        $mw = new GenericRateLimitMiddleware(
            new RateLimiter(new GenericRateLimitTest_Store(10)),
            5,
            60,
        );

        $response = $mw->process(new GenericRateLimitTest_Request, fn() => Response::text('ok'));

        $this->assertSame(429, $response->status());
    }

    public function test_includes_retry_after_header(): void
    {
        $mw = new GenericRateLimitMiddleware(
            new RateLimiter(new GenericRateLimitTest_Store(10)),
            5,
            60,
        );

        $response = $mw->process(new GenericRateLimitTest_Request, fn() => Response::text('ok'));

        $this->assertNotNull($response->header('Retry-After'));
    }

    public function test_adds_rate_limit_headers(): void
    {
        $mw = new GenericRateLimitMiddleware(
            new RateLimiter(new GenericRateLimitTest_Store(2)),
            10,
            120,
        );

        $response = $mw->process(new GenericRateLimitTest_Request, fn() => Response::text('ok'));

        $this->assertNotNull($response->header('X-RateLimit-Limit'));
        $this->assertNotNull($response->header('X-RateLimit-Remaining'));
    }

    public function test_implements_middleware_interface(): void
    {
        $this->assertInstanceOf(
            MiddlewareInterface::class,
            new GenericRateLimitMiddleware(new RateLimiter(new GenericRateLimitTest_Store(0)), 5, 60),
        );
    }
}

final class GenericRateLimitTest_Request
{
    public function ip(): string { return '192.168.1.1'; }
}

final class GenericRateLimitTest_Store implements \Engine\Atomic\RateLimit\RateLimitStoreInterface
{
    public function __construct(private int $count) {}
    public function hit(string $key, int $limit, int $ttl): bool { $this->count++; return $this->count <= $limit; }
    public function increment(string $key, int $amount, int $ttl): int { $this->count += $amount; return $this->count; }
    public function decrement(string $key, int $amount): int { $this->count -= $amount; return $this->count; }
    public function exists(string $key): bool { return $this->count > 0; }
    public function clear(string $key): void { $this->count = 0; }
    public function get(string $key): int { return $this->count; }
    public function ttl(string $key): int { return 60; }
    public function sliding_hit(string $key, int $limit, int $window): bool { return $this->count <= $limit; }
    public function reserve(string $quota_key, string $reservation_key, int $amount, int $ttl): bool { return true; }
    public function settle(string $quota_key, string $reservation_key, int $actual): int { return $actual; }
    public function release(string $quota_key, string $reservation_key): void {}
}
