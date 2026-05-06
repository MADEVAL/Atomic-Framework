<?php
declare(strict_types=1);
if (!defined( 'ATOMIC_START' ) ) exit;

$telemetry_access_mode = strtolower(trim((string)($atomic->get('TELEMETRY_ACCESS_MODE') ?: 'none')));
$telemetry_access_mode = in_array($telemetry_access_mode, ['config', 'auth', 'none'], true)
    ? $telemetry_access_mode
    : 'none';

$telemetry_middleware = static function (string $role) use ($telemetry_access_mode): array {
    return match ($telemetry_access_mode) {
        'none' => [],
        'auth' => ['role:' . $role],
        default => ['access:telemetry', 'role:' . $role],
    };
};

$telemetry_viewer = $telemetry_middleware('telemetry.viewer');
$telemetry_admin = $telemetry_middleware('telemetry.admin');

$atomic->route('GET /telemetry', 'Engine\Atomic\App\Telemetry->queue', $telemetry_viewer);
$atomic->route('POST /telemetry', 'Engine\Atomic\App\Telemetry->queue', $telemetry_admin);
$atomic->route('GET|POST /telemetry/events/@driver/@job_uuid', 'Engine\Atomic\App\Telemetry->events', $telemetry_viewer);
$atomic->route('GET|POST /telemetry/dashboard', 'Engine\Atomic\App\Telemetry->dashboard', $telemetry_viewer);
$atomic->route('GET|POST /telemetry/hive', 'Engine\Atomic\App\Telemetry->hive', $telemetry_viewer);
$atomic->route('GET|POST /telemetry/logs', 'Engine\Atomic\App\Telemetry->logs', $telemetry_viewer);
$atomic->route('GET|POST /telemetry/log-channels', 'Engine\Atomic\App\Telemetry->log_channels', $telemetry_viewer);
$atomic->route('GET|POST /telemetry/log-stat', 'Engine\Atomic\App\Telemetry->log_stat', $telemetry_viewer);
$atomic->route('GET|POST /telemetry/dumps/@dump_id', 'Engine\Atomic\App\Telemetry->dump', $telemetry_viewer);
