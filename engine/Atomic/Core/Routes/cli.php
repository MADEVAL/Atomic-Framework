<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

$this->route('GET /init [cli]', 'Engine\Atomic\App\System->app_init');
$this->route('GET /init/key [cli]',   'Engine\Atomic\App\System->app_init_key');
$this->route('GET /init/guide [cli]', 'Engine\Atomic\App\System->app_init_guide');
$this->route('GET /logs/rotate [cli]', 'Engine\Atomic\App\System->logs_rotate');
$this->route('GET /plugin/make [cli]', 'Engine\Atomic\App\System->plugin_make');
$this->route('GET /plugin/deps [cli]', 'Engine\Atomic\App\System->plugin_deps_install');
$this->route('GET /access/user/create [cli]', 'Engine\Atomic\App\System->access_user_create');
$this->route('GET /access/user/reset [cli]', 'Engine\Atomic\App\System->access_user_reset_secret');
$this->route('GET /access/user/list [cli]', 'Engine\Atomic\App\System->access_user_list');

$this->route('GET /help [cli]', 'Engine\Atomic\App\System->help');
$this->route('GET /cache/invalidate [cli]', 'Engine\Atomic\App\System->cache_invalidate');
$this->route('GET /cache/clear [cli]', 'Engine\Atomic\App\System->cache_clear');
$this->route('GET /cache/prune [cli]', 'Engine\Atomic\App\System->cache_prune');
$this->route('GET /version [cli]', 'Engine\Atomic\App\System->version');
$this->route('GET /routes [cli]', 'Engine\Atomic\App\System->routes');
$this->route('GET /classes [cli]', 'Engine\Atomic\App\System->classes');
$this->route('GET /custom-hive [cli]', 'Engine\Atomic\App\System->custom_hive');

$this->route('GET /db/tables [cli]', 'Engine\Atomic\App\System->db_tables');
$this->route('GET /db/truncate [cli]', 'Engine\Atomic\App\System->db_truncate');
$this->route('GET /db/truncate/queue [cli]', 'Engine\Atomic\App\System->db_truncate_queue');
$this->route('GET /db/sessions [cli]', 'Engine\Atomic\App\System->db_sessions');
$this->route('GET /db/queue [cli]', 'Engine\Atomic\App\System->db_queue');
$this->route('GET /db/users [cli]', 'Engine\Atomic\App\System->db_users');
$this->route('GET /db/pages [cli]', 'Engine\Atomic\App\System->db_pages');
$this->route('GET /db/storage [cli]', 'Engine\Atomic\App\System->db_storage');
$this->route('GET /db/mutex [cli]', 'Engine\Atomic\App\System->db_mutex');

$this->route('GET /queue/worker [cli]', 'Engine\Atomic\App\System->queue_worker');
$this->route('GET /queue/test [cli]', 'Engine\Atomic\App\System->queue_test');
$this->route('GET /queue/test/monitor [cli]', 'Engine\Atomic\App\System->queue_test_monitor');
$this->route('GET /queue/monitor [cli]', 'Engine\Atomic\App\System->queue_monitor');
$this->route('GET /queue/retry [cli]', 'Engine\Atomic\App\System->queue_retry');
$this->route('GET /queue/cancel [cli]', 'Engine\Atomic\App\System->queue_cancel');
$this->route('GET /queue/delete [cli]', 'Engine\Atomic\App\System->queue_delete_job');

$this->route('GET /seed/users [cli]', 'Engine\Atomic\App\System->seed_users');
$this->route('GET /seed/roles [cli]', 'Engine\Atomic\App\System->seed_roles');
$this->route('GET /seed/pages [cli]', 'Engine\Atomic\App\System->seed_pages');

$this->route('GET /migrations/create [cli]', 'Engine\Atomic\App\System->migrations_create');
$this->route('GET /migrations/init [cli]', 'Engine\Atomic\App\System->migrations_init');
$this->route('GET /migrations/migrate [cli]', 'Engine\Atomic\App\System->migrations_migrate');
$this->route('GET /migrations/rollback [cli]', 'Engine\Atomic\App\System->migrations_rollback');
$this->route('GET /migrations/status [cli]', 'Engine\Atomic\App\System->migrations_status');
$this->route('GET /migrations/publish [cli]', 'Engine\Atomic\App\System->migrations_publish');

$this->route('GET /file/csv2pdf [cli]', 'Engine\Atomic\App\System->file_csv2_pdf');
$this->route('GET /file/xls2pdf [cli]', 'Engine\Atomic\App\System->file_xls2_pdf');

$this->route('GET /redis/clear [cli]', 'Engine\Atomic\App\System->redis_clear');

$this->route('GET /schedule/run [cli]', 'Engine\Atomic\App\System->schedule_run');
$this->route('GET /schedule/work [cli]', 'Engine\Atomic\App\System->schedule_work');
$this->route('GET /schedule/list [cli]', 'Engine\Atomic\App\System->schedule_list');
$this->route('GET /schedule/test [cli]', 'Engine\Atomic\App\System->schedule_test');
$this->route('GET /schedule/help [cli]', 'Engine\Atomic\App\System->schedule_help');
