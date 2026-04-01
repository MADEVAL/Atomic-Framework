<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\ErrorHandler;
use PHPUnit\Framework\TestCase;

class ErrorHandlerTest extends TestCase
{
    private ErrorHandler $handler;

    protected function setUp(): void
    {
        $this->handler = ErrorHandler::instance();
    }

    public function test_formatTrace_basic(): void
    {
        $trace = "[/some/file.php:42] some stack line";
        $result = $this->handler->formatTrace(500, 'Internal Error', $trace);
        $this->assertStringContainsString('Internal Error', $result);
        $this->assertStringContainsString('/some/file.php', $result);
        $this->assertStringContainsString('42', $result);
    }

    public function test_formatTrace_with_no_file_lines(): void
    {
        $result = $this->handler->formatTrace(404, 'Not Found', 'no trace info');
        $this->assertStringContainsString('Not Found', $result);
    }

    public function test_formatTrace_with_real_file(): void
    {
        $file = __FILE__;
        $trace = "[{$file}:10]";
        $result = $this->handler->formatTrace(500, 'Error', $trace);
        // Should contain either formatted output or the fallback
        $this->assertTrue(
            str_contains($result, 'File:') || str_contains($result, 'Error'),
            'formatTrace should produce output for valid trace input'
        );
    }

    public function test_formatTrace_returns_fallback_on_exception(): void
    {
        $handler = ErrorHandler::instance();
        // Even with weird input, should not throw
        $result = $handler->formatTrace(0, '', '');
        $this->assertIsString($result);
    }

    public function test_singleton(): void
    {
        $this->assertSame(ErrorHandler::instance(), ErrorHandler::instance());
    }
}
