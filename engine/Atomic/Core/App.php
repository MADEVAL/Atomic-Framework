<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Auth\Services\AuthSessionService;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Lang\I18n;
use Engine\Atomic\Core\ExceptionHandlerRegistrar;
use Engine\Atomic\Core\Prefly;
use Engine\Atomic\Core\Middleware\AccessMiddleware;
use Engine\Atomic\Core\Middleware\MiddlewareStack;
use Engine\Atomic\Core\Middleware\RoleMiddleware;
use Engine\Atomic\App\PluginManager;
use Engine\Atomic\Auth\Auth;
use Engine\Atomic\Auth\Interfaces\UserProviderInterface;
use Engine\Atomic\CLI\CLI;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Hook\ApplicationHook;
use Engine\Atomic\Hook\Hook;
use Engine\Atomic\Session\Session;

class App {
    protected static ?self $instance = null;
    protected \Base $atomic;
    protected string $base_controller_class = 'Engine\Atomic\App\Controller';
    protected array $active_route_types = [];
    protected array $extra_route_files = [];
    protected array $loaded_app_route_types = [];
    protected bool $server_start_hook_fired = false;
    protected bool $auth_session_hooks_registered = false;
     
    public function __construct(\Base $atomic) {
        $this->atomic = $atomic;
    }

    public static function instance(?\Base $atomic = null): self {
        if (!self::$instance) {
            if (!$atomic) {
                $atomic = \Base::instance();
            }
            self::$instance = new self($atomic);
        }
        return self::$instance;
    }

    public static function atomic(): \Base {
        return self::instance()->atomic;
    }

    public function prefly(): self
    {
        $is_debug = (bool)filter_var($this->atomic->get('DEBUG_MODE'), FILTER_VALIDATE_BOOLEAN);
        $prefly = Prefly::instance();
        if (!$prefly->all_checks_passed()) {
            $checks = $prefly->check_environment();
            $failed = [];
            if (isset($checks['php_version']['status']) && !$checks['php_version']['status']) {
                $failed[] = 'PHP Version >= ' . $checks['php_version']['required'] . ' (Current: ' . $checks['php_version']['current'] . ')';
            }
            if (isset($checks['extensions'])) {
                foreach ($checks['extensions'] as $ext => $val) {
                    if (isset($val['status']) && !$val['status']) {
                        $failed[] = 'PHP Extension: ' . $ext;
                    }
                }
            }

            Hook::instance()->do_action(ApplicationHook::PREFLY_FAILED, $this, $failed, $checks);
            $msg = 'Prefly checks did not pass: ' . implode(', ', $failed);

            if (php_sapi_name() === 'cli') {
                $out = new Output();
                $out->writeln();
                $out->writeln('[Atomic] System Error');
                $out->writeln(str_repeat('-', 40));
                $out->writeln(implode("\n", array_map(fn($f) => " - Missing: $f", $failed)));
            } else {
                http_response_code(500);
                echo '<!DOCTYPE html><html><head><title>System Error | Atomic</title>';
                echo '<style>body{font-family:system-ui,-apple-system,sans-serif;background:#fdfdfd;color:#333;margin:0;padding:2rem;}';
                echo '.container{max-width:600px;margin:2rem auto;background:#fff;border-radius:8px;padding:2rem;box-shadow:0 4px 6px rgba(0,0,0,0.05);border-top:4px solid #ef4444;}';
                echo 'h1{color:#ef4444;margin-top:0;}</style></head><body>';
                echo '<div class="container"><h1>System Error</h1>';
                if ($is_debug) {
                    echo '<p>The application cannot start because the server environment does not meet the minimum requirements:</p>';
                    echo '<ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $failed)) . '</li></ul>';
                } else {
                    echo '<p>The application cannot start due to a server configuration error. Please contact the administrator.</p>';
                }
                echo '</div></body></html>';
            }
            exit(1);
        }

        if (php_sapi_name() !== 'cli') {
            $logs_dir = rtrim((string)$this->atomic->get('LOGS'), '/\\');
            if ($logs_dir === '') {
                $logs_dir = ATOMIC_DIR . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
            }
            $not_writable = [];

            if (!is_dir($logs_dir) || !is_writable($logs_dir)) {
                $not_writable[] = $logs_dir . DIRECTORY_SEPARATOR;
            }

            if ($not_writable !== []) {
                http_response_code(503);
                echo '<!DOCTYPE html><html><head><title>Service Unavailable | Atomic</title>';
                echo '<style>body{font-family:system-ui,-apple-system,sans-serif;background:#fdfdfd;color:#333;margin:0;padding:2rem;}';
                echo '.container{max-width:640px;margin:2rem auto;background:#fff;border-radius:8px;padding:2rem;box-shadow:0 4px 6px rgba(0,0,0,0.05);border-top:4px solid #f59e0b;}';
                echo 'h1{color:#f59e0b;margin-top:0;}code{background:#f3f4f6;padding:2px 6px;border-radius:4px;font-size:0.9em;}</style></head><body>';
                echo '<div class="container"><h1>Service Unavailable</h1>';
                if ($is_debug) {
                    echo '<p>The following directories must be writable by the web server user:</p>';
                    echo '<ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $not_writable)) . '</li></ul>';
                    echo '<p>Fix with:</p>';
                    echo '<pre><code>sudo chown -R www-data:www-data ' . htmlspecialchars($logs_dir, ENT_QUOTES, 'UTF-8') . "\n";
                    echo 'sudo chmod -R ug+rwX ' . htmlspecialchars($logs_dir, ENT_QUOTES, 'UTF-8') . '</code></pre>';
                    echo '<p>See <strong>DEPLOYMENT_GUIDE.md</strong> section 4 for details.</p>';
                } else {
                    echo '<p>The application is temporarily unavailable due to a server configuration error. Please contact the administrator.</p>';
                }
                echo '</div></body></html>';
                exit(0);
            }
        }

        $this->atomic->set('ATOMIC.NAME', ATOMIC_NAME);
        $this->atomic->set('ATOMIC.VERSION', ATOMIC_VERSION);
        $this->atomic->set('PACKAGE', ATOMIC_NAME . ' ' . ATOMIC_VERSION);
        $this->atomic->set('UPLOADS', ATOMIC_UPLOADS);
        return $this;
    }

