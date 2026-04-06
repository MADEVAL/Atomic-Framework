<?php
declare(strict_types=1);
namespace Engine\Atomic\App;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Guard;
use Engine\Atomic\Core\ID;
use Engine\Atomic\Core\Sanitizer;
use Engine\Atomic\Enums\Role;
use Engine\Atomic\Queue\Managers\TelemetryManager;
use Engine\Atomic\Theme\Theme;

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
        Sanitizer::syncFromHive($atomic);

        if ($atomic->get('TELEMETRY_ADMIN_ONLY') && !Guard::has_role(Role::ADMIN)) {
            $atomic->reroute('/login');
        }
    }

    public function queue(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        $queue_manager = new TelemetryManager();
        $filters = [];
        $allowed_filters = ['driver', 'status', 'queue', 'uuid', 'state', 'date_from', 'date_to'];
        foreach ($allowed_filters as $filter_key) {
            $value = $atomic->get('GET.' . $filter_key);
            if (!empty($value)) {
                $filters[$filter_key] = $atomic->clean($value);
            }
        }
        $all_jobs = !empty($filters)
            ? $queue_manager->fetch_all_jobs($filters['queue'] ?? '*', $filters)
            : $queue_manager->fetch_all_jobs();

        $all_jobs = (array)Sanitizer::normalize($all_jobs);

        $status_counts = [
            'failed' => 0,
            'queued' => 0,
            'running' => 0,
            'completed' => 0,
            'total' => count($all_jobs),
        ];
        foreach ($all_jobs as $job) {
            $job_state = is_array($job) ? ($job['state'] ?? 'unknown') : 'unknown';
            if (isset($status_counts[$job_state])) {
                $status_counts[$job_state]++;
            }
        }
        $atomic->set('jobs', $all_jobs);
        $atomic->set('status_counts', $status_counts);
        $atomic->set('title', 'Atomic Telemetry');
        $atomic->set('filters', $filters);

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
        if (!in_array($driver, ['redis', 'database'], true)) {
            $atomic->error(400, 'Invalid driver. Must be "redis" or "database"');
            return;
        }
        $telemetry_manager = new TelemetryManager();
        $events = $telemetry_manager->fetch_events($driver, 'default', $job_uuid);
        header('Content-Type: application/json');
        echo json_encode([
            'job_uuid' => $job_uuid,
            'driver'   => $driver,
            'events'   => Sanitizer::normalize($events),
        ]);
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
            'logs_dir'    => Sanitizer::sanitize_string((string)$atomic->get('LOGS')),
            'dumps_dir'   => Sanitizer::sanitize_string((string)$atomic->get('DUMPS')),
            'base'        => (string)$atomic->get('BASE'),
        ];

        $system = [
            'os'       => PHP_OS_FAMILY . ' ' . PHP_OS,
            // 'uname' => function_exists('php_uname') ? php_uname() : null,
            'timezone' => date_default_timezone_get(),
        ];

        $db = null;
        try {
            $conn = $atomic->get('DB');
            if ($conn instanceof \DB\SQL) {
                $pdo = $conn->pdo();
                $db = [
                    'driver'         => Sanitizer::sanitize_string((string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)),
                    'server_version' => Sanitizer::sanitize_string((string)$pdo->getAttribute(\PDO::ATTR_SERVER_VERSION)),
                    'client_version' => Sanitizer::sanitize_string((string)$pdo->getAttribute(\PDO::ATTR_CLIENT_VERSION)),
                ];
            }
        } catch (\Throwable $e) {}

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'php' => $php,
            'f3' => $f3,
            'atomic' => $atomicInfo,
            'system' => $system,
            'db' => $db,
        ]);
    }

    public function hive(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        $hive = [];
        try {
            $hive = Sanitizer::normalize($atomic->hive());
        } catch (\Throwable $e) {
            $hive = ['error' => Sanitizer::sanitize_string($e->getMessage())];
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($hive, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function logs(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        $logsDir = rtrim((string)$atomic->get('LOGS'), '/\\');
        $path = $logsDir ? ($logsDir . DIRECTORY_SEPARATOR . 'atomic.log') : null;
        if (!$path || !is_file($path)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['lines' => []]);
            return;
        }

        $maxBytes = 200 * 1024;
        $content = '';
        $size = filesize($path);
        $fh = @fopen($path, 'rb');
        if ($fh) {
            if ($size > $maxBytes) {
                fseek($fh, -$maxBytes, SEEK_END);
            }
            $content = stream_get_contents($fh) ?: '';
            fclose($fh);
        } else {
            $content = @file_get_contents($path) ?: '';
        }

        $lines = preg_split("/\r\n|\n|\r/", $content);
        $lines = array_slice($lines, -300);

        $out = [];
        foreach ($lines as $ln) {
            if ($ln === '') continue;
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
                'message' => Sanitizer::sanitize_string($msg),
                'dump_id' => $dumpId,
            ];
        }

        $out = array_reverse($out);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['lines' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function dump(\Base $atomic, array $params = [], ?string $alias = null): void
    {
        $dumpId = (string)($params['dump_id'] ?? '');
        if (!ID::is_valid_uuid_v4($dumpId)) {
            $atomic->error(400, 'Invalid dump id');
            return;
        }
        $dumpsDir = rtrim((string)$atomic->get('DUMPS'), '/\\');
        $file = $dumpsDir ? ($dumpsDir . DIRECTORY_SEPARATOR . $dumpId . '.json') : null;
        if (!$file || !is_file($file)) {
            $atomic->error(404, 'Dump not found');
            return;
        }

        $raw = @file_get_contents($file) ?: '';
        $decoded = json_decode($raw, true);
        header('Content-Type: application/json; charset=utf-8');
        if (json_last_error() === JSON_ERROR_NONE) {
            echo json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        } else {
            echo json_encode(['dump_id' => $dumpId, 'error' => 'dump file could not be decoded'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
}