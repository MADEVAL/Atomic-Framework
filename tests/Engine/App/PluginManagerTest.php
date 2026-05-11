<?php
declare(strict_types=1);

namespace Tests\Engine\App;

use Engine\Atomic\App\Plugin;
use Engine\Atomic\App\PluginManager;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\RouteLoader;
use Engine\Atomic\Exceptions\PluginDependencyException;
use Engine\Atomic\Hook\ApplicationHook;
use Engine\Atomic\Hook\Hook;
use Engine\Atomic\Plugins\WebSockets\WebSockets;
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

class MissingRuntimeDependencyPlugin extends Plugin
{
    protected function get_name(): string
    {
        return 'missing-runtime-dependency';
    }

    public function required_dependencies(): array
    {
        return [
            [
                'package' => 'vendor/missing-package',
                'classes' => [
                    'Tests\\Engine\\App\\ClassThatDoesNotExist',
                ],
            ],
        ];
    }
}

class InvalidRuntimeDependencyPlugin extends Plugin
{
    protected function get_name(): string
    {
        return 'invalid-runtime-dependency';
    }

    public function required_dependencies(): array
    {
        return ['vendor/package'];
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
        Hook::instance()->add_action(
            ApplicationHook::ROUTES_REGISTERED,
            function (App $app, string $request_type, array $files, string $source): void {
                self::$events[] = $request_type . ':' . $source;
            },
            10,
            4
        );
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
        Hook::instance()->add_action(ApplicationHook::PLUGINS_LOADED, function (): void {
            self::$events[] = 'plugins_loaded';
        }, 10, 0);

        Hook::instance()->add_action(ApplicationHook::ROUTES_REGISTERED, function (): void {
            self::$events[] = 'routes_registered';
        }, 10, 0);
    }
}

class HookQueuedRouteTypePlugin extends Plugin
{
    public static string $plugin_path = '';

    protected function get_name(): string
    {
        return 'hook-queued-route-type';
    }

    protected function get_path(): string
    {
        return self::$plugin_path !== '' ? self::$plugin_path : parent::get_path();
    }

    public function boot(): void
    {
        Hook::instance()->add_action(ApplicationHook::PLUGINS_LOADED, function (App $app): void {
            $app->register_route_type('hooked', 'hooked.php');
        }, 10, 1);
    }
}

class CustomRouteTypePlugin extends Plugin
{
    public static string $plugin_path = '';

    protected function get_name(): string
    {
        return 'custom-route-type';
    }

    protected function get_path(): string
    {
        return self::$plugin_path !== '' ? self::$plugin_path : parent::get_path();
    }

    public function register(): void
    {
        $this->atomic->register_route_type('websocket', 'websocket.php');
    }
}

class LifecycleRoutePlugin extends Plugin
{
    public static string $plugin_path = '';

    protected function get_name(): string
    {
        return 'lifecycle-route';
    }

