<?php

declare(strict_types=1);

namespace Engine\Atomic\Core;

if (!defined('ATOMIC_START')) {
    exit;
}

use Engine\Atomic\Core\Config\ConfigLoader;
use Engine\Atomic\Core\Config\ConfigSchema;
use Engine\Atomic\Core\Config\PhpConfigLoader;
use Engine\Atomic\Core\Health\HealthCheck;
use Engine\Atomic\Core\Health\HealthCheckRenderer;
use Engine\Atomic\Core\Providers\AppBootstrappedServiceProvider;
use Engine\Atomic\Core\Providers\AuthServiceProvider;
use Engine\Atomic\Core\Providers\ConfigServiceProvider;
use Engine\Atomic\Core\Providers\CorePluginServiceProvider;
use Engine\Atomic\Core\Providers\CoreReadyServiceProvider;
use Engine\Atomic\Core\Providers\DatabaseServiceProvider;
use Engine\Atomic\Core\Providers\ExceptionServiceProvider;
use Engine\Atomic\Core\Providers\LocaleServiceProvider;
use Engine\Atomic\Core\Providers\LogServiceProvider;
use Engine\Atomic\Core\Providers\MiddlewareServiceProvider;
use Engine\Atomic\Core\Providers\PluginServiceProvider;
use Engine\Atomic\Core\Providers\PreflyServiceProvider;
use Engine\Atomic\Core\Providers\RouteServiceProvider;
use Engine\Atomic\Core\Providers\ScheduleServiceProvider;
use Engine\Atomic\Core\Providers\SessionServiceProvider;
use Engine\Atomic\Core\Providers\UnloadServiceProvider;

final class Bootstrap
{
    public static function boot(): App
    {
        self::register_config_schema();
        self::load_helpers();
        self::configure_error_logging();

        $container = new Container();
        Container::setGlobal($container);

        $atomic = \Base::instance();
        $validator = new BootstrapConfigurationValidator();
        $health = PHP_SAPI === 'cli'
            ? $validator->command_from_argv((array)($_SERVER['argv'] ?? [])) === '/health'
            : self::is_health_request($atomic);
        if ($health) {
            self::run_health_check($atomic);
        }
        self::load_configuration($atomic);
        self::validate_configuration($atomic);

        $application = App::instance($atomic);

        $container->instance(\Base::class, $atomic);
        $container->instance(App::class, $application);

        $runtime = new Application($container);
        self::register_core_providers($runtime);
        self::register_application_providers($runtime);
        $runtime->boot();

        return $application;
    }

