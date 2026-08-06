<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth\Adapters;

if (!defined('ATOMIC_START')) exit;

class SystemClockAdapter
{
    public function now(): int
    {
        return time();
    }
}
