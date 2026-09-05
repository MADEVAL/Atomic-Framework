<?php

declare(strict_types=1);

namespace Engine\Atomic\Core\Health;

if (!defined('ATOMIC_START')) {
    exit;
}

final class HealthCheck
{
    /**
     * @return array{
     *     healthy: bool,
     *     required: list<array{name: string, status: string, message: string, suggestion: ?string}>,
     *     recommended: list<array{name: string, status: string, message: string, suggestion: ?string}>,
     *     optional: list<array{name: string, status: string, message: string, suggestion: ?string}>
     * }
     */
    public static function inspect(\Base $atomic): array
    {
        return self::evaluate_snapshot((new HealthCheckInspector($atomic))->snapshot());
    }

    /**
     * @return array{
     *     healthy: bool,
     *     required: list<array{name: string, status: string, message: string, suggestion: ?string}>,
     *     recommended: list<array{name: string, status: string, message: string, suggestion: ?string}>,
     *     optional: list<array{name: string, status: string, message: string, suggestion: ?string}>
     * }
     */
    public static function evaluate_snapshot(array $snapshot): array
    {
        $required = [];
        $recommended = [];
        $optional = [];
        $php = (array)($snapshot['php'] ?? []);
        $php_ok = (bool)($php['status'] ?? false);
        $required[] = self::check(
            'PHP',
            $php_ok ? 'ok' : 'fail',
            sprintf(
                '%s installed; %s or newer required.',
                (string)($php['current'] ?? 'unknown'),
                (string)($php['required'] ?? 'unknown'),
            ),
            $php_ok ? null : sprintf('Upgrade PHP to %s or newer.', (string)($php['required'] ?? 'the required version')),
        );

        foreach ((array)($snapshot['required_extensions'] ?? []) as $extension => $loaded) {
            $required[] = self::check(
                (string)$extension,
                $loaded ? 'ok' : 'fail',
                $loaded ? 'Required PHP extension is loaded.' : 'Required PHP extension is not loaded.',
                $loaded ? null : 'Install or enable the ' . $extension . ' PHP extension.',
            );
        }

        $source = (array)($snapshot['config_source'] ?? []);
        $source_ok = (bool)($source['status'] ?? false);
        $source_name = (string)($source['name'] ?? 'configuration');
        $required[] = self::check(
            'Configuration source',
            $source_ok ? 'ok' : 'fail',
            $source_ok ? $source_name . ' is readable.' : $source_name . ' is missing or unreadable.',
            $source_ok ? null : 'Create the configuration source and ensure it is readable by the application user.',
        );

        $configuration_checks = (array)($snapshot['configuration_checks'] ?? []);
        if ($configuration_checks !== []) {
            foreach ($configuration_checks as $name => $configuration_check) {
                $configuration_check = (array)$configuration_check;
                $required[] = self::check(
                    (string)$name,
                    (bool)($configuration_check['status'] ?? false) ? 'ok' : 'fail',
                    (string)($configuration_check['message'] ?? 'Configuration setting is invalid.'),
                    isset($configuration_check['suggestion']) ? (string)$configuration_check['suggestion'] : null,
                );
            }
        } else {
            $configuration_errors = (array)($snapshot['configuration_errors'] ?? []);
            if ($configuration_errors === []) {
                $required[] = self::check('Application configuration', 'ok', 'Required settings are valid.');
            } else {
                foreach ($configuration_errors as $error) {
                    $message = (string)$error;
                    preg_match('/^([A-Z][A-Z0-9_]*)\b/', $message, $matches);
                    $required[] = self::check(
                        $matches[1] ?? 'Application configuration',
                        'fail',
                        $message,
                        'Run php atomic init to create or repair the application configuration.',
                    );
                }
            }
        }

        $mysql = (array)($snapshot['mysql'] ?? []);
        $mysql_reachable = (bool)($mysql['reachable'] ?? false);
        $mysql_target = (string)($mysql['target'] ?? '127.0.0.1:3306');
        $required[] = self::check(
            'MySQL',
            $mysql_reachable ? 'ok' : 'fail',
            $mysql_reachable
                ? 'Credentials accepted and SELECT 1 succeeded at ' . $mysql_target . '.'
                : 'Connection or credentials failed at ' . $mysql_target . '.',
            $mysql_reachable
                ? null
                : 'Check the MySQL host, port, database, username, password, and service availability.',
        );

        foreach ((array)($snapshot['runtime_paths'] ?? []) as $path => $writable) {
            $required[] = self::check(
                (string)$path,
                $writable ? 'ok' : 'fail',
                $writable ? 'Runtime path is writable.' : 'Runtime path is missing or not writable.',
                $writable ? null : 'Create the directory and grant write access to the application user.',
            );
        }

        foreach ((array)($snapshot['log_files'] ?? []) as $path => $writable) {
            $required[] = self::check(
                (string)$path,
                $writable ? 'ok' : 'fail',
                $writable ? 'Log file is writable or can be created.' : 'Log file cannot be written.',
                $writable ? null : 'Check the log filename, parent directory, and file permissions for the application user.',
            );
        }

        $extensions = (array)($snapshot['extensions'] ?? []);
        $drivers = array_map(
            static fn(mixed $driver): string => strtolower(trim((string)$driver)),
            (array)($snapshot['drivers'] ?? []),
        );
        $redis_selected = in_array('redis', $drivers, true);
        $redis_extension = (bool)($extensions['redis'] ?? false);
        $redis = (array)($snapshot['redis'] ?? []);
        $redis_reachable = (bool)($redis['reachable'] ?? false);
        $redis_target = (string)($redis['target'] ?? '127.0.0.1:6379');

        if ($redis_extension && $redis_reachable) {
            $recommended[] = self::check('Redis', 'ok', 'Available at ' . $redis_target . '. Highly recommended.');
        } elseif ($redis_selected) {
            $reason = $redis_extension ? 'service is not reachable' : 'PHP extension is not loaded';
            $recommended[] = self::check(
                'Redis',
                'fail',
                'Selected by an active driver, but the ' . $reason . '. Target: ' . $redis_target . '.',
                $redis_extension
                    ? 'Check the Redis host, port, credentials, selected database, and service availability.'
                    : 'Install or enable the redis PHP extension, or select a non-Redis driver.',
            );
        } else {
            $reason = $redis_extension ? 'service is not reachable' : 'PHP extension is not loaded';
            $recommended[] = self::check(
                'Redis',
                'warn',
                'Highly recommended; currently unused and the ' . $reason . '. Target: ' . $redis_target . '.',
                $redis_extension
                    ? 'Check the Redis service if you intend to use it.'
                    : 'Install or enable the redis PHP extension to use Redis-backed features.',
            );
        }

        $sodium_loaded = (bool)($extensions['sodium'] ?? false);
        $optional[] = self::check(
            'Sodium',
            $sodium_loaded ? 'ok' : 'warn',
            $sodium_loaded
                ? 'Available for optional Crypto encryption.'
                : 'Not loaded; only required when Crypto is used.',
            $sodium_loaded ? null : 'Install or enable the sodium PHP extension before using Crypto.',
        );

        $memcached_selected = in_array('memcached', $drivers, true);
        $memcached_extension = (bool)($extensions['memcached'] ?? false);
        $memcached = (array)($snapshot['memcached'] ?? []);
        $memcached_reachable = (bool)($memcached['reachable'] ?? false);
        $memcached_target = (string)($memcached['target'] ?? '127.0.0.1:11211');

        if (!$memcached_selected) {
            $optional[] = self::check('Memcached', 'skip', 'Not selected by an active driver.');
        } elseif ($memcached_extension && $memcached_reachable) {
            $optional[] = self::check('Memcached', 'ok', 'Available at ' . $memcached_target . '.');
        } else {
            $reason = $memcached_extension ? 'service is not reachable' : 'PHP extension is not loaded';
            $optional[] = self::check(
                'Memcached',
                'fail',
                'Selected by an active driver, but the ' . $reason . '. Target: ' . $memcached_target . '.',
                $memcached_extension
                    ? 'Check the Memcached host, port, credentials, and service availability.'
                    : 'Install or enable the memcached PHP extension, or select a non-Memcached driver.',
            );
        }

        $checks = array_merge($required, $recommended, $optional);
        $healthy = !in_array('fail', array_column($checks, 'status'), true);

        return [
            'healthy' => $healthy,
            'required' => $required,
            'recommended' => $recommended,
            'optional' => $optional,
        ];
    }

    /** @return array{name: string, status: string, message: string, suggestion: ?string} */
    private static function check(string $name, string $status, string $message, ?string $suggestion = null): array
    {
        return [
            'name' => $name,
            'status' => $status,
            'message' => $message,
            'suggestion' => $suggestion,
        ];
    }
}
