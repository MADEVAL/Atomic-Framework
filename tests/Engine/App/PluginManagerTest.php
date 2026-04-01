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

    protected function getName(): string
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

    protected function getName(): string
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
        $this->manager->registerAll();

        $this->assertTrue($plugin->registered);
    }

    public function test_boot_all(): void
    {
        $plugin = new TestPlugin();
        $this->manager->register($plugin);
        $this->manager->registerAll();
        $this->manager->bootAll();

        $this->assertTrue($plugin->booted);
    }

    public function test_enable_plugin(): void
    {
        $plugin = new TestPlugin();
        $plugin->setEnabled(false);
        $this->manager->register($plugin);

        $result = $this->manager->enable('test-plugin');
        $this->assertTrue($result);
        $this->assertTrue($plugin->isEnabled());
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
        $this->manager->registerAll();

        $result = $this->manager->disable('test-plugin');
        $this->assertTrue($result);
        $this->assertFalse($plugin->isEnabled());
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
        $plugin->setEnabled(false);
        $this->manager->register($plugin);
        $this->manager->registerAll();

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
        $this->assertSame('1.0.0', $plugin->getVersion());
    }

    public function test_plugin_name(): void
    {
        $plugin = new TestPlugin();
        $this->assertSame('test-plugin', $plugin->getPluginName());
    }

    public function test_plugin_path(): void
    {
        $plugin = new TestPlugin();
        $this->assertNotEmpty($plugin->getPluginPath());
    }

    public function test_plugin_dependencies(): void
    {
        $dep = new DependentPlugin();
        $this->assertSame(['test-plugin'], $dep->getDependencies());
    }
}