    public static function register_config_schema(): void
    {
        ConfigSchema::string('APP_NAME')->default('Atomic');
        ConfigSchema::string('APP_KEY')->required();
        ConfigSchema::string('APP_UUID')->required();
        ConfigSchema::string('APP_ENCRYPTION_KEY')->required();
        ConfigSchema::string('APP_URL')->default('http://localhost:8000');
        ConfigSchema::string('APP_TIMEZONE')->default('UTC');
        ConfigSchema::string('APP_LOCALE')->default('en');
        ConfigSchema::string('THEME')->default('default');
        ConfigSchema::string('ENCODING')->default('UTF-8');
        ConfigSchema::string('LANGUAGE')->default('en');
        ConfigSchema::string('TZ')->default('UTC');
        ConfigSchema::csv('I18N_LANGUAGES')->default('en,ru');
        ConfigSchema::string('I18N_DEFAULT')->default('en');
        ConfigSchema::string('I18N_URL_MODE')->default('prefix');
        ConfigSchema::int('I18N_TTL')->default(0);
        ConfigSchema::string('I18N_COOKIE')->default('lang');
        ConfigSchema::string('I18N_SESSION')->default('lang');
        ConfigSchema::bool('DEBUG_MODE')->default(false);
        ConfigSchema::string('DEBUG_LEVEL')->default('error');
        ConfigSchema::string('DOMAIN')->required();
        ConfigSchema::string('DB_DRIVER')->default('mysql');
        ConfigSchema::string('DB_HOST')->default('127.0.0.1');
        ConfigSchema::string('DB_PORT')->default('3306');
        ConfigSchema::string('DB_DB')->default('atomic');
        ConfigSchema::string('DB_USERNAME')->default('root');
        ConfigSchema::string('DB_PASSWORD')->required();
        ConfigSchema::string('DB_CHARSET')->default('utf8mb4');
        ConfigSchema::string('DB_COLLATION')->default('utf8mb4_general_ci');
        ConfigSchema::string('DB_PREFIX')->default('atomic_');
        ConfigSchema::string('CACHE_DRIVER')->default('folder');
        ConfigSchema::string('CACHE_PATH')->default('storage/framework/cache/');
        ConfigSchema::string('CACHE_SERVER')->default('localhost');
        ConfigSchema::string('CACHE_PASSWORD')->default('');
        ConfigSchema::string('CACHE_LOGIN')->default('');
        ConfigSchema::string('CACHE_PREFIX')->default('atomic.');
        ConfigSchema::int('CACHE_TTL')->default(3600);
        ConfigSchema::bool('CACHE_F3_HIVE_TTL')->default(false);
        ConfigSchema::string('SESSION_DRIVER')->default('db');
        ConfigSchema::int('SESSION_LIFETIME')->default(259200);
        ConfigSchema::string('SESSION_COOKIE')->default('Atomic_Session');
        ConfigSchema::bool('SESSION_KILL_ON_SUSPECT')->default(true);
        ConfigSchema::string('SESSION_REDIS_PREFIX')->default('atomic.session.');
        ConfigSchema::int('COOKIE_EXPIRE')->default(259200);
        ConfigSchema::string('COOKIE_PATH')->default('/');
        ConfigSchema::string('COOKIE_DOMAIN')->default('');
        ConfigSchema::bool('COOKIE_SECURE')->default(true);
        ConfigSchema::bool('COOKIE_HTTPONLY')->default(true);
        ConfigSchema::string('COOKIE_SAMESITE')->default('Lax');
        ConfigSchema::string('MAIL_DRIVER')->default('smtp');
        ConfigSchema::string('MAIL_HOST')->default('127.0.0.1');
        ConfigSchema::int('MAIL_PORT')->default(587);
        ConfigSchema::string('MAIL_USERNAME')->default('');
        ConfigSchema::string('MAIL_PASSWORD')->default('');
        ConfigSchema::string('MAIL_ENCRYPTION')->default('tls');
        ConfigSchema::string('MAIL_FROM_ADDRESS')->default('no-reply@example.com');
        ConfigSchema::string('MAIL_FROM_NAME')->default('Atomic');
        ConfigSchema::string('TELEGRAM_BOT_TOKEN')->default('');
        ConfigSchema::string('TELEGRAM_CHAT_ID')->default('');
        ConfigSchema::string('QUEUE_DRIVER')->default('redis');
        ConfigSchema::string('QUEUE_NAME')->default('default');
        ConfigSchema::string('CORS_ORIGIN')->required();
        ConfigSchema::string('CORS_HEADERS')->default('Content-Type,Authorization');
        ConfigSchema::bool('CORS_CREDENTIALS')->default(false);
        ConfigSchema::string('CORS_EXPOSE')->default('Authorization');
        ConfigSchema::int('CORS_TTL')->default(86400);
        ConfigSchema::bool('SECURITY_HEADERS_ENABLED')->default(true);
        ConfigSchema::string('SECURITY_HEADERS_XFO')->default('DENY');
        ConfigSchema::string('SECURITY_HEADERS_HSTS')->default('');
        ConfigSchema::string('SECURITY_HEADERS_CSP')->default('');
        ConfigSchema::string('REDIS_HOST')->default('127.0.0.1');
        ConfigSchema::string('REDIS_PORT')->default('6379');
        ConfigSchema::string('REDIS_PASSWORD')->default('');
        ConfigSchema::string('REDIS_PREFIX')->default('atomic.');
        ConfigSchema::string('MEMCACHED_HOST')->default('127.0.0.1');
        ConfigSchema::string('MEMCACHED_PORT')->default('11211');
        ConfigSchema::string('MEMCACHED_USERNAME')->default('');
        ConfigSchema::string('MEMCACHED_PASSWORD')->default('');
        ConfigSchema::string('MEMCACHED_PREFIX')->default('atomic.');
        ConfigSchema::string('MUTEX_DRIVER')->default('redis');
        ConfigSchema::string('UI')->default('public/themes/');
        ConfigSchema::string('TEMP')->default('storage/framework/cache/data/');
        ConfigSchema::string('LOGS')->default('storage/logs/');
        ConfigSchema::string('FONTS')->default('storage/framework/fonts/');
        ConfigSchema::string('FONTS_TEMP')->default('storage/framework/cache/fonts/');
        ConfigSchema::string('MIGRATIONS')->default('database/migrations/');
        ConfigSchema::string('LOCALES')->default('engine/Atomic/Lang/locales/');
        ConfigSchema::string('USER_PLUGINS')->default('plugins/');
        ConfigSchema::string('SEEDS')->default('database/seeds/');
        ConfigSchema::string('MIGRATIONS_CORE')->default('Atomic/Core/Database/Migrations/');
        ConfigSchema::string('FRAMEWORK_ROUTES')->default('Atomic/Core/Routes/');
        ConfigSchema::string('AI_OPENAI_API_KEY')->default('');
        ConfigSchema::string('AI_GROQ_API_KEY')->default('');
        ConfigSchema::string('AI_OPENROUTER_API_KEY')->default('');
        ConfigSchema::string('AI_GLOBUS_API_KEY')->default('');
        ConfigSchema::int('AUTH_RATE_LIMIT_MAX_ATTEMPTS')->default(5);
        ConfigSchema::int('AUTH_RATE_LIMIT_WINDOW_SECONDS')->default(300);
        ConfigSchema::int('AUTH_RATE_LIMIT_LOCKOUT_SECONDS')->default(900);
    }

