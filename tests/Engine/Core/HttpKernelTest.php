<?php

declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\Container;
use Engine\Atomic\Core\HttpKernel;
use Engine\Atomic\Core\Middleware\MiddlewareInterface;
use Engine\Atomic\Http\Response;
use PHPUnit\Framework\TestCase;

final class HttpKernelTest extends TestCase
{
    private Container $container;
    private HttpKernel $kernel;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->kernel = new HttpKernel($this->container);
    }

    public function test_handle_with_no_middleware_returns_response(): void
    {
        $response = $this->kernel->handle(null);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->status());
    }

    public function test_middleware_can_modify_response(): void
    {
        $this->kernel->appendMiddleware(new HttpKernelTest_AddHeader('X-Test', 'hello'));

        $response = $this->kernel->handle(null);

        $this->assertSame('hello', $response->header('X-Test'));
    }

    public function test_multiple_middleware_chain(): void
    {
        $this->kernel->appendMiddleware(new HttpKernelTest_AddHeader('X-One', '1'));
        $this->kernel->appendMiddleware(new HttpKernelTest_AddHeader('X-Two', '2'));

        $response = $this->kernel->handle(null);

        $this->assertSame('1', $response->header('X-One'));
        $this->assertSame('2', $response->header('X-Two'));
    }

    public function test_prepend_adds_middleware_first(): void
    {
        $this->kernel->appendMiddleware(new HttpKernelTest_AddHeader('X-last', 'last'));
        $this->kernel->prependMiddleware(new HttpKernelTest_AddHeader('X-first', 'first'));

        $response = $this->kernel->handle(null);

        // Both headers should be set
        $this->assertSame('first', $response->header('X-first'));
        $this->assertSame('last', $response->header('X-last'));
    }

    public function test_short_circuit_stops_chain(): void
    {
        $this->kernel->appendMiddleware(new HttpKernelTest_Blocker());
        $this->kernel->appendMiddleware(new HttpKernelTest_AddHeader('X-Never', 'nope'));

        $response = $this->kernel->handle(null);

        $this->assertSame(403, $response->status());
        $this->assertNull($response->header('X-Never'));
    }
}

final class HttpKernelTest_AddHeader implements MiddlewareInterface
{
    public function __construct(private string $name, private string $value) {}
    public function handle(\Base $atomic): bool { return true; }

    public function process(mixed $request, callable $next): Response
    {
        /** @var Response $response */
        $response = $next($request);
        return $response->withHeader($this->name, $this->value);
    }
}

final class HttpKernelTest_Blocker implements MiddlewareInterface
{
    public function handle(\Base $atomic): bool { return false; }

    public function process(mixed $request, callable $next): Response
    {
        return Response::json(['error' => 'Forbidden'], 403);
    }
}
