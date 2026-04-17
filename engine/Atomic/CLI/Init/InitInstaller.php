<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI\Init;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\CLI\Style;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Migrations as CoreMigrations;

trait InitInstaller
{
    private string $initConfigMode = 'env';
    private string $initRoot       = '';
    private string $initEnvPath    = '';
    private function ask(string $label, ?string $default = null): string
    {
        if (!$this->input->is_interactive()) {
            return (string)($default ?? '');
        }

        $text = "  {$label}";
        if ($default !== null && $default !== '') {
            $text .= " [{$default}]";
        }
        $text .= ': ';

        $this->output->prompt($text);
        $value = $this->input->read_line();
        return $value === '' ? (string)($default ?? '') : $value;
    }

    private function confirm(string $label, bool $default = true): bool
    {
        if (!$this->input->is_interactive()) {
            return $default;
        }

        $this->output->prompt("  {$label} [" . ($default ? 'Y/n' : 'y/N') . ']: ');
        $value = strtolower($this->input->read_line());

        if ($value === '') {
            return $default;
        }

        return in_array($value, ['y', 'yes'], true);
    }

    private function report_init_issue(string $message, bool $warning = false): void
    {
        $label = $warning ? Style::warning_label() : Style::error_label();
        $this->output->err('  ' . $label . ' ' . $message);
    }

    private function ensure_env_file(string $root): string
    {
        $env_path = $root . DIRECTORY_SEPARATOR . '.env';
        if (file_exists($env_path)) {
            return $env_path;
        }

        $examplePath = $root . DIRECTORY_SEPARATOR . '.env.example';
        if (file_exists($examplePath)) {
            if (@copy($examplePath, $env_path)) {
                $this->output->writeln("        .env generated from .env.example");
                return $env_path;
            }
            $error = error_get_last()['message'] ?? 'unknown error';
            $this->report_init_issue("Could not copy .env.example to .env: {$error}");
            return '';
        }

        $this->report_init_issue("No .env found at {$env_path} and no .env.example to generate from.");
        return '';
    }

    private function set_env_value(string $env_path, string $key, string $value): void
    {
        if ($env_path === '' || !file_exists($env_path)) {
            $this->report_init_issue("Cannot update {$key}; .env file is missing.");
            return;
        }

        $contents = (string)@file_get_contents($env_path);
        $line     = $key . '=' . $value;
        $pattern  = '/^' . preg_quote($key, '/') . '=.*$/m';

        if (preg_match($pattern, $contents) === 1) {
            $contents = (string)preg_replace($pattern, $line, $contents, 1);
        } else {
            $contents = rtrim($contents) . PHP_EOL . $line . PHP_EOL;
        }

        if (@file_put_contents($env_path, $contents) === false) {
            $error = error_get_last()['message'] ?? 'unknown error';
            $this->report_init_issue("Could not write {$key} to {$env_path}: {$error}");
        }
    }

    private function read_env_value(string $env_path, string $key, string $default = ''): string
    {
        if ($env_path === '' || !file_exists($env_path)) {
            $this->report_init_issue("Cannot read {$key}; .env file is missing.");
            return $default;
        }

        $contents = (string)file_get_contents($env_path);
        if (preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $contents, $matches) !== 1) {
            return $default;
        }

