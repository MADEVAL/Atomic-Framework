<?php
declare(strict_types=1);

namespace Engine\Atomic\Security;
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Cache\Interfaces\CacheStoreInterface;

final class AccountLockout
{
    private const KEY_PREFIX = 'account_lockout:';

    public function __construct(
        private readonly CacheStoreInterface $cache,
        private readonly int $maxAttempts = 5,
        private readonly int $lockoutSeconds = 900,
        private readonly int $windowSeconds = 300,
    ) {}

    public function recordFailedAttempt(string $identifier): void
    {
        $attempts = $this->getAttempts($identifier);
        $attempts['count'] = ($attempts['count'] ?? 0) + 1;

        if ($attempts['count'] === 1) {
            $attempts['first_at'] = time();
        }

        $this->cache->set($this->key($identifier), $attempts, $this->windowSeconds);

        if ($attempts['count'] >= $this->maxAttempts) {
            $lockData = ['locked_until' => time() + $this->lockoutSeconds];
            $this->cache->set($this->lockKey($identifier), $lockData, $this->lockoutSeconds);
        }
    }

    public function isLocked(string $identifier): bool
    {
        $lock = $this->cache->get($this->lockKey($identifier));
        if ($lock === null) {
            return false;
        }

        if (time() > ($lock['locked_until'] ?? 0)) {
            $this->cache->clear($this->lockKey($identifier));
            return false;
        }

        return true;
    }

    public function remainingAttempts(string $identifier): int
    {
        if ($this->isLocked($identifier)) {
            return 0;
        }

        $attempts = $this->getAttempts($identifier);
        return $this->maxAttempts - ($attempts['count'] ?? 0);
    }

    public function lockoutRemaining(string $identifier): int
    {
        $lock = $this->cache->get($this->lockKey($identifier));
        if ($lock === null) {
            return 0;
        }

        $remaining = ($lock['locked_until'] ?? 0) - time();
        return max(0, $remaining);
    }

    public function reset(string $identifier): void
    {
        $this->cache->clear($this->key($identifier));
        $this->cache->clear($this->lockKey($identifier));
    }

    private function getAttempts(string $identifier): array
    {
        $val = null;
        $this->cache->exists($this->key($identifier), $val);
        return $val ?? [];
    }

    private function key(string $identifier): string
    {
        return self::KEY_PREFIX . 'attempts:' . $identifier;
    }

    private function lockKey(string $identifier): string
    {
        return self::KEY_PREFIX . 'lock:' . $identifier;
    }
}