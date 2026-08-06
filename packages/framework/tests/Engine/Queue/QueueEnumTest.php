<?php
declare(strict_types=1);

namespace Tests\Engine\Queue;

use Engine\Atomic\Queue\Enums\Driver;
use Engine\Atomic\Queue\Enums\State;
use PHPUnit\Framework\TestCase;

final class QueueEnumTest extends TestCase
{
    public function test_state_display_helpers_cover_known_and_unknown_states(): void
    {
        foreach (State::all() as $status) {
            $display = State::display($status);
            $this->assertSame($status, $display['value']);
            $this->assertNotSame('', $display['label']);
            $this->assertNotSame('', $display['icon']);
            $this->assertNotSame('', $display['class']);
        }

        $this->assertSame('Mystery state', State::label('mystery_state'));
        $this->assertSame('circle-help', State::icon('mystery_state'));
        $this->assertSame('state-unknown', State::badge_class('mystery_state'));
    }

    public function test_state_filters_totals_and_aggregate_are_stable(): void
    {
        $this->assertSame(State::FAILED->value, State::aggregate(State::FAILED->value));

        $totals = State::state_totals_template(7);
        $this->assertSame(7, $totals['total']);
        foreach (State::filterable_states() as $state) {
            $this->assertArrayHasKey($state, $totals);
        }
    }

    public function test_driver_validation(): void
    {
        $this->assertSame(['redis', 'db'], Driver::all());
        $this->assertTrue(Driver::is_valid('redis'));
        $this->assertTrue(Driver::is_valid('db'));
        $this->assertFalse(Driver::is_valid('memory'));
    }
}
