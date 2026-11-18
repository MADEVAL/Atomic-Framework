<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Reporters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Filesystem;
use Engine\Atomic\Core\ID;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\Redactor;
use Engine\Atomic\Telemetry\Data\LogLine;
use Engine\Atomic\Telemetry\Data\LogPage;
use Engine\Atomic\Tools\Transient;

/** One log file per channel and day: "atomic.2026-11-20.log". */
class LogsReporter
{
    public const MAX_PER_PAGE = 500;

    public function __construct(private readonly \Base $atomic) {}

    public const PER_PAGE = 200;

    /** "all" merges the same page of every channel; its dates are those of the first channel. */
    public function view(string $channel, string $date, int $page): LogPage
    {
        $channels = [];
        foreach ($this->channels() as $item) {
            $channels[$item['name']] = $item['count'];
        }
        $names = $channel === LogPage::ALL ? array_keys($channels) : [$channel];

        $lines = [];
        $total = 0;
        $last_page = 1;
        $first = null;
        foreach ($names as $name) {
            $result = $this->page($name, $date, $page, self::PER_PAGE);
            $first ??= $result;
            foreach ((array)($result['lines'] ?? []) as $line) {
                $lines[] = LogLine::from_array((array)$line, $name);
            }
            $total += (int)($result['pagination']['total'] ?? 0);
            $last_page = max($last_page, (int)($result['pagination']['last_page'] ?? 1));
        }
        if ($channel === LogPage::ALL) {
            usort($lines, static fn (LogLine $a, LogLine $b): int => strcmp((string)$b->ts, (string)$a->ts));
        }
        $dates = array_values(array_map('strval', (array)($first['dates'] ?? [])));
        rsort($dates, SORT_STRING);
        $shown_date = $channel === LogPage::ALL ? $date : (string)($first['date'] ?? '');

        return new LogPage(
            channel: $channel,
            date: $shown_date !== '' ? $shown_date : (string)($dates[0] ?? ''),
            dates: $dates,
            lines: $lines,
            page: max(1, $page),
            last_page: $last_page,
            total: $total,
            channels: $channels,
        );
    }

    private function logs_dir(): string
    {
        return rtrim((string)$this->atomic->get('LOGS'), '/\\');
    }

    /** @return list<array{name:string, count:int}> the GET /telemetry/log-channels shape */
    public function channels(): array
    {
        $logs_dir = $this->logs_dir();
        $filesystem = Filesystem::instance();
        $channels = Log::get_channel_names();

        $cached = Transient::get('telemetry.log_channel_counts');
        $cached_counts = is_array($cached) ? $cached : [];

        $out = [];
        $counts_changed = false;
        foreach ($channels as $channel) {
            $channel = (string)$channel;
            $count = $cached_counts[$channel] ?? null;
            if (!is_int($count)) {
                $count = $this->count_channel_lines($filesystem, $logs_dir, $channel);
                $cached_counts[$channel] = $count;
                $counts_changed = true;
            }
            $out[] = ['name' => $channel, 'count' => $count];
        }

        if ($counts_changed) {
            Transient::set('telemetry.log_channel_counts', $cached_counts, 60);
        }

        return $out;
    }

    private function count_channel_lines(Filesystem $filesystem, string $logs_dir, string $channel): int
    {
        $filename = $this->resolve_channel_filename($channel);
        $dates = $this->list_channel_log_dates($logs_dir, $filename);
        if ($dates === []) {
            $path = $logs_dir !== '' ? ($logs_dir . DIRECTORY_SEPARATOR . $filename) : null;
            if (!$path || !$filesystem->is_file($path)) {
                return 0;
            }

            return (int)($filesystem->count_lines($path) ?: 0);
        }
        $path = $logs_dir . DIRECTORY_SEPARATOR . $this->build_dated_filename($filename, (string)$dates[0]);
        if (!$filesystem->is_file($path)) {
            return 0;
        }
        $count = $filesystem->count_lines($path);

        return (int)($count ?: 0);
    }

    /** @return array{count:int, mtime:int, channel:string, date:string, dates:list<string>} the GET /telemetry/log-stat shape */
    public function stat(string $channel, string $requested_date): array
    {
        $filesystem = Filesystem::instance();
        $log_target = $this->resolve_channel_log_target($this->logs_dir(), $channel, $requested_date);
        $path = $log_target['path'];
        if (!$path || !$filesystem->is_file($path)) {
            return [
                'count'   => 0,
                'mtime'   => 0,
                'channel' => $channel,
                'date'    => $log_target['date'],
                'dates'   => $log_target['dates'],
            ];
        }

        $count = $filesystem->count_lines($path);
        if ($count === false) {
            Log::warning('Telemetry log_stat failed to count lines in log file: ' . $path);
            $count = 0;
        }
        $mtime = $filesystem->modified_time($path);
        if ($mtime === false) {
            Log::warning('Telemetry log_stat failed to read filemtime for: ' . $path);
            $mtime = 0;
        }

        return [
            'count'   => $count,
            'mtime'   => (int)$mtime,
            'channel' => $channel,
            'date'    => $log_target['date'],
            'dates'   => $log_target['dates'],
        ];
    }

