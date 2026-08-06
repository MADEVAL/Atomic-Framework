<?php

declare(strict_types=1);

namespace Tests\Engine\Core\Middleware;

use Engine\Atomic\Core\Middleware\MiddlewareInterface;
use Engine\Atomic\Http\Response;
use PHPUnit\Framework\TestCase;

final class MiddlewareInterfaceTest extends TestCase
{
    public function test_middleware_chains_to_next(): void
    {
        $m1 = new MiddlewareTest_AddHeaderMiddleware('X-One', '1');
        $m2 = new MiddlewareTest_AddHeaderMiddleware('X-Two', '2');

        $base = Response::text('hello');

        $pipeline = function ($req) use ($base) {
            return $base;
        };

        $response = $m1->process(null, function ($req) use ($m2, $pipeline) {
            return $m2->process($req, $pipeline);
        });

        $this->assertSame('1', $response->header('X-One'));
        $this->assertSame('2', $response->header('X-Two'));
        $this->assertSame('hello', $response->body());
    }

    public function test_middleware_can_short_circuit(): void
    {
        $blocker = new MiddlewareTest_BlockerMiddleware();

        $response = $blocker->process(null, function () {
            $this->fail('Next should not be called');
        });

        $this->assertSame(401, $response->status());
    }

    public function test_middleware_can_modify_response(): void
    {
        $wrapper = new MiddlewareTest_WrapperMiddleware('wrapped:');

        $response = $wrapper->process(null, function () {
            return Response::text('hello');
        });

        $this->assertStringContainsString('wrapped:', $response->body());
        $this->assertStringContainsString('hello', $response->body());
    }

    public function test_interface_has_process_method(): void
    {
        $ref = new \ReflectionClass(MiddlewareInterface::class);
        $method = $ref->getMethod('process');

        $this->assertSame('process', $method->getName());
        $params = $method->getParameters();
        $this->assertCount(2, $params);
    }
}

// ── Test middleware ──

final class MiddlewareTest_AddHeaderMiddleware implements MiddlewareInterface
{
    public function __construct(private string $name, private string $value) {}

    public function handle(\Base $atomic): bool { return true; }

    public function process($request, callable $next): Response
    {
        /** @var Response $response */
        $response = $next($request);
        return $response->withHeader($this->name, $this->value);
    }
}

final class MiddlewareTest_BlockerMiddleware implements MiddlewareInterface
{
    public function handle(\Base $atomic): bool { return false; }
    public function process($request, callable $next): Response
    {
        return Response::json(['error' => 'Unauthorized'], 401);
    }
}

final class MiddlewareTest_WrapperMiddleware implements MiddlewareInterface
{
    public function __construct(private string $prefix) {}

    public function handle(\Base $atomic): bool { return true; }

    public function process($request, callable $next): Response
    {
        /** @var Response $response */
        $response = $next($request);
        return $response->withBody($this->prefix . $response->body());
    }
}
