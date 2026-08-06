<?php
declare(strict_types=1);
if (!defined( 'ATOMIC_START' ) ) exit;

$telemetry_access_mode = strtolower(trim((string)($atomic->get('TELEMETRY_ACCESS_MODE') ?: 'none')));
$telemetry_access_mode = in_array($telemetry_access_mode, ['config', 'auth', 'none'], true)
    ? $telemetry_access_mode
    : 'none';

$telemetry_roles = static function (mixed $roles, array $default): array {
    if (!is_array($roles)) {
        return $default;
    }

    $roles = array_values(array_filter(
        array_map(static fn (mixed $role): string => trim((string)$role), $roles),
        static fn (string $role): bool => $role !== ''
    ));

    return $roles !== [] ? $roles : $default;
};

$telemetry_middleware = static function (array $roles) use ($telemetry_access_mode): array {
    $role_param = implode(',', $roles);

    return match ($telemetry_access_mode) {
        'none' => [],
        'auth' => ['role:' . $role_param],
        default => ['access:telemetry', 'role:' . $role_param],
    };
};

$telemetry_access = $telemetry_middleware($telemetry_roles($atomic->get('TELEMETRY_ACCESS_ALLOWED_ROLES'), ['admin']));

$atomic->route('GET /telemetry', 'Engine\Atomic\App\Telemetry->queue', $telemetry_access);
$atomic->route('POST /telemetry', 'Engine\Atomic\App\Telemetry->queue', $telemetry_access);
$atomic->route('GET|POST /telemetry/events/@driver/@job_uuid', 'Engine\Atomic\App\Telemetry->events', $telemetry_access);
$atomic->route('GET|POST /telemetry/dashboard', 'Engine\Atomic\App\Telemetry->dashboard', $telemetry_access);
$atomic->route('GET|POST /telemetry/hive', 'Engine\Atomic\App\Telemetry->hive', $telemetry_access);
$atomic->route('GET|POST /telemetry/logs', 'Engine\Atomic\App\Telemetry->logs', $telemetry_access);
$atomic->route('GET|POST /telemetry/log-channels', 'Engine\Atomic\App\Telemetry->log_channels', $telemetry_access);
$atomic->route('GET|POST /telemetry/log-stat', 'Engine\Atomic\App\Telemetry->log_stat', $telemetry_access);
$atomic->route('GET|POST /telemetry/dumps/@dump_id', 'Engine\Atomic\App\Telemetry->dump', $telemetry_access);
