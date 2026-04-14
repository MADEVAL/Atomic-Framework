<?php
declare(strict_types=1);
namespace Engine\Atomic\Enums;

if (!defined('ATOMIC_START')) exit;

enum LogChannel: string
{
    case ERROR         = 'error';
    case AUTH          = 'auth';
    case QUEUE_WORKER  = 'queue_worker';
    case QUEUE_MONITOR = 'queue_monitor';
}
