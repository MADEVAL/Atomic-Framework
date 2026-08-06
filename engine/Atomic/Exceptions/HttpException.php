<?php

declare(strict_types=1);

namespace Engine\Atomic\Exceptions;

class HttpException extends AtomicException
{
    private int $statusCode;

    public function __construct(
        string $message = '',
        int $statusCode = 500,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
        $this->statusCode = $statusCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}

class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'Forbidden', ?\Throwable $previous = null)
    {
        parent::__construct($message, 403, $previous);
    }
}

class UnauthorizedException extends HttpException
{
    public function __construct(string $message = 'Unauthorized', ?\Throwable $previous = null)
    {
        parent::__construct($message, 401, $previous);
    }
}

class TooManyRequestsException extends HttpException
{
    private int $retryAfter;

    public function __construct(
        string $message = 'Too Many Requests',
        int $retryAfter = 60,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 429, $previous);
        $this->retryAfter = $retryAfter;
    }

    public function retryAfterSeconds(): int
    {
        return $this->retryAfter;
    }
}

class ServiceUnavailableException extends HttpException
{
    public function __construct(string $message = 'Service Unavailable', ?\Throwable $previous = null)
    {
        parent::__construct($message, 503, $previous);
    }
}
