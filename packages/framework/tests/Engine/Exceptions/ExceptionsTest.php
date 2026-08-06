<?php
declare(strict_types=1);

namespace Tests\Engine\Exceptions;

use Engine\Atomic\Exceptions\AtomicException;
use Engine\Atomic\Exceptions\AuthenticationException;
use Engine\Atomic\Exceptions\ConfigurationException;
use Engine\Atomic\Exceptions\FileProcessingException;
use Engine\Atomic\Exceptions\ImportException;
use Engine\Atomic\Exceptions\InsufficientStockException;
use Engine\Atomic\Exceptions\NotFoundException;
use Engine\Atomic\Exceptions\PaymentException;
use Engine\Atomic\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

class ExceptionsTest extends TestCase
{
    public function test_atomic_exception_extends_runtime(): void
    {
        $e = new AtomicException('test');
        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertSame('test', $e->getMessage());
    }

    public function test_authentication_exception(): void
    {
        $e = new AuthenticationException();
        $this->assertInstanceOf(AtomicException::class, $e);
        $this->assertSame('Authentication failed', $e->getMessage());
        $this->assertSame(401, $e->getCode());
    }

    public function test_authentication_exception_custom_message(): void
    {
        $e = new AuthenticationException('Token expired', 403);
        $this->assertSame('Token expired', $e->getMessage());
        $this->assertSame(403, $e->getCode());
    }

    public function test_validation_exception(): void
    {
        $e = new ValidationException('Field invalid');
        $this->assertInstanceOf(AtomicException::class, $e);
        $this->assertSame('Field invalid', $e->getMessage());
    }

    public function test_configuration_exception(): void
    {
        $e = new ConfigurationException('Missing key');
        $this->assertInstanceOf(AtomicException::class, $e);
    }

    public function test_file_processing_exception(): void
    {
        $e = new FileProcessingException('Cannot read file');
        $this->assertInstanceOf(AtomicException::class, $e);
    }

    public function test_import_exception(): void
    {
        $e = new ImportException('Import failed');
        $this->assertInstanceOf(AtomicException::class, $e);
    }

    public function test_insufficient_stock_exception(): void
    {
        $e = new InsufficientStockException('Out of stock');
        $this->assertInstanceOf(AtomicException::class, $e);
    }

    public function test_not_found_exception(): void
    {
        $e = new NotFoundException('Not found');
        $this->assertInstanceOf(AtomicException::class, $e);
    }

    public function test_payment_exception(): void
    {
        $e = new PaymentException('Payment declined');
        $this->assertInstanceOf(AtomicException::class, $e);
    }

    public function test_exception_chaining(): void
    {
        $prev = new \Exception('Original error');
        $e = new AuthenticationException('Chain test', 401, $prev);
        $this->assertSame($prev, $e->getPrevious());
    }

    public function test_all_exceptions_are_throwable(): void
    {
        $classes = [
            AtomicException::class,
            AuthenticationException::class,
            ConfigurationException::class,
            FileProcessingException::class,
            ImportException::class,
            InsufficientStockException::class,
            NotFoundException::class,
            PaymentException::class,
            ValidationException::class,
        ];

        foreach ($classes as $class) {
            $e = new $class('test');
            $this->assertInstanceOf(\Throwable::class, $e, "$class should be Throwable");
        }
    }
}
