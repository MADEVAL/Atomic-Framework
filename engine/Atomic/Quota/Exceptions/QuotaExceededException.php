<?php
declare(strict_types=1);
namespace Engine\Atomic\Quota\Exceptions;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Quota\QuotaResult;

final class QuotaExceededException extends \RuntimeException
{
    public function __construct(public readonly QuotaResult $result, string $message = '')
    {
        parent::__construct($message !== '' ? $message : 'Quota denied: ' . $result->reason);
    }
}
