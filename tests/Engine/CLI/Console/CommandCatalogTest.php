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
            $this->assertSame(1, preg_match('/^[A-Z][^\r\n]*[^.]$/', $command['description']), $command['name'] . ' description must be one plain line');
            $this->assertStringNotContainsString('. ', $command['description'], $command['name'] . ' description must be one sentence');
        }
    }

    public function test_table_commands_describe_migration_publishing_not_table_creation(): void
    {
        foreach (['db/sessions', 'db/queue', 'db/users', 'db/pages', 'db/storage', 'db/mutex'] as $name) {
            $description = $this->documented_command($name)['description'];

            // These handlers only publish a migration; the table appears after migrations/migrate.
            $this->assertStringStartsWith('Publish the ', $description, $name);
            $this->assertStringEndsWith(' migration', $description, $name);
        }
    }

    public function test_optional_arguments_parsed_by_handlers_are_documented(): void
    {
        $this->assertSame('schedule/work [seconds]', CommandCatalog::display('schedule/work'));
        $this->assertSame('schedule/test [expression]', CommandCatalog::display('schedule/test'));
        $this->assertSame('file/csv2pdf <input.csv> <output.pdf> [header]', CommandCatalog::display('file/csv2pdf'));
        $this->assertSame('file/xls2pdf <input.xls> <output.pdf> [header]', CommandCatalog::display('file/xls2pdf'));
    }

    public function test_find_returns_one_command_definition(): void
    {
        $this->assertSame(
            ['name' => 'migrations/create', 'topic' => 'migrations', 'arguments' => '<name>', 'description' => 'Create a migration file'],
            CommandCatalog::find(' Migrations/Create/ ')
        );
        $this->assertNull(CommandCatalog::find('no/such/command'));
    }

    public function test_command_hints_in_engine_sources_name_documented_commands(): void
    {
        $documented = array_column($this->documented_commands(), 'name');
        $unknown = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(ATOMIC_ENGINE . 'Atomic', \FilesystemIterator::SKIP_DOTS));
        $plugins = ATOMIC_ENGINE . 'Atomic' . DIRECTORY_SEPARATOR . 'Plugins' . DIRECTORY_SEPARATOR;
        foreach ($files as $file) {
            // Bundled plugins own their commands; they are not in the framework catalog.
            if ($file->getExtension() !== 'php' || str_starts_with($file->getPathname(), $plugins)) {
                continue;
            }
            preg_match_all('#php atomic ([a-z][a-z0-9_/-]*)#', (string)file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $command) {
                $command = rtrim($command, '/');
                // routes/<scope>/<source> is a parameterised route documented in its own help block.
                if (!in_array($command, $documented, true) && !str_starts_with($command, 'routes/')) {
                    $unknown[] = $command . ' in ' . basename($file->getPathname());
                }
            }
        }

        $this->assertSame([], array_values(array_unique($unknown)));
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

    /** @return array{name: string, arguments: string, description: string} */
    private function documented_command(string $name): array
    {
        foreach ($this->documented_commands() as $command) {
            if ($command['name'] === $name) {
                return $command;
            }
        }

        $this->fail($name . ' is not documented');
    }

    /**
     * CLI routes registered by every framework route file.
     *
     * Bundled plugin routes (engine/Atomic/Plugins/*) are excluded on purpose:
     * they exist only when the plugin is enabled, so the framework catalog
     * cannot list them unconditionally.
     *
     * @return list<string>
     */
    private function framework_cli_routes(): array
    {
        $routes = [];
        foreach (glob(ATOMIC_ENGINE . 'Atomic' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Routes' . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            preg_match_all("/route\\(\\s*['\"]GET \\/([^'\"\\[]+?) \\[cli\\]['\"]/", (string)file_get_contents($file), $matches);
            foreach ($matches[1] as $route) {
                if (!str_contains($route, '@')) {
                    $routes[] = $route;
                }
            }
        }

        $this->assertNotSame([], $routes, 'No framework CLI routes were found; the route file layout changed.');

        return $routes;
    }
}
