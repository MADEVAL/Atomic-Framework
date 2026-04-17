<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

$atomic->route('GET /init [cli]', 'Engine\Atomic\App\System->app_init');
$atomic->route('GET /init/key [cli]',   'Engine\Atomic\App\System->app_init_key');
$atomic->route('GET /init/guide [cli]', 'Engine\Atomic\App\System->app_init_guide');
$atomic->route('GET /logs/rotate [cli]', 'Engine\Atomic\App\System->logs_rotate');

$atomic->route('GET /help [cli]', 'Engine\Atomic\App\System->help');
$atomic->route('GET /cache/clear [cli]', 'Engine\Atomic\App\System->cache_clear');
$atomic->route('GET /version [cli]', 'Engine\Atomic\App\System->version');
$atomic->route('GET /routes [cli]', 'Engine\Atomic\App\System->routes');
$atomic->route('GET /classes [cli]', 'Engine\Atomic\App\System->classes');
$atomic->route('GET /custom-hive [cli]', 'Engine\Atomic\App\System->custom_hive');

$atomic->route('GET /db/tables [cli]', 'Engine\Atomic\App\System->db_tables');
$atomic->route('GET /db/truncate [cli]', 'Engine\Atomic\App\System->db_truncate');
$atomic->route('GET /db/truncate/queue [cli]', 'Engine\Atomic\App\System->db_truncate_queue');
$atomic->route('GET /db/sessions [cli]', 'Engine\Atomic\App\System->db_sessions');

$atomic->route('GET /queue/db [cli]', 'Engine\Atomic\App\System->queue_db');
$atomic->route('GET /queue/worker [cli]', 'Engine\Atomic\App\System->queue_worker');
$atomic->route('GET /queue/test [cli]', 'Engine\Atomic\App\System->queue_test');
$atomic->route('GET /queue/test/monitor [cli]', 'Engine\Atomic\App\System->queue_test_monitor');
$atomic->route('GET /queue/monitor [cli]', 'Engine\Atomic\App\System->queue_monitor');
$atomic->route('GET /queue/retry [cli]', 'Engine\Atomic\App\System->queue_retry');
$atomic->route('GET /queue/delete [cli]', 'Engine\Atomic\App\System->queue_delete_job');

$atomic->route('GET /seed/users [cli]', 'Engine\Atomic\App\System->seed_users');
$atomic->route('GET /seed/roles [cli]', 'Engine\Atomic\App\System->seed_roles');
$atomic->route('GET /seed/pages [cli]', 'Engine\Atomic\App\System->seed_pages');

$atomic->route('GET /db/users [cli]', 'Engine\Atomic\App\System->db_users');
$atomic->route('GET /db/pages [cli]', 'Engine\Atomic\App\System->db_pages');
$atomic->route('GET /db/storage [cli]', 'Engine\Atomic\App\System->db_storage');
$atomic->route('GET /db/mutex [cli]', 'Engine\Atomic\App\System->db_mutex');

$atomic->route('GET /migrations/create [cli]', 'Engine\Atomic\App\System->migrations_create');
$atomic->route('GET /migrations/init [cli]', 'Engine\Atomic\App\System->migrations_init');
$atomic->route('GET /migrations/migrate [cli]', 'Engine\Atomic\App\System->migrations_migrate');
$atomic->route('GET /migrations/rollback [cli]', 'Engine\Atomic\App\System->migrations_rollback');
$atomic->route('GET /migrations/status [cli]', 'Engine\Atomic\App\System->migrations_status');
$atomic->route('GET /migrations/publish [cli]', 'Engine\Atomic\App\System->migrations_publish');

$atomic->route('GET /file/csv2pdf [cli]', 'Engine\Atomic\App\System->file_csv2_pdf');
$atomic->route('GET /file/xls2pdf [cli]', 'Engine\Atomic\App\System->file_xls2_pdf');

$atomic->route('GET /redis/clear [cli]', 'Engine\Atomic\App\System->redis_clear');

$atomic->route('GET /schedule/run [cli]', 'Engine\Atomic\App\System->schedule_run');
$atomic->route('GET /schedule/work [cli]', 'Engine\Atomic\App\System->schedule_work');
$atomic->route('GET /schedule/list [cli]', 'Engine\Atomic\App\System->schedule_list');
$atomic->route('GET /schedule/test [cli]', 'Engine\Atomic\App\System->schedule_test');
$atomic->route('GET /schedule/help [cli]', 'Engine\Atomic\App\System->schedule_help');
