<?php
declare(strict_types=1);

namespace Tests\Engine\App;

use Engine\Atomic\App\Plugin;
use Engine\Atomic\App\PluginManager;
use Engine\Atomic\Core\App;
use Engine\Atomic\Hook\ApplicationHook;
use Engine\Atomic\Hook\Hook;
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
    protected array $dependencies = [TestPlugin::class];

    protected function get_name(): string
    {
        return 'dependent-plugin';
    }
}

class MissingDependencyPlugin extends Plugin
{
    protected array $dependencies = ['Tests\\Engine\\App\\NoSuchPlugin'];

    protected function get_name(): string
    {
        return 'missing-dependency-plugin';
    }
}

class InvalidDependencyPlugin extends Plugin
{
    protected array $dependencies = [\stdClass::class];

    protected function get_name(): string
    {
        return 'invalid-dependency-plugin';
    }
}

class OrderedRootPlugin extends Plugin
{
    public static array $events = [];

    protected function get_name(): string
    {
        return 'ordered-root';
    }

    public function register(): void
    {
        self::$events[] = 'root:register';
    }

    public function boot(): void
    {
        self::$events[] = 'root:boot';
    }
}

class OrderedMiddlePlugin extends Plugin
{
    protected array $dependencies = [OrderedRootPlugin::class];

    protected function get_name(): string
    {
        return 'ordered-middle';
    }

    public function register(): void
    {
        OrderedRootPlugin::$events[] = 'middle:register';
    }

    public function boot(): void
    {
        OrderedRootPlugin::$events[] = 'middle:boot';
    }
}

class OrderedLeafPlugin extends Plugin
{
    protected array $dependencies = [OrderedMiddlePlugin::class];

    protected function get_name(): string
    {
        return 'ordered-leaf';
    }

    public function register(): void
    {
        OrderedRootPlugin::$events[] = 'leaf:register';
    }

    public function boot(): void
    {
        OrderedRootPlugin::$events[] = 'leaf:boot';
    }
}

class FailingRegisterPlugin extends Plugin
{
    protected function get_name(): string
    {
        return 'failing-register';
    }

    public function register(): void
    {
        throw new \RuntimeException('register failed intentionally');
    }
}

class DependsOnFailingRegisterPlugin extends Plugin
{
    protected array $dependencies = [FailingRegisterPlugin::class];

    protected function get_name(): string
    {
        return 'depends-on-failing-register';
    }

    public function register(): void
    {
        OrderedRootPlugin::$events[] = 'dependent:register';
    }
}

class FailingBootPlugin extends Plugin
{
    protected function get_name(): string
    {
        return 'failing-boot';
    }

    public function boot(): void
    {
        throw new \RuntimeException('boot failed intentionally');
    }
}

class DependsOnFailingBootPlugin extends Plugin
{
    protected array $dependencies = [FailingBootPlugin::class];

    protected function get_name(): string
    {
        return 'depends-on-failing-boot';
    }

    public function boot(): void
    {
        OrderedRootPlugin::$events[] = 'dependent:boot';
    }
}

class CyclicAPlugin extends Plugin
{
    protected array $dependencies = [CyclicBPlugin::class];

    protected function get_name(): string
    {
        return 'cyclic-a';
    }

    public function register(): void
    {
        OrderedRootPlugin::$events[] = 'cyclic-a:register';
    }
}

class CyclicBPlugin extends Plugin
{
    protected array $dependencies = [CyclicAPlugin::class];

    protected function get_name(): string
    {
        return 'cyclic-b';
    }

    public function register(): void
    {
        OrderedRootPlugin::$events[] = 'cyclic-b:register';
    }
}

class RouteHookPlugin extends Plugin
{
    public static array $events = [];

    protected function get_name(): string
    {
        return 'route-hook';
    }

    public function boot(): void
    {
        Hook::instance()->add_action(ApplicationHook::AFTER_ROUTES_REGISTERED, function (App $app, string $request_type): void {
            self::$events[] = $request_type;
        }, 10, 2);
    }
}