    public function register_logger(): self {
        Log::init($this->atomic);
        return $this;
    }

    public function register_locales(): self {
        $i18n = I18n::instance();
        return $this;
    }

    public function register_locale_hrefs(): self {
        if ($this->atomic->get('i18n.url_mode') === 'prefix') {
            $i18n = I18n::instance();
            $path = (string)$this->atomic->get('PATH');
            $parts = explode('/', ltrim($path, '/'));
            $langs = $i18n->languages();
            if (!empty($parts[0]) && in_array($parts[0], $langs, true)) {
                array_shift($parts);
                $this->atomic->set('PATH', '/'.implode('/', $parts)); 
                $this->atomic->set('PARAMS.lang', $i18n->get());     
            }
        }
        return $this;
    }

    public function register_routes(string ...$route_files): self {
        $this->activate_route_type($this->detect_request_type(), ...$route_files);
        $this->load_app_routes();
        $this->load_plugin_routes();
        return $this;
    }

    public function register_route_type(string $request_type, array|string $file_names): self
    {
        $request_type = strtolower(trim($request_type));
        RouteLoader::instance()->register_route_type($request_type, $file_names);
        $this->active_route_types[$request_type] = true;
        return $this;
    }

    public function register_routes_for(string $request_type, string ...$route_files): self {
        $this->activate_route_type($request_type, ...$route_files);
        $this->load_app_routes($request_type);
        $this->load_plugin_routes($request_type);
        return $this;
    }

    protected function activate_route_type(string $request_type, string ...$route_files): self
    {
        $request_type = strtolower(trim($request_type));
        $this->active_route_types[$request_type] = true;

        if ($route_files !== []) {
            $this->extra_route_files[$request_type] = array_values(array_unique(array_merge(
                $this->extra_route_files[$request_type] ?? [],
                $route_files
            )));
        }

        return $this;
    }

    protected function load_app_routes(?string $only_request_type = null): void
    {
        $route_types = $only_request_type === null
            ? array_keys($this->active_route_types)
            : [strtolower(trim($only_request_type))];

        foreach ($route_types as $request_type) {
            if (isset($this->loaded_app_route_types[$request_type])) {
                continue;
            }

            $app_files = $this->load_routes_for($request_type, ...($this->extra_route_files[$request_type] ?? []));
            $this->loaded_app_route_types[$request_type] = true;
            Hook::instance()->do_action(ApplicationHook::ROUTES_REGISTERED, $this, $request_type, $app_files, 'app');
        }
    }

    protected function load_routes_for(string $request_type, string ...$route_files): array
    {
        $request_type = strtolower(trim($request_type));
        $route_loader = RouteLoader::instance();
        $framework_routes = (string)$this->atomic->get('FRAMEWORK_ROUTES', '');
        if ($framework_routes === '') {
            throw new \RuntimeException('Framework routes directory is not configured.');
        }
        $route_loader->configure_paths(
            $framework_routes,
            ATOMIC_APP_ROUTES
        );
        $files_to_load = array_merge($route_loader->get_files_for($request_type), $route_files);
        $loaded_files = [];

        foreach ($files_to_load as $route_file) {
            $resolved_route_file = realpath($route_file);
            if ($resolved_route_file !== false && is_file($resolved_route_file) && is_readable($resolved_route_file)) {
                $atomic = $this;
                require $resolved_route_file;
                $loaded_files[] = $resolved_route_file;
            }
        }
        return $loaded_files;
    }

