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

    public function test_every_framework_cli_route_is_listed_in_help(): void
    {
        $this->assertSame([], array_values(array_diff(
            $this->framework_cli_routes(),
            array_column($this->documented_commands(), 'name')
        )));
    }

    public function test_help_does_not_list_commands_without_a_route(): void
    {
        // health is handled in Bootstrap before routing, so it has no route line.
        $this->assertSame([], array_values(array_diff(
            array_column($this->documented_commands(), 'name'),
            array_merge($this->framework_cli_routes(), ['health'])
        )));
    }

    public function test_every_documented_command_has_a_short_description(): void
    {
        foreach ($this->documented_commands() as $command) {
            $this->assertNotSame('', $command['description'], $command['name'] . ' needs a description');
            $this->assertSame(1, preg_match('/^[A-Z].*[^.]$/', $command['description']), $command['name'] . ' description must be one plain sentence');
        }
    }

    public function test_migration_commands_are_listed_in_migrations_help(): void
    {
        $labels = array_column(CommandCatalog::topics()['migrations']['commands'], 'name');

        $this->assertSame([
            'migrations/create',
            'migrations/init',
            'migrations/migrate',
            'migrations/rollback',
            'migrations/status',
            'migrations/upgrade',
            'migrations/publish',
        ], $labels);
    }

    public function test_database_and_seeding_commands_have_their_own_topics(): void
    {
        $topics = CommandCatalog::topics();

        $this->assertSame([
            'db/tables',
            'db/sessions',
            'db/queue',
            'db/users',
            'db/pages',
            'db/storage',
            'db/mutex',
            'db/truncate',
            'db/truncate/queue',
        ], array_column($topics['database']['commands'], 'name'));
        $this->assertSame(
            ['seed/users', 'seed/roles', 'seed/pages'],
            array_column($topics['seeding']['commands'], 'name')
        );
    }

    /** @return list<array{name: string, arguments: string, description: string}> */
    private function documented_commands(): array
    {
        $commands = [];
        foreach (CommandCatalog::topics() as $topic) {
            foreach ($topic['commands'] as $command) {
                $commands[] = $command;
            }
        }

        return $commands;
    }

    /** @return list<string> */
    private function framework_cli_routes(): array
    {
        $file = ATOMIC_ENGINE . 'Atomic' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Routes' . DIRECTORY_SEPARATOR . 'cli.php';
        $source = (string)file_get_contents($file);

        preg_match_all("/route\\('GET \\/([^'\\[]+?) \\[cli\\]'/", $source, $matches);

        $routes = [];
        foreach ($matches[1] as $route) {
            if (!str_contains($route, '@')) {
                $routes[] = $route;
            }
        }

        return $routes;
    }
}
