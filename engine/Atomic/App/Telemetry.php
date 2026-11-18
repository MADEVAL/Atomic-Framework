<?php
declare(strict_types=1);
namespace Engine\Atomic\App;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Filesystem;
use Engine\Atomic\Core\ID;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\Response;
use Engine\Atomic\Core\Redactor;
use Engine\Atomic\Queue\Enums\State;
use Engine\Atomic\Queue\Enums\Driver;
use Engine\Atomic\Queue\Managers\TelemetryManager;
use Engine\Atomic\Telemetry\Reporters\ExceptionsReporter;
use Engine\Atomic\Telemetry\Reporters\DatabaseReporter;
use Engine\Atomic\Telemetry\Reporters\MigrationsReporter;
use Engine\Atomic\Telemetry\Reporters\MutexReporter;
use Engine\Atomic\Telemetry\Reporters\PluginsReporter;
use Engine\Atomic\Telemetry\Reporters\QueueReporter;
use Engine\Atomic\Telemetry\Reporters\QuotaReporter;
use Engine\Atomic\Telemetry\Reporters\RateLimitReporter;
use Engine\Atomic\Telemetry\Reporters\SchedulerReporter;
use Engine\Atomic\Telemetry\Reporters\SecurityReporter;
use Engine\Atomic\Telemetry\Reporters\SummaryReporter;
use Engine\Atomic\Telemetry\Stats\StatsRecorder;
use Engine\Atomic\Theme\Assets;
use Engine\Atomic\Theme\Theme;
use Engine\Atomic\Tools\Transient;

class Telemetry extends Controller
{

    public function __construct()
    {
        parent::__construct();
        $app = App::instance();
        if (!$app->get('__theme_booted')) {
            Theme::instance('Telemetry');
            $app->set('__theme_booted', true);
        }
    }

    public function beforeroute(\Base $atomic): bool
    {
        if (!parent::beforeroute($atomic)) {
            return false;
        }

        Redactor::init_from_hive($atomic);

        // Requests without a DB connection spool their stats locally; persist
        // them before reporting so the dashboard reads current numbers.
        try {
            StatsRecorder::instance()->drain();
        } catch (\Throwable $e) {
            // Stats must never break the dashboard.
        }

        return true;
    }

    /**
     * The API base follows BASE, so apps served from a subdirectory fetch the right URLs.
     *
     * @return array{base:string}
     */
    public static function client_config(\Base $atomic): array
    {
        // Mirrors routes/telemetry.php: unknown modes fall back to 'none'.
        $mode = strtolower(trim((string)$atomic->get('TELEMETRY_ACCESS_MODE')));

        return [
            'base' => rtrim((string)$atomic->get('BASE'), '/') . '/telemetry',
            'publicAccess' => !in_array($mode, ['config', 'auth'], true),
        ];
    }

    public function queue(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        // Redacted: the first page of jobs lands in the page source.
        $jobs = Redactor::redact_secrets((new QueueReporter())->jobs());

        $assets = Assets::instance();
        $assets->localize_script('telemetry', self::client_config($atomic), 'TELEMETRY_CONFIG');
        $assets->localize_script('telemetry', $jobs, 'TELEMETRY_JOBS');
        $atomic->set('title', 'Atomic Telemetry');

        try {
            echo \View::instance()->render('layout/telemetry-queue.atom.php');
        } catch (\Throwable $e) {
            Log::error('Failed to render telemetry template: ' . $e->getMessage());
            $this->render_fallback($atomic);
        }
    }

    public function events(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        $driver = $atomic->clean($params['driver'] ?? '');
        $job_uuid = $atomic->clean($params['job_uuid'] ?? '');
        if (!ID::is_valid_uuid_v4($job_uuid)) {
            $atomic->error(400, 'Invalid UUID format');
            return;
        }
        if (!Driver::is_valid($driver ?? '')) {
            $atomic->error(400, 'Invalid driver. Must be one of: ' . implode(', ', Driver::all()));
            return;
        }
        $telemetry_manager = new TelemetryManager();
        $events = $telemetry_manager->fetch_events($driver, 'default', $job_uuid);
        Response::instance()->send_json([
            'job_uuid' => $job_uuid,
            'driver'   => $driver,
            'events'   => Redactor::redact_secrets($events),
        ], terminate: false);
    }

