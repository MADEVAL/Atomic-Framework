<?php
declare(strict_types=1);
namespace Engine\Atomic\Scheduler;

if (!defined('ATOMIC_START')) exit;

/** Thrown when a scheduled task is interrupted at its wall-clock budget. */
class TaskTimeoutException extends \RuntimeException
{
    public function __construct(int $seconds)
    {
        parent::__construct("Scheduled task exceeded its {$seconds}s wall-clock budget.");
    }
}
