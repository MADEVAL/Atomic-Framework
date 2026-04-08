<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI\Init;

if (!defined('ATOMIC_START')) exit;

use DB\SQL;
use Engine\Atomic\CLI\Style;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Migrations as CoreMigrations;

trait InitInstaller
{
    private string $initConfigMode = 'env';
    private string $initRoot       = '';
    private string $initEnvPath    = '';
    private function ask(string $label, ?string $default = null): string
    {
        if (!$this->input->isInteractive()) {
            return (string)($default ?? '');
        }

        $text = "  {$label}";
        if ($default !== null && $default !== '') {
            $text .= " [{$default}]";
        }
        $text .= ': ';

        $this->output->prompt($text);
        $value = $this->input->readLine();
        return $value === '' ? (string)($default ?? '') : $value;
    }

    private function confirm(string $label, bool $default = true): bool
    {
        if (!$this->input->isInteractive()) {
            return $default;
        }

        $this->output->prompt("  {$label} [" . ($default ? 'Y/n' : 'y/N') . ']: ');
        $value = strtolower($this->input->readLine());

        if ($value === '') {
            return $default;
        }

        return in_array($value, ['y', 'yes'], true);
    }

    private function reportInitIssue(string $message, bool $warning = false): void
    {
        $label = $warning ? Style::warningLabel() : Style::errorLabel();
        $this->output->err('  ' . $label . ' ' . $message);
    }

    private function ensureEnvFile(string $root): string
    {
        $envPath = $root . DIRECTORY_SEPARATOR . '.env';
        if (file_exists($envPath)) {
            return $envPath;
        }

        $examplePath = $root . DIRECTORY_SEPARATOR . '.env.example';
        if (file_exists($examplePath)) {
            if (@copy($examplePath, $envPath)) {
                $this->output->writeln("        .env generated from .env.example");
                return $envPath;
            }
            $error = error_get_last()['message'] ?? 'unknown error';
            $this->reportInitIssue("Could not copy .env.example to .env: {$error}");
            return '';
        }

        $this->reportInitIssue("No .env found at {$envPath} and no .env.example to generate from.");
        return '';
    }

    private function setEnvValue(string $envPath, string $key, string $value): void
    {
        if ($envPath === '' || !file_exists($envPath)) {
            $this->reportInitIssue("Cannot update {$key}; .env file is missing.");
            return;
        }

        $contents = (string)@file_get_contents($envPath);
        $line     = $key . '=' . $value;
        $pattern  = '/^' . preg_quote($key, '/') . '=.*$/m';

        if (preg_match($pattern, $contents) === 1) {
            $contents = (string)preg_replace($pattern, $line, $contents, 1);
        } else {
            $contents = rtrim($contents) . PHP_EOL . $line . PHP_EOL;
        }

        if (@file_put_contents($envPath, $contents) === false) {
            $error = error_get_last()['message'] ?? 'unknown error';
            $this->reportInitIssue("Could not write {$key} to {$envPath}: {$error}");
        }
    }

    private function readEnvValue(string $envPath, string $key, string $default = ''): string
    {
        if ($envPath === '' || !file_exists($envPath)) {
            $this->reportInitIssue("Cannot read {$key}; .env file is missing.");
            return $default;
        }

        $contents = (string)file_get_contents($envPath);
        if (preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $contents, $matches) !== 1) {
            return $default;
        }

