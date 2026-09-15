<?php
declare(strict_types=1);

namespace Engine\Atomic\Scheduler\Jobs;
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Filesystem;
use Engine\Atomic\Core\Log;

class LogCleanupJob
{
    public function handle(): void
    {
        $fs       = Filesystem::instance();
        $logs_dir = Log::get_logs_dir();
        if ($logs_dir === '' || !$fs->is_dir($logs_dir)) {
            return;
        }

        $cutoff_timestamps = [];

        foreach (Log::get_channel_names() as $channel) {
            $path = Log::get_channel_path($channel);
            if ($path === null) {
                continue;
            }

            $max_days = Log::get_channel_max_days($channel);
            $info     = pathinfo($path);
            $filename = (string)($info['filename'] ?? $path);
            $basename = preg_replace('/\.\d{4}-\d{2}-\d{2}$/', '', $filename);
            if (!is_string($basename) || $basename === '') {
                $basename = $filename;
            }
            $ext     = isset($info['extension']) && $info['extension'] !== '' ? '.' . $info['extension'] : '';
            $pattern = $logs_dir . $basename . '.????-??-??' . $ext;

            if (!isset($cutoff_timestamps[$max_days])) {
                $cutoff_timestamps[$max_days] = strtotime("-{$max_days} days 00:00:00");
            }
            $cutoff = $cutoff_timestamps[$max_days];

            $files = $fs->glob($pattern);
            if (!is_array($files)) {
                continue;
            }

            foreach ($files as $file) {
                $fname = basename($file);
                if (preg_match('/\.(\d{4}-\d{2}-\d{2})' . preg_quote($ext, '/') . '$/', $fname, $m)) {
                    $file_time = strtotime($m[1]);
                    if ($file_time !== false && $file_time < $cutoff) {
                        $fs->delete($file);
                        Log::debug('[LogCleanup] deleted ' . $fname . ' (channel=' . $channel . ')');
                    }
                }
            }
        }

        $this->cleanup_dumps($fs);
    }

    private function cleanup_dumps(Filesystem $fs): void
    {
        $max_days = Log::get_dumps_max_days();
        $dumps_dir = Log::get_dumps_dir();
        if ($max_days <= 0 || $dumps_dir === '' || !$fs->is_dir($dumps_dir)) {
            return;
        }

        $cutoff = strtotime("-{$max_days} days 00:00:00");
        if ($cutoff === false) {
            return;
        }

        $files = $fs->glob($dumps_dir . '*.json');
        if (!is_array($files)) {
            return;
        }

        foreach ($files as $file) {
            if (!$fs->is_file($file)) {
                continue;
            }

            $modified_at = $fs->modified_time($file);
            if ($modified_at !== false && $modified_at < $cutoff) {
                $fs->delete($file);
                Log::debug('[LogCleanup] deleted dump ' . basename($file));
            }
        }
    }
}
