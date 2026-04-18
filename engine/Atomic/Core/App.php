<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Auth\Session;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\Methods as AM;
use Engine\Atomic\Lang\I18n;
use Engine\Atomic\Core\ExceptionHandlerRegistrar;
use Engine\Atomic\Core\Prefly;
use Engine\Atomic\Core\Middleware\MiddlewareStack;
use Engine\Atomic\App\PluginManager;
use Engine\Atomic\Auth\Auth;
use Engine\Atomic\Auth\Interfaces\UserProviderInterface;
use Engine\Atomic\CLI\CLI;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\Core\ConnectionManager;

class App {
    protected static ?self $instance = null;
    protected \Base $atomic;
    protected string $baseControllerClass = 'Engine\Atomic\App\Controller';
     
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
        $isDebug = (bool)filter_var($this->atomic->get('DEBUG_MODE'), FILTER_VALIDATE_BOOLEAN);
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
                if ($isDebug) {
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
            $storageDir = ATOMIC_DIR . DIRECTORY_SEPARATOR . 'storage';
            $logsDir    = $storageDir . DIRECTORY_SEPARATOR . 'logs';
            $notWritable = [];

            if (!is_dir($storageDir) || !is_writable($storageDir)) {
                $notWritable[] = 'storage/';
            }
            if (!is_dir($logsDir) || !is_writable($logsDir)) {
                $notWritable[] = 'storage/logs/';
            }

            if ($notWritable !== []) {
                http_response_code(503);
                echo '<!DOCTYPE html><html><head><title>Service Unavailable | Atomic</title>';
                echo '<style>body{font-family:system-ui,-apple-system,sans-serif;background:#fdfdfd;color:#333;margin:0;padding:2rem;}';
                echo '.container{max-width:640px;margin:2rem auto;background:#fff;border-radius:8px;padding:2rem;box-shadow:0 4px 6px rgba(0,0,0,0.05);border-top:4px solid #f59e0b;}';
                echo 'h1{color:#f59e0b;margin-top:0;}code{background:#f3f4f6;padding:2px 6px;border-radius:4px;font-size:0.9em;}</style></head><body>';
                echo '<div class="container"><h1>Service Unavailable</h1>';
                if ($isDebug) {
                    echo '<p>The following directories must be writable by the web server user:</p>';
                    echo '<ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $notWritable)) . '</li></ul>';
                    echo '<p>Fix with:</p>';
                    echo '<pre><code>sudo chown -R www-data:www-data storage' . "\n";
                    echo 'sudo chmod -R ug+rwX storage</code></pre>';
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
        $request_type = $this->detect_request_type();
        
        $routeLoader = RouteLoader::instance();
        $frameworkRoutes = (string)$this->atomic->get('FRAMEWORK_ROUTES', '');
        if ($frameworkRoutes === '') {
            throw new \RuntimeException('Framework routes directory is not configured.');
        }
        $routeLoader->configure_paths(
            $frameworkRoutes,
            ATOMIC_APP_ROUTES
        );
        $filesToLoad = $routeLoader->get_files_for($request_type);

        foreach ($filesToLoad as $routeFile) {
            $resolvedRouteFile = realpath($routeFile);
            if ($resolvedRouteFile !== false && is_file($resolvedRouteFile) && is_readable($resolvedRouteFile)) {
                $atomic = $this;
                require $resolvedRouteFile;
            }
        }
        return $this;
    }

    public function detect_request_type(): string
    {
        if (!empty($this->atomic->CLI)) return 'cli';

        $path = (string)($this->atomic->get('PATH') ?: '');

        $path = ltrim($path, '/');
        $segments = explode('/', $path);
        $firstSegment = strtolower($segments[0] ?? '');

        switch ($firstSegment) {
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
            if (class_exists($class) && is_subclass_of($class, $this->baseControllerClass)) {
                $hasCustomHook = $this->controller_has_custom_route_hook($class);
                if ($hasCustomHook) {
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
            $customBefore = false;
            $customAfter = false;
            if ($r->hasMethod('beforeroute')) {
                $m = $r->getMethod('beforeroute');
                $customBefore = $m->getDeclaringClass()->get_name() !== $this->baseControllerClass;
            } 
            if ($r->hasMethod('afterroute')) {
                $m = $r->getMethod('afterroute');
                $customAfter = $m->getDeclaringClass()->get_name() !== $this->baseControllerClass;
            }
            return $customBefore || $customAfter;
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
    {        if (method_exists($this->atomic, $name)) {
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
        $configFile = ATOMIC_CONFIG . 'middleware.php';
        $resolvedConfigFile = realpath($configFile);
        if ($resolvedConfigFile !== false && is_file($resolvedConfigFile) && is_readable($resolvedConfigFile)) {
            $aliases = require $resolvedConfigFile;
            if (is_array($aliases)) {
                foreach ($aliases as $name => $class) {
                    MiddlewareStack::register_alias($name, $class);
                }
            }
        }
        return $this;
    }

    public function register_core_plugins(...$plugin_classes): self
    {
        if (empty($plugin_classes)) {
            $providersConfig = ATOMIC_CONFIG . 'providers.php';
            $resolvedProvidersConfig = realpath($providersConfig);
            if ($resolvedProvidersConfig !== false && is_file($resolvedProvidersConfig) && is_readable($resolvedProvidersConfig)) {
                $providers = require $resolvedProvidersConfig;
                $plugin_classes = $providers['plugins'] ?? [];
            }
        }

        $manager = PluginManager::instance();
        
        foreach ($plugin_classes as $pluginClass) {
            if (!class_exists($pluginClass)) {
                Log::warning("Plugin class not found: {$pluginClass}");
                continue;
            }
            
            try {
                $manager->register(new $pluginClass($this));
            } catch (\Throwable $e) {
                Log::error("Failed to register plugin {$pluginClass}: " . $e->getMessage());
            }
        }
        
        return $this;
    }

    public function register_plugins(): self
    {
        $manager = PluginManager::instance();
        $manager->load_user_plugins();
        $manager->register_all();
        $manager->boot_all();
        return $this;
    }

    public function register_user_provider(?string $provider_class = null): self
    {
        if ($provider_class === null) {
            $providersConfig = ATOMIC_CONFIG . 'providers.php';
            $resolvedProvidersConfig = realpath($providersConfig);
            if ($resolvedProvidersConfig !== false && is_file($resolvedProvidersConfig) && is_readable($resolvedProvidersConfig)) {
                $providers = require $resolvedProvidersConfig;
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
        return $this;
    }
}