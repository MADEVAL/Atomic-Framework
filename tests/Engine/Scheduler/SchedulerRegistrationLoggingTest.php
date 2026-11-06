<?php
declare(strict_types=1);

namespace Tests\Engine\Scheduler;

if (!defined('ATOMIC_START')) exit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchedulerRegistrationLoggingTest extends TestCase
{
    public static function registration_cases(): array
    {
        return [
            'load missing' => ['load_from', 'missing', 'does not exist'],
            'register missing' => ['register_schedule', 'missing', 'does not exist'],
            'load directory' => ['load_from', 'directory', 'not a file'],
            'register directory' => ['register_schedule', 'directory', 'not a file'],
            'load existing' => ['load_from', 'existing', null],
            'register existing' => ['register_schedule', 'existing', null],
            'default missing' => ['register_schedule', 'default', null],
        ];
    }

    #[DataProvider('registration_cases')]
    public function test_registration_logs_explicit_invalid_paths_only(string $method, string $kind, ?string $reason): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_schedule_log_' . uniqid();
        mkdir($directory);
        $script = $directory . DIRECTORY_SEPARATOR . 'probe.php';
        $path = $kind === 'directory' ? $directory : $directory . DIRECTORY_SEPARATOR . 'schedule.php';
        if ($kind === 'existing') {
            file_put_contents($path, '<?php $scheduler->call(static function (): void {});');
        }
        // A fresh process lets the default routes constant point at an absent schedule
        // without moving application files or altering the parent test's Log state.
        $source = '<?php declare(strict_types=1); define("ATOMIC_START", microtime(true));'
            . 'define("ATOMIC_APP_ROUTES", ' . var_export($directory . DIRECTORY_SEPARATOR, true) . ');'
            . 'require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';'
            . '$base = \\Base::instance(); $base->set("LOGS", ATOMIC_APP_ROUTES);'
            . '\\Engine\\Atomic\\Core\\Log::init($base);'
            . '$scheduler = \\Engine\\Atomic\\Scheduler\\Scheduler::instance()->logging(false);'
            . '$scheduler->' . $method . '(' . ($kind === 'default' ? '' : var_export($path, true)) . ');'
            . 'echo count($scheduler->events());';
        file_put_contents($script, $source);
        try {
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output, $status);
            $this->assertSame(0, $status, implode("\n", $output));
            $this->assertSame($kind === 'existing' ? '1' : '0', implode("\n", $output));
            $logs = glob($directory . DIRECTORY_SEPARATOR . '*.log');
            $contents = implode('', array_map('file_get_contents', $logs));
            if ($reason === null) {
                $this->assertStringNotContainsString('[WARNING]', $contents);
            } else {
                $this->assertStringContainsString('[WARNING] [Scheduler]', $contents);
                $this->assertStringContainsString($path, $contents);
                $this->assertStringContainsString($reason, $contents);
            }
        } finally {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
