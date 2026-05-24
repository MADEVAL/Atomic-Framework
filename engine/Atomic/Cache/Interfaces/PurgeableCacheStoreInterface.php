<?php
declare(strict_types=1);
namespace Engine\Atomic\Cache\Interfaces;

if (!defined('ATOMIC_START')) exit;

interface PurgeableCacheStoreInterface
{
    public function purge(): int;
}
