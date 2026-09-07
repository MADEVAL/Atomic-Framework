<?php
declare(strict_types=1);

namespace Tests\Engine\CLI;

use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\App\PluginManager;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Container;
use Engine\Atomic\Core\Filesystem;
use Engine\Atomic\Core\Migrations as CoreMigrations;
use Engine\Atomic\Core\Migrations\MigrationsFactory;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;
use Tests\Support\StreamCapture;

final class MigrationsTest extends TestCase
{
    protected function tearDown(): void
    {
        App::instance()->reset_cli_exit_code();
    }

    public function test_run_migration_command_reports_failure_from_callback(): void
    {
        Container::global()->instance(MigrationsFactory::class, new MigrationsFactory(
            App::instance(),
            ConnectionManager::instance(),
            PluginManager::instance(),
            Filesystem::instance(),
        ));
        $cli = new class(new Output()) {
            use \Engine\Atomic\CLI\Migrations;

            protected Output $output;

            public function __construct(Output $output)
            {
                $this->output = $output;
            }
        };
        $called = false;

        ReflectionHelper::invoke($cli, 'run_migration_command', [
            function (CoreMigrations $migrations) use (&$called): void {
                $called = true;
                ReflectionHelper::set($migrations, 'successful', false);
            },
        ]);

        $this->assertTrue($called);
        $this->assertSame(1, App::instance()->get_cli_exit_code());
    }

    public function test_migrations_publish_forwards_framework_to_publisher(): void
    {
        $migrations = $this->createMock(CoreMigrations::class);
        $migrations->expects($this->once())
            ->method('publish_from_framework')
            ->with();

        $cli = new class(new Output(StreamCapture::memory('w+b'), StreamCapture::memory('w+b')), $migrations) {
            use \Engine\Atomic\CLI\Migrations;

            protected Output $output;

            public function __construct(
                Output $output,
                private readonly CoreMigrations $migrations,
            ) {
                $this->output = $output;
            }

            public function get_cli_args(): array
            {
                return ['framework'];
            }

            protected function migration_manager(): CoreMigrations
            {
                return $this->migrations;
            }
        };

        $cli->migrations_publish();
    }
}
