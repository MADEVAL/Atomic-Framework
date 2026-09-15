<?php
declare(strict_types=1);

namespace Tests\Engine\CLI;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\CLI\CLI;
use Engine\Atomic\Core\App;
use Engine\Atomic\Scheduler\Scheduler;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

class SchedulerTest extends TestCase
{
    protected function setUp(): void
    {
        Scheduler::reset();
    }

    protected function tearDown(): void
    {
        Scheduler::reset();
    }

    public function test_cli_get_scheduler_does_not_register_schedule_again(): void
    {
        App::instance()->register_schedule();
        $scheduler = Scheduler::instance();
        $registered_events = $scheduler->events();
        $this->assertNotEmpty($registered_events);

        $cli_scheduler = ReflectionHelper::invoke(new CLI(), 'get_scheduler');

        $this->assertSame($scheduler, $cli_scheduler);
        $this->assertSame($registered_events, $scheduler->events());
    }
}
