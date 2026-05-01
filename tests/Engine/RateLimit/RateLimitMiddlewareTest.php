<?php
declare(strict_types=1);

namespace Tests\Engine\RateLimit;

use Engine\Atomic\RateLimit\Middleware\RateLimitMiddleware;
use Engine\Atomic\RateLimit\RateLimitStoreInterface;
use Engine\Atomic\RateLimit\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimitMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        $this->reset_configured_store();
        $atomic = \Base::instance();
        $atomic->clear(RateLimiter::CONFIG_ROOT);
        $atomic->set('SESSION.user.id', null);
        $atomic->set('SESSION.user_id', null);
        $atomic->set('PATTERN', '/');
        $atomic->set('IP', '127.0.0.1');
        unset($_SERVER['REMOTE_ADDR']);
    }

    protected function tearDown(): void
    {
        $this->reset_configured_store();
    }

    public function test_fixed_policy_uses_ip_key_and_blocks_after_limit(): void
    {
        $store = new MiddlewareRateLimitStore();
        $atomic = \Base::instance();
        $atomic->set('PATTERN', '/api/items');
        $atomic->set('IP', '203.0.113.10');
        $atomic->set('RATE_LIMITER.policies.api', [
            'strategy' => RateLimiter::STRATEGY_FIXED,
            'key' => RateLimitMiddleware::KEY_IP,
            'limit' => 1,
            'window' => 60,
        ]);

        $middleware = new RateLimitMiddleware('api');

        $this->assertTrue($this->check($middleware, new RateLimiter($store), $atomic)->allowed);
        $denied = $this->check($middleware, new RateLimiter($store), $atomic);

        $this->assertFalse($denied->allowed);
        $this->assertSame(0, $denied->remaining);
        $this->assertSame(2, $store->get('api:api/items:203.0.113.10'));
    }

    public function test_user_key_prefers_nested_session_user_id_then_legacy_session_user_id(): void
    {
        $store = new MiddlewareRateLimitStore();
        $atomic = \Base::instance();
        $atomic->set('PATTERN', '/account');
        $atomic->set('SESSION.user.id', 42);
        $atomic->set('SESSION.user_id', 99);
        $atomic->set('RATE_LIMITER.policies.user_policy', [
            'strategy' => RateLimiter::STRATEGY_FIXED,
            'key' => RateLimitMiddleware::KEY_USER,
            'limit' => 10,
            'window' => 60,
        ]);

        $this->check(new RateLimitMiddleware('user_policy'), new RateLimiter($store), $atomic);
        $this->assertSame(1, $store->get('user_policy:account:42'));

        $atomic->set('SESSION.user.id', null);
        $this->check(new RateLimitMiddleware('user_policy'), new RateLimiter($store), $atomic);
        $this->assertSame(1, $store->get('user_policy:account:99'));
    }

    public function test_route_key_and_root_path_are_stable(): void
    {
        $store = new MiddlewareRateLimitStore();
        $atomic = \Base::instance();
        $atomic->set('PATTERN', '/');
        $atomic->set('RATE_LIMITER.policies.route_policy', [
            'strategy' => RateLimiter::STRATEGY_FIXED,
            'key' => RateLimitMiddleware::KEY_ROUTE,
            'limit' => 10,
            'window' => 60,
        ]);

        $this->check(new RateLimitMiddleware('route_policy'), new RateLimiter($store), $atomic);

        $this->assertSame(1, $store->get('route_policy:root:root'));
    }

    public function test_default_policy_uses_configured_fixed_ip_limit_and_window(): void
    {
        $store = new MiddlewareRateLimitStore();
        $atomic = \Base::instance();
        $atomic->set('PATTERN', '/defaulted');
        $atomic->set('IP', '198.51.100.5');
        $atomic->set('RATE_LIMITER.policies.default', [
            'strategy' => RateLimiter::STRATEGY_FIXED,
            'key' => RateLimitMiddleware::KEY_IP,
            'limit' => 60,
            'window' => 60,
        ]);

        $result = $this->check(new RateLimitMiddleware(), new RateLimiter($store), $atomic);

        $this->assertTrue($result->allowed);
        $this->assertSame(60, $result->limit);
        $this->assertSame(59, $result->remaining);
        $this->assertSame(60, $result->retry_after);
        $this->assertSame(60, $store->last_ttl);
        $this->assertSame(1, $store->get('default:defaulted:198.51.100.5'));
    }

    public function test_window_config_is_used_for_ttl(): void
    {
        $store = new MiddlewareRateLimitStore();
        $atomic = \Base::instance();
        $atomic->set('PATTERN', '/window-ttl');
        $atomic->set('RATE_LIMITER.policies.windowed', [
            'strategy' => RateLimiter::STRATEGY_FIXED,
            'key' => RateLimitMiddleware::KEY_IP,
            'limit' => 3,
            'window' => 25,
        ]);

        $result = $this->check(new RateLimitMiddleware('windowed'), new RateLimiter($store), $atomic);

        $this->assertTrue($result->allowed);
        $this->assertSame(25, $store->last_ttl);
        $this->assertSame(25, $result->retry_after);
    }

    public function test_policy_dispatches_sliding_cooldown_and_concurrency_strategies(): void
    {
        $store = new MiddlewareRateLimitStore();
        $atomic = \Base::instance();
        $atomic->set('PATTERN', '/dispatch');
        $atomic->set('RATE_LIMITER.policies.sliding', [
            'strategy' => RateLimiter::STRATEGY_SLIDING,
            'key' => RateLimitMiddleware::KEY_IP,
            'limit' => 1,
            'window' => 20,
        ]);
        $atomic->set('RATE_LIMITER.policies.cooldown', [
            'strategy' => RateLimiter::STRATEGY_COOLDOWN,
            'key' => RateLimitMiddleware::KEY_IP,
            'limit' => 1,
            'window' => 30,
        ]);
        $atomic->set('RATE_LIMITER.policies.concurrent', [
            'strategy' => RateLimiter::STRATEGY_CONCURRENCY,
            'key' => RateLimitMiddleware::KEY_IP,
            'limit' => 2,
            'window' => 40,
        ]);

        $this->check(new RateLimitMiddleware('sliding'), new RateLimiter($store), $atomic);
        $this->check(new RateLimitMiddleware('cooldown'), new RateLimiter($store), $atomic);
        $this->check(new RateLimitMiddleware('concurrent'), new RateLimiter($store), $atomic);

        $this->assertSame(['sliding:dispatch:127.0.0.1'], $store->sliding_keys);
        $this->assertSame(['cooldown:dispatch:127.0.0.1', 'concurrent:dispatch:127.0.0.1'], $store->increment_keys);
        $this->assertSame(40, $store->last_ttl);
    }

    public function test_handle_fails_open_by_default_when_store_throws(): void
    {
        $this->set_configured_store(new ThrowingRateLimitStore());
        $atomic = \Base::instance();
        $atomic->set('RATE_LIMITER.fail', RateLimiter::FAIL_OPEN);
        $atomic->set('RATE_LIMITER.policies.default', [
            'strategy' => RateLimiter::STRATEGY_FIXED,
            'key' => RateLimitMiddleware::KEY_IP,
            'limit' => 1,
            'window' => 60,
        ]);

        $this->assertTrue((new RateLimitMiddleware())->handle($atomic));
    }

    public function test_handle_can_fail_closed_when_store_throws(): void
    {
        $this->set_configured_store(new ThrowingRateLimitStore());
        $atomic = \Base::instance();
        $atomic->set('RATE_LIMITER.fail', 'closed');
        $atomic->set('RATE_LIMITER.policies.default', [
            'strategy' => RateLimiter::STRATEGY_FIXED,
            'key' => RateLimitMiddleware::KEY_IP,
            'limit' => 1,
            'window' => 60,
        ]);

        $this->assertFalse((new RateLimitMiddleware())->handle($atomic));
    }

    public function test_unknown_key_source_is_rejected(): void
    {
        $store = new MiddlewareRateLimitStore();
        $atomic = \Base::instance();
        $atomic->set('RATE_LIMITER.policies.bad', [
            'strategy' => RateLimiter::STRATEGY_FIXED,
            'key' => 'credential',
            'limit' => 1,
            'window' => 60,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->check(new RateLimitMiddleware('bad'), new RateLimiter($store), $atomic);
    }

    private function check(RateLimitMiddleware $middleware, RateLimiter $limiter, \Base $atomic): \Engine\Atomic\RateLimit\RateLimitResult
    {
        $method = new \ReflectionMethod(RateLimitMiddleware::class, 'check');

        return $method->invoke($middleware, $limiter, $atomic);
    }

    private function set_configured_store(?RateLimitStoreInterface $store): void
    {
        $property = new \ReflectionProperty(RateLimiter::class, 'configured_store');
        $property->setValue(null, $store);
    }

    private function reset_configured_store(): void
    {
        $this->set_configured_store(null);
    }
}

final class MiddlewareRateLimitStore implements RateLimitStoreInterface
{
    /** @var array<string, int> */
    private array $values = [];
    /** @var list<string> */
    public array $increment_keys = [];
    /** @var list<string> */
    public array $sliding_keys = [];
    public int $last_ttl = 0;

    public function hit(string $key, int $limit, int $ttl): bool
    {
        return $this->increment($key, 1, $ttl) <= $limit;
    }

    public function increment(string $key, int $amount, int $ttl): int
    {
        $this->increment_keys[] = $key;
        $this->last_ttl = $ttl;
        $this->values[$key] = ($this->values[$key] ?? 0) + $amount;

        return $this->values[$key];
    }

    public function decrement(string $key, int $amount): int
    {
        $this->values[$key] = max(0, ($this->values[$key] ?? 0) - $amount);

        return $this->values[$key];
    }

    public function exists(string $key): bool
    {
        return isset($this->values[$key]);
    }

    public function clear(string $key): void
    {
        unset($this->values[$key]);
    }

    public function get(string $key): int
    {
        return (int)($this->values[$key] ?? 0);
    }

    public function ttl(string $key): int
    {
        return $this->last_ttl;
    }

    public function sliding_hit(string $key, int $limit, int $window): bool
    {
        $this->sliding_keys[] = $key;
        $this->last_ttl = $window;
        $this->values[$key] = ($this->values[$key] ?? 0) + 1;

        return $this->values[$key] <= $limit;
    }

    public function reserve(string $quota_key, string $reservation_key, int $amount, int $ttl): bool
    {
        return true;
    }

    public function settle(string $quota_key, string $reservation_key, int $actual): int
    {
        return 0;
    }

    public function release(string $quota_key, string $reservation_key): void {}
}

final class ThrowingRateLimitStore implements RateLimitStoreInterface
{
    public function hit(string $key, int $limit, int $ttl): bool { throw new \RuntimeException('store failed'); }
    public function increment(string $key, int $amount, int $ttl): int { throw new \RuntimeException('store failed'); }
    public function decrement(string $key, int $amount): int { throw new \RuntimeException('store failed'); }
    public function exists(string $key): bool { throw new \RuntimeException('store failed'); }
    public function clear(string $key): void { throw new \RuntimeException('store failed'); }
    public function get(string $key): int { throw new \RuntimeException('store failed'); }
    public function ttl(string $key): int { throw new \RuntimeException('store failed'); }
    public function sliding_hit(string $key, int $limit, int $window): bool { throw new \RuntimeException('store failed'); }
    public function reserve(string $quota_key, string $reservation_key, int $amount, int $ttl): bool { throw new \RuntimeException('store failed'); }
    public function settle(string $quota_key, string $reservation_key, int $actual): int { throw new \RuntimeException('store failed'); }
    public function release(string $quota_key, string $reservation_key): void { throw new \RuntimeException('store failed'); }
}
