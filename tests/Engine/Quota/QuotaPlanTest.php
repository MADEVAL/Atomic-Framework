<?php
declare(strict_types=1);

namespace Tests\Engine\Quota;

use Engine\Atomic\Quota\QuotaLimiter;
use Engine\Atomic\Quota\QuotaOperationSpec;
use Engine\Atomic\Quota\QuotaPacingSpec;
use Engine\Atomic\Quota\QuotaPlan;
use PHPUnit\Framework\TestCase;
use Tests\Support\QuotaPlans;
use Tests\Support\TestQuotaStore;

final class QuotaPlanTest extends TestCase
{
    protected function tearDown(): void
    {
        QuotaLimiter::reset();
        \Base::instance()->clear('QUOTA');
    }

    public function test_plan_reads_the_documented_free_tier(): void
    {
        $limiter = $this->limiter();
        $this->install(QuotaPlans::docs_hive());

        $plan = $limiter->plan('free');

        $this->assertSame('free', $plan->name);
        $this->assertSame(500, $plan->credits);
        $this->assertSame(2592000, $plan->period);
        $this->assertSame(300, $plan->reservation_ttl);
        $this->assertEquals([new QuotaPacingSpec(20, 60)], $plan->pacing);
        $this->assertSame(1, $plan->cost('spam_check'));
        $this->assertFalse($plan->has_operation('seo_headline'));
        $this->assertTrue($plan->declares_operation('seo_headline'));
    }

    public function test_plan_reads_the_documented_pro_tier(): void
    {
        $limiter = $this->limiter();
        $this->install(QuotaPlans::docs_hive());

        $plan = $limiter->plan('pro');

        $this->assertSame('pro', $plan->name);
        $this->assertSame(20000, $plan->credits);
        $this->assertSame(2592000, $plan->period);
        $this->assertSame(300, $plan->reservation_ttl);
        $this->assertEquals([
            new QuotaPacingSpec(200, 60),
            new QuotaPacingSpec(2000, 86400),
        ], $plan->pacing);
        $this->assertSame(5, $plan->cost('seo_headline'));
        $this->assertEquals([new QuotaPacingSpec(30, 3600)], $plan->operation_pacing('seo_headline'));
        $this->assertTrue($plan->has_operation('spam_check'));
        $this->assertTrue($plan->declares_operation('spam_check'));
    }

    public function test_a_plan_with_empty_pacing_is_valid(): void
    {
        $limiter = $this->limiter();
        $this->install(QuotaPlans::hive(['balance' => QuotaPlans::p1()]));

        $plan = $limiter->plan('balance');

        $this->assertSame('balance', $plan->name);
        $this->assertSame([], $plan->pacing);
        $this->assertSame(10, $plan->credits);
        $this->assertSame(1, $plan->cost('spam_check'));
    }

    public function test_plan_throws_on_an_undefined_tier(): void
    {
        $limiter = $this->limiter();
        $this->install(QuotaPlans::docs_hive());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Undefined quota tier: 'enterprise'");

        $limiter->plan('enterprise');
    }

