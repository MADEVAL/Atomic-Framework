<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\Migrations\FrameworkMigrationGroups;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempPath;

final class FrameworkMigrationRegistryTest extends TestCase
{
    private string $core;

    protected function setUp(): void
    {
        $this->core = TempPath::make_dir('atomic_framework_migrations_');
        mkdir($this->core . FrameworkMigrationGroups::INITIAL, 0755, true);
        mkdir($this->core . FrameworkMigrationGroups::UPDATES, 0755, true);
    }

    protected function tearDown(): void
    {
        TempPath::remove($this->core);
    }

    public function test_manifest_contains_initial_and_updates_directories(): void
    {
        $this->assertSame(
            [FrameworkMigrationGroups::INITIAL, FrameworkMigrationGroups::UPDATES],
            FrameworkMigrationGroups::manifest()
        );
    }

    public function test_initial_inventory_accepts_unversioned_migrations(): void
    {
        $this->write_initial('atomic_create_storage_tables');

        $inventory = (new FrameworkMigrationGroups())->initial_inventory($this->core);

        $this->assertSame('atomic_create_storage_tables', $inventory[0]['migration']);
        $this->assertArrayNotHasKey('version', $inventory[0]);
    }

    public function test_update_inventory_sorts_by_numeric_version_and_strips_prefix(): void
    {
        $this->write_update('0002_atomic_add_scope');
        $this->write_update('0001_atomic_add_status');

        $inventory = (new FrameworkMigrationGroups())->update_inventory($this->core);

        $this->assertSame([1, 2], array_column($inventory, 'version'));
        $this->assertSame(
            ['atomic_add_status', 'atomic_add_scope'],
            array_column($inventory, 'migration')
        );
    }

    public function test_update_inventory_rejects_missing_version(): void
    {
        $this->write_update('atomic_add_scope');

        $this->expectException(\RuntimeException::class);
        (new FrameworkMigrationGroups())->update_inventory($this->core);
    }

    public function test_update_inventory_rejects_duplicate_versions(): void
    {
        $this->write_update('0001_atomic_add_scope');
        $this->write_update('0001_atomic_add_status');

        $this->expectException(\RuntimeException::class);
        (new FrameworkMigrationGroups())->update_inventory($this->core);
    }

    private function write_initial(string $name): void
    {
        file_put_contents(
            $this->core . FrameworkMigrationGroups::INITIAL . DIRECTORY_SEPARATOR . $name . '.php',
            "<?php return [];\n"
        );
    }

    private function write_update(string $name): void
    {
        file_put_contents(
            $this->core . FrameworkMigrationGroups::UPDATES . DIRECTORY_SEPARATOR . $name . '.php',
            "<?php return [];\n"
        );
    }
}
