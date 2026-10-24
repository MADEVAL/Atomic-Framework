<?php
declare(strict_types=1);

namespace Tests\Integration\Cli;

use PHPUnit\Framework\TestCase;

/**
 * Runs the skeleton CLI entry point as a subprocess.
 *
 * Help output spans the route table, the System controller and the command
 * catalog, so it is verified end to end here: the binary is executed the same
 * way a user would run it.
 */
final class CliHelpCommandTest extends TestCase
{
    public function test_full_help_lists_commands_that_used_to_be_missing(): void
    {
        [$exit_code, $output] = $this->run_cli('help');

        $this->assertSame(0, $exit_code, $output);
        $this->assertStringContainsString('migrations/create <name>', $output);
        $this->assertStringContainsString('migrations/rollback [steps|batch]', $output);
        $this->assertStringContainsString('migrations/publish <framework|plugin-name>', $output);
        $this->assertStringContainsString('db/tables', $output);
        $this->assertStringContainsString('db/truncate/queue', $output);
        $this->assertStringContainsString('seed/users', $output);
        $this->assertStringContainsString('queue/test/monitor [queue_name]', $output);
        $this->assertStringContainsString('redis/clear', $output);
    }

    /**
     * @dataProvider topic_form_provider
     * @param list<string> $arguments
     */
    public function test_help_resolves_a_topic_in_any_supported_separator_form(array $arguments): void
    {
        [$exit_code, $output] = $this->run_cli(...$arguments);

        $this->assertSame(0, $exit_code, $output);
        $this->assertStringContainsString('Atomic Help: Migrations', $output);
        $this->assertStringContainsString('migrations/create <name>', $output);
    }

    public static function topic_form_provider(): array
    {
        return [
            'space separator' => [['help', 'migrations']],
            'slash separator' => [['help/migrations']],
            'colon separator' => [['help:migrations']],
        ];
    }

    public function test_unknown_help_topic_still_prints_available_topics(): void
    {
        [$exit_code, $output] = $this->run_cli('help', 'nosuchtopic');

        $this->assertSame(0, $exit_code, $output);
        $this->assertStringContainsString("Unknown help topic 'nosuchtopic'.", $output);
        $this->assertStringContainsString('database', $output);
        $this->assertStringContainsString('seeding', $output);
    }

    /** @return array{0: int, 1: string} */
    private function run_cli(string ...$arguments): array
    {
        $binary = ATOMIC_DIR . DIRECTORY_SEPARATOR . 'packages' . DIRECTORY_SEPARATOR . 'skeleton' . DIRECTORY_SEPARATOR . 'atomic';
        $this->assertFileExists($binary);

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($binary);
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        $output = [];
        $exit_code = -1;
        exec($command . ' 2>&1', $output, $exit_code);

        return [$exit_code, implode(PHP_EOL, $output)];
    }
}