    protected function load_plugin_routes(?string $only_request_type = null): void
    {
        $route_types = $only_request_type === null
            ? array_keys($this->active_route_types)
            : [strtolower(trim($only_request_type))];

        $manager = PluginManager::instance();

        foreach ($route_types as $request_type) {
            $plugin_files = $manager->load_plugin_routes_for($request_type);
            Hook::instance()->do_action(ApplicationHook::ROUTES_REGISTERED, $this, $request_type, $plugin_files, 'plugin');
        }
    }

    public function detect_request_type(): string
    {
        if (!empty($this->atomic->CLI)) return 'cli';

        $path = (string)($this->atomic->get('PATH') ?: '');

        $path = ltrim($path, '/');
        $segments = explode('/', $path);
        $first_segment = strtolower($segments[0] ?? '');

        switch ($first_segment) {
            case 'api': return 'api';
            case 'telemetry': return 'telemetry';
            default: return 'web';
        }
    }

    public function register_exception_handler(): self {
        ExceptionHandlerRegistrar::register($this->atomic);
        return $this;
    }

    public function register_unload_handler(): self {
        $this->atomic->set('UNLOAD', function($atomic) {
            //  Log::info('Request ended'); 
            //  TODO for unload testing only
            //  TODO Log::info('Memory usage: ' . AM::formatBytes(memory_get_usage(true)));
        });
        return $this;
    }
    
    public function open_connections(): self
    {
        ConnectionManager::instance()->open_all();
        return $this;
    }

    public function init_session(): self {
        Session::init();
        return $this;
    }

    public function handle_command(array $argv): int {
        $this->atomic->CLI = true;
        
        if (count($argv) < 2) {
            (new Output())->writeln('Usage: atomic <command> [options]');
            return 0;
        }
    
        $raw_command = strtolower(trim($argv[1]));
        $command = '/' . ltrim(str_replace(':', '/', $raw_command), '/');
    
        $cli = new CLI();
        if ($cli->check_root_warning($raw_command, $command)) {
            return 1;
        }
    
        $this->atomic->set('PATH', $command);
        $this->atomic->run();
        return 0;
    }

    // in base route($pattern,$handler,$ttl=0,$kbps=0)
    public function route(string $pattern, string $handler, array|int $ttl_or_middleware = 0, int $kbps = 0): void
    {
        $middleware = [];
        $ttl = 0;

        if (is_array($ttl_or_middleware)) {
            $middleware = $ttl_or_middleware;
        } else {
            $ttl = (int)$ttl_or_middleware;
        }

        if (!empty($middleware)) {
            MiddlewareStack::for_route($pattern, $middleware);
            $ttl = 0;
        }

        if (is_string($handler) && preg_match('/^([^>:]+)\s*(?:->|::)\s*\w+$/', $handler, $m)) {
            $class = ltrim($m[1], '\\');
            if (class_exists($class) && is_subclass_of($class, $this->base_controller_class)) {
                $has_custom_hook = $this->controller_has_custom_route_hook($class);
                if ($has_custom_hook) {
                    $ttl = 0;
                }
            }
        }

        $this->atomic->route($pattern, $handler, $ttl, $kbps);
    }

