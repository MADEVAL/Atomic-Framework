<?php
declare(strict_types=1);

namespace Tests\Engine\CLI\Console;

use Engine\Atomic\CLI\Console\CommandCatalog;
use PHPUnit\Framework\TestCase;

final class CommandCatalogTest extends TestCase
{
    public function test_command_signature_is_shared_by_help_and_runtime_metadata(): void
    {
        $queue_topic = CommandCatalog::topics()['queue'];
        $queue_labels = array_map(
            static fn(array $command): string => CommandCatalog::display($command['name']),
            $queue_topic['commands']
        );

        $this->assertContains('queue/cancel <job_uuid>', $queue_labels);
        $this->assertSame('queue/cancel <job_uuid>', CommandCatalog::display('queue/cancel'));
    }

    public function test_health_command_is_listed_in_system_help(): void
    {
        $labels = array_column(CommandCatalog::topics()['system']['commands'], 'name');

        $this->assertContains('health', $labels);
    }

}
