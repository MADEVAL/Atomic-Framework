<?php
declare(strict_types=1);

namespace Engine\Atomic\Exceptions;
if (!defined('ATOMIC_START')) exit;

class AuthenticationException extends AtomicException
{
    public function __construct(string $message = 'Authentication failed', int $code = 401, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}