class PluginRegisteredHookPlugin extends Plugin
{
    public static array $events = [];

    protected function get_name(): string
    {
        return 'plugin-registered-hook';
    }

    public function boot(): void
    {
        Hook::instance()->add_action(ApplicationHook::AFTER_PLUGINS_REGISTERED, function (): void {
            self::$events[] = 'after_plugins_registered';
        }, 10, 0);

        Hook::instance()->add_action(ApplicationHook::AFTER_ROUTES_REGISTERED, function (): void {
            self::$events[] = 'after_routes_registered';
        }, 10, 0);
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
        OrderedRootPlugin::$events = [];
        RouteHookPlugin::$events = [];
        PluginRegisteredHookPlugin::$events = [];
        $this->reset_app_lifecycle_state();
        App::atomic()->clear('EVENTS');
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

    public function test_plugin_constructor_accepts_app_instance(): void
    {
        $app = App::instance();
        $plugin = new TestPlugin($app);

        $this->assertSame('test-plugin', $plugin->get_plugin_name());
    }

    public function test_plugin_dependencies(): void
    {
        $dep = new DependentPlugin();
        $this->assertSame([TestPlugin::class], $dep->get_dependencies());
    }

    public function test_resolve_dependency_returns_registered_plugin_by_class(): void
    {
        $plugin = new TestPlugin();
        $dependent = new DependentPlugin();
        $this->manager->register($plugin);

        $this->assertSame($plugin, $this->manager->resolve_dependency($dependent, TestPlugin::class));
    }

    public function test_resolve_dependency_throws_for_missing_class(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires missing plugin class');

        $this->manager->resolve_dependency(new MissingDependencyPlugin(), 'Tests\\Engine\\App\\NoSuchPlugin');
    }

    public function test_resolve_dependency_throws_for_non_plugin_class(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must extend');

        $this->manager->resolve_dependency(new InvalidDependencyPlugin(), \stdClass::class);
    }

    public function test_resolve_dependency_throws_for_unregistered_plugin(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not registered');

        $this->manager->resolve_dependency(new DependentPlugin(), TestPlugin::class);
    }

    public function test_resolve_dependency_throws_for_disabled_plugin_during_register_all(): void
    {
        $plugin = new TestPlugin();
        $plugin->set_enabled(false);
        $dependent = new DependentPlugin();
        $this->manager->register($plugin);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('disabled');

        $ref = new \ReflectionMethod($this->manager, 'check_dependencies');
        $ref->invoke($this->manager, $dependent);
    }

    public function test_register_all_runs_dependencies_before_dependents(): void
    {
        $this->manager->register(new OrderedLeafPlugin());
        $this->manager->register(new OrderedMiddlePlugin());
        $this->manager->register(new OrderedRootPlugin());

        $this->manager->register_all();

        $this->assertSame([
            'root:register',
            'middle:register',
            'leaf:register',
        ], OrderedRootPlugin::$events);
    }

    public function test_boot_all_runs_dependencies_before_dependents(): void
    {
        $this->manager->register(new OrderedLeafPlugin());
        $this->manager->register(new OrderedMiddlePlugin());
        $this->manager->register(new OrderedRootPlugin());

        $this->manager->register_all();
        OrderedRootPlugin::$events = [];
        $this->manager->boot_all();

        $this->assertSame([
            'root:boot',
            'middle:boot',
            'leaf:boot',
        ], OrderedRootPlugin::$events);
    }

    public function test_dependency_cycle_plugins_are_skipped_without_blocking_others(): void
    {
        $this->manager->register(new CyclicAPlugin());
        $this->manager->register(new OrderedRootPlugin());
        $this->manager->register(new CyclicBPlugin());

        $this->manager->register_all();

        $this->assertSame(['root:register'], OrderedRootPlugin::$events);
    }

    public function test_dependent_does_not_register_when_dependency_registration_fails(): void
    {
        $this->manager->register(new DependsOnFailingRegisterPlugin());
        $this->manager->register(new FailingRegisterPlugin());

        $this->manager->register_all();

        $this->assertSame([], OrderedRootPlugin::$events);
    }

    public function test_dependent_does_not_boot_when_dependency_boot_fails(): void
    {
        $this->manager->register(new DependsOnFailingBootPlugin());
        $this->manager->register(new FailingBootPlugin());

        $this->manager->register_all();
        $this->manager->boot_all();

        $this->assertSame([], OrderedRootPlugin::$events);
    }

    public function test_plugin_listener_receives_after_routes_registered(): void
    {
        $routes_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_routes_' . uniqid();
        mkdir($routes_dir, 0777, true);

        App::atomic()->set('FRAMEWORK_ROUTES', $routes_dir . DIRECTORY_SEPARATOR);
        App::atomic()->set('USER_PLUGINS', $routes_dir . DIRECTORY_SEPARATOR . 'plugins');

        App::instance()->register_routes_for('web');
        $this->manager->register(new RouteHookPlugin());
        App::instance()->register_plugins();

        $this->assertSame(['web'], RouteHookPlugin::$events);
        rmdir($routes_dir);
    }

    public function test_after_plugins_registered_runs_before_route_hooks(): void
    {
        $routes_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_routes_' . uniqid();
        mkdir($routes_dir, 0777, true);

        App::atomic()->set('FRAMEWORK_ROUTES', $routes_dir . DIRECTORY_SEPARATOR);
        App::atomic()->set('USER_PLUGINS', $routes_dir . DIRECTORY_SEPARATOR . 'plugins');

        App::instance()->register_routes_for('web');
        $this->manager->register(new PluginRegisteredHookPlugin());
        App::instance()->register_plugins();

        $this->assertSame([
            'after_plugins_registered',
            'after_routes_registered',
        ], PluginRegisteredHookPlugin::$events);
        rmdir($routes_dir);
    }

    public function test_load_user_plugins_requires_local_autoload_before_plugin_file(): void
    {
        $plugins_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_user_plugins_' . uniqid();
        $plugin_dir = $plugins_dir . DIRECTORY_SEPARATOR . 'AutoloadedPlugin';
        $marker_class = 'AtomicPluginAutoloadMarker' . str_replace('.', '', uniqid('', true));
        mkdir($plugin_dir . DIRECTORY_SEPARATOR . 'vendor', 0777, true);

        file_put_contents(
            $plugin_dir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php',
            "<?php\nclass {$marker_class} {}\n"
        );
        file_put_contents(
            $plugin_dir . DIRECTORY_SEPARATOR . 'plugin.php',
            <<<PHP
<?php
if (!defined('ATOMIC_START')) exit;
if (!class_exists('{$marker_class}')) {
    throw new RuntimeException('plugin autoload was not loaded first');
}
\Engine\Atomic\App\PluginManager::instance()->register(new \Tests\Engine\App\TestPlugin());
PHP
        );

        App::atomic()->set('USER_PLUGINS', $plugins_dir);

        $this->manager->load_user_plugins();

        $this->assertTrue($this->manager->has('test-plugin'));
        $this->remove_dir($plugins_dir);
    }

    public function test_before_server_start_runs_once(): void
    {
        $calls = 0;
        Hook::instance()->add_action(ApplicationHook::BEFORE_SERVER_START, function () use (&$calls): void {
            $calls++;
        }, 10, 0);

        App::instance()->before_server_start();
        App::instance()->before_server_start();

        $this->assertSame(1, $calls);
    }

    private function reset_app_lifecycle_state(): void
    {
        $ref = new \ReflectionClass(App::instance());
        foreach (['registered_route_types' => [], 'server_start_hook_fired' => false] as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setValue(App::instance(), $value);
        }
    }

    private function remove_dir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->remove_dir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
