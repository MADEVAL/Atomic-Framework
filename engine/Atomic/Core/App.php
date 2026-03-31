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

class App {
    protected static ?self $instance = null;
    protected \Base $atomic;
    protected string $baseControllerClass = 'Engine\Atomic\App\Controller';
    private ?ConnectionManager $connection_manager = null;
     
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
        if(!Prefly::instance()->all_checks_passed()) {
            Log::error('Prefly checks did not pass. Application cannot start.');
            exit(1);
        } else {
            $this->atomic->set('ATOMIC.NAME', ATOMIC_NAME);
            $this->atomic->set('ATOMIC.VERSION', ATOMIC_VERSION);
            $this->atomic->set('PACKAGE', ATOMIC_NAME . ' ' . ATOMIC_VERSION);
            $this->atomic->set('UPLOADS', ATOMIC_UPLOADS);
            return $this;
        }
    }

    public function registerLogger(): self {
        Log::init($this->atomic);
        return $this;
    }

    public function registerLocales(): self {
        $i18n = I18n::instance();
        return $this;
    }

    public function registerLocaleHrefs(): self {
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

    public function registerRoutes(string ...$routeFiles): self {
        $requestType = $this->detectRequestType();
        
        $routeLoader = RouteLoader::instance();
        $frameworkRoutes = (string)$this->atomic->get('FRAMEWORK_ROUTES', '');
        if ($frameworkRoutes === '') {
            throw new \RuntimeException('Framework routes directory is not configured.');
        }
        $routeLoader->configurePaths(
            $frameworkRoutes,
            ATOMIC_APP_ROUTES
        );
        $filesToLoad = $routeLoader->getFilesFor($requestType);

        foreach ($filesToLoad as $routeFile) {
            if (file_exists($routeFile)) {
                $atomic = $this;
                require $routeFile;
            }
        }
        return $this;
    }

    protected function detectRequestType(): string
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

    public function registerExceptionHandler(): self {
        ExceptionHandlerRegistrar::register($this->atomic);
        return $this;
    }

    public function registerUnloadHandler(): self {
        $this->atomic->set('UNLOAD', function($atomic) {
            //  Log::info('Request ended'); 
            //  TODO for unload testing only
            //  TODO Log::info('Memory usage: ' . AM::formatBytes(memory_get_usage(true)));
        });
        return $this;
    }
    
    public function setDB(): self {
        if ($this->connection_manager === null) {
            $this->connection_manager = new ConnectionManager();
        }
        $this->atomic->set('DB', $this->connection_manager->get_db());
        return $this;
    }

    public function resetDB(): self {
        $this->connection_manager?->close_sql();
        return $this->setDB();
    }

    public function closeDB(): void {
        if ($this->connection_manager !== null) {
            $this->connection_manager->close();
            $this->connection_manager = null;
        }
        $this->atomic->set('DB', null);
    }

    public function initSession(): self {
        Session::init();
        return $this;
    }

    public function handleCommand(array $argv): int {
        $this->atomic->CLI = true;
        if (count($argv) < 2) {
            echo "Usage: atomic <command> [options]\n";
            return 0;
        }
        $command = strtolower(trim($argv[1]));
        $command = str_replace(':', '/', $command);
        if ($command[0] !== '/') {
            $command = '/' . $command;
        }
        $this->atomic->set('PATH', $command);
        $this->atomic->run();
        return 0;
    }
    
    // in base route($pattern,$handler,$ttl=0,$kbps=0)
    public function route(string $pattern, string $handler, array|int $ttlOrMiddleware = 0, int $kbps = 0): void
    {
        $middleware = [];
        $ttl = 0;

        if (is_array($ttlOrMiddleware)) {
            $middleware = $ttlOrMiddleware;
        } else {
            $ttl = (int)$ttlOrMiddleware;
        }

        if (!empty($middleware)) {
            MiddlewareStack::forRoute($pattern, $middleware);
            $ttl = 0;
        }

        if (is_string($handler) && preg_match('/^([^>:]+)\s*(?:->|::)\s*\w+$/', $handler, $m)) {
            $class = ltrim($m[1], '\\');
            if (class_exists($class) && is_subclass_of($class, $this->baseControllerClass)) {
                $hasCustomHook = $this->controllerHasCustomRouteHook($class);
                if ($hasCustomHook) {
                    $ttl = 0;
                }
            }
        }

        $this->atomic->route($pattern, $handler, $ttl, $kbps);
    }

    protected function controllerHasCustomRouteHook(string $class): bool
    {
        try {
            $r = new \ReflectionClass($class);
            $customBefore = false;
            $customAfter = false;
            if ($r->hasMethod('beforeroute')) {
                $m = $r->getMethod('beforeroute');
                $customBefore = $m->getDeclaringClass()->getName() !== $this->baseControllerClass;
            } 
            if ($r->hasMethod('afterroute')) {
                $m = $r->getMethod('afterroute');
                $customAfter = $m->getDeclaringClass()->getName() !== $this->baseControllerClass;
            }
            return $customBefore || $customAfter;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function run(): void
    {        if (empty($this->atomic->CLI)) {
            $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
            $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
            if ($path !== '/' && substr($path, -1) === '/') {
                $query = parse_url($requestUri, PHP_URL_QUERY);
                $newPath = rtrim($path, '/');
                $host = $_SERVER['HTTP_HOST'] ?? $this->atomic->get('HOST') ?? '';
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $newUrl = $scheme . '://' . $host . $newPath . ($query ? '?' . $query : '');
                header('Location: ' . $newUrl, true, 302);
                exit;
            }
        }

        $this->atomic->run();
    }

    public function __call(string $name, array $arguments): mixed
    {        if (method_exists($this->atomic, $name)) {
            return $this->atomic->$name(...$arguments);
        }
        throw new \Exception("Method {$name} not found");
    }

    public function die($message = '', bool $runAfterroute = false): void
    {
        if ($runAfterroute) {
            $ctrl = $this->atomic->get('__current_controller');
            if (is_object($ctrl) && method_exists($ctrl, 'afterroute')) {
                $ctrl->afterroute($this->atomic);
            }
        }

        $this->atomic->unload();
        exit($message);
    }  

    public function registerMiddleware(): self
    {
        $configFile = ATOMIC_CONFIG . 'middleware.php';
        if (file_exists($configFile)) {
            $aliases = require $configFile;
            if (is_array($aliases)) {
                foreach ($aliases as $name => $class) {
                    MiddlewareStack::registerAlias($name, $class);
                }
            }
        }
        return $this;
    }

    public function registerCorePlugins(...$pluginClasses): self
    {
        if (empty($pluginClasses)) {
            $providersConfig = ATOMIC_CONFIG . 'providers.php';
            if (file_exists($providersConfig)) {
                $providers = require $providersConfig;
                $pluginClasses = $providers['plugins'] ?? [];
            }
        }

        $manager = PluginManager::instance();
        
        foreach ($pluginClasses as $pluginClass) {
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

    public function registerPlugins(): self
    {
        $manager = PluginManager::instance();
        $manager->loadUserPlugins();
        $manager->registerAll();
        $manager->bootAll();
        return $this;
    }

    public function registerUserProvider(?string $providerClass = null): self
    {
        if ($providerClass === null) {
            $providersConfig = ATOMIC_CONFIG . 'providers.php';
            if (file_exists($providersConfig)) {
                $providers = require $providersConfig;
                $providerClass = $providers['user_provider'] ?? null;
            }
        }

        if ($providerClass === null) {
            Log::warning('No user provider configured.');
            return $this;
        }

        if (!class_exists($providerClass)) {
            Log::error("User provider class not found: {$providerClass}");
            return $this;
        }

        $provider = new $providerClass();

        if (!($provider instanceof \Engine\Atomic\Auth\Interfaces\UserProviderInterface)) {
            Log::error("User provider {$providerClass} must implement UserProviderInterface.");
            return $this;
        }

        \Engine\Atomic\Auth\Auth::instance()->set_user_provider($provider);
        return $this;
    }
}