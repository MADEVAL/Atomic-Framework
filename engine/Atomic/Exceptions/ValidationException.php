<?php
declare(strict_types=1);

namespace Engine\Atomic\Exceptions;
if (!defined('ATOMIC_START')) exit;

class ValidationException extends HttpException
{
    private array $errors;

    public function __construct(
        string $message = 'Validation failed',
        array $errors = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 422, $previous);
        $this->errors = $errors;
    }

    /** @return array<string, string[]> */
    public function errors(): array
    {
        return $this->errors;
    }
}