    public function dashboard(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        $php = [
            'version' => phpversion(),
            'sapi' => PHP_SAPI,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'opcache_enabled' => (bool)ini_get('opcache.enable'),
            'extensions' => get_loaded_extensions(),
        ];

        $f3ver = null;
        try {
            $base = \Base::instance();
            $f3ver = $base->exists('VERSION') ? $base->get('VERSION') : (defined('\Base::VERSION') ? \Base::VERSION : null);
        } catch (\Throwable $e) {}

        $f3 = [
            'version' => $f3ver,
        ];

        $atomicInfo = [
            'env'         => (string)$atomic->get('APP_ENV'),
            'debug_mode'  => (bool)filter_var($atomic->get('DEBUG_MODE'), FILTER_VALIDATE_BOOLEAN),
            'debug_level' => (string)$atomic->get('DEBUG_LEVEL'),
            'logs_dir'    => Redactor::redact_secrets((string)$atomic->get('LOGS')),
            'dumps_dir'   => Redactor::redact_secrets((string)$atomic->get('DUMPS')),
            'base'        => (string)$atomic->get('BASE'),
        ];

        $system = [
            'os'       => PHP_OS_FAMILY . ' ' . PHP_OS,
            'hostname' => gethostname() ?: null,
            'timezone' => date_default_timezone_get(),
            'uptime'   => self::server_uptime(),
        ];

        $db = null;
        try {
            $conn = ConnectionManager::instance()->get_db(false);
            if ($conn instanceof \DB\SQL) {
                $pdo = $conn->pdo();
                $db = [
                    'driver'         => Redactor::redact_secrets((string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)),
                    'server_version' => Redactor::redact_secrets((string)$pdo->getAttribute(\PDO::ATTR_SERVER_VERSION)),
                    'client_version' => Redactor::redact_secrets((string)$pdo->getAttribute(\PDO::ATTR_CLIENT_VERSION)),
                ];
            }
        } catch (\Throwable $e) {}

        Response::instance()->send_json([
            'php'    => $php,
            'f3'     => $f3,
            'atomic' => $atomicInfo,
            'system' => $system,
            'db'     => $db,
        ], terminate: false);
    }

    public function hive(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        $hive = [];
        try {
            $hive = Redactor::redact_secrets($atomic->hive());
        } catch (\Throwable $e) {
            $hive = ['error' => Redactor::redact_secrets($e->getMessage())];
        }

        Response::instance()->send_json($hive, terminate: false);
    }

    public function log_channels(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        // Contract shape: [{name, count}] — the count feeds the log rail.
        $logs_dir = rtrim((string)$atomic->get('LOGS'), '/\\');
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

        Response::instance()->send_json($out, terminate: false);
    }

    /** Line count of the newest dated file of a channel (0 when none exists). */
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

