<?php
declare(strict_types=1);

namespace Tests\Engine\CLI;

use Engine\Atomic\CLI\CLI;
use Engine\Atomic\CLI\Console\Output;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;
use Tests\Support\StreamCapture;

class HelpTest extends TestCase
{
    public function test_help_command_groups_commands_by_topic(): void
    {
        [$cli, $stream] = $this->cli_with_output();

        try {
            $cli->help();
            $help = StreamCapture::read($stream, true);

            $this->assertStringContainsString('Project', $help);
            $this->assertStringContainsString('Authentication', $help);
            $this->assertStringContainsString('Migrations', $help);
            $this->assertStringContainsString('Queue', $help);
            $this->assertStringContainsString('Scheduler', $help);
            $this->assertStringContainsString('Files', $help);
            $this->assertSame(1, substr_count($help, 'Available Route Commands'));
        } finally {
            fclose($stream);
        }
    }

    public function test_help_command_can_show_one_topic(): void
    {
        [$cli, $stream] = $this->cli_with_output();

        try {
            $cli->help('queue');
            $help = StreamCapture::read($stream, true);

            $this->assertStringContainsString('Queue Commands', $help);
            $this->assertStringContainsString('queue/worker', $help);
            $this->assertStringContainsString('queue/cancel <job_uuid>', $help);
            $this->assertStringNotContainsString('init/guide', $help);
        } finally {
            fclose($stream);
        }
    }

    public function test_system_help_lists_route_commands_once(): void
    {
        [$cli, $stream] = $this->cli_with_output();

        try {
            $cli->help('system');
            $help = Output::plain(StreamCapture::read($stream, true));

            $this->assertSame(1, substr_count($help, 'Available Route Commands'));
            $this->assertStringContainsString('php atomic routes/api', $help);
            $this->assertStringContainsString('List API routes', $help);
            $this->assertStringContainsString('php atomic routes/all/plugin', $help);
            $this->assertStringContainsString('List plugin routes from every type', $help);
            $this->assertStringNotContainsString('routes[/scope[/source]]', $help);
        } finally {
            fclose($stream);
        }
    }

    public function test_unknown_help_topic_lists_available_topics_on_separate_lines(): void
    {
        [$cli, $stream] = $this->cli_with_output();

        try {
            $cli->help('queue/wo');
            $output = Output::plain(StreamCapture::read($stream, true));
            $output = str_replace("\r\n", "\n", $output);

            $this->assertStringContainsString("[ERROR] Unknown help topic 'queue/wo'.", $output);
            $this->assertStringContainsString(
                "Available topics:\n"
                    . "    project\n"
                    . "    plugins\n"
                    . "    authentication\n"
                    . "    migrations\n"
                    . "    cache\n"
                    . "    system\n"
                    . "    queue\n"
                    . "    scheduler\n"
                    . "    files",
                $output
            );
            $this->assertStringNotContainsString('project, plugins', $output);
        } finally {
            fclose($stream);
        }
    }

    public function test_unknown_command_reports_suggestions_and_help(): void
    {
        [$cli, $stream] = $this->cli_with_output();
        $this->set_cli_routes([
            '/queue/worker' => 'handler',
            '/queue/monitor' => 'handler',
        ]);

        try {
            $cli->report_unknown_command('queue/workr', '/queue/workr');
            $output = StreamCapture::read($stream, true);

            $this->assertStringContainsString("Unknown command 'queue/workr'.", $output);
            $this->assertStringContainsString('Did you mean?', $output);
            $this->assertStringContainsString('php atomic queue/worker', $output);
            $this->assertStringNotContainsString('php atomic queue/monitor', $output);
            $this->assertStringContainsString('php atomic help', $output);
        } finally {
            fclose($stream);
        }
    }

    public function test_incomplete_command_reports_multiple_readable_suggestions(): void
    {
        [$cli, $stream] = $this->cli_with_output();
        $this->set_cli_routes([
            '/queue/worker' => 'handler',
            '/schedule/work' => 'handler',
            '/queue/monitor' => 'handler',
        ]);

        try {
            $cli->report_unknown_command('queue/wo', '/queue/wo');
            $output = Output::plain(StreamCapture::read($stream, true));
            $output = str_replace("\r\n", "\n", $output);

            $this->assertStringContainsString("[ERROR] Unknown command 'queue/wo'.", $output);
            $this->assertStringContainsString(
                "Did you mean?\n    php atomic queue/worker <queue_name>\n    php atomic schedule/work",
                $output
            );
            $this->assertStringContainsString("Help:\n    php atomic help", $output);
            $this->assertStringNotContainsString('queue/monitor', $output);
        } finally {
            fclose($stream);
        }
    }

    public function test_command_suggestion_includes_required_command_arguments(): void
    {
        [$cli, $stream] = $this->cli_with_output();
        $this->set_cli_routes([
            '/queue/cancel' => 'handler',
        ]);

        try {
            $cli->report_unknown_command('queue/cance', '/queue/cance');
            $output = Output::plain(StreamCapture::read($stream, true));

            $this->assertStringContainsString('php atomic queue/cancel <job_uuid>', $output);
            $this->assertStringNotContainsString('php atomic queue/cancel' . PHP_EOL, $output);
        } finally {
            fclose($stream);
        }
    }

    public function test_help_command_aligns_descriptions_in_one_column(): void
    {
        [$cli, $stream] = $this->cli_with_output();

        try {
            $cli->help();

            $lines = preg_split('/\R/', StreamCapture::read($stream, true), -1, PREG_SPLIT_NO_EMPTY);
            $separator_positions = [];

            foreach ($lines as $line) {
                $position = strpos($line, ' - ');
                if ($position !== false) {
                    $separator_positions[] = $position;
                }
            }

            $this->assertNotEmpty($separator_positions);
            $this->assertCount(1, array_unique($separator_positions));
        } finally {
            fclose($stream);
        }
    }

    /** @return array{0: CLI, 1: resource} */
    private function cli_with_output(): array
    {
        $stream = StreamCapture::memory();
        $cli = new CLI();
        ReflectionHelper::set($cli, 'output', new Output($stream, $stream));

        return [$cli, $stream];
    }

    private function set_cli_routes(array $routes): void
    {
        \Engine\Atomic\Core\App::atomic()->set('ROUTES', $routes);
    }
}