    protected function get_path(): string
    {
        return self::$plugin_path !== '' ? self::$plugin_path : parent::get_path();
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
        CustomRouteTypePlugin::$plugin_path = '';
        HookQueuedRouteTypePlugin::$plugin_path = '';
        LifecycleRoutePlugin::$plugin_path = '';
        $this->reset_app_lifecycle_state();
        $this->reset_route_loader_state();
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

    public function test_runtime_dependency_check_throws_clear_plugin_exception(): void
    {
        $this->expectException(PluginDependencyException::class);
        $this->expectExceptionMessage('Plugin missing-runtime-dependency is missing package vendor/missing-package. Run: php atomic plugin/deps install missing-runtime-dependency');

        (new MissingRuntimeDependencyPlugin())->assert_runtime_requirements();
    }

    public function test_plugin_without_runtime_dependencies_passes_check(): void
    {
        $plugin = new TestPlugin();
        $plugin->assert_runtime_requirements();

        $this->assertSame('test-plugin', $plugin->get_plugin_name());
    }

    public function test_runtime_dependency_requires_symbol_check(): void
    {
        $this->expectException(PluginDependencyException::class);
        $this->expectExceptionMessage('must declare at least one class or function check');

        (new InvalidRuntimeDependencyPlugin())->assert_runtime_requirements();
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

    public function test_plugin_listener_receives_routes_registered(): void
    {
        $routes_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_routes_' . uniqid();
        mkdir($routes_dir, 0777, true);

        App::atomic()->set('FRAMEWORK_ROUTES', $routes_dir . DIRECTORY_SEPARATOR);
        App::atomic()->set('USER_PLUGINS', $routes_dir . DIRECTORY_SEPARATOR . 'plugins');

        $this->manager->register(new RouteHookPlugin());
        App::instance()->register_plugins();
        App::instance()->register_routes_for('web');

        $this->assertSame(['web:app', 'web:plugin'], RouteHookPlugin::$events);
        rmdir($routes_dir);
    }

    public function test_routes_registered_lifecycle_hook_receives_source_and_files(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_routes_' . uniqid();
        $framework_routes = $root . DIRECTORY_SEPARATOR . 'framework';
        $plugin_dir = $root . DIRECTORY_SEPARATOR . 'plugin';

        mkdir($framework_routes, 0777, true);
        mkdir($plugin_dir . DIRECTORY_SEPARATOR . 'routes', 0777, true);

        $framework_route = $framework_routes . DIRECTORY_SEPARATOR . 'web.php';
        $plugin_route = $plugin_dir . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'web.php';

        file_put_contents($framework_route, '<?php $atomic->set("EVENTS.framework_route", true);');
        file_put_contents($plugin_route, '<?php $atomic->set("EVENTS.plugin_route", true);');

        App::atomic()->set('FRAMEWORK_ROUTES', $framework_routes . DIRECTORY_SEPARATOR);
        App::atomic()->set('USER_PLUGINS', $root . DIRECTORY_SEPARATOR . 'user_plugins');

        LifecycleRoutePlugin::$plugin_path = $plugin_dir;
        $this->manager->register(new LifecycleRoutePlugin());

        $events = [];
        Hook::instance()->add_action(
            ApplicationHook::ROUTES_REGISTERED,
            function (App $app, string $request_type, array $files, string $source) use (&$events): void {
                $events[] = [$request_type, $source, $files];
            },
            10,
            4
        );

        App::instance()->register_plugins();
        App::instance()->register_routes_for('web');

        $this->assertSame('web', $events[0][0]);
        $this->assertSame('app', $events[0][1]);
        $this->assertSame([realpath($framework_route)], $events[0][2]);
        $this->assertSame('web', $events[1][0]);
        $this->assertSame('plugin', $events[1][1]);
        $this->assertSame([realpath($plugin_route)], $events[1][2]);
        $this->remove_dir($root);
    }

    public function test_websockets_plugin_registers_route_type(): void
    {
        $this->manager->register(new WebSockets());

        $this->manager->register_all();

        $this->assertTrue(RouteLoader::instance()->has_route_type('websocket'));
    }

    public function test_plugin_registered_route_type_loads_framework_and_plugin_routes(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_routes_' . uniqid();
        $framework_routes = $root . DIRECTORY_SEPARATOR . 'framework';
        $plugin_dir = $root . DIRECTORY_SEPARATOR . 'plugin';

        mkdir($framework_routes, 0777, true);
        mkdir($plugin_dir . DIRECTORY_SEPARATOR . 'routes', 0777, true);

        file_put_contents(
            $framework_routes . DIRECTORY_SEPARATOR . 'websocket.php',
            '<?php $atomic->set("EVENTS.framework_websocket_route", true);'
        );
        file_put_contents(
            $plugin_dir . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'websocket.php',
            '<?php $atomic->set("EVENTS.plugin_websocket_route", true);'
        );

        App::atomic()->set('FRAMEWORK_ROUTES', $framework_routes . DIRECTORY_SEPARATOR);
        App::atomic()->set('USER_PLUGINS', $root . DIRECTORY_SEPARATOR . 'user_plugins');

        CustomRouteTypePlugin::$plugin_path = $plugin_dir;
        $this->manager->register(new CustomRouteTypePlugin());

        App::instance()->register_plugins();
        App::instance()->register_routes();

        $this->assertTrue(App::atomic()->get('EVENTS.framework_websocket_route'));
        $this->assertTrue(App::atomic()->get('EVENTS.plugin_websocket_route'));
        $this->remove_dir($root);
    }

    public function test_plugins_loaded_can_queue_route_type_before_route_loading(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_routes_' . uniqid();
        $framework_routes = $root . DIRECTORY_SEPARATOR . 'framework';
        $plugin_dir = $root . DIRECTORY_SEPARATOR . 'plugin';

        mkdir($framework_routes, 0777, true);
        mkdir($plugin_dir . DIRECTORY_SEPARATOR . 'routes', 0777, true);

        file_put_contents(
            $framework_routes . DIRECTORY_SEPARATOR . 'hooked.php',
            '<?php $atomic->set("EVENTS.framework_hooked_route", true);'
        );
        file_put_contents(
            $plugin_dir . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'hooked.php',
            '<?php $atomic->set("EVENTS.plugin_hooked_route", true);'
        );

        App::atomic()->set('FRAMEWORK_ROUTES', $framework_routes . DIRECTORY_SEPARATOR);
        App::atomic()->set('USER_PLUGINS', $root . DIRECTORY_SEPARATOR . 'user_plugins');

        HookQueuedRouteTypePlugin::$plugin_path = $plugin_dir;
        $this->manager->register(new HookQueuedRouteTypePlugin());

        App::instance()->register_plugins();
        App::instance()->register_routes();

        $this->assertTrue(App::atomic()->get('EVENTS.framework_hooked_route'));
        $this->assertTrue(App::atomic()->get('EVENTS.plugin_hooked_route'));
        $this->remove_dir($root);
    }

    public function test_plugins_loaded_runs_before_route_hooks(): void
    {
        $routes_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_routes_' . uniqid();
        mkdir($routes_dir, 0777, true);

        App::atomic()->set('FRAMEWORK_ROUTES', $routes_dir . DIRECTORY_SEPARATOR);
        App::atomic()->set('USER_PLUGINS', $routes_dir . DIRECTORY_SEPARATOR . 'plugins');

        $this->manager->register(new PluginRegisteredHookPlugin());
        App::instance()->register_plugins();
        App::instance()->register_routes_for('web');

        $this->assertSame([
            'plugins_loaded',
            'routes_registered',
            'routes_registered',
        ], PluginRegisteredHookPlugin::$events);
        rmdir($routes_dir);
    }

    public function test_plugins_loaded_lifecycle_hook_runs_before_route_hooks(): void
    {
        $routes_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_routes_' . uniqid();
        mkdir($routes_dir, 0777, true);

        App::atomic()->set('FRAMEWORK_ROUTES', $routes_dir . DIRECTORY_SEPARATOR);
        App::atomic()->set('USER_PLUGINS', $routes_dir . DIRECTORY_SEPARATOR . 'plugins');

        $events = [];
        Hook::instance()->add_action(ApplicationHook::PLUGINS_LOADED, function (App $app, PluginManager $manager) use (&$events): void {
            $events[] = 'plugins_loaded';
        }, 10, 2);
        Hook::instance()->add_action(ApplicationHook::ROUTES_REGISTERED, function () use (&$events): void {
            $events[] = 'routes_registered';
        }, 10, 0);

        App::instance()->register_plugins();
        App::instance()->register_routes_for('web');

        $this->assertSame([
            'plugins_loaded',
            'routes_registered',
            'routes_registered',
        ], $events);
        rmdir($routes_dir);
    }

    public function test_load_plugins_registers_provider_plugin_with_local_autoload(): void
    {
        $plugins_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_user_plugins_' . uniqid();
        $class_name = 'AutoloadedPlugin' . str_replace('.', '', uniqid('', true));
        $plugin_name = strtolower($class_name);
        $plugin_dir = $plugins_dir . DIRECTORY_SEPARATOR . $class_name;
        $plugin_class = "App\\Plugins\\{$class_name}\\{$class_name}";
        $marker_class = 'AtomicPluginAutoloadMarker' . str_replace('.', '', uniqid('', true));
        mkdir($plugin_dir . DIRECTORY_SEPARATOR . 'vendor', 0777, true);

        file_put_contents(
            $plugin_dir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php',
            "<?php\nclass {$marker_class} {}\nrequire_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '{$class_name}.php';\n"
        );
        file_put_contents(
            $plugin_dir . DIRECTORY_SEPARATOR . $class_name . '.php',
            <<<PHP
<?php
declare(strict_types=1);
namespace App\\Plugins\\{$class_name};

if (!defined('ATOMIC_START')) exit;
if (!class_exists('{$marker_class}')) {
    throw new \\RuntimeException('plugin autoload was not loaded first');
}

final class {$class_name} extends \\Engine\\Atomic\\App\\Plugin
{
    protected function get_name(): string
    {
        return '{$plugin_name}';
    }
}
PHP
        );

        App::atomic()->set('USER_PLUGINS', $plugins_dir);

        $this->manager->load_plugins([$plugin_class]);

        $this->assertTrue($this->manager->has($plugin_name));
        $this->assertInstanceOf($plugin_class, $this->manager->get($plugin_name));
        $this->remove_dir($plugins_dir);
    }

    public function test_load_plugins_loads_known_plugin_composer_before_instantiation(): void
    {
        $plugin_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_known_plugin_' . uniqid();
        $class_name = 'KnownComposerPlugin' . str_replace('.', '', uniqid('', true));
        $plugin_name = strtolower($class_name);
        $plugin_class = "Tests\\Engine\\App\\{$class_name}";
        $marker_class = 'KnownPluginAutoloadMarker' . str_replace('.', '', uniqid('', true));
        mkdir($plugin_dir . DIRECTORY_SEPARATOR . 'vendor', 0777, true);

        file_put_contents(
            $plugin_dir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php',
            "<?php\nclass {$marker_class} {}\n"
        );
        file_put_contents(
            $plugin_dir . DIRECTORY_SEPARATOR . $class_name . '.php',
            <<<PHP
<?php
declare(strict_types=1);
namespace Tests\\Engine\\App;

if (!defined('ATOMIC_START')) exit;

final class {$class_name} extends \\Engine\\Atomic\\App\\Plugin
{
    public function __construct(?\\Engine\\Atomic\\Core\\App \$atomic = null)
    {
        if (!class_exists('{$marker_class}')) {
            throw new \\RuntimeException('plugin autoload was not loaded before construction');
        }

        parent::__construct(\$atomic);
    }

    protected function get_name(): string
    {
        return '{$plugin_name}';
    }
}
PHP
        );
        require_once $plugin_dir . DIRECTORY_SEPARATOR . $class_name . '.php';

        $this->manager->load_plugins([$plugin_class]);

        $this->assertTrue($this->manager->has($plugin_name));
        $this->assertInstanceOf($plugin_class, $this->manager->get($plugin_name));
        $this->remove_dir($plugin_dir);
    }

    public function test_load_plugins_ignores_unregistered_plugin_file(): void
    {
        $plugins_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_user_plugins_' . uniqid();
        $plugin_dir = $plugins_dir . DIRECTORY_SEPARATOR . 'UnregisteredPlugin';
        mkdir($plugin_dir, 0777, true);

        file_put_contents(
            $plugin_dir . DIRECTORY_SEPARATOR . 'plugin.php',
            "<?php\n\\Engine\\Atomic\\App\\PluginManager::instance()->register(new \\Tests\\Engine\\App\\TestPlugin());\n"
        );

        App::atomic()->set('USER_PLUGINS', $plugins_dir);

        $this->manager->load_plugins([]);

        $this->assertFalse($this->manager->has('test-plugin'));
        $this->remove_dir($plugins_dir);
    }

    public function test_provider_plugin_namespace_autoloads_plugin_classes(): void
    {
        $plugins_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_user_plugins_' . uniqid();
        $plugin_dir = $plugins_dir . DIRECTORY_SEPARATOR . 'AutoloadedPlugin';
        $services_dir = $plugin_dir . DIRECTORY_SEPARATOR . 'Services';
        mkdir($services_dir, 0777, true);

        $plugin_class = 'App\\Plugins\\AutoloadedPlugin\\AutoloadedPlugin';
        $service_class = 'App\\Plugins\\AutoloadedPlugin\\Services\\MarkerService';

        file_put_contents(
            $plugin_dir . DIRECTORY_SEPARATOR . 'AutoloadedPlugin.php',
            <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Plugins\AutoloadedPlugin;

if (!defined('ATOMIC_START')) exit;

final class AutoloadedPlugin extends \Engine\Atomic\App\Plugin
{
    protected function get_name(): string
    {
        return 'autoloaded-plugin';
    }

    public function register(): void
    {
        \App\Plugins\AutoloadedPlugin\Services\MarkerService::touch();
    }
}
PHP
        );

        file_put_contents(
            $services_dir . DIRECTORY_SEPARATOR . 'MarkerService.php',
            <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Plugins\AutoloadedPlugin\Services;

final class MarkerService
{
    public static bool $touched = false;

    public static function touch(): void
    {
        self::$touched = true;
    }
}
PHP
        );

        App::atomic()->set('USER_PLUGINS', $plugins_dir);

        $this->manager->load_plugins([$plugin_class]);
        $this->manager->register_all();

        $this->assertTrue(class_exists($service_class));
        $this->assertTrue($service_class::$touched);
        $this->remove_dir($plugins_dir);
    }

    public function test_load_plugins_supports_namespace_plugin_directory_names(): void
    {
        $plugins_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_user_plugins_' . uniqid();
        $plugin_dir = $plugins_dir . DIRECTORY_SEPARATOR . 'Example_Plugin';
        $services_dir = $plugin_dir . DIRECTORY_SEPARATOR . 'Services';
        mkdir($services_dir, 0777, true);

        $plugin_class = 'App\\Plugins\\Example_Plugin\\ExamplePlugin';
        $service_class = 'App\\Plugins\\Example_Plugin\\Services\\MarkerService';

        file_put_contents(
            $plugin_dir . DIRECTORY_SEPARATOR . 'ExamplePlugin.php',
            <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Plugins\Example_Plugin;

if (!defined('ATOMIC_START')) exit;

final class ExamplePlugin extends \Engine\Atomic\App\Plugin
{
    protected function get_name(): string
    {
        return 'example-plugin';
    }

    public function register(): void
    {
        \App\Plugins\Example_Plugin\Services\MarkerService::touch();
    }
}
PHP
        );

        file_put_contents(
            $services_dir . DIRECTORY_SEPARATOR . 'MarkerService.php',
            <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Plugins\Example_Plugin\Services;

final class MarkerService
{
    public static bool $touched = false;

    public static function touch(): void
    {
        self::$touched = true;
    }
}
PHP
        );

        App::atomic()->set('USER_PLUGINS', $plugins_dir);

        $this->manager->load_plugins([$plugin_class]);
        $this->manager->register_all();

        $this->assertTrue($this->manager->has('example-plugin'));
        $this->assertTrue(class_exists($service_class));
        $this->assertTrue($service_class::$touched);
        $this->remove_dir($plugins_dir);
    }

    public function test_before_server_start_runs_once(): void
    {
        $calls = 0;
        Hook::instance()->add_action(ApplicationHook::BEFORE_SERVER_START, function (App $app) use (&$calls): void {
            $calls++;
            $this->assertSame(App::instance(), $app);
        }, 10, 1);

        App::instance()->before_server_start();
        App::instance()->before_server_start();

        $this->assertSame(1, $calls);
    }

    public function test_bootstrap_lifecycle_methods_fire_expected_payloads(): void
    {
        $events = [];

        Hook::instance()->add_action(ApplicationHook::CONFIG_LOADED, function (App $app, string $loader) use (&$events): void {
            $events[] = ['config_loaded', $app, $loader];
        }, 10, 2);
        Hook::instance()->add_action(ApplicationHook::CORE_READY, function (App $app) use (&$events): void {
            $events[] = ['core_ready', $app];
        }, 10, 1);
        Hook::instance()->add_action(ApplicationHook::APP_BOOTSTRAPPED, function (App $app) use (&$events): void {
            $events[] = ['app_bootstrapped', $app];
        }, 10, 1);

        App::instance()
            ->config_loaded('php')
            ->core_ready()
            ->app_bootstrapped();

        $this->assertSame(['config_loaded', 'core_ready', 'app_bootstrapped'], array_column($events, 0));
        $this->assertSame('php', $events[0][2]);
        foreach ($events as $event) {
            $this->assertSame(App::instance(), $event[1]);
        }
    }

    private function reset_app_lifecycle_state(): void
    {
        $ref = new \ReflectionClass(App::instance());
        foreach (['active_route_types' => [], 'extra_route_files' => [], 'loaded_app_route_types' => [], 'server_start_hook_fired' => false] as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setValue(App::instance(), $value);
        }
    }

    private function reset_route_loader_state(): void
    {
        $loader = RouteLoader::instance();
        $ref = new \ReflectionClass($loader);

        $route_type_map = $ref->getProperty('route_type_map');
        $route_type_map->setValue($loader, $ref->getConstant('DEFAULT_ROUTE_TYPE_MAP'));

        foreach (['framework_routes_path', 'app_routes_path'] as $name) {
            $prop = $ref->getProperty($name);
            $prop->setValue($loader, '');
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