    protected function controller_has_custom_route_hook(string $class): bool
    {
        try {
            $r = new \ReflectionClass($class);
            $custom_before = false;
            $custom_after = false;
            if ($r->hasMethod('beforeroute')) {
                $m = $r->getMethod('beforeroute');
                $custom_before = $m->getDeclaringClass()->getName() !== $this->base_controller_class;
            } 
            if ($r->hasMethod('afterroute')) {
                $m = $r->getMethod('afterroute');
                $custom_after = $m->getDeclaringClass()->getName() !== $this->base_controller_class;
            }
            return $custom_before || $custom_after;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function run(): void
    {
        if (empty($this->atomic->CLI)) {
            $path = (string)$this->atomic->get('PATH');
            if ($path === '/index.php' || str_starts_with($path, '/index.php/')) {
                $clean = substr($path, strlen('/index.php')) ?: '/';
                $query = $this->atomic->get('QUERY');
                $this->atomic->reroute($clean . ($query ? '?' . $query : ''), true);
            }
            $this->apply_cors();
        }
        $this->before_server_start();
        $this->atomic->run();
    }

    private function apply_cors(): void
    {
        $cors = (array)$this->atomic->get('CORS');
        $origin = (string)$cors['origin'];
        $request_origin = (string)$this->atomic->get('HEADERS.Origin');
        $credentials = (bool)$cors['credentials'];

        if ($credentials && $origin === '*' && $request_origin !== '') {
            $origin = $request_origin;
        }

        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Headers: ' . (string)$cors['headers']);
        header('Access-Control-Expose-Headers: ' . (string)$cors['expose']);
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        if ($credentials) {
            header('Access-Control-Allow-Credentials: true');
        }
        $ttl = (int)$cors['ttl'];
        if ($ttl > 0) {
            header('Access-Control-Max-Age: ' . $ttl);
        }
        if ($origin !== '*') {
            header('Vary: Origin');
        }

        $verb = strtoupper((string)$this->atomic->get('VERB'));
        if ($verb === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    public function __call(string $name, array $arguments): mixed
    {
        if (method_exists($this->atomic, $name)) {
            return $this->atomic->$name(...$arguments);
        }
        throw new \Exception("Method {$name} not found");
    }

    public function die($message = '', bool $run_afterroute = false): void
    {
        if ($run_afterroute) {
            $ctrl = $this->atomic->get('__current_controller');
            if (is_object($ctrl) && method_exists($ctrl, 'afterroute')) {
                $ctrl->afterroute($this->atomic);
            }
        }

        // $this->atomic->unload();
        exit($message);
    }  

    public function register_middleware(): self
    {
        MiddlewareStack::register_alias('access', AccessMiddleware::class);
        MiddlewareStack::register_alias('role', RoleMiddleware::class);

        $config_file = ATOMIC_CONFIG . 'middleware.php';
        $resolved_config_file = realpath($config_file);
        if ($resolved_config_file !== false && is_file($resolved_config_file) && is_readable($resolved_config_file)) {
            $aliases = require $resolved_config_file;
            if (is_array($aliases)) {
                foreach ($aliases as $name => $class) {
                    MiddlewareStack::register_alias($name, $class);
                }
            }
        }
        return $this;
    }

    public function register_core_plugins(array ...$plugin_classes): self
    {
        PluginManager::instance()->load_plugins(empty($plugin_classes) ? null : $plugin_classes);
        return $this;
    }

    public function register_plugins(): self
    {
        $manager = PluginManager::instance();
        $manager->load_plugins();
        $manager->register_all();
        $manager->boot_all();
        Hook::instance()->do_action(ApplicationHook::PLUGINS_LOADED, $this, $manager);
        return $this;
    }

    public function before_server_start(): self
    {
        if ($this->server_start_hook_fired) {
            return $this;
        }

        $this->server_start_hook_fired = true;
        Hook::instance()->do_action(ApplicationHook::BEFORE_SERVER_START, $this);
        return $this;
    }

    public function config_loaded(string $loader): self
    {
        Hook::instance()->do_action(ApplicationHook::CONFIG_LOADED, $this, $loader);
        return $this;
    }

    public function core_ready(): self
    {
        Hook::instance()->do_action(ApplicationHook::CORE_READY, $this);
        return $this;
    }

    public function app_bootstrapped(): self
    {
        Hook::instance()->do_action(ApplicationHook::APP_BOOTSTRAPPED, $this);
        return $this;
    }

    public function register_user_provider(?string $provider_class = null): self
    {
        if ($provider_class === null) {
            $providers_config = ATOMIC_CONFIG . 'providers.php';
            $resolved_providers_config = realpath($providers_config);
            if ($resolved_providers_config !== false && is_file($resolved_providers_config) && is_readable($resolved_providers_config)) {
                $providers = require $resolved_providers_config;
                $provider_class = $providers['user_provider'] ?? null;
            }
        }

        if ($provider_class === null) {
            Log::warning('No user provider configured.');
            return $this;
        }

        if (!class_exists($provider_class)) {
            Log::error("User provider class not found: {$provider_class}");
            return $this;
        }

        $provider = new $provider_class();

        if (!($provider instanceof UserProviderInterface)) {
            Log::error("User provider {$provider_class} must implement UserProviderInterface.");
            return $this;
        }

        Auth::instance()->set_user_provider($provider);
        $this->register_auth_session_hooks();
        return $this;
    }

    private function register_auth_session_hooks(): void
    {
        if ($this->auth_session_hooks_registered) {
            return;
        }

        $this->auth_session_hooks_registered = true;
        Hook::instance()->add_action('SESSION_STARTED', function (): void {
            $app = new \Engine\Atomic\Auth\Adapters\AppContextAdapter();
            $session = new AuthSessionService(
                $app,
                new \Engine\Atomic\Auth\Adapters\PhpSessionAdapter(),
                new \Engine\Atomic\Auth\Adapters\SessionDriverFactoryAdapter($app),
                new \Engine\Atomic\Auth\Adapters\SystemClockAdapter(),
                new \Engine\Atomic\Auth\Adapters\IdValidatorAdapter(),
                new \Engine\Atomic\Auth\Adapters\LogAdapter(),
            );
            $session->validate_auth_session();
        }, 10, 0);
    }
}
