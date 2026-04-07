<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI\Init;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\CLI\Paint;
use Engine\Atomic\Core\Migrations;

trait InitScaffold
{
    private function createSkeletonDirectories(string $root): int
    {
        $dirs = [
            'app/Event',
            'app/Hook',
            'app/Http/Controllers',
            'app/Http/Middleware',
            'app/Models',
            'bootstrap',
            'config',
            'database/migrations',
            'database/seeds',
            'public/plugins',
            'public/themes/default',
            'public/uploads',
            'resources/views',
            'routes',
            'storage/framework/cache/data',
            'storage/framework/cache/fonts',
            'storage/framework/fonts',
            'storage/logs',
        ];

        $runtimeDirs = array_flip([
            'storage/logs',
            'storage/framework/cache/data',
            'storage/framework/cache/fonts',
            'storage/framework/fonts',
            'public/uploads',
        ]);

        $runtimeNotWritable = [];
        $created = 0;

        foreach ($dirs as $dir) {
            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dir);
            $isRuntime = isset($runtimeDirs[$dir]);
            if (!is_dir($path)) {
                if (mkdir($path, $isRuntime ? 0775 : 0755, true)) {
                    $created++;
                } else {
                    $err = error_get_last()['message'] ?? 'unknown error';
                    echo "        " . Paint::warningLabel() . " could not create {$dir}: {$err}\n";
                    continue;
                }
            }
            if ($isRuntime) {
                @chmod($path, 0775);
                if (!is_writable($path)) {
                    echo "        " . Paint::warningLabel() . " {$dir} is not writable by current web user context\n";
                    $runtimeNotWritable[] = $dir;
                }
            }
        }

        $this->printRuntimePermissionsGuide($runtimeNotWritable);
        return $created;
    }

    private function createAppStubs(string $root): int
    {
        $stubs = 0;
        $stubs += $this->writeStubIfMissing(
            $root . '/routes/web.php',
            "<?php\ndeclare(strict_types=1);\nif (!defined('ATOMIC_START')) exit;\n\n// Web routes\n"
        );
        $stubs += $this->writeStubIfMissing(
            $root . '/routes/api.php',
            "<?php\ndeclare(strict_types=1);\nif (!defined('ATOMIC_START')) exit;\n\n// API routes\n"
        );
        $stubs += $this->writeStubIfMissing(
            $root . '/routes/cli.php',
            "<?php\ndeclare(strict_types=1);\nif (!defined('ATOMIC_START')) exit;\n\n// Application CLI routes\n"
        );
        $stubs += $this->writeStubIfMissing(
            $root . '/app/Event/Application.php',
            "<?php\ndeclare(strict_types=1);\nnamespace App\\Event;\n\nclass Application {\n    use \\Engine\\Atomic\\Core\\Traits\\Singleton;\n    public function init(): void {}\n}\n"
        );
        $stubs += $this->writeStubIfMissing(
            $root . '/app/Hook/Application.php',
            "<?php\ndeclare(strict_types=1);\nnamespace App\\Hook;\n\nclass Application {\n    use \\Engine\\Atomic\\Core\\Traits\\Singleton;\n    public function init(): void {}\n}\n"
        );
        return $stubs;
    }

    private function runUserSetupBranch(string $root): void
    {
        if (!$this->confirm('Run users migration now?', false)) {
            return;
        }

        if (!method_exists($this, 'db_users')) {
            echo '  ' . Paint::warningLabel() . " CLI method 'db_users' is unavailable, skipping users migration." . PHP_EOL;
            return;
        }

        $this->db_users();

        $migrations = new Migrations();
        $migrations->migrate();

        echo '  ' . Paint::successLabel() . " Users migration executed." . PHP_EOL;
    }

    private function generateEncryptionKey(): string
    {
        if (!function_exists('sodium_crypto_secretbox_keygen')) {
            return '';
        }

        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    private function writeStubIfMissing(string $path, string $content): int
    {
        $path = str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (file_exists($path)) {
            return 0;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);
        return 1;
    }

    private function printRuntimePermissionsGuide(array $runtimeNotWritable): void
    {
        if ($runtimeNotWritable === []) {
            return;
        }

        echo "\n  Runtime permissions guide:\n";
        echo "    Applications fail with writable/permission errors if these are not writable:\n";
        foreach ($runtimeNotWritable as $dir) {
            echo "      - {$dir}\n";
        }
        echo "\n";
        echo "    Host fix (replace placeholders for your server):\n";
        echo "      sudo chown -R <web-user>:<web-group> storage public/uploads\n";
        echo "      sudo chmod -R ug+rwX storage public/uploads\n";
        echo "      find storage public/uploads -type d -exec chmod g+s {} \\\;\n";
        echo "      sudo -u <web-user> test -w storage && sudo -u <web-user> test -w storage/logs && sudo -u <web-user> test -w public/uploads\n";
        echo "\n";
        echo "    Examples of <web-user>: www-data, apache, nginx\n\n";
    }
}
