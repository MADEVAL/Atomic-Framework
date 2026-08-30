<?php
declare(strict_types=1);

namespace Tests\Engine\Quota;

use Engine\Atomic\Quota\Drivers\Redis as RedisQuotaStore;
use Engine\Atomic\Quota\Exceptions\QuotaExceededException;
use Engine\Atomic\Quota\QuotaLimiter;
use Engine\Atomic\Quota\QuotaPacingWindow;
use Engine\Atomic\Quota\QuotaResult;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestConfig;
use Tests\Support\Wait;

final class RedisQuotaStoreTest extends TestCase
{
    private const BALANCE = '{user:1}.balance';
    private const EPOCH = '{user:1}.epoch';
    private const LUA_RESERVE = __DIR__ . '/../../../engine/Atomic/Quota/Drivers/lua/reserve.lua';

    private ?\Redis $redis = null;
    private ?RedisQuotaStore $store = null;
    private string $prefix = '';

    protected function setUp(): void
    {
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('Redis extension is not installed.');
        }

        $config = TestConfig::redis();
        $host = (string)$config['host'];
        $port = (int)$config['port'];
        $password = (string)$config['password'];
        $db = (int)$config['db'];

        $redis = new \Redis();
        try {
            $connected = $redis->connect($host, $port, 0.2);
            if (!$connected) {
                $this->markTestSkipped('Redis server is not reachable.');
            }
            if ($password !== '') {
                $redis->auth($password);
            }
            $redis->select($db);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis server is not reachable: ' . $e->getMessage());
        }

