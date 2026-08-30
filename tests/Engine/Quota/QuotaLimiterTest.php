<?php
declare(strict_types=1);

namespace Tests\Engine\Quota;

use Engine\Atomic\Quota\Exceptions\QuotaExceededException;
use Engine\Atomic\Quota\QuotaLimiter;
use Engine\Atomic\Quota\QuotaOperationSpec;
use Engine\Atomic\Quota\QuotaPacingSpec;
use Engine\Atomic\Quota\QuotaPlan;
use Engine\Atomic\Quota\QuotaResult;
use PHPUnit\Framework\TestCase;
use Tests\Support\QuotaPlans;
use Tests\Support\QuotaSubject;
use Tests\Support\TestQuotaStore;

final class QuotaLimiterTest extends TestCase
{
    protected function tearDown(): void
    {
        QuotaLimiter::reset();
        \Base::instance()->clear('QUOTA');
    }

    public function test_set_quota_overwrites_the_balance_and_refreshes_the_period(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);

        $this->assertSame(500, $limiter->quota_set('user:1', 500, 100));
        $limiter->quota_reserve('user:1', 'a', $this->plan(), 'spam_check');
        $this->assertSame(499, $limiter->quota_get('user:1'));

        $store->advance(50);
        $this->assertSame(50, $limiter->quota_ttl('user:1'));