    /**
     * Pages after the first are cached until the file changes.
     *
     * @return array<string, mixed> the GET /telemetry/logs shape: channel, date, dates, lines, pagination
     */
    public function page(string $channel, string $requested_date, int $page, int $per_page): array
    {
        $filesystem = Filesystem::instance();
        $log_target = $this->resolve_channel_log_target($this->logs_dir(), $channel, $requested_date);
        $path = $log_target['path'];
        if (!$path || !$filesystem->is_file($path)) {
            return [
                'channel'    => $channel,
                'date'       => $log_target['date'],
                'dates'      => $log_target['dates'],
                'lines'      => [],
                'pagination' => ['page' => 1, 'per_page' => 100, 'total' => 0, 'last_page' => 1],
            ];
        }

        $page     = max(1, $page);
        $per_page = min(self::MAX_PER_PAGE, max(1, $per_page));

        $cache_channel  = ($channel ?: 'default') . ':' . ($log_target['date'] ?: 'none');
        $meta_key       = "log_meta:{$cache_channel}";

        $current_mtime_raw = $filesystem->modified_time($path);
        if ($current_mtime_raw === false) {
            Log::warning('Telemetry logs failed to read modified_time for: ' . $path);
        }
        $current_mtime = $current_mtime_raw !== false ? (int)$current_mtime_raw : 0;

        $current_size_raw = $filesystem->filesize($path);
        if ($current_size_raw === false) {
            Log::warning('Telemetry logs failed to read filesize for: ' . $path);
        }
        $current_size = $current_size_raw !== false ? (int)$current_size_raw : 0;

        $meta = Transient::get($meta_key, Transient::DRIVER_REDIS);
        $meta_data = is_array($meta) ? $meta : null;
        $generation = ($meta_data !== null && isset($meta_data['gen'])) ? max(1, (int)$meta_data['gen']) : 1;
        $meta_changed = ($meta_data === null || !isset($meta_data['mtime'], $meta_data['size'], $meta_data['gen']));

        $content_changed = false;
        if ($meta_data !== null && isset($meta_data['mtime'], $meta_data['size'])) {
            $content_changed = ((int)$meta_data['mtime'] !== $current_mtime) || ((int)$meta_data['size'] !== $current_size);
            if ($content_changed) {
                $generation++;
                $meta_changed = true;
            }
        }

        if (!$content_changed && $meta_data !== null && isset($meta_data['total'])) {
            $total = (int)$meta_data['total'];
        } else {
            $counted = $filesystem->count_lines($path);
            if ($counted === false) {
                Log::warning('Telemetry logs failed to count lines for: ' . $path);
                $counted = 0;
            }
            $total = $counted;
            $meta_changed = true;
        }

        if ($meta_changed) {
            Transient::set($meta_key, [
                'mtime' => $current_mtime,
                'size'  => $current_size,
                'gen'   => $generation,
                'total' => $total,
            ], 86400 * 30, Transient::DRIVER_REDIS);
        }

        $last_page = max(1, (int)ceil($total / $per_page));
        $cache_key = "log_page:{$cache_channel}:{$generation}:{$page}:{$per_page}";

        if ($page > 1) {
            $cached = Transient::get($cache_key, Transient::DRIVER_REDIS);
            $cached_last_page = is_array($cached) ? (int)($cached['pagination']['last_page'] ?? 0) : 0;
            if (
                $cached !== null
                && $cached !== false
                && $this->can_use_cached_log_page($page, $cached_last_page)
            ) {
                return $cached;
            }
        }

        $offset = ($page - 1) * $per_page;
        $page_lines = $filesystem->read_lines_from_end($path, $offset, $per_page);
        if ($page_lines === false) {
            Log::warning('Telemetry logs failed to read log file: ' . $path);
            $page_lines = [];
        }

        $out = [];
        foreach ($page_lines as $ln) {
            $ts = null; $level = null; $msg = $ln;

            if (preg_match('/^(?<ts>\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s*(?:\[[^\]]+\]\s*)?\[(?<level>[A-Z]+)\]\s*(?<message>.*)$/', $ln, $m)) {
                $ts = $m['ts'];
                $level = $m['level'];
                $msg = $m['message'];
            }
            elseif (preg_match('/^(?<ts>[A-Za-z]{3},\s+\d{2}\s+[A-Za-z]{3}\s+\d{4}\s+\d{2}:\d{2}:\d{2}\s+[+\-]\d{4})\s*(?:\[[^\]]+\]\s*)?\[(?<level>[A-Z]+)\]\s*(?<message>.*)$/', $ln, $m3)) {
                $ts = $m3['ts'];
                $level = $m3['level'];
                $msg = $m3['message'];
            }
            elseif (preg_match('/^\[(?<level>[A-Z]+)\]\s*(?<message>.*)$/', $ln, $m2)) {
                $level = $m2['level'];
                $msg = $m2['message'];
            }
            $dumpId = null;
            if (preg_match('/dump_id[:=]([0-9a-fA-F-]{36})/', $ln, $dm)) {
                $cand = $dm[1];
                if (ID::is_valid_uuid_v4($cand)) $dumpId = $cand;
            }

            $out[] = [
                'ts'      => $ts,
                'level'   => $level,
                'message' => Redactor::redact_secrets($msg),
                'dump_id' => $dumpId,
            ];
        }

        $response_data = [
            'channel' => $channel,
            'date'    => $log_target['date'],
            'dates'   => $log_target['dates'],
            'lines'   => $out,
            'pagination' => [
                'page'      => $page,
                'per_page'  => $per_page,
                'total'     => $total,
                'last_page' => $last_page,
            ],
        ];

        if ($this->should_cache_log_page($page, $last_page)) {
            Transient::set($cache_key, $response_data, 86400 * 30, Transient::DRIVER_REDIS);
        }

        return $response_data;
    }