    public function test_plan_throws_when_the_operation_list_is_missing(): void
    {
        $limiter = $this->limiter();
        $this->install(['quotas' => ['free' => QuotaPlans::p2()]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must declare an operation list');

        $limiter->plan('free');
    }

    public function test_plan_throws_when_the_operation_list_is_empty(): void
    {
        $limiter = $this->limiter();
        $this->install(QuotaPlans::hive(['free' => QuotaPlans::p2()], []));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must declare an operation list');

        $limiter->plan('free');
    }

    /**
     * @dataProvider missing_key_provider
     */
    public function test_plan_throws_when_a_tier_is_missing_a_required_key(string $missing): void
    {
        $limiter = $this->limiter();
        $tier = QuotaPlans::p2();
        unset($tier[$missing]);
        $this->install(QuotaPlans::hive(['free' => $tier]));

        try {
            $limiter->plan('free');
            $this->fail('Expected a missing key to throw.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("missing the required key '{$missing}'", $e->getMessage());
            $this->assertStringContainsString('has no defaults', $e->getMessage());
        }
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
        $limiter = $this->limiter();
        $tier = QuotaPlans::p3();
        unset($tier['reservation_ttl']);
        $this->install(QuotaPlans::hive([
            'free' => QuotaPlans::p2(),
            'pro' => $tier,
        ]));

        try {
            $limiter->plan('pro');
            $this->fail('Expected a missing reservation_ttl to throw.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("missing the required key 'reservation_ttl'", $e->getMessage());
            $this->assertStringContainsString('has no defaults', $e->getMessage());
        }
    }

    public function test_plan_throws_when_operations_is_not_a_map(): void
    {
        $limiter = $this->limiter();
        $tier = QuotaPlans::p2();
        $tier['operations'] = 'spam_check';
        $this->install(QuotaPlans::hive(['free' => $tier]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('operations must be a map');

        $limiter->plan('free');
    }

    public function test_plan_throws_when_an_operation_is_missing_cost(): void
    {
        $limiter = $this->limiter();
        $tier = QuotaPlans::p2();
        $tier['operations']['spam_check'] = ['pacing' => []];
        $this->install(QuotaPlans::hive(['free' => $tier]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("missing the required key 'cost'");

        $limiter->plan('free');
    }

    public function test_plan_throws_when_an_operation_has_an_unknown_key(): void
    {
        $limiter = $this->limiter();
        $tier = QuotaPlans::p2();
        $tier['operations']['spam_check'] = ['cost' => 1, 'burst' => 2];
        $this->install(QuotaPlans::hive(['free' => $tier]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown key');

        $limiter->plan('free');
    }

    public function test_plan_rejects_shared_global_pacing(): void
    {
        $limiter = $this->limiter();
        $tier = QuotaPlans::p2();
        $tier['operations']['spam_check'] = ['cost' => 1, 'global' => [['limit' => 2, 'window' => 60]]];
        $this->install(QuotaPlans::hive(['free' => $tier], ['spam_check']));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("shared (global) pacing is not in this version");

        $limiter->plan('free');
    }

    public function test_plan_rejects_an_operation_outside_the_declared_list(): void
    {
        $limiter = $this->limiter();
        $tier = QuotaPlans::p2();
        $tier['operations']['undeclared'] = ['cost' => 1];
        $this->install(QuotaPlans::hive(['free' => $tier]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("operation 'undeclared' is not in the declared operation list");

        $limiter->plan('free');
    }

    public function test_plan_rejects_the_reserved_tier_pacing_name(): void
    {
        $limiter = $this->limiter();
        $this->install(QuotaPlans::hive(['free' => QuotaPlans::p2()], ['spam_check', QuotaPlan::TIER]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved pacing name');

        $limiter->plan('free');
    }

    public function test_plan_throws_when_pacing_is_not_a_list(): void
    {
        $limiter = $this->limiter();
        $tier = QuotaPlans::p2();
        $tier['pacing'] = 'hourly';
        $this->install(QuotaPlans::hive(['free' => $tier]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a list of windows');

        $limiter->plan('free');
    }

    public function test_plan_throws_when_a_window_is_missing_limit_or_window(): void
    {
        $limiter = $this->limiter();
        $tier = QuotaPlans::p2();
        $tier['pacing'] = [['limit' => 20]];
        $this->install(QuotaPlans::hive(['free' => $tier]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("needs both 'limit' and 'window'");

        $limiter->plan('free');
    }

    public function test_plan_throws_when_a_window_is_not_positive(): void
    {
        $limiter = $this->limiter();
        $tier = QuotaPlans::p2();
        $tier['pacing'] = [['limit' => 0, 'window' => 60]];
        $this->install(QuotaPlans::hive(['free' => $tier]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("positive 'limit' and 'window'");

        $limiter->plan('free');
    }

    public function test_plan_throws_when_operation_pacing_is_not_a_list(): void
    {
        $limiter = $this->limiter();
        $tier = QuotaPlans::p2();
        $tier['operations']['spam_check'] = ['cost' => 1, 'pacing' => 'weekly'];
        $this->install(QuotaPlans::hive(['free' => $tier]));

        try {
            $limiter->plan('free');
            $this->fail('Expected operation pacing shape to throw.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('must be a list of windows', $e->getMessage());
            $this->assertStringContainsString('spam_check', $e->getMessage());
        }
    }

    public function test_plan_throws_when_an_operation_window_is_missing_a_pair(): void
    {
        $limiter = $this->limiter();
        $tier = QuotaPlans::p2();
        $tier['operations']['spam_check'] = ['cost' => 1, 'pacing' => [['limit' => 1]]];
        $this->install(QuotaPlans::hive(['free' => $tier]));

        try {
            $limiter->plan('free');
            $this->fail('Expected operation pacing pair to throw.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("needs both 'limit' and 'window'", $e->getMessage());
            $this->assertStringContainsString('spam_check', $e->getMessage());
        }
    }

    public function test_plan_throws_when_an_operation_window_is_not_positive(): void
    {
        $limiter = $this->limiter();
        $tier = QuotaPlans::p2();
        $tier['operations']['spam_check'] = ['cost' => 1, 'pacing' => [['limit' => 1, 'window' => 0]]];
        $this->install(QuotaPlans::hive(['free' => $tier]));

        try {
            $limiter->plan('free');
            $this->fail('Expected operation pacing positivity to throw.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("positive 'limit' and 'window'", $e->getMessage());
            $this->assertStringContainsString('spam_check', $e->getMessage());
        }
    }

    public function test_from_array_throws_when_the_tier_name_is_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        QuotaPlan::from_array('', QuotaPlans::p1(), QuotaPlans::operations());
    }

    public function test_from_array_throws_when_credits_are_negative(): void
    {
        $tier = QuotaPlans::p1();
        $tier['credits'] = -1;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be negative');

        QuotaPlan::from_array('balance', $tier, QuotaPlans::operations());
    }

    public function test_from_array_throws_when_period_is_not_positive(): void
    {
        $tier = QuotaPlans::p1();
        $tier['period'] = 0;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be positive');

        QuotaPlan::from_array('balance', $tier, QuotaPlans::operations());
    }

    public function test_from_array_throws_when_reservation_ttl_is_not_positive(): void
    {
        $tier = QuotaPlans::p1();
        $tier['reservation_ttl'] = 0;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be positive');

        QuotaPlan::from_array('balance', $tier, QuotaPlans::operations());
    }

    public function test_zero_credits_is_a_valid_plan(): void
    {
        $plan = QuotaPlan::from_array('zero', QuotaPlans::p6(), QuotaPlans::operations());

        $this->assertSame(0, $plan->credits);
        $this->assertSame(1, $plan->cost('spam_check'));
    }

    public function test_constructor_throws_when_the_tier_name_is_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        new QuotaPlan('', 10, 3600, 300, [], ['spam_check' => new QuotaOperationSpec(1, [])], ['spam_check']);
    }

    private function limiter(): QuotaLimiter
    {
        return new QuotaLimiter(new TestQuotaStore());
    }

    /** @param array<string, mixed> $quota */
    private function install(array $quota): void
    {
        \Base::instance()->set('QUOTA', $quota);
    }
}
