<?php

declare(strict_types=1);

namespace Tests\Engine\Security;

use Engine\Atomic\Core\Middleware\MiddlewareInterface;
use Engine\Atomic\Http\Response;
use Engine\Atomic\Security\Middleware\SecurityHeadersMiddleware;
use PHPUnit\Framework\TestCase;

final class SecurityHeadersMiddlewareTest extends TestCase
{
    public function test_adds_default_security_headers(): void
    {
        $mw = new SecurityHeadersMiddleware();
        $base = Response::html('<h1>OK</h1>');

        /** @var Response $response */
        $response = $mw->process(null, fn() => $base);

        $this->assertSame('nosniff', $response->header('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->header('X-Frame-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->header('Referrer-Policy'));
    }

    public function test_preserves_existing_headers(): void
    {
        $mw = new SecurityHeadersMiddleware();
        $base = Response::html('body', 200, ['X-Custom' => 'my-value']);

        /** @var Response $response */
        $response = $mw->process(null, fn() => $base);

        $this->assertSame('my-value', $response->header('X-Custom'));
        $this->assertSame('nosniff', $response->header('X-Content-Type-Options'));
    }

    public function test_implements_middleware_interface(): void
    {
        $this->assertInstanceOf(MiddlewareInterface::class, new SecurityHeadersMiddleware());
    }
}