    public static function configure_error_logging(): void
    {
        $base_log_file = ATOMIC_ENGINE . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'php_errors.log';
        $log_dir = dirname($base_log_file);

        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0775, true);
        }

        $daily_log_file = $log_dir . DIRECTORY_SEPARATOR . 'php_errors-' . date('Y-m-d') . '.log';

        ini_set('log_errors', '1');
        ini_set('html_errors', '0');
        ini_set('ignore_repeated_errors', '1');
        ini_set('log_errors_max_len', '16384');
        ini_set('error_log', $daily_log_file);

        if (!file_exists($daily_log_file)) {
            @file_put_contents($daily_log_file, '');
            @chmod($daily_log_file, 0664);
        }
        @chmod($log_dir, 0775);

        $logs = glob($log_dir . DIRECTORY_SEPARATOR . 'php_errors-*.log');
        if (is_array($logs) && count($logs) > 10) {
            natsort($logs);
            $excess = array_slice(array_values($logs), 0, count($logs) - 10);
            foreach ($excess as $old) {
                @unlink($old);
            }
        }
    }

    private static function load_helpers(): void
    {
        require_once ATOMIC_SUPPORT . 'helpers.php';
    }

    private static function load_configuration(\Base $atomic, bool $initialize_cache = true): void
    {
        if (ATOMIC_LOADER === 'php') {
            (new PhpConfigLoader($atomic, $initialize_cache))->load();
            return;
        }

        (new ConfigLoader($atomic, $initialize_cache))->load(ATOMIC_ENV);
    }

    private static function run_health_check(\Base $atomic): never
    {
        // F3's normal warning handler can render an error and exit before a
        // diagnostic report is produced. Keep failures inside this boundary.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            self::load_configuration($atomic, false);
            $report = HealthCheck::inspect($atomic);
        } catch (\Throwable $exception) {
            $message = PHP_SAPI === 'cli'
                ? sprintf(
                    '%s: %s [%s:%d]',
                    $exception::class,
                    $exception->getMessage(),
                    $exception->getFile(),
                    $exception->getLine(),
                )
                : 'Configuration or diagnostic initialization failed.';
            $report = [
                'healthy' => false,
                'required' => [[
                    'name' => 'Configuration source', 'status' => 'fail',
                    'message' => $message,
                    'suggestion' => 'Check configuration file readability, PHP syntax, and setting types. Remaining checks could not complete.',
                ]],
                'recommended' => [], 'optional' => [],
            ];
        } finally {
            restore_error_handler();
        }

        if (PHP_SAPI === 'cli') {
            HealthCheckRenderer::render_cli($report);
            exit($report['healthy'] ? 0 : 1);
        }
        HealthCheckRenderer::render_web($report);
        exit;
    }

    private static function validate_configuration(\Base $atomic): void
    {
        $validator = new BootstrapConfigurationValidator();
        $configuration = $validator->configuration_from_base($atomic);

        if (PHP_SAPI === 'cli') {
            $argv = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : [];
            $command = $validator->command_from_argv($argv);
            $errors = $validator->validate_cli($configuration, $command);
            if ($errors !== []) {
                BootstrapConfigurationErrorRenderer::render_cli($errors);
                exit(1);
            }
            return;
        }

        $errors = $validator->validate_web($configuration);
        if ($errors !== []) {
            $debug = filter_var($atomic->get('DEBUG_MODE'), FILTER_VALIDATE_BOOLEAN);
            BootstrapConfigurationErrorRenderer::render_web($errors, $debug);
            exit(1);
        }
    }

    private static function is_health_request(\Base $atomic): bool
    {
        if (strtoupper((string)($atomic->get('VERB') ?? '')) !== 'GET') {
            return false;
        }

        $path = parse_url((string)($atomic->get('PATH') ?? ''), PHP_URL_PATH);
        $path = '/' . trim(is_string($path) ? $path : '', '/');

        return $path === '/health' || $path === '/index.php/health';
    }

    private static function register_core_providers(Application $runtime): void
    {
        $runtime
            ->registerProvider(new ConfigServiceProvider())
            ->registerProvider(new LogServiceProvider())
            ->registerProvider(new ExceptionServiceProvider())
            ->registerProvider(new PreflyServiceProvider())
            ->registerProvider(new LocaleServiceProvider())
            ->registerProvider(new UnloadServiceProvider())
            ->registerProvider(new MiddlewareServiceProvider())
            ->registerProvider(new CoreReadyServiceProvider())
            ->registerProvider(new CorePluginServiceProvider())
            ->registerProvider(new PluginServiceProvider())
            ->registerProvider(new RouteServiceProvider())
            ->registerProvider(new ScheduleServiceProvider())
            ->registerProvider(new SessionServiceProvider())
            ->registerProvider(new DatabaseServiceProvider())
            ->registerProvider(new AuthServiceProvider())
            ->registerProvider(new AppBootstrappedServiceProvider());
    }

    private static function register_application_providers(Application $runtime): void
    {
        $providers_file = ATOMIC_CONFIG . 'providers.php';
        $resolved_providers_file = realpath($providers_file);

        if ($resolved_providers_file === false || !is_file($resolved_providers_file) || !is_readable($resolved_providers_file)) {
            return;
        }

        $config = require_once $resolved_providers_file;
        $providers = is_array($config) ? (array)($config['providers'] ?? []) : [];

        foreach ($providers as $provider) {
            if (!is_string($provider) || !is_a($provider, ServiceProvider::class, true)) {
                throw new \RuntimeException('Invalid application service provider: ' . (is_string($provider) ? $provider : get_debug_type($provider)));
            }

            $runtime->registerProvider(new $provider());
        }
    }
}
