<?php
declare(strict_types=1);
namespace Engine\Atomic\Cache\Interfaces;

if (!defined('ATOMIC_START')) exit;

interface PrunableCacheStoreInterface
{
    public function prune(): bool;
}
