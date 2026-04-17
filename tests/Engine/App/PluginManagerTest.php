<?php
declare(strict_types=1);

namespace Tests\Engine\App;

use Engine\Atomic\App\Plugin;
use Engine\Atomic\App\PluginManager;
use PHPUnit\Framework\TestCase;

class TestPlugin extends Plugin
{
    public bool $registered = false;
    public bool $booted = false;
    public bool $activated = false;
    public bool $deactivated = false;

    protected function get_name(): string
    {
        return 'test-plugin';
    }

    public function register(): void
    {
        $this->registered = true;
    }

    public function boot(): void
    {
        $this->booted = true;
    }

    public function activate(): void
    {
        $this->activated = true;
    }

    public function deactivate(): void
    {
        $this->deactivated = true;
    }
}

class DependentPlugin extends Plugin
{
    protected array $dependencies = ['test-plugin'];

    protected function get_name(): string
    {
        return 'dependent-plugin';
    }
}

class PluginManagerTest extends TestCase
{
    private PluginManager $manager;

    protected function setUp(): void
    {
        // Reset singleton
        $ref = new \ReflectionClass(PluginManager::class);
        $prop = $ref->getProperty('instance');
        $prop->setValue(null, null);

        $this->manager = PluginManager::instance();
    }

    public function test_singleton(): void
    {
        $this->assertSame($this->manager, PluginManager::instance());
    }

    public function test_register_plugin(): void
    {
        $plugin = new TestPlugin();
        $this->manager->register($plugin);

        $this->assertTrue($this->manager->has('test-plugin'));
        $this->assertSame($plugin, $this->manager->get('test-plugin'));
    }

    public function test_has_unregistered(): void
    {
        $this->assertFalse($this->manager->has('nonexistent'));
    }

    public function test_get_unregistered(): void
    {
        $this->assertNull($this->manager->get('nonexistent'));
    }

    public function test_register_all(): void
    {
        $plugin = new TestPlugin();
        $this->manager->register($plugin);
        $this->manager->register_all();

        $this->assertTrue($plugin->registered);
    }

    public function test_boot_all(): void
    {
        $plugin = new TestPlugin();
        $this->manager->register($plugin);
        $this->manager->register_all();
        $this->manager->boot_all();

        $this->assertTrue($plugin->booted);
    }

    public function test_enable_plugin(): void
    {
        $plugin = new TestPlugin();
        $plugin->set_enabled(false);
        $this->manager->register($plugin);

        $result = $this->manager->enable('test-plugin');
        $this->assertTrue($result);
        $this->assertTrue($plugin->is_enabled());
        $this->assertTrue($plugin->activated);
    }

    public function test_enable_nonexistent(): void
    {
        $this->assertFalse($this->manager->enable('nonexistent'));
    }

    public function test_disable_plugin(): void
    {
        $plugin = new TestPlugin();
        $this->manager->register($plugin);
        $this->manager->register_all();

        $result = $this->manager->disable('test-plugin');
        $this->assertTrue($result);
        $this->assertFalse($plugin->is_enabled());
        $this->assertTrue($plugin->deactivated);
    }

    public function test_disable_nonexistent(): void
    {
        $this->assertFalse($this->manager->disable('nonexistent'));
    }

    public function test_all_plugins(): void
    {
        $plugin = new TestPlugin();
        $this->manager->register($plugin);

        $all = $this->manager->all();
        $this->assertCount(1, $all);
        $this->assertArrayHasKey('test-plugin', $all);
    }

    public function test_disabled_plugin_not_registered(): void
    {
        $plugin = new TestPlugin();
        $plugin->set_enabled(false);
        $this->manager->register($plugin);
        $this->manager->register_all();

        $this->assertFalse($plugin->registered);
    }

    public function test_duplicate_register_ignored(): void
    {
        $plugin1 = new TestPlugin();
        $plugin2 = new TestPlugin();

        $this->manager->register($plugin1);
        $this->manager->register($plugin2);

        // First one should be kept
        $this->assertSame($plugin1, $this->manager->get('test-plugin'));
    }

    public function test_plugin_version(): void
    {
        $plugin = new TestPlugin();
        $this->assertSame('1.0.0', $plugin->get_version());
    }

    public function test_plugin_name(): void
    {
        $plugin = new TestPlugin();
        $this->assertSame('test-plugin', $plugin->get_plugin_name());
    }

    public function test_plugin_path(): void
    {
        $plugin = new TestPlugin();
        $this->assertNotEmpty($plugin->get_plugin_path());
    }

    public function test_plugin_dependencies(): void
    {
        $dep = new DependentPlugin();
        $this->assertSame(['test-plugin'], $dep->get_dependencies());
    }
}
