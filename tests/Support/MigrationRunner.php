<?php
declare(strict_types=1);

namespace Tests\Support;

use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Migrations\MigrationContext;
use Engine\Atomic\Core\Migrations\MigrationExecutor;

/** Runs one migration file through the real executor with its console output discarded. */
final class MigrationRunner
{
    public static function up(string $path): void
    {
        self::executor()->up(self::migration($path));
    }

    public static function down(string $path): void
    {
        self::executor()->down(self::migration($path));
    }

    private static function executor(): MigrationExecutor
    {
        return new MigrationExecutor(new MigrationContext(
            App::instance(),
            ConnectionManager::instance(),
            new Output(StreamCapture::memory('w+b'), StreamCapture::memory('w+b')),
        ));
    }

    /** @return array{source: string, migration: string, path: string} */
    private static function migration(string $path): array
    {
        return ['source' => 'test', 'migration' => basename($path, '.php'), 'path' => $path];
    }
}
