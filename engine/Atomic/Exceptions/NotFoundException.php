<?php
declare(strict_types=1);

namespace Engine\Atomic\Exceptions;
if (!defined('ATOMIC_START')) exit;

class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Resource not found', int $code = 404, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}