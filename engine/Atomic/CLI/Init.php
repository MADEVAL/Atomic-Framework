<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\ID;
use Engine\Atomic\Core\Migrations as CoreMigrations;
use Engine\Atomic\CLI\Init\InitInstaller;
use Engine\Atomic\CLI\Init\InitScaffold;

trait Init
{
    use InitInstaller;
    use InitScaffold;

    /**
     * php atomic init
     * Set up the application.
     */
    public function init(): void
    {
        $this->output->writeln();
        $this->output->writeln("  " . Style::bold('Atomic Framework -- Project Initialization'));
        $this->output->writeln("  " . str_repeat('-', 48));
        $this->output->writeln();

        $root = ATOMIC_DIR;

        $this->output->writeln("  " . Style::yellow('[1/4]', true) . " Creating directories...");
        $created = $this->createSkeletonDirectories($root);
        $this->output->writeln("        {$created} new director" . ($created === 1 ? 'y' : 'ies') . " created");
        $this->output->writeln();

        $this->output->writeln("  " . Style::yellow('[2/4]', true) . " Preparing settings...");
        $configSource = $this->chooseConfigSource();
        $this->initializeConfigSource($root, $configSource);

        $this->setConfigValue('APP_UUID', $this->readConfigValue('APP_UUID', ID::uuid_v4()));
        $this->setConfigValue('APP_KEY',  $this->readConfigValue('APP_KEY',  bin2hex(random_bytes(16))));
        $this->configureBasicEnv('');

        if ($this->configMode() === 'env') {
            $envPath = ATOMIC_DIR . DIRECTORY_SEPARATOR . '.env';
            $this->setEnvValue($envPath, 'APP_ENCRYPTION_KEY', $this->readEnvValue($envPath, 'APP_ENCRYPTION_KEY', $this->generateEncryptionKey()));
            $this->output->writeln("        .env ready");
        } else {
            $this->output->writeln("        PHP config files ready");
        }
        $this->output->writeln();

        $this->output->writeln("  " . Style::yellow('[3/4]', true) . " Stub files...");
        $stubs = $this->createAppStubs($root);
        $this->output->writeln("        {$stubs} stub file" . ($stubs === 1 ? '' : 's') . " created");
        $this->output->writeln();

        $this->output->writeln("  " . Style::yellow('[4/4]', true) . " Database and backend setup...");
        $dbConfig = $this->configureDatabase('');
        if ($dbConfig !== null) {
            $this->bootDatabase($dbConfig);
            if ($this->initializeMigrationDatabase()) {
                (new CoreMigrations($this->output))->migrate();
                $this->output->writeln();

                $driver = $this->chooseMainDriver();
                $this->output->writeln();

                if ($driver === 'redis') {
                    $this->setConfigValue('SESSION_DRIVER', 'redis');
                    $this->setConfigValue('MUTEX_DRIVER',   'redis');
                    $this->setConfigValue('QUEUE_DRIVER',   'redis');
                    $this->output->writeln('  ' . Style::successLabel() . " Redis selected for queue/mutex/session backends.");
                } else {
                    $this->setConfigValue('SESSION_DRIVER', 'db');
                    $this->setConfigValue('MUTEX_DRIVER',   'database');
                    $this->setConfigValue('QUEUE_DRIVER',   'database');
                    $this->setupDatabaseBackendsMigrations();
                }
            }
        } else {
            $this->output->writeln("  Database setup skipped.");
        }

        $this->output->writeln();
        $this->output->writeln("  " . str_repeat('=', 48));
        $this->output->writeln("  " . Style::successLabel() . " Done.");
        $this->output->writeln();
        $this->output->writeln("  Next:");
        if ($this->configMode() === 'env') {
            $this->output->writeln("    1. Open .env if you want to change anything.");
        } else {
            $this->output->writeln("    1. Review config/*.php if you want to change anything.");
        }
        $this->output->writeln("    2. Point your server to public/.");
        $this->output->writeln("    3. Open the site.");
        $this->output->writeln();
    }

    /**
     * php atomic init/key
     * Regenerate APP_UUID, APP_KEY, APP_ENCRYPTION_KEY in existing .env
     */
    public function initKey(): void
    {
        $envPath = ATOMIC_DIR . DIRECTORY_SEPARATOR . '.env';

        if (!file_exists($envPath)) {
            $this->output->writeln(Style::errorLabel() . " No .env file found. Run 'php atomic init' first.");
            return;
        }

        $contents = (string)file_get_contents($envPath);
        $contents = (string)preg_replace('/^APP_UUID=.*$/m',           'APP_UUID='           . ID::uuid_v4(),                     $contents);
        $contents = (string)preg_replace('/^APP_KEY=.*$/m',            'APP_KEY='            . bin2hex(random_bytes(16)),          $contents);
        $contents = (string)preg_replace('/^APP_ENCRYPTION_KEY=.*$/m', 'APP_ENCRYPTION_KEY=' . $this->generateEncryptionKey(),     $contents);

        file_put_contents($envPath, $contents);
        $this->output->writeln(Style::successLabel() . " Keys written to .env");
    }

    /**
     * php atomic logs/rotate
     * Delete php_errors-*.log files beyond the most recent 10.
     */
    public function logsRotate(): void
    {
        $logDir = ATOMIC_DIR . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        $logs   = glob($logDir . DIRECTORY_SEPARATOR . 'php_errors-*.log');

        if (!is_array($logs) || count($logs) <= 10) {
            $this->output->writeln("Nothing to rotate (" . (is_array($logs) ? count($logs) : 0) . " log file(s)).");
            return;
        }

        natsort($logs);
        $excess  = array_slice(array_values($logs), 0, count($logs) - 10);
        $deleted = 0;

        foreach ($excess as $old) {
            if (unlink($old)) {
                $deleted++;
            } else {
                $err = error_get_last()['message'] ?? 'unknown error';
                $this->output->err(Style::warningLabel() . " could not delete {$old}: {$err}");
            }
        }

        $this->output->writeln(Style::successLabel() . " Rotated {$deleted} log file" . ($deleted === 1 ? '' : 's') . ".");
    }
}
