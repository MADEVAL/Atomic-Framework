<?php
declare(strict_types=1);
if (!defined( 'ATOMIC_START' ) ) exit;

$atomic->route('GET /telemetry', 'Engine\Atomic\App\Telemetry->queue');
$atomic->route('GET /telemetry/events/@driver/@job_uuid', 'Engine\Atomic\App\Telemetry->events');
$atomic->route('GET /telemetry/dashboard', 'Engine\Atomic\App\Telemetry->dashboard');
$atomic->route('GET /telemetry/hive', 'Engine\Atomic\App\Telemetry->hive');
$atomic->route('GET /telemetry/logs', 'Engine\Atomic\App\Telemetry->logs');
$atomic->route('GET /telemetry/dumps/@dump_id', 'Engine\Atomic\App\Telemetry->dump');