        return trim($matches[1]);
    }

    private function configure_basic_env(string $env_path): void
    {
        $appName = $this->read_config_value('APP_NAME', 'Atomic');
        $this->set_config_value('APP_NAME', $appName);
        $this->set_config_value('MAIL_FROM_NAME', $this->read_config_value('MAIL_FROM_NAME', $appName));
    }

    private function choose_config_source(): string
    {
        if (!$this->input->is_interactive()) {
            return 'env';
        }

        while (true) {
            $this->output->prompt("  Configuration source [env/php] [env]: ");
            $value = strtolower($this->input->read_line());

            if ($value === '' || $value === 'env') {
                return 'env';
            }

            if ($value === 'php') {
                return 'php';
            }

            $this->output->err('  ' . Style::warning_label() . " Please enter 'env' or 'php'.");
        }
    }

    private function initialize_config_source(string $root, string $mode): void
    {
        $this->initRoot       = $root;
        $this->initConfigMode = $mode === 'php' ? 'php' : 'env';
        $this->initEnvPath    = '';

        $this->persist_config_loader_selection($root, $this->initConfigMode);

        if ($this->initConfigMode === 'env') {
            $this->initEnvPath = $this->ensure_env_file($root);
        }
    }

    private function persist_config_loader_selection(string $root, string $mode): void
    {
        $target = null;
        $candidates = [
            $root . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'const.php',
            $root . DIRECTORY_SEPARATOR . 'const.php',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate) && is_writable($candidate)) {
                $target = $candidate;
                break;
            }
        }

        if ($target === null) {
            $this->report_init_issue('Could not persist loader mode; no writable const.php found in bootstrap/const.php or const.php.', true);
            return;
        }

        $contents = (string)file_get_contents($target);
        $updated = (string)preg_replace(
            "/define\\(\\s*'ATOMIC_LOADER'\\s*,\\s*'[^']*'\\s*\\);/",
            "define('ATOMIC_LOADER', '{$mode}');",
            $contents,
            1,
            $count
        );

        if ($count === 0) {
            $this->report_init_issue("{$target} does not define ATOMIC_LOADER; skipping loader mode persistence.", true);
            return;
        }

        if (@file_put_contents($target, $updated) === false) {
            $error = error_get_last()['message'] ?? 'unknown error';
            $this->report_init_issue("Could not write loader mode to {$target}: {$error}", true);
            return;
        }

        $this->output->writeln("        Loader mode set to {$mode} in " . basename(dirname($target)) . '/'. basename($target));
    }

    private function config_mode(): string
    {
        return $this->initConfigMode;
    }

    private function read_config_value(string $key, string $default = ''): string
    {
        if ($this->initConfigMode === 'env') {
            return $this->read_env_value($this->initEnvPath, $key, $default);
        }

        return $this->read_php_config_value($key, $default);
    }

    private function set_config_value(string $key, string $value): void
    {
        if ($this->initConfigMode === 'env') {
            $this->set_env_value($this->initEnvPath, $key, $value);
            return;
        }

        $this->set_php_config_value($key, $value);
    }

    private function read_php_config_file(string $name): array
    {
        $path = $this->initRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $name . '.php';
        if (!file_exists($path)) {
            return [];
        }

        $data = require $path;
        return is_array($data) ? $data : [];
    }

    private function write_php_config_file(string $name, array $config): void
    {
        $path = $this->initRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $name . '.php';
        
        if (!file_exists($path)) {
            $this->report_init_issue("Config file {$path} does not exist; cannot update.", true);
            return;
        }
        
        $content = (string)file_get_contents($path);
        
        $exportedConfig = var_export($config, true);
        
        $pattern = '/(return\s+)(\[[\s\S]*?\];)/';
        $replacement = '$1' . $exportedConfig . ';';
        
        $updated = preg_replace($pattern, $replacement, $content, 1, $count);
        
        if ($count === 0 || $updated === null) {
            $updated = "<?php\ndeclare(strict_types=1);\nif (!defined('ATOMIC_START')) exit;\n\nreturn " . $exportedConfig . ";\n";
        }
        
        if (@file_put_contents($path, $updated) === false) {
            $error = error_get_last()['message'] ?? 'unknown error';
            $this->report_init_issue("Could not write config file {$path}: {$error}");
        }
    }

    private function set_array_path_value(array &$array, array $path, mixed $value): void
    {
        $ref = &$array;
        foreach ($path as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
    }

    private function get_array_path_value(array $array, array $path, mixed $default = null): mixed
    {
        $ref = $array;
        foreach ($path as $segment) {
            if (!is_array($ref) || !array_key_exists($segment, $ref)) {
                return $default;
            }
            $ref = $ref[$segment];
        }
        return $ref;
    }

    private function php_config_key_map(string $key): ?array
    {
        return match ($key) {
            'APP_UUID'            => ['app',      ['uuid']],
            'APP_NAME'            => ['app',      ['name']],
            'APP_KEY'             => ['app',      ['key']],
            'APP_ENCRYPTION_KEY'  => ['app',      ['encryption_key']],
            'MAIL_FROM_NAME'      => ['app',      ['name']],
            'DB_DRIVER'           => ['database', ['connections', 'mysql', 'driver']],
            'DB_HOST'             => ['database', ['connections', 'mysql', 'host']],
            'DB_PORT'             => ['database', ['connections', 'mysql', 'port']],
            'DB_DATABASE'         => ['database', ['connections', 'mysql', 'database']],
            'DB_USERNAME'         => ['database', ['connections', 'mysql', 'username']],
            'DB_PASSWORD'         => ['database', ['connections', 'mysql', 'password']],
            'SESSION_DRIVER'      => ['session',  ['driver']],
            'MUTEX_DRIVER'        => ['database', ['mutex', 'driver']],
            'QUEUE_DRIVER'        => ['queue',    ['driver']],
            default               => null,
        };
    }

    private function read_php_config_value(string $key, string $default = ''): string
    {
        $map = $this->php_config_key_map($key);
        if ($map === null) {
            return $default;
        }

        [$file, $path] = $map;
        $configPath = $this->initRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $file . '.php';
        if (!file_exists($configPath)) {
            $this->report_init_issue("Missing config file {$configPath}; cannot read {$key}.");
            return $default;
        }

        $config = $this->read_php_config_file($file);
        $value  = $this->get_array_path_value($config, $path, $default);
        return is_scalar($value) ? (string)$value : $default;
    }

    private function set_php_config_value(string $key, string $value): void
    {
        $map = $this->php_config_key_map($key);
        if ($map === null) {
            return;
        }

        [$file, $path] = $map;
        $configPath = $this->initRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $file . '.php';
        if (!file_exists($configPath)) {
            $this->report_init_issue("Missing config file {$configPath}; skipping {$key}.", true);
            return;
        }

        $config = $this->read_php_config_file($file);
        $this->set_array_path_value($config, $path, $value);
        $this->write_php_config_file($file, $config);
    }

    private function choose_main_driver(): string
    {
        if (!$this->input->is_interactive()) {
            return 'database';
        }

        while (true) {
            $this->output->prompt("  Main backend driver [database/redis] [database]: ");
            $value = strtolower($this->input->read_line());

            if ($value === '' || $value === 'database') {
                return 'database';
            }

            if ($value === 'redis') {
                if (!extension_loaded('redis')) {
                    $this->output->err('  ' . Style::error_label() . ' Redis backend requires the PHP redis extension (ext-redis).');
                    $this->output->err("  Install/enable ext-redis and try again, or choose 'database'.");
                    continue;
                }

                return 'redis';
            }

            $this->output->err('  ' . Style::warning_label() . " Please enter 'database' or 'redis'.");
        }
    }

    private function configure_database(string $env_path): ?array
    {
        if (!$this->input->is_interactive()) {
            return null;
        }

        while (true) {
            $config = [
                'driver'   => 'mysql',
                'host'     => $this->ask('DB host',     $this->read_config_value('DB_HOST',     '127.0.0.1')),
                'port'     => $this->ask('DB port',     $this->read_config_value('DB_PORT',     '3306')),
                'database' => $this->ask('DB name',     $this->read_config_value('DB_DATABASE', 'atomic')),
                'username' => $this->ask('DB user',     $this->read_config_value('DB_USERNAME', 'root')),
                'password' => $this->input->read_secret('DB password', $this->read_config_value('DB_PASSWORD', '')),
            ];

            $error = $this->test_database_connection($config);
            if ($error === null) {
                $this->set_config_value('DB_DRIVER',   $config['driver']);
                $this->set_config_value('DB_HOST',     $config['host']);
                $this->set_config_value('DB_PORT',     $config['port']);
                $this->set_config_value('DB_DATABASE', $config['database']);
                $this->set_config_value('DB_USERNAME', $config['username']);
                $this->set_config_value('DB_PASSWORD', $config['password']);
                $this->output->writeln('  ' . Style::success_label() . " Database is ready.");
                $this->output->writeln();
                return $config;
            }

            $this->output->err('  ' . Style::error_label() . " Could not connect.");
            $this->output->err("  {$error}");
            $this->output->err("  Please retry and check your credentials.");

            if (!$this->confirm('Try again?', true)) {
                $this->output->writeln();
                return null;
            }
        }
    }

    private function test_database_connection(array $config): ?string
    {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $config['host'],
                $config['port'],
                $config['database']
            );

            new \PDO($dsn, $config['username'], $config['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 3,
            ]);

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    private function boot_database(array $config): SQL
    {
        $atomic   = App::instance();
        $dbConfig = [
            'driver'                  => $config['driver'],
            'host'                    => $config['host'],
            'port'                    => $config['port'],
            'database'                => $config['database'],
            'username'                => $config['username'],
            'password'                => $config['password'],
            'unix_socket'             => '',
            'charset'                 => 'utf8mb4',
            'collation'               => 'utf8mb4_general_ci',
            'ATOMIC_DB_PREFIX'        => 'atomic_',
            'ATOMIC_DB_QUEUE_PREFIX'  => 'atomic_queue_',
        ];

        $atomic->set('DB_CONFIG', $dbConfig);

        return ConnectionManager::instance()->get_db();
    }

    private function initialize_migration_database(): bool
    {
        $migrations = new CoreMigrations($this->output);
        if (!$migrations->db()) {
            $this->output->err('  ' . Style::error_label() . " Could not initialize migration database.");
            return false;
        }

        $this->output->writeln('  ' . Style::success_label() . " Migration database initialized.");
        return true;
    }

    private function setup_optional_database_systems(string $root): void
    {
        $this->run_user_setup_branch($root);
    }

    private function setup_database_backends_migrations(): void
    {
        $options = [
            ['label' => 'session', 'method' => 'db_sessions'],
            ['label' => 'mutex',   'method' => 'db_mutex'],
            ['label' => 'queue',   'method' => 'queue_db'],
        ];

        $queued = 0;
        foreach ($options as $option) {
            if (!$this->confirm('Run ' . $option['label'] . ' migration?', false)) {
                continue;
            }

            $method = $option['method'];
            if (!method_exists($this, $method)) {
                $this->output->err('  ' . Style::warning_label() . " Missing CLI method '{$method}', skipping.");
                continue;
            }

            $this->{$method}();
            $queued++;
        }

        if ($queued > 0) {
            $this->output->writeln();
            $migrations = new CoreMigrations($this->output);
            $migrations->migrate();
            $this->output->writeln('  ' . Style::success_label() . " {$queued} backend migration(s) applied.");
        }
    }

    private function detect_config_mode(string $root): ?string
    {
        $candidates = [
            $root . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'const.php',
            $root . DIRECTORY_SEPARATOR . 'const.php',
        ];

        foreach ($candidates as $candidate) {
            if (!is_file($candidate) || !is_readable($candidate)) {
                continue;
            }

            $contents = (string)file_get_contents($candidate);
            if (preg_match("/define\\(\\s*'ATOMIC_LOADER'\\s*,\\s*'([^']*)'\\s*\\);/", $contents, $matches)) {
                return $matches[1] === 'php' ? 'php' : 'env';
            }
        }

        // Fallback: check if .env exists
        $env_path = $root . DIRECTORY_SEPARATOR . '.env';
        if (file_exists($env_path)) {
            return 'env';
        }

        // Check if config/app.php exists
        $appConfigPath = $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
        if (file_exists($appConfigPath)) {
            return 'php';
        }

        return null;
    }
}
