<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\ID;
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
        echo "\n  " . Paint::bold('Atomic Framework -- Project Initialization') . "\n";
        echo "  " . str_repeat('-', 48) . "\n\n";

        $root = ATOMIC_DIR;

        echo "  " . Paint::yellow('[1/4]', true) . " Creating directories...\n";
        $created = $this->createSkeletonDirectories($root);
        echo "        {$created} new director" . ($created === 1 ? 'y' : 'ies') . " created\n\n";

        echo "  " . Paint::yellow('[2/4]', true) . " Preparing settings...\n";
        $configSource = $this->chooseConfigSource();
        $this->initializeConfigSource($root, $configSource);

        $this->setConfigValue('APP_UUID', $this->readConfigValue('APP_UUID', ID::uuid_v4()));
        $this->setConfigValue('APP_KEY', $this->readConfigValue('APP_KEY', bin2hex(random_bytes(16))));
        $this->configureBasicEnv('');

        if ($this->configMode() === 'env') {
            $this->setEnvValue(ATOMIC_DIR . DIRECTORY_SEPARATOR . '.env', 'APP_ENCRYPTION_KEY', $this->readEnvValue(ATOMIC_DIR . DIRECTORY_SEPARATOR . '.env', 'APP_ENCRYPTION_KEY', $this->generateEncryptionKey()));
            echo "        .env ready\n\n";
        } else {
            echo "        PHP config files ready\n\n";
        }

        echo "  " . Paint::yellow('[3/4]', true) . " Stub files...\n";
        $stubs = $this->createAppStubs($root);
        echo "        {$stubs} stub file" . ($stubs === 1 ? '' : 's') . " created\n\n";

        echo "  " . Paint::yellow('[4/4]', true) . " Database and backend setup...\n";
        $dbConfig = $this->configureDatabase('');
        if ($dbConfig !== null) {
            $this->bootDatabase($dbConfig);
            if ($this->initializeMigrationDatabase()) {
                $this->setupOptionalDatabaseSystems($root);

                if (!$this->maybeEnableRedisBackends('')) {
                    $this->setConfigValue('SESSION_DRIVER', 'db');
                    $this->setConfigValue('MUTEX_DRIVER', 'database');
                    $this->setConfigValue('QUEUE_DRIVER', 'database');
                    $this->setupDatabaseBackendsMigrations();
                }
            }
        } else {
            echo "  Database setup skipped." . PHP_EOL;
        }

        echo "\n  " . str_repeat('=', 48) . "\n";
        echo "  " . Paint::successLabel() . " Done.\n\n";
        echo "  Next:\n";
        if ($this->configMode() === 'env') {
            echo "    1. Open .env if you want to change anything.\n";
        } else {
            echo "    1. Review config/*.php if you want to change anything.\n";
        }
        echo "    2. Point your server to public/.\n";
        echo "    3. Open the site.\n\n";
    }

    /**
     * php atomic init/key
     * Regenerate APP_UUID, APP_KEY, APP_ENCRYPTION_KEY in existing .env
     */
    public function initKey(): void
    {
        $envPath = ATOMIC_DIR . DIRECTORY_SEPARATOR . '.env';

        if (!file_exists($envPath)) {
            echo Paint::errorLabel() . " No .env file found. Run 'php atomic init' first.\n";
            return;
        }

        $contents = (string)file_get_contents($envPath);
        $contents = (string)preg_replace('/^APP_UUID=.*$/m', 'APP_UUID=' . ID::uuid_v4(), $contents);
        $contents = (string)preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . bin2hex(random_bytes(16)), $contents);
        $contents = (string)preg_replace('/^APP_ENCRYPTION_KEY=.*$/m', 'APP_ENCRYPTION_KEY=' . $this->generateEncryptionKey(), $contents);

        file_put_contents($envPath, $contents);
        echo Paint::successLabel() . " Keys written to .env\n";
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
            echo "Nothing to rotate (" . (is_array($logs) ? count($logs) : 0) . " log file(s)).\n";
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
                echo Paint::warningLabel() . " could not delete {$old}: {$err}\n";
            }
        }
        echo Paint::successLabel() . " Rotated {$deleted} log file" . ($deleted === 1 ? '' : 's') . ".\n";
    }
}
