<?php
declare(strict_types=1);

namespace Tests\Engine\App;

use Engine\Atomic\App\Plugin;
use Engine\Atomic\App\PluginManager;
use PHPUnit\Framework\TestCase;

class AccessorsTestPlugin extends Plugin
{
    protected function get_name(): string
    {
        return 'accessors-test-plugin';
    }

    public function register(): void {}
    public function boot(): void {}
}

class AccessorsFailingBootPlugin extends Plugin
{
    protected function get_name(): string
    {
        return 'accessors-failing-boot-plugin';
    }

    public function boot(): void
    {
        throw new \RuntimeException('boot failed intentionally');
    }
}

final class PluginManagerAccessorsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PluginManager::reset();
    }

    protected function tearDown(): void
    {
        PluginManager::reset();
        parent::tearDown();
    }

    public function test_maps_start_empty(): void
    {
        $manager = PluginManager::instance();

        self::assertSame([], $manager->registered_map());
        self::assertSame([], $manager->booted_map());
        self::assertSame([], $manager->get_errors());
    }

    public function test_maps_reflect_register_and_boot_lifecycle(): void
    {
        $manager = PluginManager::instance();
        $manager->register(new AccessorsTestPlugin());
        $name = 'accessors-test-plugin';

        self::assertArrayNotHasKey($name, $manager->registered_map());
        $manager->register_all();
        self::assertArrayHasKey($name, $manager->registered_map());
        self::assertArrayNotHasKey($name, $manager->booted_map());

        $manager->boot_all();
        self::assertArrayHasKey($name, $manager->booted_map());
        self::assertSame([], $manager->get_errors());
    }

    public function test_boot_failure_is_visible_in_errors_but_not_in_booted_map(): void
    {
        $manager = PluginManager::instance();
        $manager->register(new AccessorsFailingBootPlugin());
        $manager->register_all();
        $manager->boot_all();

        $name = 'accessors-failing-boot-plugin';
        self::assertArrayHasKey($name, $manager->registered_map());
        self::assertArrayNotHasKey($name, $manager->booted_map());
        self::assertArrayHasKey($name, $manager->get_errors());
        self::assertStringContainsString('boot failed intentionally', (string)$manager->get_errors()[$name]);
    }

    public function test_disable_removes_plugin_from_maps(): void
    {
        $manager = PluginManager::instance();
        $plugin = new AccessorsTestPlugin();
        $manager->register($plugin);
        $manager->register_all();
        $manager->boot_all();
        $name = 'accessors-test-plugin';
        self::assertArrayHasKey($name, $manager->booted_map());

        $manager->disable($name);
        self::assertArrayNotHasKey($name, $manager->registered_map());
        self::assertArrayNotHasKey($name, $manager->booted_map());
    }
}
