<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

$atomic->route('GET /init [cli]', 'Engine\Atomic\App\System->appInit');
$atomic->route('GET /init/key [cli]',   'Engine\Atomic\App\System->appInitKey');
$atomic->route('GET /init/guide [cli]', 'Engine\Atomic\App\System->appInitGuide');
$atomic->route('GET /logs/rotate [cli]', 'Engine\Atomic\App\System->logsRotate');

$atomic->route('GET /help [cli]', 'Engine\Atomic\App\System->help');
$atomic->route('GET /cache/clear [cli]', 'Engine\Atomic\App\System->cacheClear');
$atomic->route('GET /version [cli]', 'Engine\Atomic\App\System->version');
$atomic->route('GET /routes [cli]', 'Engine\Atomic\App\System->routes');
$atomic->route('GET /classes [cli]', 'Engine\Atomic\App\System->classes');
$atomic->route('GET /custom-hive [cli]', 'Engine\Atomic\App\System->customHive');

$atomic->route('GET /db/tables [cli]', 'Engine\Atomic\App\System->dbTables');
$atomic->route('GET /db/truncate [cli]', 'Engine\Atomic\App\System->dbTruncate');
$atomic->route('GET /db/truncate/queue [cli]', 'Engine\Atomic\App\System->dbTruncateQueue');
$atomic->route('GET /db/sessions [cli]', 'Engine\Atomic\App\System->dbSessions');

$atomic->route('GET /queue/db [cli]', 'Engine\Atomic\App\System->queueDb');
$atomic->route('GET /queue/worker [cli]', 'Engine\Atomic\App\System->queueWorker');
$atomic->route('GET /queue/test [cli]', 'Engine\Atomic\App\System->queueTest');
$atomic->route('GET /queue/test/monitor [cli]', 'Engine\Atomic\App\System->queueTestMonitor');
$atomic->route('GET /queue/monitor [cli]', 'Engine\Atomic\App\System->queueMonitor');
$atomic->route('GET /queue/retry [cli]', 'Engine\Atomic\App\System->queueRetry');
$atomic->route('GET /queue/delete [cli]', 'Engine\Atomic\App\System->queueDeleteJob');

$atomic->route('GET /seed/users [cli]', 'Engine\Atomic\App\System->seedUsers');
$atomic->route('GET /seed/roles [cli]', 'Engine\Atomic\App\System->seedRoles');
$atomic->route('GET /seed/stores [cli]', 'Engine\Atomic\App\System->seedStores');
$atomic->route('GET /seed/pages [cli]', 'Engine\Atomic\App\System->seedPages');
$atomic->route('GET /seed/products [cli]', 'Engine\Atomic\App\System->seedProducts');
$atomic->route('GET /seed/categories [cli]', 'Engine\Atomic\App\System->seedCategories');

$atomic->route('GET /db/users [cli]', 'Engine\Atomic\App\System->dbUsers');
$atomic->route('GET /db/stores [cli]', 'Engine\Atomic\App\System->dbStores');
$atomic->route('GET /db/orders [cli]', 'Engine\Atomic\App\System->dbOrders');
$atomic->route('GET /db/recent-activity [cli]', 'Engine\Atomic\App\System->dbRecentActivity');
$atomic->route('GET /db/coupons [cli]', 'Engine\Atomic\App\System->dbCoupons');
$atomic->route('GET /db/payments [cli]', 'Engine\Atomic\App\System->dbPayments');
$atomic->route('GET /db/tariffs [cli]', 'Engine\Atomic\App\System->dbTariffs');
$atomic->route('GET /db/tg-front-err [cli]', 'Engine\Atomic\App\System->dbTgFrontErr');
$atomic->route('GET /db/pages [cli]', 'Engine\Atomic\App\System->dbPages');
$atomic->route('GET /db/storage [cli]', 'Engine\Atomic\App\System->dbStorage');
$atomic->route('GET /db/mutex [cli]', 'Engine\Atomic\App\System->dbMutex');

$atomic->route('GET /migrations/create [cli]', 'Engine\Atomic\App\System->migrationsCreate');
$atomic->route('GET /migrations/init [cli]', 'Engine\Atomic\App\System->migrationsInit');
$atomic->route('GET /migrations/migrate [cli]', 'Engine\Atomic\App\System->migrationsMigrate');
$atomic->route('GET /migrations/rollback [cli]', 'Engine\Atomic\App\System->migrationsRollback');
$atomic->route('GET /migrations/status [cli]', 'Engine\Atomic\App\System->migrationsStatus');
$atomic->route('GET /migrations/publish [cli]', 'Engine\Atomic\App\System->migrationsPublish');

$atomic->route('GET /file/csv2pdf [cli]', 'Engine\Atomic\App\System->fileCsv2Pdf');
$atomic->route('GET /file/xls2pdf [cli]', 'Engine\Atomic\App\System->fileXls2Pdf');

$atomic->route('GET /redis/clear [cli]', 'Engine\Atomic\App\System->redisClear');

$atomic->route('GET /schedule/run [cli]', 'Engine\Atomic\App\System->scheduleRun');
$atomic->route('GET /schedule/work [cli]', 'Engine\Atomic\App\System->scheduleWork');
$atomic->route('GET /schedule/list [cli]', 'Engine\Atomic\App\System->scheduleList');
$atomic->route('GET /schedule/test [cli]', 'Engine\Atomic\App\System->scheduleTest');
$atomic->route('GET /schedule/help [cli]', 'Engine\Atomic\App\System->scheduleHelp');