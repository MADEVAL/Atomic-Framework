<?php
declare(strict_types=1);

namespace Tests\Engine\Scheduler;

use Engine\Atomic\Core\Log;
use Engine\Atomic\Scheduler\Jobs\LogCleanupJob;
use PHPUnit\Framework\TestCase;

class LogCleanupJobTest extends TestCase
{
    private string $logsDir;

    protected function setUp(): void
    {
        $this->logsDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_cleanup_' . uniqid('', true) . DIRECTORY_SEPARATOR;
        mkdir($this->logsDir . 'dumps', 0755, true);

        $f3 = \Base::instance();
        $f3->set('LOGS', $this->logsDir);
        $f3->set('DEBUG_MODE', 'false');
        $f3->set('DEBUG_LEVEL', 'error');
        $f3->set('LOG_CHANNELS', [
            'default' => 'atomic',
            'dumps_max_days' => 3,
            'channels' => [
                'atomic' => [
                    'driver' => 'file',
                    'path' => 'atomic.log',
                    'level' => 'debug',
                    'max_days' => 3,
                ],
            ],
        ]);
        Log::init($f3);
    }

    protected function tearDown(): void
    {
        $this->remove_directory($this->logsDir);
        Log::reset();
    }

    public function test_cleanup_removes_expired_dumps_and_keeps_recent_dumps(): void
    {
        $old_dump = Log::get_dumps_dir() . 'old.json';
        $recent_dump = Log::get_dumps_dir() . 'recent.json';
        file_put_contents($old_dump, '{}');
        file_put_contents($recent_dump, '{}');
        touch($old_dump, strtotime('-5 days'));
        touch($recent_dump, strtotime('-1 day'));

        (new LogCleanupJob())->handle();

        $this->assertFileDoesNotExist($old_dump);
        $this->assertFileExists($recent_dump);
    }

    public function test_cleanup_removes_expired_channel_logs_and_keeps_recent_channel_logs(): void
    {
        $old_log = Log::get_logs_dir() . 'atomic.' . date('Y-m-d', strtotime('-5 days')) . '.log';
        $recent_log = Log::get_logs_dir() . 'atomic.' . date('Y-m-d', strtotime('-1 day')) . '.log';
        file_put_contents($old_log, 'old channel log');
        file_put_contents($recent_log, 'recent channel log');

        (new LogCleanupJob())->handle();

        $this->assertFileDoesNotExist($old_log);
        $this->assertFileExists($recent_log);
    }

    public function test_cleanup_respects_the_configured_retention_window_for_logs_and_dumps(): void
    {
        // The test channel and dumps are configured with a three-day retention window.
        $inside_log = Log::get_logs_dir() . 'atomic.' . date('Y-m-d', strtotime('-2 days')) . '.log';
        $outside_log = Log::get_logs_dir() . 'atomic.' . date('Y-m-d', strtotime('-5 days')) . '.log';
        $inside_dump = Log::get_dumps_dir() . 'inside.json';
        $outside_dump = Log::get_dumps_dir() . 'outside.json';

        file_put_contents($inside_log, 'inside retention window');
        file_put_contents($outside_log, 'outside retention window');
        file_put_contents($inside_dump, '{}');
        file_put_contents($outside_dump, '{}');
        touch($inside_dump, strtotime('-2 days'));
        touch($outside_dump, strtotime('-5 days'));

        (new LogCleanupJob())->handle();

        $this->assertFileExists($inside_log);
        $this->assertFileDoesNotExist($outside_log);
        $this->assertFileExists($inside_dump);
        $this->assertFileDoesNotExist($outside_dump);
    }

    public function test_cleanup_does_not_remove_unrelated_nested_logs(): void
    {
        $nested_dir = $this->logsDir . 'ai' . DIRECTORY_SEPARATOR;
        mkdir($nested_dir, 0755, true);
        $nested_log = $nested_dir . 'atomic.ai.log';
        file_put_contents($nested_log, 'application log');
        touch($nested_log, strtotime('-90 days'));

        (new LogCleanupJob())->handle();

        $this->assertFileExists($nested_log);
    }

    private function remove_directory(string $directory): void
    {
        foreach (glob($directory . '*') ?: [] as $path) {
            if (is_dir($path)) {
                $this->remove_directory($path . DIRECTORY_SEPARATOR);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}
