<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Reporters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Telemetry\Data\EnvGroup;
use Engine\Atomic\Telemetry\Data\EnvItem;
use Engine\Atomic\Telemetry\Data\Environment;
use Engine\Atomic\Core\Redactor;

class EnvironmentReporter
{
    private const DAY = 86400;
    private const HOUR = 3600;

    public function __construct(private readonly \Base $atomic) {}

    public function environment(): Environment
    {
        $groups = [
            'php' => self::group('php', [
                'version' => phpversion(),
                'sapi' => PHP_SAPI,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'opcache_enabled' => (bool)ini_get('opcache.enable'),
                'extensions' => get_loaded_extensions(),
            ]),
            'f3' => self::group('f3', ['version' => $this->f3_version()]),
            'atomic' => self::group('atomic', [
                'env' => (string)$this->atomic->get('APP_ENV'),
                'debug_mode' => (bool)filter_var($this->atomic->get('DEBUG_MODE'), FILTER_VALIDATE_BOOLEAN),
                'debug_level' => (string)$this->atomic->get('DEBUG_LEVEL'),
                'logs_dir' => Redactor::redact_secrets((string)$this->atomic->get('LOGS')),
                'dumps_dir' => Redactor::redact_secrets((string)$this->atomic->get('DUMPS')),
                'base' => (string)$this->atomic->get('BASE'),
            ]),
            'system' => self::group('system', [
                'os' => PHP_OS_FAMILY . ' ' . PHP_OS,
                'hostname' => gethostname() ?: null,
                'timezone' => date_default_timezone_get(),
                'uptime' => self::server_uptime(),
            ]),
        ];
        $db = $this->database();
        if ($db !== null) {
            $groups['db'] = $db;
        }

        return new Environment($groups);
    }

    /** @param array<string, mixed> $values */
    private static function group(string $key, array $values): EnvGroup
    {
        $items = [];
        foreach ($values as $name => $value) {
            $items[] = is_array($value)
                ? new EnvItem($name, null, array_values(array_map('strval', $value)))
                : new EnvItem($name, is_scalar($value) || $value === null ? $value : null);
        }

        return new EnvGroup($key, $items);
    }

    private function f3_version(): ?string
    {
        try {
            $base = \Base::instance();

            return $base->exists('VERSION') ? (string)$base->get('VERSION') : (defined('\Base::VERSION') ? \Base::VERSION : null);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function database(): ?EnvGroup
    {
        try {
            $conn = ConnectionManager::instance()->get_db(false);
            if (!$conn instanceof \DB\SQL) {
                return null;
            }
            $pdo = $conn->pdo();

            return self::group('db', [
                'driver' => Redactor::redact_secrets((string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)),
                'server_version' => Redactor::redact_secrets((string)$pdo->getAttribute(\PDO::ATTR_SERVER_VERSION)),
                'client_version' => Redactor::redact_secrets((string)$pdo->getAttribute(\PDO::ATTR_CLIENT_VERSION)),
            ]);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function server_uptime(): ?string
    {
        if (is_readable('/proc/uptime')) {
            $raw = @file_get_contents('/proc/uptime');
            if (is_string($raw) && preg_match('/^(\d+)/', $raw, $m)) {
                $seconds = (int)$m[1];
                $days = intdiv($seconds, self::DAY);
                $hours = intdiv($seconds % self::DAY, self::HOUR);

                return $days > 0 ? "{$days}d {$hours}h" : "{$hours}h";
            }
        }

        return null;
    }
}
