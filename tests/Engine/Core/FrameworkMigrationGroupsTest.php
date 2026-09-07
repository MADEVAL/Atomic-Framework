<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\App\PluginManager;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Filesystem;
use Engine\Atomic\Core\Migrations\FrameworkMigrationGroups;
use Engine\Atomic\Core\Migrations\MigrationCatalog;
use Engine\Atomic\Core\Migrations\MigrationHistory;
use Engine\Atomic\Core\Migrations\MigrationLedger;
use Engine\Atomic\Core\Migrations\MigrationPublisher;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;
use Tests\Support\StreamCapture;
use Tests\Support\TempPath;

final class FrameworkMigrationGroupsTest extends TestCase
{
    private string $tmp_dir;
    private string $app_dir;
    private string $framework_dir;
    private mixed $original_migrations = null;
    private mixed $original_core = null;

    protected function setUp(): void
    {
        $this->tmp_dir = TempPath::make_dir('atomic_migration_groups_');
        $this->app_dir = $this->tmp_dir . 'app' . DIRECTORY_SEPARATOR;
        $this->framework_dir = $this->tmp_dir . 'framework' . DIRECTORY_SEPARATOR;
        mkdir($this->app_dir, 0755, true);
        mkdir($this->framework_dir . FrameworkMigrationGroups::INITIAL, 0755, true);
        mkdir($this->framework_dir . FrameworkMigrationGroups::UPDATES, 0755, true);

        $atomic = App::instance();
        $this->original_migrations = $atomic->get('MIGRATIONS');
        $this->original_core = $atomic->get('MIGRATIONS_CORE');
        $atomic->set('MIGRATIONS', $this->app_dir);
        $atomic->set('MIGRATIONS_CORE', $this->framework_dir);
        ReflectionHelper::set(PluginManager::class, 'instance', null);

        $this->write_initial('atomic_create_storage_tables');
        $this->write_update('0001_atomic_add_status');
        $this->write_update('0002_atomic_add_scope');
    }

    protected function tearDown(): void
    {
        $atomic = App::instance();
        $atomic->set('MIGRATIONS', $this->original_migrations);
        $atomic->set('MIGRATIONS_CORE', $this->original_core);
        ReflectionHelper::set(PluginManager::class, 'instance', null);
        TempPath::remove($this->tmp_dir);
    }

    public function test_manifest_contains_initial_and_updates_directories(): void
    {
        $this->assertSame(
            [FrameworkMigrationGroups::INITIAL, FrameworkMigrationGroups::UPDATES],
            FrameworkMigrationGroups::manifest(),
        );
    }

    public function test_initial_inventory_accepts_unversioned_migrations(): void
    {
        $inventory = (new FrameworkMigrationGroups())->initial_inventory($this->framework_dir);

        $this->assertSame(['atomic_create_storage_tables'], array_column($inventory, 'migration'));
        $this->assertArrayNotHasKey('version', $inventory[0]);
    }

    public function test_update_inventory_sorts_by_numeric_version_and_strips_prefix(): void
    {
        $inventory = (new FrameworkMigrationGroups())->update_inventory($this->framework_dir);

        $this->assertSame([1, 2], array_column($inventory, 'version'));
        $this->assertSame(['atomic_add_status', 'atomic_add_scope'], array_column($inventory, 'migration'));
    }

    public function test_locate_update_returns_the_normalized_inventory_item(): void
    {
        $located = (new FrameworkMigrationGroups())->locate_update($this->framework_dir, 'atomic_add_status');

        $this->assertSame('updates', $located['group']);
        $this->assertSame('atomic_add_status', $located['migration']);
        $this->assertSame(1, $located['version']);
    }

    public function test_publish_framework_defaults_to_versioned_updates(): void
    {
        $this->publisher()->publish_from_framework();

        $this->assertSame(['atomic_add_scope', 'atomic_add_status'], $this->published_names());
    }

    public function test_publish_framework_reports_published_and_skipped_migrations_separately(): void
    {
        $stdout = StreamCapture::memory('w+b');
        $stderr = StreamCapture::memory('w+b');
        $publisher = new MigrationPublisher(
            App::instance(),
            PluginManager::instance(),
            Filesystem::instance(),
            new MigrationLedger(App::instance(), ConnectionManager::instance()),
            new Output($stdout, $stderr),
        );

        $publisher->publish_from_framework();
        $publisher->publish_from_framework();

        $output = StreamCapture::read($stdout);
        $this->assertStringContainsString('2 migration(s) published for framework.', $output);
        $this->assertStringContainsString('2 migration(s) already published; skipped.', $output);
    }

    public function test_publisher_helpers_are_not_public_api(): void
    {
        foreach (['publish_plugin_migrations', 'find_plugin', 'next_migration_timestamp', 'resolve_migration_file'] as $method) {
            $this->assertFalse((new \ReflectionMethod(MigrationPublisher::class, $method))->isPublic());
        }
    }

    public function test_publish_framework_locates_initial_and_update_migrations(): void
    {
        $publisher = $this->publisher();

        $publisher->publish_framework('atomic_create_storage_tables');
        $publisher->publish_framework('atomic_add_status');

        $this->assertSame(['atomic_add_status', 'atomic_create_storage_tables'], $this->published_names());
    }

    public function test_root_framework_files_are_ignored(): void
    {
        file_put_contents($this->framework_dir . 'stray.php', "<?php return [];\n");

        $this->publisher()->publish_from_framework();

        $this->assertNotContains('stray', $this->published_names());
        $this->assertCount(2, $this->published_names());
    }

    public function test_catalog_maps_published_initial_files_to_framework_and_keeps_updates(): void
    {
        copy(
            $this->framework_dir . FrameworkMigrationGroups::INITIAL . DIRECTORY_SEPARATOR . 'atomic_create_storage_tables.php',
            $this->app_dir . '20260101000000_atomic_create_storage_tables.php',
        );

        $migrations = $this->catalog()->discover();

        $this->assertSame(
            ['atomic_create_storage_tables', 'atomic_add_status', 'atomic_add_scope'],
            array_column($migrations, 'migration'),
        );
        $this->assertSame(['framework', 'framework', 'framework'], array_column($migrations, 'source'));
    }

    private function write_initial(string $name): void
    {
        file_put_contents(
            $this->framework_dir . FrameworkMigrationGroups::INITIAL . DIRECTORY_SEPARATOR . $name . '.php',
            "<?php return [];\n",
        );
    }

    private function write_update(string $name): void
    {
        file_put_contents(
            $this->framework_dir . FrameworkMigrationGroups::UPDATES . DIRECTORY_SEPARATOR . $name . '.php',
            "<?php return [];\n",
        );
    }

    /** @return list<string> */
    private function published_names(): array
    {
        $names = array_map(
            static fn(string $file): string => (string)preg_replace('/^\d{14}_/', '', basename($file, '.php')),
            glob($this->app_dir . '*.php') ?: [],
        );
        sort($names);
        return $names;
    }

    private function publisher(): MigrationPublisher
    {
        $output = new Output(StreamCapture::memory('w+b'), StreamCapture::memory('w+b'));

        return new MigrationPublisher(
            App::instance(),
            PluginManager::instance(),
            Filesystem::instance(),
            new MigrationLedger(App::instance(), ConnectionManager::instance()),
            $output,
        );
    }

    private function catalog(): MigrationCatalog
    {
        return new MigrationCatalog(
            App::instance(),
            PluginManager::instance(),
            new MigrationHistory(),
            null,
            new FrameworkMigrationGroups(),
        );
    }
}
