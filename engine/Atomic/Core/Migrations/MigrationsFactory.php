<?php
declare(strict_types=1);

namespace Engine\Atomic\Core\Migrations;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\PluginManager;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\CLI\Console\Input;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Filesystem;
use Engine\Atomic\Core\Migrations;

final class MigrationsFactory
{
    public function __construct(
        private readonly App $app,
        private readonly ConnectionManager $connections,
        private readonly PluginManager $plugins,
        private readonly Filesystem $files,
    ) {}

    public function create(?Output $output = null, ?Input $input = null): Migrations
    {
        $output ??= new Output();
        $history = new MigrationHistory();
        $ledger = new MigrationLedger($this->app, $this->connections);
        $groups = new FrameworkMigrationGroups($this->app);

        return new Migrations(
            $output,
            new MigrationCatalog($this->app, $this->plugins, $history, $ledger, $groups),
            $ledger,
            $history,
            new LegacyMigrationAdopter($ledger, $history, $output),
            new MigrationExecutor(),
            new MigrationPublisher($this->app, $this->plugins, $this->files, $ledger, $output, $groups),
            $input,
        );
    }
}