    public function log_stat(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        $logsDir = rtrim((string)$atomic->get('LOGS'), '/\\');
        $filesystem = Filesystem::instance();

        $channel = $atomic->clean((string)($atomic->get('GET.channel') ?? ''));
        $requested_date = $atomic->clean((string)($atomic->get('GET.date') ?? ''));
        $log_target = $this->resolve_channel_log_target($logsDir, $channel, $requested_date);
        $path = $log_target['path'];
        $res = Response::instance();
        if (!$path || !$filesystem->is_file($path)) {
            $res->send_json([
                'count'   => 0,
                'mtime'   => 0,
                'channel' => $channel,
                'date'    => $log_target['date'],
                'dates'   => $log_target['dates'],
            ], terminate: false);
            return;
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

        $res->send_json([
            'count'   => $count,
            'mtime'   => (int)$mtime,
            'channel' => $channel,
            'date'    => $log_target['date'],
            'dates'   => $log_target['dates'],
        ], terminate: false);
    }

    public function logs(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        $logsDir = rtrim((string)$atomic->get('LOGS'), '/\\');
        $filesystem = Filesystem::instance();

        $channel = $atomic->clean((string)($atomic->get('GET.channel') ?? ''));
        $requested_date = $atomic->clean((string)($atomic->get('GET.date') ?? ''));
        $log_target = $this->resolve_channel_log_target($logsDir, $channel, $requested_date);
        $path = $log_target['path'];
        $res = Response::instance();
        if (!$path || !$filesystem->is_file($path)) {
            $res->send_json([
                'channel'    => $channel,
                'date'       => $log_target['date'],
                'dates'      => $log_target['dates'],
                'lines'      => [],
                'pagination' => ['page' => 1, 'per_page' => 100, 'total' => 0, 'last_page' => 1],
            ], terminate: false);
            return;
        }

        $page     = max(1, (int)($atomic->get('GET.page') ?? 1));
        $per_page = min(500, max(1, (int)($atomic->get('GET.per_page') ?? 100)));

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
                $res->send_json($cached, terminate: false);
                return;
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

        $res->send_json($response_data, terminate: false);
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

    public function dump(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        $dumpId = (string)($params['dump_id'] ?? '');
        if (!ID::is_valid_uuid_v4($dumpId)) {
            $atomic->error(400, 'Invalid dump id');
            return;
        }
        $filesystem = Filesystem::instance();
        $dumpsDir = rtrim((string)$atomic->get('DUMPS'), '/\\');
        $file = $dumpsDir ? ($dumpsDir . DIRECTORY_SEPARATOR . $dumpId . '.json') : null;
        if (!$file || !$filesystem->is_file($file)) {
            $atomic->error(404, 'Dump not found');
            return;
        }

        $raw = $filesystem->read($file) ?: '';
        $decoded = json_decode($raw, true);
        $res = Response::instance();
        if (json_last_error() === JSON_ERROR_NONE) {
            $res->send_json(Redactor::redact_secrets($decoded), terminate: false);
        } else {
            $res->send_json(['dump_id' => $dumpId, 'error' => 'dump file could not be decoded'], terminate: false);
        }
    }

    private function range(\Base $atomic): string
    {
        $range = strtolower(trim((string)($atomic->get('GET.range') ?? '24h')));

        return in_array($range, ['24h', '7d', '30d'], true) ? $range : '24h';
    }

    public function jobs(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json(
            Redactor::redact_secrets((new QueueReporter())->jobs()),
            terminate: false,
        );
    }

    public function summary(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json(
            Redactor::redact_secrets((new SummaryReporter())->summary()),
            terminate: false,
        );
    }

    public function schedule(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json(
            Redactor::redact_secrets((new SchedulerReporter())->report()),
            terminate: false,
        );
    }

    public function migrations(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json(
            Redactor::redact_secrets((new MigrationsReporter())->report()),
            terminate: false,
        );
    }

    public function migration_source(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        $source = (new MigrationsReporter())->source(
            (string)$atomic->get('GET.source'),
            (string)$atomic->get('GET.name'),
        );
        if ($source === null) {
            $atomic->error(404, 'Migration file not available');
            return;
        }
        Response::instance()->send_json($source, terminate: false);
    }

    public function database(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json((new DatabaseReporter())->report(), terminate: false);
    }

    public function plugins(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json(
            Redactor::redact_secrets((new PluginsReporter())->report()),
            terminate: false,
        );
    }

    public function mutex(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json(
            Redactor::redact_secrets((new MutexReporter())->report()),
            terminate: false,
        );
    }

    public function rate_limits(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json(
            Redactor::redact_secrets((new RateLimitReporter())->report()),
            terminate: false,
        );
    }

    public function quota(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json(
            Redactor::redact_secrets((new QuotaReporter())->report($this->range($atomic))),
            terminate: false,
        );
    }

    public function exceptions(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json(
            Redactor::redact_secrets((new ExceptionsReporter())->report($this->range($atomic))),
            terminate: false,
        );
    }

    public function security(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json(
            Redactor::redact_secrets((new SecurityReporter())->report($this->range($atomic))),
            terminate: false,
        );
    }

    /** Server uptime when the OS exposes it (null otherwise). */
    private static function server_uptime(): ?string
    {
        if (is_readable('/proc/uptime')) {
            $raw = @file_get_contents('/proc/uptime');
            if (is_string($raw) && preg_match('/^(\d+)/', $raw, $m)) {
                $seconds = (int)$m[1];
                $days = intdiv($seconds, 86400);
                $hours = intdiv($seconds % 86400, 3600);

                return $days > 0 ? "{$days}d {$hours}h" : "{$hours}h";
            }
        }

        return null;
    }

    private function render_fallback(\Base $atomic): void
    {
        $atomic->status(500);
        if (!headers_sent()) {
            header('HTTP/1.1 500 Internal Server Error');
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Telemetry</title></head><body>';
        echo '<h1>Telemetry Unavailable</h1>';
        echo '<p>The telemetry theme is not installed. Place a custom override in the configured public themes directory.</p>';
        echo '</body></html>';
    }
}
