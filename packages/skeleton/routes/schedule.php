<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Scheduler\Scheduler;

$scheduler = Scheduler::instance();

// $scheduler->call(function() {
//     \Engine\Atomic\Core\Log::debug('Scheduled task executed at ' . date('H:i:s'));
// })->every_minute();