        return trim($matches[1]);
    }

    private function configureBasicEnv(string $envPath): void
    {
        $appName = $this->readConfigValue('APP_NAME', 'Atomic');
        $this->setConfigValue('APP_NAME', $appName);
        $this->setConfigValue('MAIL_FROM_NAME', $this->readConfigValue('MAIL_FROM_NAME', $appName));
    }

    private function chooseConfigSource(): string
    {
        if (!$this->input->isInteractive()) {
            return 'env';
        }

        while (true) {
            $this->output->prompt("  Configuration source [env/php] [env]: ");
            $value = strtolower($this->input->readLine());

            if ($value === '' || $value === 'env') {
                return 'env';
            }

            if ($value === 'php') {
                return 'php';
            }

            $this->output->err('  ' . Style::warningLabel() . " Please enter 'env' or 'php'.");
        }
    }

    private function initializeConfigSource(string $root, string $mode): void
    {
        $this->initRoot       = $root;
        $this->initConfigMode = $mode === 'php' ? 'php' : 'env';
        $this->initEnvPath    = '';

        if ($this->initConfigMode === 'env') {
            $this->initEnvPath = $this->ensureEnvFile($root);
        }
    }

    private function configMode(): string
    {
        return $this->initConfigMode;
    }

    private function readConfigValue(string $key, string $default = ''): string
    {
        if ($this->initConfigMode === 'env') {
            return $this->readEnvValue($this->initEnvPath, $key, $default);
        }

        return $this->readPhpConfigValue($key, $default);
    }

    private function setConfigValue(string $key, string $value): void
    {
        if ($this->initConfigMode === 'env') {
            $this->setEnvValue($this->initEnvPath, $key, $value);
            return;
        }

        $this->setPhpConfigValue($key, $value);
    }

    private function readPhpConfigFile(string $name): array
    {
        $path = $this->initRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $name . '.php';
        if (!file_exists($path)) {
            return [];
        }

        $data = require $path;
        return is_array($data) ? $data : [];
    }

    private function writePhpConfigFile(string $name, array $config): void
    {
        $path    = $this->initRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $name . '.php';
        $content = "<?php\n" . 'declare(strict_types=1);' . "\n\nreturn " . var_export($config, true) . ";\n";
        if (@file_put_contents($path, $content) === false) {
            $error = error_get_last()['message'] ?? 'unknown error';
            $this->reportInitIssue("Could not write config file {$path}: {$error}");
        }
    }

    private function setArrayPathValue(array &$array, array $path, mixed $value): void
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

    private function getArrayPathValue(array $array, array $path, mixed $default = null): mixed
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

    private function phpConfigKeyMap(string $key): ?array
    {
        return match ($key) {
            'APP_UUID'       => ['app',      ['uuid']],
            'APP_NAME'       => ['app',      ['name']],
            'APP_KEY'        => ['app',      ['key']],
            'MAIL_FROM_NAME' => ['app',      ['name']],
            'DB_DRIVER'      => ['database', ['connections', 'mysql', 'driver']],
            'DB_HOST'        => ['database', ['connections', 'mysql', 'host']],
            'DB_PORT'        => ['database', ['connections', 'mysql', 'port']],
            'DB_DATABASE'    => ['database', ['connections', 'mysql', 'database']],
            'DB_USERNAME'    => ['database', ['connections', 'mysql', 'username']],
            'DB_PASSWORD'    => ['database', ['connections', 'mysql', 'password']],
            'SESSION_DRIVER' => ['session',  ['driver']],
            'MUTEX_DRIVER'   => ['database', ['mutex', 'driver']],
            'QUEUE_DRIVER'   => ['queue',    ['driver']],
            default          => null,
        };
    }

    private function readPhpConfigValue(string $key, string $default = ''): string
    {
        $map = $this->phpConfigKeyMap($key);
        if ($map === null) {
            return $default;
        }

        [$file, $path] = $map;
        $configPath = $this->initRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $file . '.php';
        if (!file_exists($configPath)) {
            $this->reportInitIssue("Missing config file {$configPath}; cannot read {$key}.");
            return $default;
        }

        $config = $this->readPhpConfigFile($file);
        $value  = $this->getArrayPathValue($config, $path, $default);
        return is_scalar($value) ? (string)$value : $default;
    }

    private function setPhpConfigValue(string $key, string $value): void
    {
        $map = $this->phpConfigKeyMap($key);
        if ($map === null) {
            return;
        }

        [$file, $path] = $map;
        $configPath = $this->initRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $file . '.php';
        if (!file_exists($configPath)) {
            $this->reportInitIssue("Missing config file {$configPath}; skipping {$key}.", true);
            return;
        }

        $config = $this->readPhpConfigFile($file);
        $this->setArrayPathValue($config, $path, $value);
        $this->writePhpConfigFile($file, $config);
    }

    private function chooseMainDriver(): string
    {
        if (!$this->input->isInteractive()) {
            return 'database';
        }

        while (true) {
            $this->output->prompt("  Main backend driver [database/redis] [database]: ");
            $value = strtolower($this->input->readLine());

            if ($value === '' || $value === 'database') {
                return 'database';
            }

            if ($value === 'redis') {
                return 'redis';
            }

            $this->output->err('  ' . Style::warningLabel() . " Please enter 'database' or 'redis'.");
        }
    }

    private function configureDatabase(string $envPath): ?array
    {
        if (!$this->input->isInteractive()) {
            return null;
        }

        while (true) {
            $config = [
                'driver'   => 'mysql',
                'host'     => $this->ask('DB host',     $this->readConfigValue('DB_HOST',     '127.0.0.1')),
                'port'     => $this->ask('DB port',     $this->readConfigValue('DB_PORT',     '3306')),
                'database' => $this->ask('DB name',     $this->readConfigValue('DB_DATABASE', 'atomic')),
                'username' => $this->ask('DB user',     $this->readConfigValue('DB_USERNAME', 'root')),
                'password' => $this->input->readSecret('DB password', $this->readConfigValue('DB_PASSWORD', '')),
            ];

            $error = $this->testDatabaseConnection($config);
            if ($error === null) {
                $this->setConfigValue('DB_DRIVER',   $config['driver']);
                $this->setConfigValue('DB_HOST',     $config['host']);
                $this->setConfigValue('DB_PORT',     $config['port']);
                $this->setConfigValue('DB_DATABASE', $config['database']);
                $this->setConfigValue('DB_USERNAME', $config['username']);
                $this->setConfigValue('DB_PASSWORD', $config['password']);
                $this->output->writeln('  ' . Style::successLabel() . " Database is ready.");
                $this->output->writeln();
                return $config;
            }

            $this->output->err('  ' . Style::errorLabel() . " Could not connect.");
            $this->output->err("  {$error}");
            $this->output->err("  Please retry and check your credentials.");

            if (!$this->confirm('Try again?', true)) {
                $this->output->writeln();
                return null;
            }
        }
    }

    private function testDatabaseConnection(array $config): ?string
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

    private function bootDatabase(array $config): SQL
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

        $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset={$dbConfig['charset']};port=" . (int)$dbConfig['port'];
        $db  = new SQL($dsn, $dbConfig['username'], $dbConfig['password']);

        $atomic->set('DB_CONFIG', $dbConfig);
        $atomic->set('DB', $db);

        return $db;
    }

    private function initializeMigrationDatabase(): bool
    {
        $migrations = new CoreMigrations();
        if (!$migrations->db()) {
            $this->output->err('  ' . Style::errorLabel() . " Could not initialize migration database.");
            return false;
        }

        $this->output->writeln('  ' . Style::successLabel() . " Migration database initialized.");
        return true;
    }

    private function setupOptionalDatabaseSystems(string $root): void
    {
        $this->runUserSetupBranch($root);
    }

    private function setupDatabaseBackendsMigrations(): void
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
                $this->output->err('  ' . Style::warningLabel() . " Missing CLI method '{$method}', skipping.");
                continue;
            }

            $this->{$method}();
            $queued++;
        }

        if ($queued > 0) {
            $this->output->writeln();
            $migrations = new CoreMigrations();
            $migrations->migrate();
            $this->output->writeln('  ' . Style::successLabel() . " {$queued} backend migration(s) applied.");
        }
    }
}
