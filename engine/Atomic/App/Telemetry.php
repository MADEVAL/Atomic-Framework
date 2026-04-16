<?php
declare(strict_types=1);
namespace Engine\Atomic\App;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Filesystem;
use Engine\Atomic\Core\Guard;
use Engine\Atomic\Core\ID;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\Response;
use Engine\Atomic\Core\Redactor;
use Engine\Atomic\Enums\Role;
use Engine\Atomic\Queue\Enums\Status;
use Engine\Atomic\Queue\Enums\Driver;
use Engine\Atomic\Queue\Managers\TelemetryManager;
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

    public function beforeroute(\Base $atomic): void
    {
        Redactor::sync_from_hive($atomic);

        if ($atomic->get('TELEMETRY_ADMIN_ONLY') && !Guard::has_role(Role::ADMIN)) {
            $atomic->reroute('/login');
        }
    }

    public function queue(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        $queue_manager = new TelemetryManager();
        $filters = [];
        $allowed_filters = ['status', 'uuid'];
        foreach ($allowed_filters as $filter_key) {
            $value = $atomic->get('GET.' . $filter_key);
            if (!empty($value)) {
                $filters[$filter_key] = $atomic->clean($value);
            }
        }
        if (isset($filters['status']) && !in_array($filters['status'], Status::filterable_statuses(), true)) {
            unset($filters['status']);
        }

        $page = max(1, (int)($atomic->get('GET.page') ?? 1));
        $per_page = min(200, max(1, (int)($atomic->get('GET.per_page') ?? 50)));

        $result = $queue_manager->fetch_all_jobs('*', $filters, $page, $per_page);
        $filtered_items = [];
        foreach ($result['items'] as $job_uuid => $job) {
            if (!is_array($job)) {
                continue;
            }
            if (isset($filters['status'])) {
                $job_status = (string)($job['status'] ?? '');
                if ($filters['status'] === Status::PENDING->value) {
                    if (!in_array($job_status, Status::pending_like(), true)) {
                        continue;
                    }
                } elseif ($job_status !== $filters['status']) {
                    continue;
                }
            }
            $filtered_items[$job_uuid] = $job;
        }

        $all_jobs = (array)Redactor::normalize($filtered_items);
        $status_counts = (array)Redactor::normalize($result['status_totals'] ?? []);
        $filtered_total = (int)($result['total'] ?? count($filtered_items));
        if (isset($filters['status']) && $filters['status'] !== '') {
            $status_key = $filters['status'] === Status::PENDING->value ? Status::PENDING->value : $filters['status'];
            $filtered_total = (int)($status_counts[$status_key] ?? count($filtered_items));
        }
        $atomic->set('jobs', $all_jobs);
        $atomic->set('status_counts', $status_counts);
        $atomic->set('title', 'Atomic Telemetry');
        $atomic->set('filters', $filters);
        $atomic->set('pagination', [
            'page'      => $page,
            'per_page'  => $per_page,
            'total'     => $filtered_total,
            'last_page' => (int)ceil($filtered_total / $per_page),
        ]);

        echo \View::instance()->render('layout/telemetry-queue.atom.php');
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
            'events'   => Redactor::normalize($events),
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
            //'env' => (string)$atomic->get('ENV'),
            'debug_mode'  => (bool)filter_var($atomic->get('DEBUG_MODE'), FILTER_VALIDATE_BOOLEAN),
            'debug_level' => (string)$atomic->get('DEBUG_LEVEL'),
            'logs_dir'    => Redactor::sanitize_string((string)$atomic->get('LOGS')),
            'dumps_dir'   => Redactor::sanitize_string((string)$atomic->get('DUMPS')),
            'base'        => (string)$atomic->get('BASE'),
        ];

        $system = [
            'os'       => PHP_OS_FAMILY . ' ' . PHP_OS,
            // 'uname' => function_exists('php_uname') ? php_uname() : null,
            'timezone' => date_default_timezone_get(),
        ];

        $db = null;
        try {
            $conn = ConnectionManager::instance()->get_db(false);
            if ($conn instanceof \DB\SQL) {
                $pdo = $conn->pdo();
                $db = [
                    'driver'         => Redactor::sanitize_string((string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)),
                    'server_version' => Redactor::sanitize_string((string)$pdo->getAttribute(\PDO::ATTR_SERVER_VERSION)),
                    'client_version' => Redactor::sanitize_string((string)$pdo->getAttribute(\PDO::ATTR_CLIENT_VERSION)),
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
            $hive = Redactor::normalize($atomic->hive());
        } catch (\Throwable $e) {
            $hive = ['error' => Redactor::sanitize_string($e->getMessage())];
        }

        Response::instance()->send_json($hive, terminate: false);
    }

    public function log_channels(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        Response::instance()->send_json(['channels' => Log::get_channel_names()], terminate: false);
    }

    public function log_stat(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        $logsDir = rtrim((string)$atomic->get('LOGS'), '/\\');
        $filesystem = Filesystem::instance();

        $channel = $atomic->clean((string)($atomic->get('GET.channel') ?? ''));
        $filename = 'atomic.log';
        if ($channel !== '') {
            $channelPath = Log::get_channel_path($channel);
            if ($channelPath !== null) {
                $filename = basename($channelPath);
            }
        }

        $path = $logsDir ? ($logsDir . DIRECTORY_SEPARATOR . $filename) : null;
        $res = Response::instance();
        if (!$path || !$filesystem->is_file($path)) {
            $res->send_json(['count' => 0, 'mtime' => 0], terminate: false);
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

        $res->send_json(['count' => $count, 'mtime' => (int)$mtime], terminate: false);
    }

    public function logs(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        $logsDir = rtrim((string)$atomic->get('LOGS'), '/\\');
        $filesystem = Filesystem::instance();

        $channel = $atomic->clean((string)($atomic->get('GET.channel') ?? ''));
        $filename = 'atomic.log';
        if ($channel !== '') {
            $channelPath = Log::get_channel_path($channel);
            if ($channelPath !== null) {
                $filename = basename($channelPath);
            }
        }

        $path = $logsDir ? ($logsDir . DIRECTORY_SEPARATOR . $filename) : null;
        $res = Response::instance();
        if (!$path || !$filesystem->is_file($path)) {
            $res->send_json(['lines' => [], 'pagination' => ['page' => 1, 'per_page' => 100, 'total' => 0, 'last_page' => 1]], terminate: false);
            return;
        }

        $page     = max(1, (int)($atomic->get('GET.page') ?? 1));
        $per_page = min(500, max(1, (int)($atomic->get('GET.per_page') ?? 100)));

        $cache_channel  = $channel ?: 'default';
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
                'message' => Redactor::sanitize_string($msg),
                'dump_id' => $dumpId,
            ];
        }

        $response_data = [
            'lines' => $out,
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
            $res->send_json(Redactor::normalize($decoded), terminate: false);
        } else {
            $res->send_json(['dump_id' => $dumpId, 'error' => 'dump file could not be decoded'], terminate: false);
        }
    }
}
