<?php
declare(strict_types=1);

namespace Engine\Atomic\Exceptions;

class NotFoundException extends AtomicException
{
    public function __construct(string $message = 'Resource not found', int $code = 404, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
