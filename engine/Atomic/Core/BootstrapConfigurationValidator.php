<?php

declare(strict_types=1);

namespace Engine\Atomic\Core;

if (!defined('ATOMIC_START')) {
    exit;
}

final class BootstrapConfigurationValidator
{
    private const REPAIR_COMMANDS = [
        '',
        '/init',
        '/init/key',
        '/help',
        '/version',
    ];

    /** @return list<string> */
    public function validate_web(array $configuration): array
    {
        $errors = [];
        $domain = trim((string)($configuration['DOMAIN'] ?? ''));

        if ($domain === '') {
            $errors[] = 'DOMAIN is not configured.';
        } elseif (!$this->is_valid_domain($domain)) {
            $errors[] = 'DOMAIN is invalid. Use a host with an optional port, or an HTTP(S) origin.';
        }

        return array_merge($errors, $this->validate_application_keys($configuration));
    }

    /** @return list<string> */
    public function validate_cli(array $configuration, string $command): array
    {
        if (in_array($this->normalize_command($command), self::REPAIR_COMMANDS, true)) {
            return [];
        }

        return $this->validate_application_keys($configuration);
    }

    /** @param list<string> $argv */
    public function command_from_argv(array $argv): string
    {
        if (!isset($argv[1])) {
            return '';
        }

        return $this->normalize_command((string)$argv[1]);
    }

    /** @return array{DOMAIN: string, APP_KEY: string, APP_UUID: string, APP_ENCRYPTION_KEY: string} */
    public function configuration_from_base(\Base $atomic): array
    {
        return [
            'DOMAIN' => (string)($atomic->get('DOMAIN') ?? ''),
            'APP_KEY' => (string)($atomic->get('APP_KEY') ?? ''),
            'APP_UUID' => (string)($atomic->get('APP_UUID') ?? ''),
            'APP_ENCRYPTION_KEY' => (string)($atomic->get('APP_ENCRYPTION_KEY') ?? ''),
        ];
    }

    public function is_valid_domain(string $domain): bool
    {
        $domain = trim($domain);
        if ($domain === '' || preg_match('/\s/', $domain) === 1) {
            return false;
        }

        $has_scheme = str_contains($domain, '://');
        $candidate = $has_scheme ? $domain : 'http://' . $domain;
        $parts = @parse_url($candidate);

        if (!is_array($parts)) {
            return false;
        }

        if ($has_scheme && !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        $path = (string)($parts['path'] ?? '');
        if ($path !== '' && $path !== '/') {
            return false;
        }

        $host = trim((string)($parts['host'] ?? ''), '[]');
        if (!$this->is_valid_host($host)) {
            return false;
        }

        $port = $parts['port'] ?? null;
        return $port === null || ($port >= 1 && $port <= 65535);
    }

    /** @return list<string> */
    private function validate_application_keys(array $configuration): array
    {
        $errors = [];
        $app_key = trim((string)($configuration['APP_KEY'] ?? ''));
        $app_uuid = trim((string)($configuration['APP_UUID'] ?? ''));
        $encryption_key = trim((string)($configuration['APP_ENCRYPTION_KEY'] ?? ''));

        if ($app_key === '') {
            $errors[] = 'APP_KEY is not configured.';
        } elseif (in_array(strtolower($app_key), ['default-key', 'your-key-here', 'change-me', 'changeme'], true)) {
            $errors[] = 'APP_KEY contains a placeholder value.';
        }

        if ($app_uuid === '') {
            $errors[] = 'APP_UUID is not configured.';
        } elseif (!ID::is_valid_uuid_v4($app_uuid)) {
            $errors[] = 'APP_UUID must be a valid UUID.';
        }

        if ($encryption_key === '') {
            $errors[] = 'APP_ENCRYPTION_KEY is not configured.';
        } else {
            $decoded = base64_decode($encryption_key, true);
            if ($decoded === false || strlen($decoded) !== 32) {
                $errors[] = 'APP_ENCRYPTION_KEY must be valid base64 encoding exactly 32 bytes.';
            }
        }

        return $errors;
    }

    private function normalize_command(string $command): string
    {
        $command = strtolower(trim(str_replace(':', '/', $command)));
        return $command === '' ? '' : '/' . ltrim($command, '/');
    }

    private function is_valid_host(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }

        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }

        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63 || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i', $label) !== 1) {
                return false;
            }
        }

        return true;
    }
}
