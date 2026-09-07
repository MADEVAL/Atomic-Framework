<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\Core\Migrations;
use Engine\Atomic\Core\Migrations\LegacyMigrationAdopter;
use Engine\Atomic\Core\Migrations\MigrationCatalog;
use Engine\Atomic\Core\Migrations\MigrationExecutor;
use Engine\Atomic\Core\Migrations\MigrationHistory;
use Engine\Atomic\Core\Migrations\MigrationLedger;
use Engine\Atomic\Core\Migrations\MigrationPublisher;
use PHPUnit\Framework\TestCase;
use Tests\Support\StreamCapture;

final class MigrationsArchitectureTest extends TestCase
{
    public function test_migrations_receives_its_collaborators_through_the_constructor(): void
    {
        $constructor = (new \ReflectionClass(Migrations::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertSame(
            [
                'Engine\\Atomic\\CLI\\Console\\Output',
                'Engine\\Atomic\\Core\\Migrations\\MigrationCatalog',
                'Engine\\Atomic\\Core\\Migrations\\MigrationLedger',
                'Engine\\Atomic\\Core\\Migrations\\MigrationHistory',
                'Engine\\Atomic\\Core\\Migrations\\LegacyMigrationAdopter',
                'Engine\\Atomic\\Core\\Migrations\\MigrationExecutor',
                'Engine\\Atomic\\Core\\Migrations\\MigrationPublisher',
                '?Engine\\Atomic\\CLI\\Console\\Input',
            ],
            array_map(
                static fn(\ReflectionParameter $parameter): string => (string)$parameter->getType(),
                $constructor->getParameters(),
            ),
        );
    }

    public function test_migrations_facade_does_not_use_global_service_accessors(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/engine/Atomic/Core/Migrations.php',
        );

        self::assertStringNotContainsString('App::instance()', $source);
        self::assertStringNotContainsString('ConnectionManager::instance()', $source);
        self::assertStringNotContainsString('PluginManager::instance()', $source);
    }

    public function test_init_workflow_does_not_construct_migrations_with_the_legacy_signature(): void
    {
        $root = dirname(__DIR__, 3);
        $sources = [
            (string)file_get_contents($root . '/engine/Atomic/CLI/Init.php'),
            (string)file_get_contents($root . '/engine/Atomic/CLI/Init/InitInstaller.php'),
        ];

        foreach ($sources as $source) {
            self::assertStringNotContainsString('new CoreMigrations(', $source);
        }
    }

    public function test_migrate_orchestration_can_be_tested_with_injected_collaborators(): void
    {
        $migration = [
            'source' => 'app',
            'migration' => '20260101000000_create_example',
            'path' => 'unused-by-the-test.php',
            'checksum' => str_repeat('a', 64),
        ];
        $catalog = $this->createMock(MigrationCatalog::class);
        $catalog->expects(self::once())->method('discover')->willReturn([$migration]);

        $ledger = $this->createMock(MigrationLedger::class);
        $ledger->method('ensure')->willReturn(true);
        $ledger->method('rows')->willReturn([]);
        $ledger->expects(self::once())
            ->method('synchronized')
            ->willReturnCallback(static fn(callable $operation): mixed => $operation());
        $ledger->expects(self::once())->method('record')->with($migration, 'batch-id');

        $executor = $this->createMock(MigrationExecutor::class);
        $executor->method('batch_id')->willReturn('batch-id');
        $executor->expects(self::once())->method('up')->with($migration);

        $history = new MigrationHistory();
        $output = new Output(StreamCapture::memory('w+b'), StreamCapture::memory('w+b'));
        $service = new Migrations(
            $output,
            $catalog,
            $ledger,
            $history,
            $this->createMock(LegacyMigrationAdopter::class),
            $executor,
            $this->createMock(MigrationPublisher::class),
        );

        $service->migrate();

        self::assertTrue($service->was_successful());
    }

    public function test_migrate_stops_when_the_migration_database_is_not_ready(): void
    {
        $catalog = $this->createMock(MigrationCatalog::class);
        $catalog->expects(self::never())->method('discover');

        $ledger = $this->createMock(MigrationLedger::class);
        $ledger->expects(self::once())->method('ensure')->willReturn(false);

        $service = new Migrations(
            new Output(StreamCapture::memory('w+b'), StreamCapture::memory('w+b')),
            $catalog,
            $ledger,
            $this->createMock(MigrationHistory::class),
            $this->createMock(LegacyMigrationAdopter::class),
            $this->createMock(MigrationExecutor::class),
            $this->createMock(MigrationPublisher::class),
        );

        $service->migrate();

        self::assertFalse($service->was_successful());
    }
}
