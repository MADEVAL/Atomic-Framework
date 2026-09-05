<?php

declare(strict_types=1);

namespace Engine\Atomic\Core\Health;

if (!defined('ATOMIC_START')) {
    exit;
}

use Engine\Atomic\Core\BootstrapConfigurationValidator;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\Prefly;

final class HealthCheckInspector
{
    private readonly Prefly $prefly;
    private readonly BootstrapConfigurationValidator $validator;
    private readonly ConnectionManager $connections;
    private readonly \Closure $extension_available;
    private readonly \Closure $directory_writable;
    private readonly \Closure $configuration_source;

    public function __construct(
        private readonly \Base $atomic,
        ?Prefly $prefly = null,
        ?BootstrapConfigurationValidator $validator = null,
        ?ConnectionManager $connections = null,
        ?callable $extension_available = null,
        ?callable $directory_writable = null,
        ?callable $configuration_source = null,
    ) {
        $this->prefly = $prefly ?? Prefly::instance();
        $this->validator = $validator ?? new BootstrapConfigurationValidator();
        $this->connections = $connections ?? ConnectionManager::instance();
        $this->extension_available = \Closure::fromCallable(
            $extension_available ?? self::extension_available(...)
        );
        $this->directory_writable = \Closure::fromCallable(
            $directory_writable ?? self::directory_writable(...)
        );
        $this->configuration_source = \Closure::fromCallable(
            $configuration_source ?? self::configuration_source(...)
        );
    }

    public function snapshot(): array
    {
        $environment = $this->prefly->check_environment();
        $configuration = $this->validator->configuration_from_base($this->atomic);
        $mysql = $this->atomic->get('DB_CONFIG');
        $redis = $this->atomic->get('REDIS');
        $memcached = $this->atomic->get('MEMCACHED');

        return [
            'php' => $environment['php_version'],
            'required_extensions' => array_map(
                static fn(array $extension): bool => (bool)($extension['status'] ?? false),
                (array)($environment['extensions'] ?? []),
            ),
            'extensions' => [
                'redis' => ($this->extension_available)('redis'),
                'sodium' => ($this->extension_available)('sodium'),
                'memcached' => ($this->extension_available)('memcached'),
            ],
            'configuration_errors' => $this->validator->validate_web($configuration),
            'configuration_checks' => $this->configuration_checks($configuration),
            'config_source' => ($this->configuration_source)(),
            'runtime_paths' => $this->runtime_paths(),
            'log_files' => $this->log_files(),
            'drivers' => $this->drivers(),
            'mysql' => [
                'reachable' => $this->connections->probe_mysql($mysql),
                'target' => self::mysql_target($mysql),
            ],
            'redis' => [
                'reachable' => $this->connections->probe_redis($redis),
                'target' => self::target($redis),
            ],
            'memcached' => [
                'reachable' => $this->connections->probe_memcached($memcached),
                'target' => self::target($memcached),
            ],
        ];
    }

    /** @return array<string, bool> */
    private function runtime_paths(): array
    {
        $paths = [];
        foreach (['LOGS', 'TEMP', 'FONTS', 'FONTS_TEMP'] as $name) {
            $path = (string)$this->atomic->get($name);
            $paths[$name . ($path !== '' ? ' (' . $path . ')' : '')] =
                $path !== '' && ($this->directory_writable)($path);
        }

        $cache = $this->atomic->get('CACHE_CONFIG');
        if (strtolower(trim((string)$cache['default'])) === 'folder') {
            $path = (string)$cache['path'];
            $paths['CACHE' . ($path !== '' ? ' (' . $path . ')' : '')] =
                $path !== '' && ($this->directory_writable)($path);
        }

        if (defined('ATOMIC_UPLOADS')) {
            $path = (string)ATOMIC_UPLOADS;
            $paths['UPLOADS (' . $path . ')'] = $path !== '' && ($this->directory_writable)($path);
        }

        return $paths;
    }

    /** @return array{cache: string, session: string, mutex: string, queue: string} */
    private function drivers(): array
    {
        $cache = $this->atomic->get('CACHE_CONFIG');
        $session = $this->atomic->get('SESSION_CONFIG');
        $mutex = $this->atomic->get('MUTEX');

        return [
            'cache' => (string)$cache['default'],
            'session' => (string)$session['driver'],
            'mutex' => (string)$mutex['driver'],
            'queue' => (string)$this->atomic->get('QUEUE_DRIVER'),
        ];
    }

    /** @return array<string, bool> */
    private function log_files(): array
    {
        $logging = $this->atomic->get('LOG_CHANNELS');
        $channels = $logging['channels'];
        $paths = [];
        foreach ($channels as $name => $config) {
            $path = (string)$this->atomic->get('LOGS')
                . Log::resolve_dated_path((string)$config['path']);
            $paths['Log ' . $name . ' (' . $path . ')'] = $this->file_writable($path);
        }
        $php_log = (string)ini_get('error_log');
        if ($php_log !== '' && $php_log !== 'syslog') {
            $paths['PHP error log (' . $php_log . ')'] = $this->file_writable($php_log);
        }
        return $paths;
    }

    private function file_writable(string $path): bool
    {
        return file_exists($path)
            ? is_file($path) && is_writable($path)
            : ($this->directory_writable)(dirname($path));
    }

    /** @return array<string, array{status: bool, message: string, suggestion: ?string}> */
    private function configuration_checks(array $configuration): array
    {
        $errors = [];
        foreach ($this->validator->validate_web($configuration) as $error) {
            $name = strstr($error, ' ', true);
            $errors[$name] = $error;
        }
        $messages = [
            'DOMAIN' => 'Configured domain is valid.',
            'APP_KEY' => 'Application key is configured.',
            'APP_UUID' => 'Application UUID is valid.',
            'APP_ENCRYPTION_KEY' => 'Security encryption key is valid.',
        ];
        $checks = [];
        foreach ($messages as $name => $message) {
            $checks[$name] = [
                'status' => !isset($errors[$name]),
                'message' => $errors[$name] ?? $message,
                'suggestion' => isset($errors[$name])
                    ? 'Run php atomic init to create or repair the application configuration.'
                    : null,
            ];
        }
        return $checks;
    }

    private static function extension_available(string $extension): bool
    {
        if (!extension_loaded($extension)) {
            return false;
        }

        return match ($extension) {
            'redis' => class_exists(\Redis::class),
            'memcached' => class_exists(\Memcached::class),
            default => true,
        };
    }

    /** @return array{name: string, status: bool} */
    private static function configuration_source(): array
    {
        $loader = defined('ATOMIC_LOADER') ? strtolower((string)ATOMIC_LOADER) : 'env';
        $path = $loader === 'php'
            ? (defined('ATOMIC_CONFIG') ? ATOMIC_CONFIG . 'app.php' : '')
            : (defined('ATOMIC_ENV') ? (string)ATOMIC_ENV : '');

        return [
            'name' => $path !== '' ? $path : ($loader === 'php' ? 'config/app.php' : '.env'),
            'status' => $path !== '' && is_file($path) && is_readable($path),
        ];
    }

    private static function directory_writable(string $path): bool
    {
        return is_dir($path) && is_writable($path);
    }

    private static function target(array $config): string
    {
        return (string)$config['host'] . ':' . (int)$config['port'];
    }

    private static function mysql_target(array $config): string
    {
        $host = (string)$config['host'];
        $database = (string)$config['db'];
        $port = (int)$config['port'];

        return $host . ':' . $port . ($database !== '' ? '/' . $database : '');
    }
}
