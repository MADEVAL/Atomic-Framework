<?php

declare(strict_types=1);

namespace Tests\Engine\Exceptions;

use Engine\Atomic\Exceptions\HttpException;
use Engine\Atomic\Exceptions\NotFoundException;
use Engine\Atomic\Exceptions\ForbiddenException;
use Engine\Atomic\Exceptions\UnauthorizedException;
use Engine\Atomic\Exceptions\TooManyRequestsException;
use Engine\Atomic\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class ExceptionHierarchyTest extends TestCase
{
    public function test_http_exception_stores_status_code(): void
    {
        $e = new HttpException('Error', 418);

        $this->assertSame(418, $e->statusCode());
        $this->assertSame('Error', $e->getMessage());
    }

    public function test_http_exception_default_status_is_500(): void
    {
        $e = new HttpException();

        $this->assertSame(500, $e->statusCode());
    }

    public function test_not_found_has_404(): void
    {
        $e = new NotFoundException('Page not found');

        $this->assertSame(404, $e->statusCode());
        $this->assertInstanceOf(HttpException::class, $e);
    }

    public function test_forbidden_has_403(): void
    {
        $e = new ForbiddenException();

        $this->assertSame(403, $e->statusCode());
    }

    public function test_unauthorized_has_401(): void
    {
        $e = new UnauthorizedException();

        $this->assertSame(401, $e->statusCode());
    }

    public function test_too_many_requests_has_429(): void
    {
        $e = new TooManyRequestsException('Rate limit exceeded');

        $this->assertSame(429, $e->statusCode());
    }

    public function test_validation_has_422(): void
    {
        $e = new ValidationException();

        $this->assertSame(422, $e->statusCode());
    }

    public function test_http_exception_is_runtime(): void
    {
        $e = new NotFoundException();
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }
}
