<?php

declare(strict_types=1);

namespace Tests\Engine\Security;

use Engine\Atomic\Security\AccountLockout;
use PHPUnit\Framework\TestCase;
use Engine\Atomic\Cache\Interfaces\CacheStoreInterface;

final class AccountLockoutTest extends TestCase
{
    private CacheStoreInterface $cache;
    private AccountLockout $lockout;

    protected function setUp(): void
    {
        $this->cache = new class implements CacheStoreInterface {
            private array $store = [];
            public function exists(string $key, mixed &$val = null): array|false { $val = $this->store[$key] ?? null; return isset($this->store[$key]) ? ['exists' => true] : false; }
            public function set(string $key, mixed $value, int $ttl = 0): bool { $this->store[$key] = $value; return true; }
            public function get(string $key): mixed { return $this->store[$key] ?? null; }
            public function clear(string $key): bool { unset($this->store[$key]); return true; }
            public function reset(): bool { $this->store = []; return true; }
            public function flush_local_cache(): void {}
        };

        $this->lockout = new AccountLockout($this->cache, 5, 900, 300);
    }

    public function test_initial_state_not_locked(): void
    {
        $this->assertFalse($this->lockout->isLocked('user@test.com'));
    }

    public function test_remaining_attempts_starts_at_max(): void
    {
        $this->assertSame(5, $this->lockout->remainingAttempts('user@test.com'));
    }

    public function test_record_failed_attempt_decrements_remaining(): void
    {
        $this->lockout->recordFailedAttempt('user@test.com');
        $this->assertSame(4, $this->lockout->remainingAttempts('user@test.com'));
    }

    public function test_max_failed_attempts_locks_account(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->lockout->recordFailedAttempt('user@test.com');
        }

        $this->assertTrue($this->lockout->isLocked('user@test.com'));
        $this->assertSame(0, $this->lockout->remainingAttempts('user@test.com'));
    }

    public function test_lockout_remaining_returns_positive_for_locked(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->lockout->recordFailedAttempt('user@test.com');
        }

        $remaining = $this->lockout->lockoutRemaining('user@test.com');
        $this->assertGreaterThan(0, $remaining);
        $this->assertLessThanOrEqual(900, $remaining);
    }

    public function test_reset_clears_lockout(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->lockout->recordFailedAttempt('user@test.com');
        }
        $this->assertTrue($this->lockout->isLocked('user@test.com'));

        $this->lockout->reset('user@test.com');

        $this->assertFalse($this->lockout->isLocked('user@test.com'));
        $this->assertSame(5, $this->lockout->remainingAttempts('user@test.com'));
    }

    public function test_different_users_independent(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->lockout->recordFailedAttempt('user-a@test.com');
        }

        $this->assertTrue($this->lockout->isLocked('user-a@test.com'));
        $this->assertFalse($this->lockout->isLocked('user-b@test.com'));
        $this->assertSame(5, $this->lockout->remainingAttempts('user-b@test.com'));
    }

    public function test_successful_login_should_reset(): void
    {
        $this->lockout->recordFailedAttempt('user@test.com');
        $this->lockout->recordFailedAttempt('user@test.com');
        $this->assertSame(3, $this->lockout->remainingAttempts('user@test.com'));

        $this->lockout->reset('user@test.com');
        $this->assertSame(5, $this->lockout->remainingAttempts('user@test.com'));
    }

    public function test_lockout_remaining_returns_zero_for_not_locked(): void
    {
        $this->assertSame(0, $this->lockout->lockoutRemaining('user@test.com'));
    }
}
