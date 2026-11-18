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

$this->route('GET /telemetry', 'Engine\Atomic\App\Telemetry->queue', $telemetry_access);
$this->route('POST /telemetry', 'Engine\Atomic\App\Telemetry->queue', $telemetry_access);
$this->route('GET|POST /telemetry/events/@driver/@job_uuid', 'Engine\Atomic\App\Telemetry->events', $telemetry_access);
$this->route('GET|POST /telemetry/dashboard', 'Engine\Atomic\App\Telemetry->dashboard', $telemetry_access);
$this->route('GET|POST /telemetry/hive', 'Engine\Atomic\App\Telemetry->hive', $telemetry_access);
$this->route('GET|POST /telemetry/logs', 'Engine\Atomic\App\Telemetry->logs', $telemetry_access);
$this->route('GET|POST /telemetry/log-channels', 'Engine\Atomic\App\Telemetry->log_channels', $telemetry_access);
$this->route('GET|POST /telemetry/log-stat', 'Engine\Atomic\App\Telemetry->log_stat', $telemetry_access);
$this->route('GET|POST /telemetry/dumps/@dump_id', 'Engine\Atomic\App\Telemetry->dump', $telemetry_access);
$this->route('GET /telemetry/view/@tab', 'Engine\Atomic\App\Telemetry->fragment', $telemetry_access);
$this->route('GET /telemetry/part/@part', 'Engine\Atomic\App\Telemetry->part', $telemetry_access);

$this->route('GET|POST /telemetry/jobs', 'Engine\Atomic\App\Telemetry->jobs', $telemetry_access);
$this->route('GET|POST /telemetry/summary', 'Engine\Atomic\App\Telemetry->summary', $telemetry_access);
$this->route('GET|POST /telemetry/schedule', 'Engine\Atomic\App\Telemetry->schedule', $telemetry_access);
$this->route('GET|POST /telemetry/migrations', 'Engine\Atomic\App\Telemetry->migrations', $telemetry_access);
$this->route('GET /telemetry/migration-source', 'Engine\Atomic\App\Telemetry->migration_source', $telemetry_access);
$this->route('GET /telemetry/database', 'Engine\Atomic\App\Telemetry->database', $telemetry_access);
$this->route('GET|POST /telemetry/plugins', 'Engine\Atomic\App\Telemetry->plugins', $telemetry_access);
$this->route('GET|POST /telemetry/mutex', 'Engine\Atomic\App\Telemetry->mutex', $telemetry_access);
$this->route('GET|POST /telemetry/rate-limits', 'Engine\Atomic\App\Telemetry->rate_limits', $telemetry_access);
$this->route('GET|POST /telemetry/quota', 'Engine\Atomic\App\Telemetry->quota', $telemetry_access);
$this->route('GET|POST /telemetry/exceptions', 'Engine\Atomic\App\Telemetry->exceptions', $telemetry_access);
$this->route('GET|POST /telemetry/security', 'Engine\Atomic\App\Telemetry->security', $telemetry_access);
