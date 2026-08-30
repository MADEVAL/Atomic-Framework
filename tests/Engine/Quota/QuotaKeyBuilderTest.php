<?php
declare(strict_types=1);

namespace Tests\Engine\Quota;

use Engine\Atomic\Quota\QuotaKeyBuilder;
use Engine\Atomic\Quota\QuotaLimiter;
use Engine\Atomic\Quota\QuotaPacingWindow;
use Engine\Atomic\Quota\QuotaPlan;
use PHPUnit\Framework\TestCase;
use Tests\Support\QuotaPlans;
use Tests\Support\TestQuotaStore;

final class QuotaKeyBuilderTest extends TestCase
{
    protected function tearDown(): void
    {
        QuotaLimiter::reset();
        \Base::instance()->clear('QUOTA');
    }

    public function test_balance_key_wraps_the_scope_in_a_hash_tag(): void
    {
        $this->assertSame('{user:1}.balance', QuotaKeyBuilder::balance_key('user:1'));
    }

    public function test_epoch_key_wraps_the_scope_in_a_hash_tag(): void
    {
        $this->assertSame('{user:1}.epoch', QuotaKeyBuilder::epoch_key('user:1'));
    }

    public function test_reservation_key_wraps_the_scope_and_id(): void
    {
        $this->assertSame('{user:1}.reservation.abc', QuotaKeyBuilder::reservation_key('user:1', 'abc'));
    }

    public function test_a_tier_window_uses_the_reserved_pacing_name(): void
    {
        $plan = QuotaPlan::from_array('tight', QuotaPlans::p4(), QuotaPlans::operations());
        $keys = QuotaKeyBuilder::pacing_keys('user:1', $plan, 'spam_check');

        $this->assertEquals([
            new QuotaPacingWindow('{user:1}.pacing._tier.60', 4, 60),
        ], $keys);
    }

    public function test_an_operation_window_uses_the_operation_name(): void
    {
        $plan = QuotaPlan::from_array('op_pace', QuotaPlans::p5(), QuotaPlans::operations());
        $keys = QuotaKeyBuilder::pacing_keys('user:1', $plan, 'spam_check');

        $this->assertEquals([
            new QuotaPacingWindow('{user:1}.pacing._tier.60', 100, 60),
            new QuotaPacingWindow('{user:1}.pacing.spam_check.3600', 1, 3600),
        ], $keys);
    }

    public function test_pacing_keys_are_every_tier_window_then_every_operation_window(): void
    {
        $plan = QuotaPlan::from_array('pro', QuotaPlans::p3(), QuotaPlans::operations());
        $keys = QuotaKeyBuilder::pacing_keys('user:1', $plan, 'seo_headline');

        $this->assertEquals([
            new QuotaPacingWindow('{user:1}.pacing._tier.60', 200, 60),
            new QuotaPacingWindow('{user:1}.pacing._tier.86400', 2000, 86400),
            new QuotaPacingWindow('{user:1}.pacing.seo_headline.3600', 30, 3600),
        ], $keys);
        $this->assertCount(3, $keys);
    }

    public function test_use_store_is_what_from_config_returns_and_reset_drops_it(): void
    {
        $store = new TestQuotaStore();
        QuotaLimiter::use_store($store);

        $this->assertSame($store, QuotaLimiter::from_config()->store());

        QuotaLimiter::reset();
        QuotaLimiter::use_store($store);
        $limiter = QuotaLimiter::from_config();
        $limiter->resolve_plan_using(static fn(): string => 'pro');
        $limiter->resolve_scope_using(static fn(): string => 'user:1');

        QuotaLimiter::reset();
        QuotaLimiter::use_store($store);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('resolve_plan_using');

        QuotaLimiter::from_config()->resolve_subject(null);
    }

    public function test_reservation_id_is_32_hex_chars_and_unique(): void
    {
        $limiter = new QuotaLimiter(new TestQuotaStore());

        $first = $limiter->reservation_id();
        $second = $limiter->reservation_id();

        $this->assertSame(32, strlen($first));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first);
        $this->assertNotSame($first, $second);
    }
}
