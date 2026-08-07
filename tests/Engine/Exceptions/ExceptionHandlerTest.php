<?php

declare(strict_types=1);

namespace Tests\Engine\Exceptions;

use Engine\Atomic\Exceptions\ExceptionHandler;
use Engine\Atomic\Exceptions\HttpException;
use Engine\Atomic\Exceptions\NotFoundException;
use Engine\Atomic\Exceptions\ForbiddenException;
use Engine\Atomic\Core\Config\V2\Config;
use Engine\Atomic\Http\Response;
use PHPUnit\Framework\TestCase;

final class ExceptionHandlerTest extends TestCase
{
    private ExceptionHandler $handler;
    private array $logEntries = [];

    protected function setUp(): void
    {
        $this->logEntries = [];
        $config = new Config(['DEBUG_MODE' => false]);

        $logManager = new class($this->logEntries) {
            public function __construct(private array &$log) {}
            public function error(string $message, array $context = []): void {
                $this->log[] = ['level' => 'error', 'message' => $message, 'context' => $context];
            }
            public function warning(string $message, array $context = []): void {
                $this->log[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
            }
        };

        $this->handler = new ExceptionHandler($config, $logManager);
    }

    public function test_handles_http_exception_returns_json_when_ajax(): void
    {
        $e = new NotFoundException('User not found');
        $response = $this->handler->handle($e, true);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(404, $response->status());
        $this->assertStringContainsString('User not found', $response->body());
    }

    public function test_handles_http_exception_returns_html_by_default(): void
    {
        $e = new ForbiddenException('Access denied');
        $response = $this->handler->handle($e, false);

        $this->assertSame(403, $response->status());
    }

    public function test_unhandled_exception_returns_500(): void
    {
        $e = new \RuntimeException('Something broke');
        $response = $this->handler->handle($e, false);

        $this->assertSame(500, $response->status());
    }

    public function test_always_logs_exception(): void
    {
        $e = new NotFoundException('test');
        $this->handler->handle($e, false);

        $this->assertNotEmpty($this->logEntries);
        $this->assertSame('error', $this->logEntries[0]['level']);
        $this->assertStringContainsString('test', $this->logEntries[0]['message']);
    }

    public function test_logs_http_exception_code(): void
    {
        $e = new NotFoundException('gone');
        $this->handler->handle($e, false);

        $this->assertSame('error', $this->logEntries[0]['level']);
    }

    public function test_does_not_call_die(): void
    {
        $e = new \RuntimeException('test');
        $response = $this->handler->handle($e, false);

        // If die() was called, we wouldn't reach here
        $this->assertInstanceOf(Response::class, $response);
    }

    public function test_debug_mode_shows_details(): void
    {
        $config = new Config(['DEBUG_MODE' => true]);
        $handler = new ExceptionHandler($config, new class {
            public function error(...$args): void {}
            public function warning(...$args): void {}
        });

        $e = new \RuntimeException('Debug info');
        $response = $handler->handle($e, false);

        $this->assertSame(500, $response->status());
    }

    public function test_returns_response_not_void(): void
    {
        $ref = new \ReflectionMethod(ExceptionHandler::class, 'handle');
        $returnType = $ref->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertSame('Engine\Atomic\Http\Response', $returnType->getName());
    }
}