        $this->assertSame(500, $limiter->quota_set('user:1', 500, 100));
        $this->assertSame(500, $limiter->quota_get('user:1'));
        $this->assertSame(100, $limiter->quota_ttl('user:1'));
    }

    public function test_add_quota_tops_up_without_moving_the_end_of_the_period(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);

        $this->assertSame(100, $limiter->quota_add('user:1', 100, 60));
        $store->advance(30);
        $this->assertSame(30, $limiter->quota_ttl('user:1'));

        // A top-up is not a renewal. The period must still end where it did.
        $this->assertSame(150, $limiter->quota_add('user:1', 50, 86400));
        $this->assertSame(30, $limiter->quota_ttl('user:1'));
    }

    public function test_the_epoch_survives_the_balance_period(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $limiter->quota_set('user:1', 100, 60);
        $plan = $this->plan();

        $limiter->quota_reserve('user:1', 'a', $plan, 'seo_headline');
        $store->advance(120);

        // The balance lapsed, then a renewal landed. The epoch kept counting,
        // so the stale reservation cannot refund into the new balance.
        $limiter->quota_set('user:1', 200, 60);
        $limiter->quota_release('user:1', 'a');

        $this->assertSame(200, $limiter->quota_get('user:1'));
    }

    public function test_clear_quota_removes_the_balance_and_the_epoch(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);

        $limiter->quota_set('user:1', 100, 60);
        $limiter->quota_clear('user:1');

        $this->assertSame(0, $limiter->quota_get('user:1'));
        $this->assertSame('', $store->get_string($limiter->epoch_key('user:1')));
        $this->assertFalse($store->exists($limiter->epoch_key('user:1')));
    }

    public function test_quota_keys_carry_the_scope_as_a_hash_tag(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());

        $this->assertSame('{user:1}.balance', $limiter->balance_key('user:1'));
        $this->assertSame('{user:1}.epoch', $limiter->epoch_key('user:1'));
        $this->assertSame('{user:1}.reservation.abc', $limiter->reservation_key('user:1', 'abc'));
    }

    public function test_plan_reads_a_tier_from_config(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $this->set_config();

        $plan = $limiter->plan('pro');

        $this->assertSame('pro', $plan->name);
        $this->assertSame(20000, $plan->credits);
        $this->assertSame(2592000, $plan->period);
        $this->assertSame(300, $plan->reservation_ttl);
        $this->assertEquals([new QuotaPacingSpec(200, 60)], $plan->pacing);
        $this->assertSame(5, $plan->cost('seo_headline'));
        $this->assertEquals([new QuotaPacingSpec(30, 3600)], $plan->operation_pacing('seo_headline'));
    }

    public function test_plan_throws_on_an_undefined_tier(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $this->set_config();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Undefined quota tier: 'enterprise'");

        $limiter->plan('enterprise');
    }

    /**
     * @dataProvider missing_key_provider
     */
    public function test_plan_throws_when_a_tier_is_missing_a_required_key(string $missing): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $tier = $this->tier();
        unset($tier[$missing]);
        $this->set_config(['free' => $tier]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("missing the required key '{$missing}'");

        $limiter->plan('free');
    }

    public static function missing_key_provider(): array
    {
        return [
            ['credits'],
            ['period'],
            ['reservation_ttl'],
            ['pacing'],
            ['operations'],
        ];
    }

    public function test_plan_applies_no_defaults_and_no_inheritance(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $tier = $this->tier();
        unset($tier['reservation_ttl']);
        $this->set_config(['free' => $tier]);

        try {
            $limiter->plan('free');
            $this->fail('Expected a missing reservation_ttl to throw.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('has no defaults', $e->getMessage());
        }
    }

    public function test_plan_rejects_an_operation_outside_the_declared_list(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $tier = $this->tier();
        $tier['operations']['undeclared'] = ['cost' => 1];
        $this->set_config(['free' => $tier]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("operation 'undeclared' is not in the declared operation list");

        $limiter->plan('free');
    }

    public function test_plan_rejects_the_reserved_tier_pacing_name(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $this->set_config(null, ['spam_check', QuotaPlan::TIER]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved pacing name');

        $limiter->plan('free');
    }

    public function test_plan_throws_when_the_operation_list_is_not_declared(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $this->set_config(null, []);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must declare an operation list');

        $limiter->plan('free');
    }

    public function test_an_operation_absent_from_the_tier_is_not_entitled(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $this->set_config();
        $limiter->quota_set('user:1', 100, 60);

        $result = $limiter->quota_reserve('user:1', 'a', $limiter->plan('free'), 'seo_headline');

        $this->assertFalse($result->allowed);
        $this->assertSame(QuotaResult::REASON_NOT_ENTITLED, $result->reason);
        $this->assertNull($result->retry_after);
        $this->assertSame(100, $limiter->quota_get('user:1'));
    }

    public function test_an_undeclared_operation_name_throws_rather_than_denying(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $this->set_config();
        $limiter->quota_set('user:1', 100, 60);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown quota operation: 'seo_headlines'");

        $limiter->quota_reserve('user:1', 'a', $limiter->plan('pro'), 'seo_headlines');
    }

    public function test_the_cost_override_replaces_the_config_cost(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $this->set_config();
        $limiter->quota_set('user:1', 100, 60);
        $plan = $limiter->plan('pro');

        $this->assertTrue($limiter->quota_reserve('user:1', 'a', $plan, 'seo_headline')->allowed);
        $this->assertSame(95, $limiter->quota_get('user:1'));

        $this->assertTrue($limiter->quota_reserve('user:1', 'b', $plan, 'seo_headline', 20)->allowed);
        $this->assertSame(75, $limiter->quota_get('user:1'));

        $limiter->quota_release('user:1', 'b');
        $this->assertSame(95, $limiter->quota_get('user:1'));
    }

    public function test_reserve_charges_and_settle_leaves_the_charge_standing(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $this->set_config();
        $limiter->quota_set('user:1', 100, 60);

        $result = $limiter->quota_reserve('user:1', 'a', $limiter->plan('pro'), 'seo_headline');

        $this->assertTrue($result->allowed);
        $this->assertSame(QuotaResult::REASON_OK, $result->reason);
        $this->assertSame(95, $result->balance);

        $this->assertSame(95, $limiter->quota_settle('user:1', 'a'));
        $this->assertSame(95, $limiter->quota_get('user:1'));
    }

    public function test_release_refunds_the_charge(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $this->set_config();
        $limiter->quota_set('user:1', 100, 60);

        $limiter->quota_reserve('user:1', 'a', $limiter->plan('pro'), 'seo_headline');
        $limiter->quota_release('user:1', 'a');

        $this->assertSame(100, $limiter->quota_get('user:1'));
    }

    public function test_an_insufficient_balance_is_denied_with_the_period_as_retry_after(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $this->set_config();
        $limiter->quota_set('user:1', 3, 60);

        $result = $limiter->quota_reserve('user:1', 'a', $limiter->plan('pro'), 'seo_headline');

        $this->assertFalse($result->allowed);
        $this->assertSame(QuotaResult::REASON_INSUFFICIENT_BALANCE, $result->reason);
        $this->assertSame(3, $result->balance);
        $this->assertSame(60, $result->retry_after);
    }

    public function test_a_duplicate_reservation_id_does_not_double_charge(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $this->set_config();
        $limiter->quota_set('user:1', 100, 60);
        $plan = $limiter->plan('pro');

        $this->assertTrue($limiter->quota_reserve('user:1', 'a', $plan, 'seo_headline')->allowed);
        $duplicate = $limiter->quota_reserve('user:1', 'a', $plan, 'seo_headline');

        $this->assertFalse($duplicate->allowed);
        $this->assertSame(QuotaResult::REASON_DUPLICATE_RESERVATION, $duplicate->reason);
        $this->assertNull($duplicate->retry_after);
        $this->assertSame(95, $limiter->quota_get('user:1'));
    }

    public function test_settle_and_release_on_a_missing_reservation_are_noops(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $limiter->quota_set('user:1', 25, 60);

        $this->assertSame(25, $limiter->quota_settle('user:1', 'missing'));
        $limiter->quota_release('user:1', 'missing');

        $this->assertSame(25, $limiter->quota_get('user:1'));
    }

    public function test_an_expired_reservation_keeps_the_charge(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->set_config();
        $limiter->quota_set('user:1', 100, 3600);

        $limiter->quota_reserve('user:1', 'a', $limiter->plan('pro'), 'seo_headline');
        $store->advance(301);

        $limiter->quota_release('user:1', 'a');

        $this->assertSame(95, $limiter->quota_get('user:1'));
    }

    public function test_release_does_not_recreate_an_expired_balance(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->set_config();
        $limiter->quota_set('user:1', 100, 10);

        $limiter->quota_reserve('user:1', 'a', $limiter->plan('pro'), 'seo_headline');
        $store->advance(11);

        $limiter->quota_release('user:1', 'a');

        $this->assertSame(0, $limiter->quota_get('user:1'));
        $this->assertSame(0, $limiter->quota_ttl('user:1'));
    }

    public function test_an_empty_pacing_list_behaves_like_a_balance_only_quota(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $tier = $this->tier();
        $tier['pacing'] = [];
        $this->set_config(['free' => $tier]);
        $limiter->quota_set('user:1', 3, 60);
        $plan = $limiter->plan('free');

        $this->assertTrue($limiter->quota_reserve('user:1', 'a', $plan, 'spam_check')->allowed);
        $this->assertTrue($limiter->quota_reserve('user:1', 'b', $plan, 'spam_check')->allowed);
        $this->assertTrue($limiter->quota_reserve('user:1', 'c', $plan, 'spam_check')->allowed);

        $denied = $limiter->quota_reserve('user:1', 'd', $plan, 'spam_check');
        $this->assertFalse($denied->allowed);
        $this->assertSame(QuotaResult::REASON_INSUFFICIENT_BALANCE, $denied->reason);
    }

    public function test_pacing_denies_once_the_summed_cost_fills_the_window(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $tier = $this->tier();
        $tier['pacing'] = [['limit' => 4, 'window' => 60]];
        $tier['operations']['spam_check'] = ['cost' => 2];
        $this->set_config(['free' => $tier]);
        $limiter->quota_set('user:1', 1000, 3600);
        $plan = $limiter->plan('free');

        $this->assertTrue($limiter->quota_reserve('user:1', 'a', $plan, 'spam_check')->allowed);
        $this->assertTrue($limiter->quota_reserve('user:1', 'b', $plan, 'spam_check')->allowed);

        $denied = $limiter->quota_reserve('user:1', 'c', $plan, 'spam_check');

        $this->assertFalse($denied->allowed);
        $this->assertSame(QuotaResult::REASON_PACING, $denied->reason);
        $this->assertSame('{user:1}.pacing._tier.60', $denied->limited_by);
        $this->assertGreaterThan(0, (int)$denied->retry_after);
        $this->assertLessThanOrEqual(60, (int)$denied->retry_after);
        $this->assertSame(996, $limiter->quota_get('user:1'));
    }

    public function test_pacing_sums_costs_rather_than_counting_requests(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $tier = $this->tier();
        $tier['pacing'] = [['limit' => 10, 'window' => 60]];
        $this->set_config(['free' => $tier]);
        $limiter->quota_set('user:1', 1000, 3600);
        $plan = $limiter->plan('free');

        $this->assertTrue($limiter->quota_reserve('user:1', 'a', $plan, 'spam_check', 10)->allowed);
        $this->assertFalse($limiter->quota_reserve('user:1', 'b', $plan, 'spam_check', 1)->allowed);
    }

    public function test_release_frees_the_pacing_member_it_added(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $tier = $this->tier();
        $tier['pacing'] = [['limit' => 2, 'window' => 60]];
        $this->set_config(['free' => $tier]);
        $limiter->quota_set('user:1', 1000, 3600);
        $plan = $limiter->plan('free');

        $limiter->quota_reserve('user:1', 'a', $plan, 'spam_check');
        $limiter->quota_reserve('user:1', 'b', $plan, 'spam_check');
        $this->assertFalse($limiter->quota_reserve('user:1', 'c', $plan, 'spam_check')->allowed);

        $limiter->quota_release('user:1', 'a');

        $this->assertTrue($limiter->quota_reserve('user:1', 'c', $plan, 'spam_check')->allowed);
    }

    public function test_settle_leaves_the_pacing_member_in_place(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $tier = $this->tier();
        $tier['pacing'] = [['limit' => 1, 'window' => 60]];
        $this->set_config(['free' => $tier]);
        $limiter->quota_set('user:1', 1000, 3600);
        $plan = $limiter->plan('free');

        $limiter->quota_reserve('user:1', 'a', $plan, 'spam_check');
        $limiter->quota_settle('user:1', 'a');

        $this->assertFalse($limiter->quota_reserve('user:1', 'b', $plan, 'spam_check')->allowed);
    }

    public function test_settle_then_the_same_reservation_id_is_a_new_paced_spend(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $plan = $this->paced_plan(500, 20, 60, 10);
        $limiter->quota_set('user:1', 500, 3600);

        $this->assertTrue($limiter->quota_reserve('user:1', 'a', $plan, 'spam_check')->allowed);
        $limiter->quota_settle('user:1', 'a');
        $this->assertTrue($limiter->quota_reserve('user:1', 'a', $plan, 'spam_check')->allowed);
        $limiter->quota_settle('user:1', 'a');

        $this->assertSame(480, $limiter->quota_get('user:1'));
        $this->assertSame(20, $store->zset_weight('{user:1}.pacing._tier.60'));
        $this->assertSame(2, $store->zcard('{user:1}.pacing._tier.60'));
        $this->assertSame(QuotaResult::REASON_PACING, $limiter->quota_reserve('user:1', 'a', $plan, 'spam_check')->reason);
    }

    public function test_reusing_a_settled_reservation_id_cannot_bypass_pacing_inside_the_window(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $plan = $this->paced_plan(500, 20, 60, 10);
        $limiter->quota_set('user:1', 500, 3600);

        $this->assertTrue($limiter->quota_reserve('user:1', 'recycled', $plan, 'spam_check')->allowed);
        $limiter->quota_settle('user:1', 'recycled');

        $allowed = 1;
        for ($i = 0; $i < 49; $i++) {
            $result = $limiter->quota_reserve('user:1', 'recycled', $plan, 'spam_check');
            if (!$result->allowed) {
                break;
            }
            $allowed++;
            $limiter->quota_settle('user:1', 'recycled');
        }

        $this->assertSame(2, $allowed, 'pacing 20/60s with cost 10 must admit at most two spends, even when the reservation id is reused after settle');
        $this->assertSame(480, $limiter->quota_get('user:1'));
        $this->assertSame(QuotaResult::REASON_PACING, $limiter->quota_reserve('user:1', 'recycled', $plan, 'spam_check')->reason);
    }

    public function test_unique_ids_stay_paced_until_the_window_elapses(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $plan = $this->paced_plan(500, 20, 60, 10);
        $limiter->quota_set('user:1', 500, 3600);

        $this->assertTrue($limiter->quota_reserve('user:1', 'a', $plan, 'spam_check')->allowed);
        $this->assertTrue($limiter->quota_reserve('user:1', 'b', $plan, 'spam_check')->allowed);
        $denied = $limiter->quota_reserve('user:1', 'c', $plan, 'spam_check');

        $this->assertFalse($denied->allowed);
        $this->assertSame(QuotaResult::REASON_PACING, $denied->reason);
        $this->assertSame(480, $limiter->quota_get('user:1'));

        $store->advance(59);
        $still = $limiter->quota_reserve('user:1', 'c', $plan, 'spam_check');
        $this->assertFalse($still->allowed);
        $this->assertSame(QuotaResult::REASON_PACING, $still->reason);
        $this->assertSame(480, $limiter->quota_get('user:1'));

        $store->advance(1);
        $this->assertTrue($limiter->quota_reserve('user:1', 'c', $plan, 'spam_check')->allowed);
        $this->assertSame(470, $limiter->quota_get('user:1'));
    }

    public function test_recycled_id_drain_is_still_inside_the_original_window(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $plan = $this->paced_plan(500, 20, 60, 10);
        $limiter->quota_set('user:1', 500, 3600);

        for ($i = 0; $i < 50; $i++) {
            $result = $limiter->quota_reserve('user:1', 'recycled', $plan, 'spam_check');
            if (!$result->allowed) {
                break;
            }
            $limiter->quota_settle('user:1', 'recycled');
        }

        $this->assertSame(
            480,
            $limiter->quota_get('user:1'),
            'a 20/60s window must still hold at t+0 even if the caller reuses one id'
        );

        $store->advance(60);
        $this->assertTrue($limiter->quota_reserve('user:1', 'fresh', $plan, 'spam_check')->allowed);
        $this->assertSame(470, $limiter->quota_get('user:1'));
    }

    public function test_tier_pacing_and_operation_pacing_both_apply(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $tier = $this->tier();
        $tier['pacing'] = [['limit' => 100, 'window' => 60]];
        $tier['operations']['spam_check'] = ['cost' => 1, 'pacing' => [['limit' => 1, 'window' => 3600]]];
        $this->set_config(['free' => $tier]);
        $limiter->quota_set('user:1', 1000, 7200);
        $plan = $limiter->plan('free');

        $this->assertTrue($limiter->quota_reserve('user:1', 'a', $plan, 'spam_check')->allowed);

        $denied = $limiter->quota_reserve('user:1', 'b', $plan, 'spam_check');
        $this->assertFalse($denied->allowed);
        $this->assertSame('{user:1}.pacing.spam_check.3600', $denied->limited_by);
    }

    public function test_two_windows_on_the_same_key_are_refused(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $tier = $this->tier();
        $tier['pacing'] = [
            ['limit' => 10, 'window' => 60],
            ['limit' => 20, 'window' => 60],
        ];
        $this->set_config(['free' => $tier]);
        $limiter->quota_set('user:1', 100, 60);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate pacing key');

        $limiter->quota_reserve('user:1', 'a', $limiter->plan('free'), 'spam_check');
    }

    public function test_plan_rejects_shared_global_pacing(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $tier = $this->tier();
        $tier['operations']['spam_check'] = ['cost' => 1, 'global' => [['limit' => 2, 'window' => 60]]];
        $this->set_config(['free' => $tier], ['spam_check']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("shared (global) pacing is not in this version");

        $limiter->plan('free');
    }

    public function test_meter_reserves_runs_and_settles(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $this->set_config();
        $limiter->quota_set('user:7', 100, 3600);
        $this->register_resolvers($limiter);

        $value = $limiter->meter(new QuotaSubject(7, 'pro'), 'seo_headline', static fn(): string => 'headline');

        $this->assertSame('headline', $value);
        $this->assertSame(95, $limiter->quota_get('user:7'));
    }

    public function test_meter_releases_on_exception_and_rethrows(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $this->set_config();
        $limiter->quota_set('user:7', 100, 3600);
        $this->register_resolvers($limiter);

        try {
            $limiter->meter(new QuotaSubject(7, 'pro'), 'seo_headline', static function (): never {
                throw new \RuntimeException('provider down');
            });
            $this->fail('Expected the closure exception to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('provider down', $e->getMessage());
        }

        $this->assertSame(100, $limiter->quota_get('user:7'));
    }

    public function test_meter_throws_a_quota_exception_carrying_the_result(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $this->set_config();
        $limiter->quota_set('user:7', 1, 3600);
        $this->register_resolvers($limiter);
        $ran = false;

        try {
            $limiter->meter(new QuotaSubject(7, 'pro'), 'seo_headline', static function () use (&$ran): string {
                $ran = true;
                return 'x';
            });
            $this->fail('Expected QuotaExceededException.');
        } catch (QuotaExceededException $e) {
            $this->assertFalse($e->result->allowed);
            $this->assertSame(QuotaResult::REASON_INSUFFICIENT_BALANCE, $e->result->reason);
            $this->assertSame(1, $e->result->balance);
        }

        $this->assertFalse($ran);
        $this->assertSame(1, $limiter->quota_get('user:7'));
    }

    public function test_meter_throws_when_no_plan_resolver_is_registered(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('resolve_plan_using()');

        $limiter->meter(new QuotaSubject(1, 'pro'), 'spam_check', static fn(): int => 1);
    }

    public function test_meter_throws_when_no_scope_resolver_is_registered(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $limiter->resolve_plan_using(static fn(QuotaSubject $s): string => $s->plan);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('resolve_scope_using()');

        $limiter->meter(new QuotaSubject(1, 'pro'), 'spam_check', static fn(): int => 1);
    }

    public function test_a_resolver_may_return_a_plan_object_directly(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $plan = new QuotaPlan('db', 100, 3600, 300, [], ['spam_check' => new QuotaOperationSpec(7, [])], ['spam_check']);
        $limiter->resolve_plan_using(static fn(): QuotaPlan => $plan);
        $limiter->resolve_scope_using(static fn(QuotaSubject $s): string => 'user:' . $s->id);
        $limiter->quota_set('user:7', 100, 3600);

        $limiter->meter(new QuotaSubject(7, 'ignored'), 'spam_check', static fn(): int => 1);

        $this->assertSame(93, $limiter->quota_get('user:7'));
    }

    public function test_a_release_after_a_renewal_does_not_inflate_the_new_balance(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $this->set_config();
        $limiter->quota_set('user:1', 100, 3600);
        $plan = $limiter->plan('pro');

        $limiter->quota_reserve('user:1', 'a', $plan, 'seo_headline');
        $this->assertSame(95, $limiter->quota_get('user:1'));

        $limiter->quota_set('user:1', 100, 3600);

        $limiter->quota_release('user:1', 'a');

        $this->assertSame(100, $limiter->quota_get('user:1'));
    }

    public function test_a_release_within_the_same_epoch_still_refunds(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $this->set_config();
        $limiter->quota_set('user:1', 100, 3600);
        $limiter->quota_add('user:1', 50, 3600);
        $plan = $limiter->plan('pro');

        $limiter->quota_reserve('user:1', 'a', $plan, 'seo_headline');
        $limiter->quota_release('user:1', 'a');

        $this->assertSame(150, $limiter->quota_get('user:1'));
    }

    public function test_set_quota_on_an_empty_scope_starts_a_new_epoch(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);

        $this->assertSame(500, $limiter->quota_set('user:1', 500, 100));
        $this->assertSame(500, $limiter->quota_get('user:1'));
        $this->assertSame(100, $limiter->quota_ttl('user:1'));
        $this->assert_epoch_present($store, $limiter, 'user:1');
        $this->assertSame(0, $store->ttl($limiter->epoch_key('user:1')));
        $this->assert_conservation($limiter, $store, 'user:1', 500, 0, 0);
    }

    public function test_set_quota_after_a_reserve_does_not_roll_unused_credit(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['balance' => QuotaPlans::p1()]));
        $limiter->quota_set('user:1', 500, 100);
        $this->assertTrue($limiter->quota_reserve('user:1', 'a', $limiter->plan('balance'), 'spam_check')->allowed);
        $this->assertSame(499, $limiter->quota_get('user:1'));
        $store->advance(50);

        $first = $store->get_string($limiter->epoch_key('user:1'));
        $this->assertSame(500, $limiter->quota_set('user:1', 500, 100));
        $this->assertSame(500, $limiter->quota_get('user:1'));
        $this->assertSame(100, $limiter->quota_ttl('user:1'));
        $this->assert_epoch_present($store, $limiter, 'user:1', $first);
    }

    public function test_release_after_a_renewal_leaves_the_new_grant(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['balance' => QuotaPlans::p1()]));
        $limiter->quota_set('user:1', 10, 3600);
        $this->assertTrue($limiter->quota_reserve('user:1', 'a', $limiter->plan('balance'), 'spam_check')->allowed);

        $limiter->quota_set('user:1', 10, 3600);
        $limiter->quota_release('user:1', 'a');

        $this->assertSame(10, $limiter->quota_get('user:1'));
        $this->assertFalse($store->has_reservation($limiter->reservation_key('user:1', 'a')));
        $this->assert_conservation($limiter, $store, 'user:1', 10, 0, 0);
    }

    public function test_a_second_set_quota_replaces_the_grant_and_bumps_the_epoch(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);

        $limiter->quota_set('user:1', 100, 60);
        $first = $store->get_string($limiter->epoch_key('user:1'));
        $this->assertSame(40, $limiter->quota_set('user:1', 40, 60));
        $this->assertSame(40, $limiter->quota_get('user:1'));
        $this->assert_epoch_present($store, $limiter, 'user:1', $first);
    }

    public function test_add_quota_on_an_empty_scope_starts_the_period(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);

        $this->assertSame(100, $limiter->quota_add('user:1', 100, 60));
        $this->assertSame(100, $limiter->quota_get('user:1'));
        $this->assertGreaterThan(0, $limiter->quota_ttl('user:1'));
        $this->assertLessThanOrEqual(60, $limiter->quota_ttl('user:1'));
        $this->assertSame('', $store->get_string($limiter->epoch_key('user:1')));
    }

    public function test_add_quota_after_a_reserve_is_refunded_on_release(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['pro' => QuotaPlans::p3()]));
        $limiter->quota_set('user:1', 100, 3600);
        $plan = $limiter->plan('pro');

        $this->assertTrue($limiter->quota_reserve('user:1', 'a', $plan, 'seo_headline')->allowed);
        $this->assertSame(95, $limiter->quota_get('user:1'));
        $this->assert_conservation($limiter, $store, 'user:1', 100, 5, 0);

        $epoch = $store->get_string($limiter->epoch_key('user:1'));
        $limiter->quota_add('user:1', 50, 3600);
        $limiter->quota_release('user:1', 'a');

        $this->assertSame(150, $limiter->quota_get('user:1'));
        $this->assertSame($epoch, $store->get_string($limiter->epoch_key('user:1')));
        $this->assert_conservation($limiter, $store, 'user:1', 150, 0, 0);
    }

    public function test_add_quota_after_the_balance_ttl_starts_a_new_period(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $limiter->quota_set('user:1', 100, 60);
        $epoch = $store->get_string($limiter->epoch_key('user:1'));
        $store->advance(61);

        $this->assertSame(0, $limiter->quota_get('user:1'));
        $this->assertSame(50, $limiter->quota_add('user:1', 50, 60));
        $this->assertSame(50, $limiter->quota_get('user:1'));
        $this->assertGreaterThan(0, $limiter->quota_ttl('user:1'));
        $this->assertLessThanOrEqual(60, $limiter->quota_ttl('user:1'));
        $this->assertSame($epoch, $store->get_string($limiter->epoch_key('user:1')));
    }

    public function test_quota_get_on_a_never_granted_scope_is_zero(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());

        $this->assertSame(0, $limiter->quota_get('user:1'));
        $this->assertSame(0, $limiter->quota_ttl('user:1'));
    }

    public function test_quota_get_after_the_balance_ttl_is_zero(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['short_period' => QuotaPlans::p8()]));
        $limiter->quota_set('user:1', 100, 1);
        $this->assertTrue($limiter->quota_reserve('user:1', 'a', $limiter->plan('short_period'), 'spam_check')->allowed);
        $store->advance(2);

        $this->assertSame(0, $limiter->quota_get('user:1'));
        $this->assertSame(0, $limiter->quota_ttl('user:1'));
        $this->assertTrue($store->exists($limiter->epoch_key('user:1')));
    }

    public function test_clear_quota_is_the_only_epoch_reset(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $limiter->quota_set('user:1', 100, 60);
        $old = $store->get_string($limiter->epoch_key('user:1'));
        $limiter->quota_clear('user:1');

        $this->assertSame(0, $limiter->quota_get('user:1'));
        $this->assertFalse($store->exists($limiter->epoch_key('user:1')));

        $limiter->quota_set('user:1', 40, 60);
        $this->assert_epoch_present($store, $limiter, 'user:1', $old);
    }

    public function test_clear_quota_does_not_touch_another_scope(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $limiter->quota_set('user:1', 100, 60);
        $limiter->quota_set('user:2', 80, 60);

        $limiter->quota_clear('user:1');

        $this->assertSame(0, $limiter->quota_get('user:1'));
        $this->assertSame(80, $limiter->quota_get('user:2'));
        $this->assert_epoch_present($store, $limiter, 'user:2');
        $this->assertFalse($store->exists($limiter->epoch_key('user:1')));
    }

    public function test_clear_then_set_does_not_let_a_stale_reservation_invent_credits(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $plan = $this->paced_plan(100, 1000, 60, 40);
        $limiter->quota_set('user:1', 100, 3600);
        $old = $store->get_string($limiter->epoch_key('user:1'));

        $this->assertTrue($limiter->quota_reserve('user:1', 'live', $plan, 'spam_check')->allowed);
        $this->assertSame(60, $limiter->quota_get('user:1'));

        $store->clear($limiter->balance_key('user:1'));
        $store->clear($limiter->epoch_key('user:1'));
        $limiter->quota_set('user:1', 100, 3600);
        $limiter->quota_release('user:1', 'live');

        $this->assertSame(100, $limiter->quota_get('user:1'));
        $this->assert_epoch_present($store, $limiter, 'user:1', $old);
    }

    public function test_clear_quota_does_not_leave_pacing_members_on_the_new_grant(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $plan = $this->paced_plan(1000, 4, 60, 2);
        $limiter->quota_set('user:1', 1000, 3600);

        $this->assertTrue($limiter->quota_reserve('user:1', 'a', $plan, 'spam_check')->allowed);
        $this->assertTrue($limiter->quota_reserve('user:1', 'b', $plan, 'spam_check')->allowed);
        $this->assertFalse($limiter->quota_reserve('user:1', 'c', $plan, 'spam_check')->allowed);

        $limiter->quota_clear('user:1');
        $limiter->quota_set('user:1', 1000, 3600);

        $result = $limiter->quota_reserve('user:1', 'fresh', $plan, 'spam_check');
        $this->assertTrue($result->allowed);
        $this->assertSame(QuotaResult::REASON_OK, $result->reason);
        $this->assertSame(998, $limiter->quota_get('user:1'));
        $this->assertSame(1, $store->zcard('{user:1}.pacing._tier.60'));
    }

    public function test_an_unknown_operation_leaves_the_balance_untouched(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::docs_hive());
        $limiter->quota_set('user:1', 100, 60);

        try {
            $limiter->quota_reserve('user:1', 'a', $limiter->plan('free'), 'seo_headlines');
            $this->fail('Expected an unknown operation to throw.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("Unknown quota operation: 'seo_headlines'", $e->getMessage());
        }

        $this->assertSame(100, $limiter->quota_get('user:1'));
        $this->assertFalse($store->has_reservation($limiter->reservation_key('user:1', 'a')));
        $this->assert_conservation($limiter, $store, 'user:1', 100, 0, 0);
    }

    public function test_a_declared_operation_absent_from_the_tier_writes_no_reservation(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::docs_hive());
        $limiter->quota_set('user:1', 100, 60);

        $result = $limiter->quota_reserve('user:1', 'a', $limiter->plan('free'), 'seo_headline');

        $this->assert_denied($result, \Engine\Atomic\Quota\QuotaResult::REASON_NOT_ENTITLED, 100, null, null);
        $this->assertSame(100, $limiter->quota_get('user:1'));
        $this->assertFalse($store->has_reservation($limiter->reservation_key('user:1', 'a')));
        $this->assertSame(0, $store->zcard('{user:1}.pacing._tier.60'));
        $this->assert_conservation($limiter, $store, 'user:1', 100, 0, 0);
    }

    public function test_an_entitled_operation_proceeds_to_the_store(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::docs_hive());
        $limiter->quota_set('user:1', 500, 3600);

        $result = $limiter->quota_reserve('user:1', 'a', $limiter->plan('free'), 'spam_check');

        $this->assert_allowed($result, 499);
        $this->assertTrue($store->has_reservation($limiter->reservation_key('user:1', 'a')));
        $this->assert_conservation($limiter, $store, 'user:1', 500, 1, 0);
    }

    public function test_a_duplicate_reservation_is_reported_before_insufficient_balance(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['balance' => QuotaPlans::p1()]));
        $limiter->quota_set('user:1', 100, 60);
        $plan = $limiter->plan('balance');

        $first = $limiter->quota_reserve('user:1', 'a', $plan, 'spam_check', 5);
        $this->assert_allowed($first, 95);

        $duplicate = $limiter->quota_reserve('user:1', 'a', $plan, 'spam_check', 200);

        $this->assert_denied($duplicate, \Engine\Atomic\Quota\QuotaResult::REASON_DUPLICATE_RESERVATION, 95, null, null);
        $this->assertSame(95, $limiter->quota_get('user:1'));
        $this->assertSame(5, $store->outstanding('{user:1}.reservation.'));
        $this->assert_conservation($limiter, $store, 'user:1', 100, 5, 0);
    }

    public function test_a_cost_that_fits_exactly_is_allowed(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['balance' => QuotaPlans::p1()]));
        $limiter->quota_set('user:1', 5, 3600);

        $result = $limiter->quota_reserve('user:1', 'a', $limiter->plan('balance'), 'spam_check', 5);

        $this->assert_allowed($result, 0);
        $this->assert_conservation($limiter, $store, 'user:1', 5, 5, 0);
    }

    public function test_an_overage_is_insufficient_balance_with_the_period_as_retry_after(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['balance' => QuotaPlans::p1()]));
        $limiter->quota_set('user:1', 3, 60);
        $before = $limiter->quota_get('user:1');

        $result = $limiter->quota_reserve('user:1', 'a', $limiter->plan('balance'), 'spam_check', 5);

        $this->assert_denied($result, \Engine\Atomic\Quota\QuotaResult::REASON_INSUFFICIENT_BALANCE, 3, 60, null);
        $this->assertSame($before, $limiter->quota_get('user:1'));
        $this->assertFalse($store->has_reservation($limiter->reservation_key('user:1', 'a')));
        $this->assert_conservation($limiter, $store, 'user:1', 3, 0, 0);
    }

    public function test_a_zero_grant_denies_a_positive_cost(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['zero' => QuotaPlans::p6()]));
        $limiter->quota_set('user:1', 0, 3600);

        $result = $limiter->quota_reserve('user:1', 'a', $limiter->plan('zero'), 'spam_check');

        $this->assert_denied($result, \Engine\Atomic\Quota\QuotaResult::REASON_INSUFFICIENT_BALANCE, 0, 3600, null);
        $this->assertFalse($store->has_reservation($limiter->reservation_key('user:1', 'a')));
        $this->assert_conservation($limiter, $store, 'user:1', 0, 0, 0);
    }

    public function test_a_zero_cost_reserve_is_a_noop_on_the_balance(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['balance' => QuotaPlans::p1()]));
        $limiter->quota_set('user:1', 10, 3600);
        $plan = $limiter->plan('balance');

        $result = $limiter->quota_reserve('user:1', 'a', $plan, 'spam_check', 0);

        $this->assert_allowed($result, 10);
        $this->assertSame(10, $limiter->quota_settle('user:1', 'a'));
        $this->assertSame(10, $limiter->quota_get('user:1'));

        $limiter->quota_reserve('user:1', 'b', $plan, 'spam_check', 0);
        $limiter->quota_release('user:1', 'b');
        $this->assertSame(10, $limiter->quota_get('user:1'));
        $this->assert_conservation($limiter, $store, 'user:1', 10, 0, 0);
    }

    public function test_a_missing_grant_is_insufficient_balance(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['balance' => QuotaPlans::p1()]));

        $result = $limiter->quota_reserve('user:1', 'a', $limiter->plan('balance'), 'spam_check');

        $this->assertFalse($result->allowed);
        $this->assertSame(\Engine\Atomic\Quota\QuotaResult::REASON_INSUFFICIENT_BALANCE, $result->reason);
        $this->assertSame(0, $result->balance);
        $this->assertNull($result->retry_after);
        $this->assertNull($result->limited_by);
        $this->assertFalse($store->has_reservation($limiter->reservation_key('user:1', 'a')));
    }

    public function test_exact_remaining_after_n_reserves_until_the_balance_is_exhausted(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['balance' => QuotaPlans::p1()]));
        $limiter->quota_set('user:1', 10, 3600);
        $plan = $limiter->plan('balance');

        for ($i = 1; $i <= 10; $i++) {
            $result = $limiter->quota_reserve('user:1', 'r' . $i, $plan, 'spam_check');
            $this->assert_allowed($result, 10 - $i);
        }

        $denied = $limiter->quota_reserve('user:1', 'r11', $plan, 'spam_check');
        $this->assert_denied($denied, \Engine\Atomic\Quota\QuotaResult::REASON_INSUFFICIENT_BALANCE, 0, 3600, null);
        $this->assertSame(0, $limiter->quota_get('user:1'));
        $this->assertFalse($store->has_reservation($limiter->reservation_key('user:1', 'r11')));
        $this->assert_conservation($limiter, $store, 'user:1', 10, 10, 0);
    }

    public function test_a_negative_cost_throws_and_writes_nothing(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['balance' => QuotaPlans::p1()]));
        $limiter->quota_set('user:1', 10, 3600);

        try {
            $limiter->quota_reserve('user:1', 'a', $limiter->plan('balance'), 'spam_check', -1);
            $this->fail('Expected a negative cost to throw.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('cost must not be negative', $e->getMessage());
        }

        $this->assertSame(10, $limiter->quota_get('user:1'));
        $this->assertFalse($store->has_reservation($limiter->reservation_key('user:1', 'a')));
    }

    public function test_a_cost_override_larger_than_remaining_does_not_charge(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::docs_hive());
        $limiter->quota_set('user:1', 10, 3600);
        $plan = $limiter->plan('pro');

        $denied = $limiter->quota_reserve('user:1', 'a', $plan, 'seo_headline', 20);

        $this->assert_denied($denied, \Engine\Atomic\Quota\QuotaResult::REASON_INSUFFICIENT_BALANCE, 10, 3600, null);
        $this->assertSame(10, $limiter->quota_get('user:1'));
        $this->assertFalse($store->has_reservation($limiter->reservation_key('user:1', 'a')));
    }

    public function test_pacing_allows_two_cost_two_reserves_on_a_four_window(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['tight' => QuotaPlans::p4()]));
        $limiter->quota_set('user:1', 1000, 7200);
        $plan = $limiter->plan('tight');

        $this->assert_allowed($limiter->quota_reserve('user:1', 'a', $plan, 'spam_check'), 998);
        $this->assert_allowed($limiter->quota_reserve('user:1', 'b', $plan, 'spam_check'), 996);
        $this->assertSame(996, $limiter->quota_get('user:1'));
        $this->assert_conservation($limiter, $store, 'user:1', 1000, 4, 0);
    }

    public function test_pacing_denies_the_third_reserve_on_a_four_window(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['tight' => QuotaPlans::p4()]));
        $limiter->quota_set('user:1', 1000, 7200);
        $plan = $limiter->plan('tight');
        $limiter->quota_reserve('user:1', 'a', $plan, 'spam_check');
        $limiter->quota_reserve('user:1', 'b', $plan, 'spam_check');
        $zcard = $store->zcard('{user:1}.pacing._tier.60');

        $denied = $limiter->quota_reserve('user:1', 'c', $plan, 'spam_check');

        $this->assert_denied_pacing($denied, '{user:1}.pacing._tier.60', 60);
        $this->assertSame(996, $limiter->quota_get('user:1'));
        $this->assertSame($zcard, $store->zcard('{user:1}.pacing._tier.60'));
        $this->assertFalse($store->has_reservation($limiter->reservation_key('user:1', 'c')));
        $this->assert_conservation($limiter, $store, 'user:1', 1000, 4, 0);
    }

    public function test_release_frees_the_tight_pacing_window_for_a_third_reserve(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['tight' => QuotaPlans::p4()]));
        $limiter->quota_set('user:1', 1000, 7200);
        $plan = $limiter->plan('tight');
        $limiter->quota_reserve('user:1', 'a', $plan, 'spam_check');
        $limiter->quota_reserve('user:1', 'b', $plan, 'spam_check');
        $this->assertFalse($limiter->quota_reserve('user:1', 'c', $plan, 'spam_check')->allowed);

        $limiter->quota_release('user:1', 'a');
        $this->assertTrue($limiter->quota_reserve('user:1', 'c', $plan, 'spam_check')->allowed);
        $this->assertSame(996, $limiter->quota_get('user:1'));
        $this->assert_conservation($limiter, $store, 'user:1', 1000, 4, 0);
    }

    public function test_settle_leaves_the_tight_pacing_window_full(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['tight' => QuotaPlans::p4()]));
        $limiter->quota_set('user:1', 1000, 7200);
        $plan = $limiter->plan('tight');
        $limiter->quota_reserve('user:1', 'a', $plan, 'spam_check');
        $limiter->quota_reserve('user:1', 'b', $plan, 'spam_check');
        $limiter->quota_settle('user:1', 'a');

        $denied = $limiter->quota_reserve('user:1', 'c', $plan, 'spam_check');
        $this->assertSame(\Engine\Atomic\Quota\QuotaResult::REASON_PACING, $denied->reason);
        $this->assertSame(996, $limiter->quota_get('user:1'));
        $this->assertSame(2, $store->zcard('{user:1}.pacing._tier.60'));
        $this->assert_conservation($limiter, $store, 'user:1', 1000, 2, 2);
    }

    public function test_pacing_sums_an_override_cost_against_the_window(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $tier = QuotaPlans::p2();
        $tier['pacing'] = [['limit' => 10, 'window' => 60]];
        $this->install(QuotaPlans::hive(['free' => $tier]));
        $limiter->quota_set('user:1', 500, 3600);
        $plan = $limiter->plan('free');

        $this->assertTrue($limiter->quota_reserve('user:1', 'a', $plan, 'spam_check', 10)->allowed);
        $denied = $limiter->quota_reserve('user:1', 'b', $plan, 'spam_check', 1);
        $this->assertSame(\Engine\Atomic\Quota\QuotaResult::REASON_PACING, $denied->reason);
        $this->assertSame(490, $limiter->quota_get('user:1'));
        $this->assertFalse($store->has_reservation($limiter->reservation_key('user:1', 'b')));
    }

    public function test_operation_pacing_denies_while_the_tier_window_still_has_room(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['op_pace' => QuotaPlans::p5()]));
        $limiter->quota_set('user:1', 1000, 7200);
        $plan = $limiter->plan('op_pace');

        $this->assertTrue($limiter->quota_reserve('user:1', 'a', $plan, 'spam_check')->allowed);
        $tier_zcard = $store->zcard('{user:1}.pacing._tier.60');

        $denied = $limiter->quota_reserve('user:1', 'b', $plan, 'spam_check');
        $this->assertSame(\Engine\Atomic\Quota\QuotaResult::REASON_PACING, $denied->reason);
        $this->assertSame('{user:1}.pacing.spam_check.3600', $denied->limited_by);
        $this->assertSame(999, $limiter->quota_get('user:1'));
        $this->assertSame($tier_zcard, $store->zcard('{user:1}.pacing._tier.60'));
        $this->assertFalse($store->has_reservation($limiter->reservation_key('user:1', 'b')));
    }

    public function test_two_tier_windows_name_the_window_that_failed_and_leave_the_other_untouched(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::docs_hive());
        $limiter->quota_set('user:1', 20000, 2592000);
        $plan = $limiter->plan('pro');

        for ($i = 0; $i < 200; $i++) {
            $this->assertTrue($limiter->quota_reserve('user:1', 'r' . $i, $plan, 'spam_check')->allowed);
        }
        $day_zcard = $store->zcard('{user:1}.pacing._tier.86400');
        $this->assertSame(200, $day_zcard);
        $this->assertSame(19800, $limiter->quota_get('user:1'));

        $denied = $limiter->quota_reserve('user:1', 'overflow', $plan, 'spam_check');

        $this->assertSame(\Engine\Atomic\Quota\QuotaResult::REASON_PACING, $denied->reason);
        $this->assertSame('{user:1}.pacing._tier.60', $denied->limited_by);
        $this->assertSame(19800, $limiter->quota_get('user:1'));
        $this->assertSame(200, $store->zcard('{user:1}.pacing._tier.60'));
        $this->assertSame($day_zcard, $store->zcard('{user:1}.pacing._tier.86400'));
        $this->assertFalse($store->has_reservation($limiter->reservation_key('user:1', 'overflow')));
    }

    public function test_two_operations_share_the_tier_pacing_window(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['multi_op' => QuotaPlans::p9()]));
        $limiter->quota_set('user:1', 100, 3600);
        $plan = $limiter->plan('multi_op');

        $this->assertTrue($limiter->quota_reserve('user:1', 'a', $plan, 'spam_check')->allowed);
        $this->assertTrue($limiter->quota_reserve('user:1', 'b', $plan, 'seo_headline')->allowed);
        $this->assertSame(92, $limiter->quota_get('user:1'));

        $denied = $limiter->quota_reserve('user:1', 'c', $plan, 'spam_check');
        $this->assertSame(\Engine\Atomic\Quota\QuotaResult::REASON_PACING, $denied->reason);
        $this->assertSame(92, $limiter->quota_get('user:1'));
        $this->assertFalse($store->has_reservation($limiter->reservation_key('user:1', 'c')));
        $this->assert_conservation($limiter, $store, 'user:1', 100, 8, 0);
    }

    public function test_shared_tier_windows_are_isolated_per_scope(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['multi_op' => QuotaPlans::p9()]));
        $plan = $limiter->plan('multi_op');
        $limiter->quota_set('user:1', 100, 3600);
        $limiter->quota_set('user:2', 100, 3600);

        foreach (['user:1', 'user:2'] as $scope) {
            $this->assertTrue($limiter->quota_reserve($scope, 'a', $plan, 'spam_check')->allowed);
            $this->assertTrue($limiter->quota_reserve($scope, 'b', $plan, 'seo_headline')->allowed);
            $this->assertFalse($limiter->quota_reserve($scope, 'c', $plan, 'spam_check')->allowed);
        }

        $this->assertSame(92, $limiter->quota_get('user:1'));
        $this->assertSame(92, $limiter->quota_get('user:2'));
        $this->assertSame(8, $store->outstanding('{user:1}.reservation.'));
        $this->assertSame(8, $store->outstanding('{user:2}.reservation.'));
    }

    public function test_duplicate_pacing_keys_write_nothing(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $tier = QuotaPlans::p1();
        $tier['pacing'] = [
            ['limit' => 10, 'window' => 60],
            ['limit' => 20, 'window' => 60],
        ];
        $this->install(QuotaPlans::hive(['balance' => $tier]));
        $limiter->quota_set('user:1', 100, 60);

        try {
            $limiter->quota_reserve('user:1', 'a', $limiter->plan('balance'), 'spam_check');
            $this->fail('Expected duplicate pacing keys to throw.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('duplicate pacing key', $e->getMessage());
        }

        $this->assertSame(100, $limiter->quota_get('user:1'));
        $this->assertFalse($store->has_reservation($limiter->reservation_key('user:1', 'a')));
        $this->assertSame(0, $store->zcard('{user:1}.pacing._tier.60'));
    }

    public function test_a_pacing_window_rolls_and_frees_room(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['tight' => QuotaPlans::p4()]));
        $limiter->quota_set('user:1', 1000, 7200);
        $plan = $limiter->plan('tight');
        $limiter->quota_reserve('user:1', 'a', $plan, 'spam_check');
        $limiter->quota_reserve('user:1', 'b', $plan, 'spam_check');
        $this->assertFalse($limiter->quota_reserve('user:1', 'c', $plan, 'spam_check')->allowed);

        $store->advance(60);
        $this->assertTrue($limiter->quota_reserve('user:1', 'c', $plan, 'spam_check')->allowed);
        $this->assertSame(994, $limiter->quota_get('user:1'));
    }

    public function test_pacing_retry_after_drops_after_advance(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['tight' => QuotaPlans::p4()]));
        $limiter->quota_set('user:1', 1000, 7200);
        $plan = $limiter->plan('tight');
        $limiter->quota_reserve('user:1', 'a', $plan, 'spam_check');
        $limiter->quota_reserve('user:1', 'b', $plan, 'spam_check');

        $first = $limiter->quota_reserve('user:1', 'c', $plan, 'spam_check');
        $this->assertSame(\Engine\Atomic\Quota\QuotaResult::REASON_PACING, $first->reason);
        $retry = (int)$first->retry_after;

        $store->advance(10);
        $second = $limiter->quota_reserve('user:1', 'c', $plan, 'spam_check');
        $this->assertSame(\Engine\Atomic\Quota\QuotaResult::REASON_PACING, $second->reason);
        $this->assertSame(max(1, $retry - 10), $second->retry_after);
    }

    public function test_a_denied_second_window_does_not_add_a_member_to_the_first(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['op_pace' => QuotaPlans::p5()]));
        $limiter->quota_set('user:1', 1000, 7200);
        $plan = $limiter->plan('op_pace');
        $this->assertTrue($limiter->quota_reserve('user:1', 'a', $plan, 'spam_check')->allowed);

        $tier_before = $store->zcard('{user:1}.pacing._tier.60');
        $op_before = $store->zcard('{user:1}.pacing.spam_check.3600');
        $remaining = $limiter->quota_get('user:1');

        $denied = $limiter->quota_reserve('user:1', 'b', $plan, 'spam_check');

        $this->assertSame(\Engine\Atomic\Quota\QuotaResult::REASON_PACING, $denied->reason);
        $this->assertSame('{user:1}.pacing.spam_check.3600', $denied->limited_by);
        $this->assertSame($remaining, $limiter->quota_get('user:1'));
        $this->assertSame($tier_before, $store->zcard('{user:1}.pacing._tier.60'));
        $this->assertSame($op_before, $store->zcard('{user:1}.pacing.spam_check.3600'));
        $this->assertFalse($store->has_reservation($limiter->reservation_key('user:1', 'b')));
    }

    public function test_settle_keeps_the_charge_and_drops_the_reservation(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::docs_hive());
        $limiter->quota_set('user:1', 100, 3600);

        $this->assertTrue($limiter->quota_reserve('user:1', 'a', $limiter->plan('pro'), 'seo_headline')->allowed);
        $this->assertSame(95, $limiter->quota_settle('user:1', 'a'));
        $this->assertSame(95, $limiter->quota_get('user:1'));
        $this->assertFalse($store->has_reservation($limiter->reservation_key('user:1', 'a')));
        $this->assertSame(1, $store->zcard('{user:1}.pacing._tier.60'));
        $this->assert_conservation($limiter, $store, 'user:1', 100, 0, 5);
    }

    public function test_settle_after_the_balance_ttl_returns_zero_and_does_not_recreate_it(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['short_period' => QuotaPlans::p8()]));
        $limiter->quota_set('user:1', 100, 1);
        $this->assertTrue($limiter->quota_reserve('user:1', 'a', $limiter->plan('short_period'), 'spam_check')->allowed);
        $store->advance(2);

        $this->assertSame(0, $limiter->quota_settle('user:1', 'a'));
        $this->assertSame(0, $limiter->quota_get('user:1'));
        $this->assertFalse($store->exists($limiter->balance_key('user:1')));
    }

    public function test_settle_twice_is_a_noop_on_the_second_call(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::docs_hive());
        $limiter->quota_set('user:1', 100, 3600);
        $limiter->quota_reserve('user:1', 'a', $limiter->plan('pro'), 'seo_headline');
        $this->assertSame(95, $limiter->quota_settle('user:1', 'a'));
        $this->assertSame(95, $limiter->quota_settle('user:1', 'a'));
        $this->assertSame(95, $limiter->quota_get('user:1'));
    }

    public function test_the_same_id_after_settle_is_a_new_paced_spend(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['tight' => QuotaPlans::p4()]));
        $limiter->quota_set('user:1', 1000, 7200);
        $plan = $limiter->plan('tight');

        $this->assertTrue($limiter->quota_reserve('user:1', 'a', $plan, 'spam_check')->allowed);
        $limiter->quota_settle('user:1', 'a');
        $this->assertSame(1, $store->zcard('{user:1}.pacing._tier.60'));
        $this->assertSame(2, $store->zset_weight('{user:1}.pacing._tier.60'));

        $this->assertTrue($limiter->quota_reserve('user:1', 'a', $plan, 'spam_check')->allowed);
        $this->assertSame(2, $store->zcard('{user:1}.pacing._tier.60'));
        $this->assertSame(4, $store->zset_weight('{user:1}.pacing._tier.60'));
        $this->assertSame(996, $limiter->quota_get('user:1'));
        $this->assert_conservation($limiter, $store, 'user:1', 1000, 2, 2);
        $this->assertSame(QuotaResult::REASON_PACING, $limiter->quota_reserve('user:1', 'c', $plan, 'spam_check')->reason);
    }

    public function test_release_restores_the_balance_and_removes_the_member(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::docs_hive());
        $limiter->quota_set('user:1', 100, 3600);
        $limiter->quota_reserve('user:1', 'a', $limiter->plan('pro'), 'seo_headline');

        $limiter->quota_release('user:1', 'a');

        $this->assertSame(100, $limiter->quota_get('user:1'));
        $this->assertFalse($store->has_reservation($limiter->reservation_key('user:1', 'a')));
        $this->assertSame(0, $store->zcard('{user:1}.pacing._tier.60'));
        $this->assert_conservation($limiter, $store, 'user:1', 100, 0, 0);
    }

    public function test_release_of_one_of_two_live_reservations_leaves_the_other(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['balance' => QuotaPlans::p1()]));
        $limiter->quota_set('user:1', 20, 3600);
        $plan = $limiter->plan('balance');
        $this->assertTrue($limiter->quota_reserve('user:1', 'a', $plan, 'spam_check', 6)->allowed);
        $this->assertTrue($limiter->quota_reserve('user:1', 'b', $plan, 'spam_check', 4)->allowed);

        $limiter->quota_release('user:1', 'a');

        $this->assertSame(16, $limiter->quota_get('user:1'));
        $this->assertFalse($store->has_reservation($limiter->reservation_key('user:1', 'a')));
        $this->assertTrue($store->has_reservation($limiter->reservation_key('user:1', 'b')));
        $this->assert_conservation($limiter, $store, 'user:1', 20, 4, 0);
    }

    public function test_an_expired_reservation_on_the_cheap_plan_keeps_the_charge(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['cheap' => QuotaPlans::p7()]));
        $limiter->quota_set('user:1', 100, 3600);
        $this->assertTrue($limiter->quota_reserve('user:1', 'a', $limiter->plan('cheap'), 'spam_check')->allowed);
        $store->advance(2);

        $limiter->quota_release('user:1', 'a');

        $this->assertSame(95, $limiter->quota_get('user:1'));
        $this->assertFalse($store->has_reservation($limiter->reservation_key('user:1', 'a')));
        $this->assert_conservation($limiter, $store, 'user:1', 100, 0, 5);
    }

    public function test_release_after_the_balance_ttl_and_a_new_grant_does_not_inflate(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['short_period' => QuotaPlans::p8()]));
        $limiter->quota_set('user:1', 100, 1);
        $this->assertTrue($limiter->quota_reserve('user:1', 'a', $limiter->plan('short_period'), 'spam_check')->allowed);
        $store->advance(2);
        $limiter->quota_set('user:1', 200, 60);
        $limiter->quota_release('user:1', 'a');

        $this->assertSame(200, $limiter->quota_get('user:1'));
    }

    public function test_release_after_the_pacing_window_rolled_still_refunds(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['tight' => QuotaPlans::p4()]));
        $limiter->quota_set('user:1', 1000, 7200);
        $this->assertTrue($limiter->quota_reserve('user:1', 'a', $limiter->plan('tight'), 'spam_check')->allowed);
        $store->advance(60);

        $limiter->quota_release('user:1', 'a');

        $this->assertSame(1000, $limiter->quota_get('user:1'));
        $this->assertSame(0, $store->zcard('{user:1}.pacing._tier.60'));
    }

    public function test_release_after_settle_is_a_noop_on_the_balance(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::docs_hive());
        $limiter->quota_set('user:1', 100, 3600);
        $limiter->quota_reserve('user:1', 'a', $limiter->plan('pro'), 'seo_headline');
        $limiter->quota_settle('user:1', 'a');
        $limiter->quota_release('user:1', 'a');

        $this->assertSame(95, $limiter->quota_get('user:1'));
        $this->assert_conservation($limiter, $store, 'user:1', 100, 0, 5);
    }

    public function test_scopes_are_isolated_across_reserve_settle_and_release(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['balance' => QuotaPlans::p1()]));
        $plan = $limiter->plan('balance');
        $limiter->quota_set('user:1', 10, 3600);
        $limiter->quota_set('user:2', 10, 3600);

        $this->assertTrue($limiter->quota_reserve('user:1', 'a', $plan, 'spam_check')->allowed);
        $this->assertSame(10, $limiter->quota_get('user:2'));
        $this->assert_epoch_present($store, $limiter, 'user:2');

        $limiter->quota_settle('user:1', 'a');
        $this->assertSame(10, $limiter->quota_get('user:2'));

        $this->assertTrue($limiter->quota_reserve('user:1', 'b', $plan, 'spam_check')->allowed);
        $limiter->quota_release('user:1', 'b');
        $this->assertSame(9, $limiter->quota_get('user:1'));
        $this->assertSame(10, $limiter->quota_get('user:2'));
        $this->assertSame(0, $store->outstanding('{user:2}.reservation.'));
    }

    public function test_meter_denies_an_operation_the_tier_does_not_include(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::docs_hive());
        $limiter->quota_set('user:7', 100, 3600);
        $this->register_resolvers($limiter);
        $ran = false;

        try {
            $limiter->meter(new QuotaSubject(7, 'free'), 'seo_headline', static function () use (&$ran): string {
                $ran = true;
                return 'x';
            });
            $this->fail('Expected QuotaExceededException.');
        } catch (\Engine\Atomic\Quota\Exceptions\QuotaExceededException $e) {
            $this->assertSame(\Engine\Atomic\Quota\QuotaResult::REASON_NOT_ENTITLED, $e->result->reason);
        }

        $this->assertFalse($ran);
        $this->assertSame(100, $limiter->quota_get('user:7'));
        $this->assertSame(0, $store->outstanding('{user:7}.reservation.'));
    }

    public function test_meter_throws_on_an_undeclared_operation_before_running_work(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $this->install(QuotaPlans::docs_hive());
        $limiter->quota_set('user:7', 100, 3600);
        $this->register_resolvers($limiter);
        $ran = false;

        try {
            $limiter->meter(new QuotaSubject(7, 'pro'), 'seo_headlines', static function () use (&$ran): string {
                $ran = true;
                return 'x';
            });
            $this->fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("Unknown quota operation: 'seo_headlines'", $e->getMessage());
        }

        $this->assertFalse($ran);
        $this->assertSame(100, $limiter->quota_get('user:7'));
    }

    public function test_meter_throws_pacing_after_the_window_is_full(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::hive(['tight' => QuotaPlans::p4()]));
        $limiter->quota_set('user:7', 1000, 7200);
        $limiter->resolve_plan_using(static fn(): string => 'tight');
        $limiter->resolve_scope_using(static fn(QuotaSubject $s): string => 'user:' . $s->id);

        $limiter->meter(new QuotaSubject(7, 'tight'), 'spam_check', static fn(): int => 1);
        $limiter->meter(new QuotaSubject(7, 'tight'), 'spam_check', static fn(): int => 1);
        $this->assertSame(996, $limiter->quota_get('user:7'));

        try {
            $limiter->meter(new QuotaSubject(7, 'tight'), 'spam_check', static fn(): int => 1);
            $this->fail('Expected QuotaExceededException.');
        } catch (\Engine\Atomic\Quota\Exceptions\QuotaExceededException $e) {
            $this->assertSame(\Engine\Atomic\Quota\QuotaResult::REASON_PACING, $e->result->reason);
        }

        $this->assertSame(996, $limiter->quota_get('user:7'));
        $this->assertSame(0, $store->outstanding('{user:7}.reservation.'));
        $this->assertSame(2, $store->zcard('{user:7}.pacing._tier.60'));
    }

    public function test_meter_charges_two_subjects_independently(): void
    {
        $store = new TestQuotaStore();
        $limiter = new QuotaLimiter($store);
        $this->install(QuotaPlans::docs_hive());
        $this->register_resolvers($limiter);
        $limiter->quota_set('user:7', 100, 3600);
        $limiter->quota_set('user:8', 100, 3600);

        $limiter->meter(new QuotaSubject(7, 'pro'), 'seo_headline', static fn(): string => 'a');
        $limiter->meter(new QuotaSubject(8, 'pro'), 'seo_headline', static fn(): string => 'b');

        $this->assertSame(95, $limiter->quota_get('user:7'));
        $this->assertSame(95, $limiter->quota_get('user:8'));
        $this->assertSame(1, $store->zcard('{user:7}.pacing._tier.60'));
        $this->assertSame(1, $store->zcard('{user:8}.pacing._tier.60'));
    }

    public function test_empty_quota_config_cannot_plan_a_spend(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());
        $this->install(QuotaPlans::p0());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Undefined quota tier');

        $limiter->plan('free');
    }

    /**
     * @param positive-int $credits
     * @param positive-int $limit
     * @param positive-int $window
     * @param positive-int $cost
     */
    private function paced_plan(int $credits, int $limit, int $window, int $cost): QuotaPlan
    {
        $tier = $this->tier();
        $tier['credits'] = $credits;
        $tier['pacing'] = [['limit' => $limit, 'window' => $window]];
        $tier['operations']['spam_check'] = ['cost' => $cost];
        $this->set_config(['free' => $tier]);

        return (new QuotaLimiter(new TestQuotaStore()))->plan('free');
    }

    private function assert_epoch_present(
        TestQuotaStore $store,
        QuotaLimiter $limiter,
        string $scope,
        ?string $not_same_as = null
    ): string {
        $epoch = $store->get_string($limiter->epoch_key($scope));
        $this->assertNotSame('', $epoch);
        $this->assertTrue($store->exists($limiter->epoch_key($scope)));
        if ($not_same_as !== null) {
            $this->assertNotSame($not_same_as, $epoch);
        }

        return $epoch;
    }

    private function plan(): QuotaPlan
    {
        return new QuotaPlan(
            'test',
            500,
            2592000,
            300,
            [],
            ['spam_check' => new QuotaOperationSpec(1, [])],
            ['spam_check', 'seo_headline']
        );
    }

    /** @return array<string, mixed> */
    private function tier(): array
    {
        return [
            'credits' => 500,
            'period' => 2592000,
            'reservation_ttl' => 300,
            'pacing' => [['limit' => 20, 'window' => 60]],
            'operations' => ['spam_check' => ['cost' => 1]],
        ];
    }

    private function set_config(?array $quotas = null, ?array $operations = null): void
    {
        \Base::instance()->set('QUOTA', [
            'operations' => $operations ?? ['spam_check', 'seo_headline'],
            'quotas' => $quotas ?? [
                'free' => $this->tier(),
                'pro' => [
                    'credits' => 20000,
                    'period' => 2592000,
                    'reservation_ttl' => 300,
                    'pacing' => [['limit' => 200, 'window' => 60]],
                    'operations' => [
                        'spam_check' => ['cost' => 1],
                        'seo_headline' => [
                            'cost' => 5,
                            'pacing' => [['limit' => 30, 'window' => 3600]],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function register_resolvers(QuotaLimiter $limiter): void
    {
        $limiter->resolve_plan_using(static fn(QuotaSubject $s): string => $s->plan);
        $limiter->resolve_scope_using(static fn(QuotaSubject $s): string => 'user:' . $s->id);
    }

    /** @param array<string, mixed> $quota */
    private function install(array $quota): void
    {
        \Base::instance()->set('QUOTA', $quota);
    }

    private function assert_allowed(\Engine\Atomic\Quota\QuotaResult $result, int $remaining): void
    {
        $this->assertTrue($result->allowed);
        $this->assertSame(\Engine\Atomic\Quota\QuotaResult::REASON_OK, $result->reason);
        $this->assertSame($remaining, $result->balance);
        $this->assertNull($result->retry_after);
        $this->assertNull($result->limited_by);
    }

    private function assert_denied(
        \Engine\Atomic\Quota\QuotaResult $result,
        string $reason,
        int $remaining,
        ?int $retry_after,
        ?string $limited_by
    ): void {
        $this->assertFalse($result->allowed);
        $this->assertSame($reason, $result->reason);
        $this->assertSame($remaining, $result->balance);
        $this->assertSame($retry_after, $result->retry_after);
        $this->assertSame($limited_by, $result->limited_by);
    }

    private function assert_denied_pacing(\Engine\Atomic\Quota\QuotaResult $result, string $limited_by, int $window): void
    {
        $this->assertFalse($result->allowed);
        $this->assertSame(\Engine\Atomic\Quota\QuotaResult::REASON_PACING, $result->reason);
        $this->assertSame($limited_by, $result->limited_by);
        $this->assertNotNull($result->retry_after);
        $this->assertGreaterThan(0, (int)$result->retry_after);
        $this->assertLessThanOrEqual($window, (int)$result->retry_after);
    }

    private function assert_conservation(
        QuotaLimiter $limiter,
        TestQuotaStore $store,
        string $scope,
        int $granted,
        int $outstanding,
        int $settled
    ): void {
        $remaining = $limiter->quota_get($scope);
        $this->assertSame(
            $granted,
            $remaining + $outstanding + $settled,
            "C1 remaining={$remaining} outstanding={$outstanding} settled={$settled} granted={$granted}"
        );
        $this->assertSame($outstanding, $store->outstanding('{' . $scope . '}.reservation.'));
    }
}
