<?php

declare(strict_types=1);

namespace Tests\Engine\Security;

use Engine\Atomic\Security\ShellCommand;
use PHPUnit\Framework\TestCase;

final class ShellCommandTest extends TestCase
{
    public function test_basic_command_line(): void
    {
        $cmd = ShellCommand::new('php')
            ->arg('artisan')
            ->arg('queue:work');

        $line = $cmd->toCommandLine();

        $this->assertStringStartsWith('php', $line);
        $this->assertStringContainsString('artisan', $line);
        $this->assertStringContainsString('queue:work', $line);
    }

    public function test_option_with_value(): void
    {
        $cmd = ShellCommand::new('php')
            ->option('-d', 'memory_limit=256M');

        $line = $cmd->toCommandLine();

        $this->assertStringContainsString('-d', $line);
        $this->assertStringContainsString('memory_limit=256M', $line);
    }

    public function test_option_without_value(): void
    {
        $cmd = ShellCommand::new('ls')
            ->option('-la');

        $line = $cmd->toCommandLine();

        $this->assertStringContainsString('-la', $line);
    }

    public function test_uses_escapeshellcmd_for_executable(): void
    {
        $cmd = ShellCommand::new('php');

        $line = $cmd->toCommandLine();

        $this->assertStringStartsWith('php', $line);
    }

    public function test_uses_escapeshellarg_for_arguments(): void
    {
        $cmd = ShellCommand::new('echo')
            ->arg('hello world');

        $line = $cmd->toCommandLine();

        // spaces should be escaped for the shell
        $this->assertStringContainsString('hello world', $line);
    }

    public function test_execute_runs_command(): void
    {
        $cmd = ShellCommand::new(PHP_BINARY)
            ->option('-r')
            ->arg("echo 'hello';");

        $output = '';
        $exitCode = -1;
        $cmd->execute($output, $exitCode);

        $this->assertStringContainsString('hello', $output);
        $this->assertSame(0, $exitCode);
    }

    public function test_to_string_returns_command_line(): void
    {
        $cmd = ShellCommand::new('php')->arg('test.php');

        $this->assertSame($cmd->toCommandLine(), (string)$cmd);
    }

    public function test_multiple_args(): void
    {
        $cmd = ShellCommand::new('git')
            ->arg('commit')
            ->option('-m', 'fix bug')
            ->option('--author', 'dev');

        $line = $cmd->toCommandLine();

        $this->assertStringContainsString('git', $line);
        $this->assertStringContainsString('commit', $line);
        $this->assertStringContainsString('-m', $line);
        $this->assertStringContainsString('--author', $line);
    }
}