    private function resolve_channel_filename(string $channel): string
    {
        $filename = 'atomic.log';
        if ($channel !== '') {
            $channelPath = Log::get_channel_path($channel);
            if ($channelPath !== null) {
                $filename = basename($channelPath);
            }
        }
        return $filename;
    }

    private function build_dated_filename(string $filename, string $date): string
    {
        $info = pathinfo($filename);
        $name = (string)($info['filename'] ?? $filename);
        $dated = $name . '.' . $date;
        if (isset($info['extension']) && $info['extension'] !== '') {
            $dated .= '.' . $info['extension'];
        }
        return $dated;
    }

    private function is_valid_log_date(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $date));
        return checkdate($month, $day, $year);
    }

    private function list_channel_log_dates(string $logs_dir, string $filename): array
    {
        if ($logs_dir === '' || !is_dir($logs_dir)) {
            return [];
        }

        $info = pathinfo($filename);
        $name = (string)($info['filename'] ?? $filename);
        $escaped_name = preg_quote($name, '/');
        $pattern = isset($info['extension']) && $info['extension'] !== ''
            ? '/^' . $escaped_name . '\.(\d{4}-\d{2}-\d{2})\.' . preg_quote((string)$info['extension'], '/') . '$/'
            : '/^' . $escaped_name . '\.(\d{4}-\d{2}-\d{2})$/';

        $dates = [];
        $entries = scandir($logs_dir);
        if ($entries === false) {
            return [];
        }
        foreach ($entries as $entry) {
            $path = $logs_dir . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path)) {
                continue;
            }
            if (preg_match($pattern, $entry, $m) === 1 && $this->is_valid_log_date($m[1])) {
                $dates[$m[1]] = true;
            }
        }

        $out = array_keys($dates);
        rsort($out, SORT_STRING);
        return $out;
    }

    /** @return array{path: ?string, date: string, dates: array<int, string>} */
    private function resolve_channel_log_target(string $logs_dir, string $channel, string $requested_date): array
    {
        $filename = $this->resolve_channel_filename($channel);
        $dates = $this->list_channel_log_dates($logs_dir, $filename);
        $selected_date = '';

        if ($this->is_valid_log_date($requested_date)) {
            if (empty($dates) || in_array($requested_date, $dates, true)) {
                $selected_date = $requested_date;
            }
        }

        if ($selected_date === '' && !empty($dates)) {
            $selected_date = (string)$dates[0];
        }

        if ($selected_date === '') {
            $selected_date = date('Y-m-d');
        }

        $dated_filename = $this->build_dated_filename($filename, $selected_date);
        $path = $logs_dir !== '' ? ($logs_dir . DIRECTORY_SEPARATOR . $dated_filename) : null;

        return [
            'path'  => $path,
            'date'  => $selected_date,
            'dates' => $dates,
        ];
    }

    private function can_use_cached_log_page(int $page, int $cached_last_page): bool
    {
        if ($cached_last_page <= 0) {
            return false;
        }

        return $page > 1 && $page <= $cached_last_page;
    }

    private function should_cache_log_page(int $page, int $last_page): bool
    {
        if ($last_page <= 1) {
            return false;
        }

        return $page > 1 && $page <= $last_page;
    }
}