        $this->redis = $redis;
        $this->prefix = 'atomic_test_quota_' . bin2hex(random_bytes(6)) . ':';
        $this->store = new RedisQuotaStore($this->redis, $this->prefix);
    }

    protected function tearDown(): void
    {
        QuotaLimiter::reset();
        \Base::instance()->clear('QUOTA');

        if ($this->redis instanceof \Redis && $this->prefix !== '') {
            $keys = $this->redis->keys($this->prefix . 'quota.*');
            if (is_array($keys) && $keys !== []) {
                $this->redis->del($keys);
            }
            $this->redis->close();
        }
    }

    public function test_set_quota_overwrites_the_balance_and_bumps_the_epoch(): void
    {
        $store = $this->store();

        $this->assertSame(100, $store->set_quota(self::BALANCE, self::EPOCH, 100, 60));
        $first = $store->get_string(self::EPOCH);
        $this->assertNotSame('', $first);
        $this->assertTrue($store->exists(self::EPOCH));

        $this->assertSame(40, $store->set_quota(self::BALANCE, self::EPOCH, 40, 60));
        $second = $store->get_string(self::EPOCH);
        $this->assertNotSame('', $second);
        $this->assertNotSame($first, $second);

        $this->assertGreaterThan(0, $store->ttl(self::BALANCE));
        // The epoch carries no TTL, so ttl() reports 0 for it.
        $this->assertSame(0, $store->ttl(self::EPOCH));
        $this->assertTrue($store->exists(self::EPOCH));
    }

    public function test_add_quota_keeps_the_period_of_an_existing_balance(): void
    {
        $store = $this->store();

        $this->assertSame(100, $store->add_quota(self::BALANCE, 100, 60));
        $this->assertLessThanOrEqual(60, $store->ttl(self::BALANCE));

        $this->assertSame(150, $store->add_quota(self::BALANCE, 50, 86400));
        $this->assertLessThanOrEqual(60, $store->ttl(self::BALANCE));
        $this->assertGreaterThan(0, $store->ttl(self::BALANCE));
    }

    public function test_reserve_charges_the_balance_and_writes_the_reservation_hash(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 100, 60);

        $result = $this->reserve($store, 'r1', 40, [$this->window('{user:1}.pacing._tier.60', 200, 60)]);

        $this->assertTrue($result->allowed);
        $this->assertSame(QuotaResult::REASON_OK, $result->reason);
        $this->assertSame(60, $result->balance);
        $this->assertNull($result->retry_after);
        $this->assertSame(60, $store->get(self::BALANCE));

        $record = $this->redis()->hGetAll($this->prefix . 'quota.{user:1}.reservation.r1');
        $this->assertSame('40', $record['cost']);
        $this->assertSame($this->prefix . 'quota.{user:1}.pacing._tier.60', $record['pacing_keys']);
        $this->assertSame($this->prefix . 'quota.{user:1}.pacing._tier.60.used', $record['used_keys']);
        $this->assertSame($store->get_string(self::EPOCH), $record['epoch']);
        $this->assertMatchesRegularExpression('/^r1:[0-9a-f]+:40$/', $record['member']);
        $this->assertSame(40, $this->used('{user:1}.pacing._tier.60'));
    }

    public function test_reserve_with_an_empty_pacing_list_is_balance_only(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 3, 60);

        $this->assertTrue($this->reserve($store, 'r1', 1, [])->allowed);
        $this->assertTrue($this->reserve($store, 'r2', 1, [])->allowed);
        $this->assertTrue($this->reserve($store, 'r3', 1, [])->allowed);

        $denied = $this->reserve($store, 'r4', 1, []);
        $this->assertFalse($denied->allowed);
        $this->assertSame(QuotaResult::REASON_INSUFFICIENT_BALANCE, $denied->reason);
    }

    public function test_an_insufficient_balance_reports_the_seconds_until_the_period_rolls(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 5, 120);

        $denied = $this->reserve($store, 'r1', 40, []);

        $this->assertFalse($denied->allowed);
        $this->assertSame(QuotaResult::REASON_INSUFFICIENT_BALANCE, $denied->reason);
        $this->assertSame(5, $denied->balance);
        $this->assertGreaterThan(0, $denied->retry_after);
        $this->assertLessThanOrEqual(120, $denied->retry_after);
        $this->assertSame(5, $store->get(self::BALANCE));
    }

    public function test_a_duplicate_reservation_id_is_refused_without_double_charging(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 100, 60);

        $this->assertTrue($this->reserve($store, 'r1', 40, [])->allowed);
        $duplicate = $this->reserve($store, 'r1', 20, []);

        $this->assertFalse($duplicate->allowed);
        $this->assertSame(QuotaResult::REASON_DUPLICATE_RESERVATION, $duplicate->reason);
        $this->assertNull($duplicate->retry_after);
        $this->assertSame(60, $store->get(self::BALANCE));
    }

    public function test_pacing_sums_costs_and_denies_with_an_exact_retry_after(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 1000, 600);
        $pacing = [$this->window('{user:1}.pacing._tier.60', 10, 60)];

        $this->assertTrue($this->reserve($store, 'r1', 6, $pacing)->allowed);
        $denied = $this->reserve($store, 'r2', 6, $pacing);

        $this->assertFalse($denied->allowed);
        $this->assertSame(QuotaResult::REASON_PACING, $denied->reason);
        $this->assertSame($this->prefix . 'quota.{user:1}.pacing._tier.60', $denied->limited_by);
        $this->assertGreaterThan(0, $denied->retry_after);
        $this->assertLessThanOrEqual(60, $denied->retry_after);
        $this->assertSame(994, $store->get(self::BALANCE));
        $this->assertSame(6, $this->used('{user:1}.pacing._tier.60'));
    }

    public function test_a_denied_pacing_window_leaves_the_balance_and_every_window_untouched(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 1000, 600);
        $pacing = [
            $this->window('{user:1}.pacing._tier.60', 100, 60),
            $this->window('{user:1}.pacing.spam_check.60', 1, 60),
        ];

        $this->assertTrue($this->reserve($store, 'r1', 1, $pacing)->allowed);
        $this->assertFalse($this->reserve($store, 'r2', 1, $pacing)->allowed);

        $this->assertSame(1, $this->zcard('{user:1}.pacing._tier.60'));
        $this->assertSame(1, $this->used('{user:1}.pacing._tier.60'));
        $this->assertSame(1, $this->used('{user:1}.pacing.spam_check.60'));
        $this->assertSame(999, $store->get(self::BALANCE));
    }

    public function test_a_pacing_window_expires_and_frees_room(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 1000, 600);
        $pacing = [$this->window('{user:1}.pacing._tier.1', 1, 1)];

        $this->assertTrue($this->reserve($store, 'r1', 1, $pacing)->allowed);
        $this->assertFalse($this->reserve($store, 'r2', 1, $pacing)->allowed);

        $this->assertTrue(Wait::until(fn (): bool => $this->reserve($store, 'r3', 1, $pacing)->allowed, 4));
    }

    public function test_a_settled_burst_cannot_drain_the_grant_inside_one_window(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 500, 600);
        $pacing = [$this->window('{user:1}.pacing._tier.2', 20, 2)];
        $deadline = microtime(true) + 1.2;
        $spent = 0;
        $denied = 0;
        $i = 0;

        while (microtime(true) < $deadline) {
            $id = 'burst' . $i++;
            $result = $this->reserve($store, $id, 10, $pacing);
            if ($result->allowed) {
                $store->quota_settle(self::BALANCE, '{user:1}.reservation.' . $id);
                $spent += 10;
            } else {
                $this->assertSame(QuotaResult::REASON_PACING, $result->reason);
                $denied++;
            }
            $this->assert_used_matches_zset('{user:1}.pacing._tier.2');
        }

        $this->assertGreaterThan(0, $denied);
        $this->assertSame(20, $spent);
        $this->assertSame(480, $store->get(self::BALANCE));
        $this->assertSame(20, $this->zset_weight('{user:1}.pacing._tier.2'));
    }

    public function test_a_rolled_window_admits_another_slice_not_the_rest_of_the_grant(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 500, 600);
        $pacing = [$this->window('{user:1}.pacing._tier.2', 20, 2)];

        $this->assertTrue($this->reserve($store, 'a', 10, $pacing)->allowed);
        $this->assertTrue($this->reserve($store, 'b', 10, $pacing)->allowed);
        $this->assertSame(QuotaResult::REASON_PACING, $this->reserve($store, 'c', 10, $pacing)->reason);
        $this->assert_used_matches_zset('{user:1}.pacing._tier.2');

        $this->assertTrue(Wait::until(fn (): bool => $this->reserve($store, 'd', 10, $pacing)->allowed, 6));
        $this->assertTrue($this->reserve($store, 'e', 10, $pacing)->allowed);
        $this->assertSame(460, $store->get(self::BALANCE));
        $this->assert_used_matches_zset('{user:1}.pacing._tier.2');

        $denied = $this->reserve($store, 'f', 10, $pacing);
        $this->assertFalse($denied->allowed);
        $this->assertSame(QuotaResult::REASON_PACING, $denied->reason);
        $this->assertSame(460, $store->get(self::BALANCE));
        $this->assert_used_matches_zset('{user:1}.pacing._tier.2');
    }

    public function test_settle_and_release_together_keep_used_equal_to_the_zset(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 500, 600);
        $pacing = [$this->window('{user:1}.pacing._tier.60', 20, 60)];

        $this->assertTrue($this->reserve($store, 'a', 10, $pacing)->allowed);
        $this->assertTrue($this->reserve($store, 'b', 6, $pacing)->allowed);
        $this->assertTrue($this->reserve($store, 'c', 4, $pacing)->allowed);
        $this->assert_used_matches_zset('{user:1}.pacing._tier.60');

        $store->quota_settle(self::BALANCE, '{user:1}.reservation.a');
        $this->assertSame(486, $store->quota_release(self::BALANCE, self::EPOCH, '{user:1}.reservation.b', 'b'));
        $this->assert_used_matches_zset('{user:1}.pacing._tier.60');
        $this->assertSame(14, $this->zset_weight('{user:1}.pacing._tier.60'));
        $this->assertSame(486, $store->get(self::BALANCE));

        $store->quota_release(self::BALANCE, self::EPOCH, '{user:1}.reservation.c', 'c');
        $this->assert_used_matches_zset('{user:1}.pacing._tier.60');
        $this->assertSame(10, $this->zset_weight('{user:1}.pacing._tier.60'));
        $this->assertSame(490, $store->get(self::BALANCE));
    }

    public function test_meter_cannot_dump_the_grant_inside_one_window(): void
    {
        $store = $this->store();
        $limiter = new QuotaLimiter($store);
        \Base::instance()->set('QUOTA', [
            'operations' => ['spam_check'],
            'quotas' => [
                'free' => [
                    'credits' => 500,
                    'period' => 600,
                    'reservation_ttl' => 300,
                    'pacing' => [['limit' => 20, 'window' => 2]],
                    'operations' => ['spam_check' => ['cost' => 1]],
                ],
            ],
        ]);
        $limiter->resolve_plan_using(static fn (): string => 'free');
        $limiter->resolve_scope_using(static fn (): string => 'user:1');
        $limiter->quota_set('user:1', 500, 600);

        $ok = 0;
        $paced = 0;
        for ($i = 0; $i < 40; $i++) {
            try {
                $limiter->meter(null, 'spam_check', static fn (): true => true);
                $ok++;
            } catch (QuotaExceededException $e) {
                $this->assertSame(QuotaResult::REASON_PACING, $e->result->reason);
                $paced++;
            }
            $this->assert_used_matches_zset('{user:1}.pacing._tier.2');
        }

        $this->assertSame(20, $ok);
        $this->assertSame(20, $paced);
        $this->assertSame(480, $limiter->quota_get('user:1'));
        $this->assertSame(20, $this->zset_weight('{user:1}.pacing._tier.2'));
    }

    public function test_settle_drops_the_record_and_leaves_the_charge_standing(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 100, 60);
        $this->reserve($store, 'r1', 40, [$this->window('{user:1}.pacing._tier.60', 200, 60)]);

        $this->assertSame(60, $store->quota_settle(self::BALANCE, '{user:1}.reservation.r1'));
        $this->assertSame(60, $store->get(self::BALANCE));
        $this->assertFalse($store->exists('{user:1}.reservation.r1'));
        $this->assertSame(1, $this->zcard('{user:1}.pacing._tier.60'));
        $this->assertSame(40, $this->used('{user:1}.pacing._tier.60'));
    }

    public function test_settle_on_a_missing_reservation_does_not_throw(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 25, 60);

        $this->assertSame(25, $store->quota_settle(self::BALANCE, '{user:1}.reservation.missing'));
        $this->assertSame(25, $store->get(self::BALANCE));
    }

    public function test_settle_does_not_throw_when_the_balance_expired_first(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 100, 1);
        $this->reserve($store, 'r1', 40, []);

        $this->assertTrue(Wait::until(fn (): bool => !$store->exists(self::BALANCE), 4));
        $this->assertSame(0, $store->quota_settle(self::BALANCE, '{user:1}.reservation.r1'));
    }

    public function test_an_expired_reservation_keeps_the_charge(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 100, 600);
        $this->reserve($store, 'r1', 40, [], 1);

        $this->assertTrue(Wait::until(fn (): bool => !$store->exists('{user:1}.reservation.r1'), 4));

        $this->assertSame(60, $store->quota_release(self::BALANCE, self::EPOCH, '{user:1}.reservation.r1', 'r1'));
        $this->assertSame(60, $store->get(self::BALANCE));
    }

    public function test_release_refunds_the_charge_and_frees_the_pacing_member(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 100, 60);
        $this->reserve($store, 'r1', 40, [$this->window('{user:1}.pacing._tier.60', 200, 60)]);

        $this->assertSame(100, $store->quota_release(self::BALANCE, self::EPOCH, '{user:1}.reservation.r1', 'r1'));
        $this->assertSame(100, $store->get(self::BALANCE));
        $this->assertSame(0, $this->zcard('{user:1}.pacing._tier.60'));
        $this->assertSame(0, $this->used('{user:1}.pacing._tier.60'));
    }

    public function test_release_on_a_missing_reservation_does_not_throw(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 25, 60);

        $this->assertSame(25, $store->quota_release(self::BALANCE, self::EPOCH, '{user:1}.reservation.missing', 'missing'));
        $this->assertSame(25, $store->get(self::BALANCE));
    }

    public function test_release_after_the_pacing_window_rolled_is_a_noop_on_that_window(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 100, 600);
        $this->reserve($store, 'r1', 40, [$this->window('{user:1}.pacing._tier.1', 100, 1)]);

        $this->assertTrue(Wait::until(fn (): bool => $this->zcard('{user:1}.pacing._tier.1') === 0, 4));

        $this->assertSame(100, $store->quota_release(self::BALANCE, self::EPOCH, '{user:1}.reservation.r1', 'r1'));
        $this->assertSame(0, $this->zcard('{user:1}.pacing._tier.1'));
        $this->assertSame(0, $this->used('{user:1}.pacing._tier.1'));
    }

    public function test_release_does_not_refund_across_a_renewal(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 100, 600);
        $this->reserve($store, 'r1', 40, []);
        $this->assertSame(60, $store->get(self::BALANCE));

        $store->set_quota(self::BALANCE, self::EPOCH, 100, 600);

        $this->assertSame(100, $store->quota_release(self::BALANCE, self::EPOCH, '{user:1}.reservation.r1', 'r1'));
        $this->assertSame(100, $store->get(self::BALANCE));
    }

    public function test_release_does_not_recreate_a_balance_that_already_expired(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 100, 1);
        $this->reserve($store, 'r1', 40, [], 600);

        $this->assertTrue(Wait::until(fn (): bool => !$store->exists(self::BALANCE), 4));

        $this->assertSame(0, $store->quota_release(self::BALANCE, self::EPOCH, '{user:1}.reservation.r1', 'r1'));
        $this->assertFalse($store->exists(self::BALANCE));
    }

    public function test_the_epoch_outlives_the_balance_period(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 100, 1);

        $this->assertTrue(Wait::until(fn (): bool => !$store->exists(self::BALANCE), 4));

        // The epoch must never expire. If it vanished, the next set_quota would
        // write a new uid, but a missing key must not be recreatable as the
        // same value or an old reservation refunds into a fresh balance.
        $this->assertTrue($store->exists(self::EPOCH));
        $first = $store->get_string(self::EPOCH);
        $this->assertNotSame('', $first);

        $store->set_quota(self::BALANCE, self::EPOCH, 200, 600);
        $second = $store->get_string(self::EPOCH);
        $this->assertNotSame('', $second);
        $this->assertNotSame($first, $second);
    }

    public function test_release_does_not_refund_into_a_balance_renewed_after_the_period_lapsed(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 100, 1);
        $this->reserve($store, 'r1', 40, [], 600);

        $this->assertTrue(Wait::until(fn (): bool => !$store->exists(self::BALANCE), 4));

        // A renewal lands while the old reservation is still alive.
        $store->set_quota(self::BALANCE, self::EPOCH, 200, 600);

        $this->assertSame(200, $store->quota_release(self::BALANCE, self::EPOCH, '{user:1}.reservation.r1', 'r1'));
        $this->assertSame(200, $store->get(self::BALANCE));
    }

    public function test_release_still_refunds_after_a_top_up(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 100, 600);
        $this->reserve($store, 'r1', 40, []);
        $store->add_quota(self::BALANCE, 50, 600);
        $this->assertSame(110, $store->get(self::BALANCE));

        $this->assertSame(150, $store->quota_release(self::BALANCE, self::EPOCH, '{user:1}.reservation.r1', 'r1'));
    }

    public function test_a_top_up_does_not_move_the_end_of_the_period(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 100, 60);

        $store->add_quota(self::BALANCE, 50, 86400);

        $this->assertSame(150, $store->get(self::BALANCE));
        $this->assertLessThanOrEqual(60, $store->ttl(self::BALANCE));
        $this->assertGreaterThan(0, $store->ttl(self::BALANCE));
    }

    public function test_a_top_up_on_a_missing_balance_starts_the_period(): void
    {
        $store = $this->store();

        $this->assertSame(50, $store->add_quota(self::BALANCE, 50, 60));
        $this->assertGreaterThan(0, $store->ttl(self::BALANCE));
        $this->assertLessThanOrEqual(60, $store->ttl(self::BALANCE));
    }

    public function test_the_limiter_builds_hash_tagged_keys_the_driver_prefixes_once(): void
    {
        $store = $this->store();
        $limiter = new QuotaLimiter($store);
        $limiter->quota_set('user:1', 100, 60);

        $keys = $this->redis()->keys($this->prefix . 'quota.*');
        sort($keys);

        $this->assertSame([
            $this->prefix . 'quota.{user:1}.balance',
            $this->prefix . 'quota.{user:1}.epoch',
        ], $keys);
    }

    public function test_a_full_meter_cycle_settles_against_real_redis(): void
    {
        $store = $this->store();
        $limiter = new QuotaLimiter($store);
        \Base::instance()->set('QUOTA', [
            'operations' => ['seo_headline'],
            'quotas' => [
                'pro' => [
                    'credits' => 100,
                    'period' => 600,
                    'reservation_ttl' => 300,
                    'pacing' => [['limit' => 200, 'window' => 60]],
                    'operations' => ['seo_headline' => ['cost' => 5]],
                ],
            ],
        ]);
        $limiter->resolve_plan_using(static fn(): string => 'pro');
        $limiter->resolve_scope_using(static fn(): string => 'user:1');
        $limiter->quota_set('user:1', 100, 600);

        $this->assertSame('ok', $limiter->meter(null, 'seo_headline', static fn(): string => 'ok'));
        $this->assertSame(95, $limiter->quota_get('user:1'));

        try {
            $limiter->meter(null, 'seo_headline', static function (): never {
                throw new \RuntimeException('provider down');
            });
            $this->fail('Expected the closure exception to propagate.');
        } catch (\RuntimeException) {
        }

        $this->assertSame(95, $limiter->quota_get('user:1'));
        $this->assertSame(1, $this->zcard('{user:1}.pacing._tier.60'));
        $this->assertSame(5, $this->used('{user:1}.pacing._tier.60'));
    }

    public function test_expired_pacing_members_are_subtracted_from_the_used_counter(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 1000, 600);
        $pacing_key = $this->prefix . 'quota.{user:1}.pacing._tier.60';
        $this->redis()->zAdd($pacing_key, time() - 120, 'old:10');
        $this->redis()->set($pacing_key . ':used', '10');
        $this->redis()->expire($pacing_key, 60);
        $this->redis()->expire($pacing_key . ':used', 60);

        $pacing = [$this->window('{user:1}.pacing._tier.60', 10, 60)];
        $this->assertTrue($this->reserve($store, 'r1', 6, $pacing)->allowed);
        $this->assertSame(6, $this->used('{user:1}.pacing._tier.60'));
        $this->assertSame(1, $this->zcard('{user:1}.pacing._tier.60'));
    }

    public function test_a_missing_pacing_counter_is_rebuilt_from_the_zset(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 1000, 600);
        $pacing = [$this->window('{user:1}.pacing._tier.60', 10, 60)];

        $this->assertTrue($this->reserve($store, 'r1', 3, $pacing)->allowed);
        $this->redis()->del($this->prefix . 'quota.{user:1}.pacing._tier.60.used');

        $this->assertTrue($this->reserve($store, 'r2', 4, $pacing)->allowed);
        $this->assertSame(7, $this->used('{user:1}.pacing._tier.60'));

        $denied = $this->reserve($store, 'r3', 6, $pacing);
        $this->assertFalse($denied->allowed);
        $this->assertSame(QuotaResult::REASON_PACING, $denied->reason);
        $this->assertSame(7, $this->used('{user:1}.pacing._tier.60'));
        $this->assertSame(993, $store->get(self::BALANCE));
    }

    public function test_release_decrements_only_the_removed_pacing_cost(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 1000, 600);
        $pacing = [$this->window('{user:1}.pacing._tier.60', 100, 60)];

        $this->assertTrue($this->reserve($store, 'r1', 6, $pacing)->allowed);
        $this->assertTrue($this->reserve($store, 'r2', 4, $pacing)->allowed);
        $this->assertSame(10, $this->used('{user:1}.pacing._tier.60'));

        $this->assertSame(996, $store->quota_release(self::BALANCE, self::EPOCH, '{user:1}.reservation.r1', 'r1'));
        $this->assertSame(4, $this->used('{user:1}.pacing._tier.60'));
        $this->assertSame(1, $this->zcard('{user:1}.pacing._tier.60'));
    }

    public function test_a_negative_cost_is_refused(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 100, 60);

        try {
            $this->reserve($store, 'r1', -1, []);
            $this->fail('Expected a negative cost to throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cost must not be negative', $e->getMessage());
        }

        $this->assertSame(100, $store->get(self::BALANCE));
        $this->assertFalse($store->exists('{user:1}.reservation.r1'));
    }

    public function test_settle_then_the_same_reservation_id_is_a_new_paced_spend(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 100, 60);
        $pacing = [$this->window('{user:1}.pacing._tier.60', 200, 60)];

        $this->assertTrue($this->reserve($store, 'r1', 40, $pacing)->allowed);
        $this->assertSame(40, $this->used('{user:1}.pacing._tier.60'));
        $this->assertSame(60, $store->quota_settle(self::BALANCE, '{user:1}.reservation.r1'));

        $this->assertTrue($this->reserve($store, 'r1', 40, $pacing)->allowed);
        $this->assertSame(80, $this->used('{user:1}.pacing._tier.60'));
        $this->assertSame(2, $this->zcard('{user:1}.pacing._tier.60'));
        $this->assertSame(20, $store->get(self::BALANCE));
    }

    public function test_a_negative_used_counter_heal_keeps_the_ttl(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 1000, 600);
        $tier = $this->prefix . 'quota.{user:1}.pacing._tier.60';
        $op = $this->prefix . 'quota.{user:1}.pacing.spam_check.60';

        $this->redis()->zAdd($tier, time(), 'old:1');
        $this->redis()->set($tier . ':used', '-5');
        $this->redis()->expire($tier, 60);
        $this->redis()->expire($tier . ':used', 60);

        $this->redis()->zAdd($op, time(), 'fill:1');
        $this->redis()->set($op . ':used', '1');
        $this->redis()->expire($op, 60);
        $this->redis()->expire($op . ':used', 60);

        $pacing = [
            $this->window('{user:1}.pacing._tier.60', 100, 60),
            $this->window('{user:1}.pacing.spam_check.60', 1, 60),
        ];
        $denied = $this->reserve($store, 'r1', 1, $pacing);

        $this->assertFalse($denied->allowed);
        $this->assertSame(QuotaResult::REASON_PACING, $denied->reason);
        $this->assertSame(1, $this->used('{user:1}.pacing._tier.60'));
        $this->assertGreaterThan(0, $this->redis()->ttl($tier . ':used'));
        $this->assertSame(1000, $store->get(self::BALANCE));
    }

    public function test_duplicate_pacing_keys_are_refused(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 100, 60);
        $pacing = [
            $this->window('{user:1}.pacing._tier.60', 10, 60),
            $this->window('{user:1}.pacing._tier.60', 20, 60),
        ];

        try {
            $this->reserve($store, 'r1', 1, $pacing);
            $this->fail('Expected duplicate pacing keys to throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('duplicate pacing key', $e->getMessage());
        }

        $this->assertSame(100, $store->get(self::BALANCE));
        $this->assertFalse($store->exists('{user:1}.reservation.r1'));
        $this->assertSame(0, $this->used('{user:1}.pacing._tier.60'));
    }

    public function test_add_quota_applies_expire_when_the_balance_has_no_ttl(): void
    {
        $store = $this->store();
        $this->redis()->set($this->prefix . 'quota.' . self::BALANCE, '100');
        $this->assertLessThan(0, $this->redis()->ttl($this->prefix . 'quota.' . self::BALANCE));

        $this->assertSame(150, $store->add_quota(self::BALANCE, 50, 60));
        $this->assertSame(150, $store->get(self::BALANCE));
        $this->assertGreaterThan(0, $store->ttl(self::BALANCE));
        $this->assertLessThanOrEqual(60, $store->ttl(self::BALANCE));
    }

    public function test_quota_get_on_a_never_granted_key_is_zero(): void
    {
        $this->assertSame(0, $this->store()->get(self::BALANCE));
        $this->assertSame(0, $this->store()->ttl(self::BALANCE));
    }

    public function test_clear_quota_does_not_touch_another_scope(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 100, 60);
        $store->set_quota('{user:2}.balance', '{user:2}.epoch', 80, 60);

        $store->clear(self::BALANCE);
        $store->clear(self::EPOCH);

        $this->assertSame(0, $store->get(self::BALANCE));
        $this->assertFalse($store->exists(self::EPOCH));
        $this->assertSame(80, $store->get('{user:2}.balance'));
        $this->assertTrue($store->exists('{user:2}.epoch'));
        $other = $store->get_string('{user:2}.epoch');
        $this->assertNotSame('', $other);

        $store->set_quota(self::BALANCE, self::EPOCH, 40, 60);
        $this->assertNotSame('', $store->get_string(self::EPOCH));
        $this->assertSame($other, $store->get_string('{user:2}.epoch'));
    }

    public function test_a_cost_that_fits_exactly_leaves_zero(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 5, 60);

        $result = $this->reserve($store, 'r1', 5, []);

        $this->assertTrue($result->allowed);
        $this->assertSame(QuotaResult::REASON_OK, $result->reason);
        $this->assertSame(0, $result->balance);
        $this->assertSame(0, $store->get(self::BALANCE));
    }

    public function test_a_zero_grant_denies_a_positive_cost(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 0, 60);

        $denied = $this->reserve($store, 'r1', 1, []);

        $this->assertFalse($denied->allowed);
        $this->assertSame(QuotaResult::REASON_INSUFFICIENT_BALANCE, $denied->reason);
        $this->assertSame(0, $denied->balance);
        $this->assertFalse($store->exists('{user:1}.reservation.r1'));
    }

    public function test_a_missing_balance_is_insufficient_and_does_not_write_a_reservation(): void
    {
        $store = $this->store();

        $denied = $this->reserve($store, 'r1', 1, []);

        $this->assertFalse($denied->allowed);
        $this->assertSame(QuotaResult::REASON_INSUFFICIENT_BALANCE, $denied->reason);
        $this->assertSame(0, $denied->balance);
        $this->assertNull($denied->retry_after);
        $this->assertFalse($store->exists('{user:1}.reservation.r1'));
    }

    public function test_a_zero_cost_reserve_is_a_noop_on_the_balance(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 10, 60);

        $this->assertTrue($this->reserve($store, 'r1', 0, [])->allowed);
        $this->assertSame(10, $store->get(self::BALANCE));
        $this->assertSame(10, $store->quota_settle(self::BALANCE, '{user:1}.reservation.r1'));
        $this->assertSame(10, $store->get(self::BALANCE));

        $this->assertTrue($this->reserve($store, 'r2', 0, [])->allowed);
        $this->assertSame(10, $store->quota_release(self::BALANCE, self::EPOCH, '{user:1}.reservation.r2', 'r2'));
        $this->assertSame(10, $store->get(self::BALANCE));
    }

    public function test_exact_remaining_after_n_reserves_until_exhausted(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 10, 60);

        for ($i = 1; $i <= 10; $i++) {
            $result = $this->reserve($store, 'r' . $i, 1, []);
            $this->assertTrue($result->allowed);
            $this->assertSame(10 - $i, $result->balance);
        }

        $denied = $this->reserve($store, 'r11', 1, []);
        $this->assertFalse($denied->allowed);
        $this->assertSame(QuotaResult::REASON_INSUFFICIENT_BALANCE, $denied->reason);
        $this->assertSame(0, $denied->balance);
        $this->assertSame(0, $store->get(self::BALANCE));
        $this->assertFalse($store->exists('{user:1}.reservation.r11'));
    }

    public function test_two_operations_share_the_tier_window(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 100, 600);
        $pacing = [$this->window('{user:1}.pacing._tier.60', 10, 60)];

        $this->assertTrue($this->reserve($store, 'r1', 3, $pacing)->allowed);
        $this->assertTrue($this->reserve($store, 'r2', 5, $pacing)->allowed);
        $denied = $this->reserve($store, 'r3', 3, $pacing);

        $this->assertFalse($denied->allowed);
        $this->assertSame(QuotaResult::REASON_PACING, $denied->reason);
        $this->assertSame(92, $store->get(self::BALANCE));
        $this->assertFalse($store->exists('{user:1}.reservation.r3'));
        $this->assertSame(8, $this->used('{user:1}.pacing._tier.60'));
    }

    public function test_two_scopes_do_not_share_a_pacing_window_or_balance(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 100, 600);
        $store->set_quota('{user:2}.balance', '{user:2}.epoch', 100, 600);
        $one = [$this->window('{user:1}.pacing._tier.60', 10, 60)];
        $two = [$this->window('{user:2}.pacing._tier.60', 10, 60)];

        $this->assertTrue($this->reserve($store, 'r1', 3, $one)->allowed);
        $this->assertTrue($this->reserve($store, 'r2', 5, $one)->allowed);
        $this->assertFalse($this->reserve($store, 'r3', 3, $one)->allowed);

        $this->assertTrue($store->quota_reserve(
            '{user:2}.balance',
            '{user:2}.epoch',
            '{user:2}.reservation.r1',
            'r1',
            3,
            300,
            $two,
            time()
        )->allowed);
        $this->assertTrue($store->quota_reserve(
            '{user:2}.balance',
            '{user:2}.epoch',
            '{user:2}.reservation.r2',
            'r2',
            5,
            300,
            $two,
            time()
        )->allowed);
        $denied_two = $store->quota_reserve(
            '{user:2}.balance',
            '{user:2}.epoch',
            '{user:2}.reservation.r3',
            'r3',
            3,
            300,
            $two,
            time()
        );
        $this->assertFalse($denied_two->allowed);
        $this->assertSame(QuotaResult::REASON_PACING, $denied_two->reason);

        $this->assertSame(92, $store->get(self::BALANCE));
        $this->assertSame(92, $store->get('{user:2}.balance'));
        $this->assertSame(8, $this->used('{user:1}.pacing._tier.60'));
        $this->assertSame(8, $this->used('{user:2}.pacing._tier.60'));
    }

    public function test_two_tier_windows_leave_the_open_window_untouched_on_deny(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 20000, 600);
        $pacing = [
            $this->window('{user:1}.pacing._tier.60', 200, 60),
            $this->window('{user:1}.pacing._tier.86400', 2000, 86400),
        ];

        for ($i = 0; $i < 200; $i++) {
            $this->assertTrue($this->reserve($store, 'r' . $i, 1, $pacing)->allowed);
        }
        $day_used = $this->used('{user:1}.pacing._tier.86400');
        $this->assertSame(200, $day_used);

        $denied = $this->reserve($store, 'overflow', 1, $pacing);
        $this->assertFalse($denied->allowed);
        $this->assertSame(QuotaResult::REASON_PACING, $denied->reason);
        $this->assertSame($this->prefix . 'quota.{user:1}.pacing._tier.60', $denied->limited_by);
        $this->assertSame(19800, $store->get(self::BALANCE));
        $this->assertSame(200, $this->used('{user:1}.pacing._tier.60'));
        $this->assertSame($day_used, $this->used('{user:1}.pacing._tier.86400'));
        $this->assertFalse($store->exists('{user:1}.reservation.overflow'));
    }

    public function test_the_reservation_hash_carries_ttl_and_zset_member(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 100, 60);
        $now = time();
        $this->reserve($store, 'r1', 40, [$this->window('{user:1}.pacing._tier.60', 200, 60)]);

        $reservation = $this->prefix . 'quota.{user:1}.reservation.r1';
        $ttl = $this->redis()->ttl($reservation);
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(300, $ttl);

        $members = $this->redis()->zRange($this->prefix . 'quota.{user:1}.pacing._tier.60', 0, -1, true);
        $this->assertCount(1, $members);
        $member = array_key_first($members);
        $this->assertIsString($member);
        $this->assertMatchesRegularExpression('/^r1:[0-9a-f]+:40$/', $member);
        $this->assertEqualsWithDelta($now, (float)$members[$member], 2);
        $this->assertSame($member, $this->redis()->hGet($this->prefix . 'quota.{user:1}.reservation.r1', 'member'));
    }

    public function test_physical_keys_apply_the_prefix_once(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 100, 60);
        $this->reserve($store, 'r1', 1, [$this->window('{user:1}.pacing._tier.60', 10, 60)]);

        $keys = $this->redis()->keys($this->prefix . 'quota.*');
        $this->assertNotEmpty($keys);
        foreach ($keys as $key) {
            $this->assertStringStartsWith($this->prefix . 'quota.', $key);
            $this->assertStringNotContainsString('quota.quota.', $key);
        }
    }

    public function test_settle_twice_is_a_noop_on_the_second_call(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 100, 60);
        $this->reserve($store, 'r1', 5, []);

        $this->assertSame(95, $store->quota_settle(self::BALANCE, '{user:1}.reservation.r1'));
        $this->assertSame(95, $store->quota_settle(self::BALANCE, '{user:1}.reservation.r1'));
        $this->assertSame(95, $store->get(self::BALANCE));
        $this->assertFalse($store->exists('{user:1}.reservation.r1'));
    }

    public function test_release_of_one_of_two_live_reservations_leaves_the_other(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 1000, 600);
        $pacing = [$this->window('{user:1}.pacing._tier.60', 100, 60)];

        $this->assertTrue($this->reserve($store, 'r1', 6, $pacing)->allowed);
        $this->assertTrue($this->reserve($store, 'r2', 4, $pacing)->allowed);

        $this->assertSame(996, $store->quota_release(self::BALANCE, self::EPOCH, '{user:1}.reservation.r1', 'r1'));
        $this->assertSame(4, $this->used('{user:1}.pacing._tier.60'));
        $this->assertSame(1, $this->zcard('{user:1}.pacing._tier.60'));
        $this->assertTrue($store->exists('{user:1}.reservation.r2'));
        $this->assertFalse($store->exists('{user:1}.reservation.r1'));
    }

    public function test_release_after_settle_is_a_noop_on_the_balance(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 100, 60);
        $this->reserve($store, 'r1', 5, []);
        $this->assertSame(95, $store->quota_settle(self::BALANCE, '{user:1}.reservation.r1'));
        $this->assertSame(95, $store->quota_release(self::BALANCE, self::EPOCH, '{user:1}.reservation.r1', 'r1'));
        $this->assertSame(95, $store->get(self::BALANCE));
    }

    public function test_release_after_a_renewal_does_not_refund_into_the_new_grant(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 100, 60);
        $this->reserve($store, 'r1', 5, []);
        $store->set_quota(self::BALANCE, self::EPOCH, 40, 60);

        $this->assertSame(40, $store->quota_release(self::BALANCE, self::EPOCH, '{user:1}.reservation.r1', 'r1'));
        $this->assertSame(40, $store->get(self::BALANCE));
        $this->assertFalse($store->exists('{user:1}.reservation.r1'));
    }

    public function test_injected_now_keeps_unique_ids_paced_until_the_window_elapses(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 500, 600);
        $pacing = [$this->window('{user:1}.pacing._tier.60', 20, 60)];
        $t = 1_700_000_000;

        $this->assertTrue($this->reserve($store, 'a', 10, $pacing, 300, $t)->allowed);
        $this->assertTrue($this->reserve($store, 'b', 10, $pacing, 300, $t)->allowed);

        $denied = $this->reserve($store, 'c', 10, $pacing, 300, $t + 59);
        $this->assertFalse($denied->allowed);
        $this->assertSame(QuotaResult::REASON_PACING, $denied->reason);
        $this->assertSame(480, $store->get(self::BALANCE));

        $rolled = $this->reserve($store, 'c', 10, $pacing, 300, $t + 60);
        $this->assertTrue($rolled->allowed);
        $this->assertSame(470, $store->get(self::BALANCE));
    }

    public function test_reusing_a_settled_id_cannot_drain_the_grant_inside_one_injected_window(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 500, 600);
        $pacing = [$this->window('{user:1}.pacing._tier.60', 20, 60)];
        $t = 1_700_000_000;

        $this->assertTrue($this->reserve($store, 'recycled', 10, $pacing, 300, $t)->allowed);
        $store->quota_settle(self::BALANCE, '{user:1}.reservation.recycled');

        $allowed = 1;
        for ($i = 0; $i < 49; $i++) {
            $result = $this->reserve($store, 'recycled', 10, $pacing, 300, $t);
            if (!$result->allowed) {
                break;
            }
            $allowed++;
            $store->quota_settle(self::BALANCE, '{user:1}.reservation.recycled');
        }

        $this->assertSame(2, $allowed);
        $this->assertSame(480, $store->get(self::BALANCE));
        $this->assertSame(20, $this->used('{user:1}.pacing._tier.60'));
        $this->assertSame(2, $this->zcard('{user:1}.pacing._tier.60'));
    }

    /**
     * The PHP driver mints a nonce, so this goes through reserve.lua with a
     * chosen member string. A reused nonce after settle is a ZADD NX miss:
     * that reserve must not DECRBY. Unique-nonce / unique-id pacing (two
     * spends, remaining 480) is the test above.
     */
    public function test_reused_lua_nonce_after_settle_cannot_drain_the_grant(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 500, 600);
        $now = time();
        $nonce = 'aaaaaaaaaaaaaaaa';

        $allowed = 0;
        $collision = null;
        for ($i = 0; $i < 50; $i++) {
            try {
                $payload = $this->eval_reserve('recycled', $nonce, 10, $now, 20, 60);
            } catch (\RuntimeException $e) {
                $collision = $e;
                break;
            }
            if ((int)$payload[0] !== 1) {
                break;
            }
            $allowed++;
            $store->quota_settle(self::BALANCE, '{user:1}.reservation.recycled');
        }

        $remaining = $store->get(self::BALANCE);
        $this->assertInstanceOf(
            \RuntimeException::class,
            $collision,
            "reused nonce charged {$allowed} times; remaining {$remaining} (expected 1 spend, then duplicate pacing member)"
        );
        $this->assertStringContainsString('duplicate pacing member', $collision->getMessage());
        $this->assertSame(1, $allowed);
        $this->assertSame(490, $remaining);
        $this->assertSame(10, $this->used('{user:1}.pacing._tier.60'));
        $this->assertSame(1, $this->zcard('{user:1}.pacing._tier.60'));
        $this->assertFalse($store->exists('{user:1}.reservation.recycled'));
    }

    /**
     * Scores and trim use ARGV now. The old EXPIRE(window) used Redis TIME.
     * Inject now ahead of Redis, fill the window, wait past the old 2s TTL,
     * and reserve again at now+1 — still inside the logical window.
     */
    public function test_injected_now_inside_the_window_must_still_pace_after_redis_ttl_elapses(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 500, 600);
        $pacing = [$this->window('{user:1}.pacing._tier.2', 20, 2)];
        $now = time() + 1000;
        $zset = $this->prefix . 'quota.{user:1}.pacing._tier.2';

        $this->assertTrue($this->reserve($store, 'a', 10, $pacing, 300, $now)->allowed);
        $this->assertTrue($this->reserve($store, 'b', 10, $pacing, 300, $now)->allowed);
        $this->assertSame(QuotaResult::REASON_PACING, $this->reserve($store, 'c', 10, $pacing, 300, $now)->reason);
        $this->assertSame(480, $store->get(self::BALANCE));

        $started = time();
        $this->assertTrue(
            Wait::until(fn (): bool => time() >= $started + 3, 6),
            'wall clock must pass the old 2s EXPIRE window'
        );

        $this->assertGreaterThan(
            0,
            $this->redis()->ttl($zset),
            'zset must outlive the old EXPIRE window when now is ahead of Redis'
        );

        $still = $this->reserve($store, 'c', 10, $pacing, 300, $now + 1);
        $this->assertFalse($still->allowed, 'logical now is still inside the 2s window that started at injected now');
        $this->assertSame(QuotaResult::REASON_PACING, $still->reason);
        $this->assertSame(480, $store->get(self::BALANCE));
    }

    public function test_a_negative_used_counter_is_rebuilt_from_live_members_not_zeroed(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 1000, 600);
        $tier = $this->prefix . 'quota.{user:1}.pacing._tier.60';
        $now = time();

        $this->redis()->zAdd($tier, $now, 'old:8');
        $this->redis()->set($tier . ':used', '-5');
        $this->redis()->expire($tier, 60);
        $this->redis()->expire($tier . ':used', 60);

        $denied = $this->reserve($store, 'r1', 5, [$this->window('{user:1}.pacing._tier.60', 10, 60)]);

        $this->assertFalse($denied->allowed);
        $this->assertSame(QuotaResult::REASON_PACING, $denied->reason);
        $this->assertSame(1000, $store->get(self::BALANCE));
        $this->assertSame(8, $this->used('{user:1}.pacing._tier.60'));
        $this->assertSame(1, $this->zcard('{user:1}.pacing._tier.60'));
        $this->assertFalse($store->exists('{user:1}.reservation.r1'));
    }

    public function test_clear_then_set_does_not_refund_a_pre_clear_reservation_into_the_new_grant(): void
    {
        $store = $this->store();
        $store->set_quota(self::BALANCE, self::EPOCH, 100, 600);
        $old = $store->get_string(self::EPOCH);
        $this->assertTrue($this->reserve($store, 'live', 40, [])->allowed);
        $this->assertSame(60, $store->get(self::BALANCE));

        $store->clear(self::BALANCE);
        $store->clear(self::EPOCH);
        $store->set_quota(self::BALANCE, self::EPOCH, 100, 600);

        $this->assertSame(100, $store->quota_release(self::BALANCE, self::EPOCH, '{user:1}.reservation.live', 'live'));
        $this->assertSame(100, $store->get(self::BALANCE));
        $this->assertNotSame('', $store->get_string(self::EPOCH));
        $this->assertNotSame($old, $store->get_string(self::EPOCH));
    }

    public function test_clear_quota_drops_pacing_keys_for_the_scope(): void
    {
        $store = $this->store();
        $limiter = new QuotaLimiter($store);
        $limiter->quota_set('user:1', 1000, 3600);
        $pacing = [$this->window('{user:1}.pacing._tier.60', 4, 60)];

        $this->assertTrue($this->reserve($store, 'a', 2, $pacing)->allowed);
        $this->assertTrue($this->reserve($store, 'b', 2, $pacing)->allowed);
        $this->assertFalse($this->reserve($store, 'c', 2, $pacing)->allowed);

        $limiter->quota_clear('user:1');

        $this->assertSame(0, $this->zcard('{user:1}.pacing._tier.60'));
        $this->assertSame(0, $this->used('{user:1}.pacing._tier.60'));
        $this->assertFalse($store->exists('{user:1}.reservation.a'));
        $this->assertFalse($store->exists('{user:1}.reservation.b'));

        $limiter->quota_set('user:1', 1000, 3600);
        $this->assertTrue($this->reserve($store, 'fresh', 2, $pacing)->allowed);
        $this->assertSame(998, $store->get(self::BALANCE));
    }

    /**
     * Call reserve.lua with a chosen nonce. The PHP driver cannot inject one.
     *
     * @return list<mixed>
     */
    private function eval_reserve(
        string $id,
        string $nonce,
        int $cost,
        int $now,
        int $limit,
        int $window
    ): array {
        $script = file_get_contents(self::LUA_RESERVE);
        $this->assertNotFalse($script);

        $prefix = $this->prefix . 'quota.';
        $keys = [
            $prefix . self::BALANCE,
            $prefix . self::EPOCH,
            $prefix . '{user:1}.reservation.' . $id,
            $prefix . '{user:1}.pacing._tier.' . $window,
            $prefix . '{user:1}.pacing._tier.' . $window . '.used',
        ];
        $arguments = [
            (string)$now,
            (string)$cost,
            '300',
            $id,
            $nonce,
            (string)$limit,
            (string)$window,
            '4',
        ];

        $this->redis()->clearLastError();
        $payload = $this->redis()->eval($script, array_merge($keys, $arguments), count($keys));
        if ($payload === false) {
            $error = $this->redis()->getLastError();
            throw new \RuntimeException(is_string($error) ? $error : 'unknown');
        }

        $this->assertIsArray($payload);

        return array_values($payload);
    }

    /** @param list<QuotaPacingWindow> $pacing */
    private function reserve(
        RedisQuotaStore $store,
        string $id,
        int $cost,
        array $pacing,
        int $ttl = 300,
        ?int $now = null
    ): QuotaResult {
        return $store->quota_reserve(
            self::BALANCE,
            self::EPOCH,
            '{user:1}.reservation.' . $id,
            $id,
            $cost,
            $ttl,
            $pacing,
            $now ?? time()
        );
    }

    private function window(string $key, int $limit, int $seconds): QuotaPacingWindow
    {
        return new QuotaPacingWindow($key, $limit, $seconds);
    }

    private function zcard(string $key): int
    {
        return (int)$this->redis()->zCard($this->prefix . 'quota.' . $key);
    }

    private function zset_weight(string $key): int
    {
        $members = $this->redis()->zRange($this->prefix . 'quota.' . $key, 0, -1);
        if (!is_array($members)) {
            return 0;
        }

        $sum = 0;
        foreach ($members as $member) {
            if (preg_match('/:(\d+)$/', (string)$member, $match) === 1) {
                $sum += (int)$match[1];
            }
        }

        return $sum;
    }

    private function assert_used_matches_zset(string $key): void
    {
        $this->assertSame(
            $this->zset_weight($key),
            $this->used($key),
            $key . ' .used must equal the summed cost of live zset members'
        );
    }

    private function used(string $key): int
    {
        $value = $this->redis()->get($this->prefix . 'quota.' . $key . '.used');

        return $value === false ? 0 : (int)$value;
    }

    private function store(): RedisQuotaStore
    {
        self::assertInstanceOf(RedisQuotaStore::class, $this->store);

        return $this->store;
    }

    private function redis(): \Redis
    {
        self::assertInstanceOf(\Redis::class, $this->redis);

        return $this->redis;
    }
}
