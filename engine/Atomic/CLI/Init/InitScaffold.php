<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI\Init;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\CLI\Style;
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
        $created            = 0;

        foreach ($dirs as $dir) {
            $path      = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dir);
            $isRuntime = isset($runtimeDirs[$dir]);

            if (!is_dir($path)) {
                if (mkdir($path, $isRuntime ? 0775 : 0755, true)) {
                    $created++;
                } else {
                    $err = error_get_last()['message'] ?? 'unknown error';
                    $this->output->err("        " . Style::warningLabel() . " could not create {$dir}: {$err}");
                    continue;
                }
            }

            if ($isRuntime) {
                @chmod($path, 0775);
                if (!is_writable($path)) {
                    $this->output->err("        " . Style::warningLabel() . " {$dir} is not writable by current web user context");
                    $runtimeNotWritable[] = $dir;
                }
            }
        }

        $this->printRuntimePermissionsGuide($runtimeNotWritable);
        return $created;
    }

    private function createAppStubs(string $root): int
    {
        $stubs  = 0;
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
            $this->output->err('  ' . Style::warningLabel() . " CLI method 'db_users' is unavailable, skipping users migration.");
            return;
        }

        $this->db_users();

        $migrations = new Migrations($this->output);
        $migrations->migrate();

        $this->output->writeln('  ' . Style::successLabel() . " Users migration executed.");
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

        $this->output->writeln();
        $this->output->writeln("  Runtime permissions guide:");
        $this->output->writeln("    Applications fail with writable/permission errors if these are not writable:");
        foreach ($runtimeNotWritable as $dir) {
            $this->output->writeln("      - {$dir}");
        }
        $this->output->writeln();
        $this->output->writeln("    Host fix (replace placeholders for your server):");
        $this->output->writeln("      sudo chown -R <web-user>:<web-group> storage public/uploads");
        $this->output->writeln("      sudo chmod -R ug+rwX storage public/uploads");
        $this->output->writeln("      find storage public/uploads -type d -exec chmod g+s {} \\;");
        $this->output->writeln("      sudo -u <web-user> test -w storage && sudo -u <web-user> test -w storage/logs && sudo -u <web-user> test -w public/uploads");
        $this->output->writeln();
        $this->output->writeln("    Examples of <web-user>: www-data, apache, nginx");
        $this->output->writeln();
    }
}
