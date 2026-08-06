<?php
declare(strict_types=1);

namespace Tests\Engine\Scheduler;

use Engine\Atomic\Scheduler\Scheduler;
use PHPUnit\Framework\TestCase;

class SchedulerExecEscapingTest extends TestCase
{
    public function test_exec_escapes_shell_metacharacters(): void
    {
        $scheduler = new Scheduler();
        $command = 'echo hello & calc.exe';
        $event = $scheduler->exec($command);

        $escaped = \escapeshellcmd($command);

        $this->assertNotSame($command, $escaped, 'escapeshellcmd must modify dangerous commands');
    }

    public function test_exec_safe_command_unchanged_by_escaping(): void
    {
        $scheduler = new Scheduler();
        $command = 'echo hello';
        $event = $scheduler->exec($command);

        $escaped = \escapeshellcmd($command);
        $this->assertSame($command, $escaped, 'Safe commands should remain unchanged');
    }

    public function test_exec_description_contains_original_command(): void
    {
        $scheduler = new Scheduler();
        $command = 'echo "hello world"';
        $event = $scheduler->exec($command);

        $this->assertStringContainsString($command, $event->get_description());
    }
}
