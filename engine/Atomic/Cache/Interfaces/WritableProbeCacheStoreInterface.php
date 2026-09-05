<?php
declare(strict_types=1);
namespace Engine\Atomic\Cache\Interfaces;

if (!defined('ATOMIC_START')) exit;

interface WritableProbeCacheStoreInterface extends CacheStoreInterface
{
    /**
     * Stat-level probe: whether the store can accept writes. Used by
     * CacheManager::health_check() instead of a mutating write+read+clear probe.
     */
    public function can_write(): bool;
}
