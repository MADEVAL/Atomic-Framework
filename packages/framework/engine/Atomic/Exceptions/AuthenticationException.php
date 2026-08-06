<?php
declare(strict_types=1);

namespace Engine\Atomic\Exceptions;

class AuthenticationException extends AtomicException
{
    public function __construct(string $message = 'Authentication failed', int $code = 401, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
